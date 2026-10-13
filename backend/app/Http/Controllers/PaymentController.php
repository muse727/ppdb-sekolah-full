<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Payment;
use App\Models\Setting;
use App\Support\ImageMetadata;
use App\Support\PaymentEvidence;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    // AMBIL HARGA DARI SETTINGS
    public function getPrices()
    {
        return response()->json([
            'SD'  => (int) (Setting::where('key', 'biaya_sd')->first()->value ?? 0),
            'SMP' => (int) (Setting::where('key', 'biaya_smp')->first()->value ?? 0),
        ]);
    }

    /**
     * CEK STATUS PEMBAYARAN (manual-transfer only).
     *
     * Expiry: the only auto-expire logic ever present here belonged to the
     * removed gateway flow (it aged out gateway invoices nobody paid). The
     * manual-transfer flow has NO expiry by design — proof stays PENDING_MANUAL
     * until a human verifies or rejects it, and REJECTED lets the parent upload
     * again. So no manual expiry behavior was lost when that branch was removed.
     *
     * Ordering: latest('id') must match manualPayment()'s duplicate guard, which
     * also uses latest('id'). created_at is second-resolution, so two uploads in
     * the same second leave latest() ties broken arbitrarily by MySQL — that
     * returned a STALE row (e.g. an old REJECTED) and disagreed with the guard
     * deciding whether an upload is allowed. id is a monotonic auto-increment, so
     * latest('id') is deterministic and the two call sites now agree.
     */
    public function checkStatus()
    {
        $user    = Auth::user();
        // PPDB-only: a later DAFTAR_ULANG payment must never masquerade as the
        // PPDB status record. latest('id') matches manualPayment()'s guard.
        $payment = Payment::ppdb()->where('user_id', $user->id)->latest('id')->first();

        if (!$payment) {
            return response()->json(['has_payment' => false, 'step' => 1]);
        }

        $step = $payment->status === 'SUCCESS' ? 2 : 1;

        return response()->json([
            'has_payment' => true,
            'payment'     => [
                'id'               => $payment->id,
                'invoice_number'   => $payment->invoice_number,
                'jenjang'          => $payment->jenjang,
                'amount'           => $payment->amount,
                'status'           => $payment->status,
                'proof_of_payment' => $payment->proof_of_payment,
                'verified_at'      => $payment->verified_at,
                // Surfaced so the parent learns WHY it was rejected and can fix it.
                'rejection_reason' => $payment->status === 'REJECTED' ? $payment->rejection_reason : null,
                // Deliberately omitted: verifier_id. The reviewer's identity is an
                // internal audit field, not parent-facing data.
            ],
            'step'        => $step,
        ]);
    }

    // UPLOAD BUKTI MANUAL TRANSFER
    public function manualPayment(Request $request)
    {
        $user = Auth::user();

        // Unggahan bukti bayar menulis file ke disk privat + membuat row
        // payment. Tanpa throttle, seorang user bisa retry-churn menimbun
        // file/row (orphan pada tiap retry valid). Kunci = user id + IP
        // (keduanya server-observable; field client tak bisa mem-bypass).
        // Limit longgar (10/menit) agar retry sah tidak terganggu. Dicek
        // SEBELUM validasi/tulis file supaya 429 tidak meninggalkan artifact.
        $throttleKey = 'payment-manual|' . $user->id . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'message' => "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.",
            ], 429);
        }
        RateLimiter::hit($throttleKey, 60);

        $request->validate([
            'jenjang'    => 'required|in:SD,SMP',
            'bukti_bayar'=> 'required|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        $jenjang = $user->jenjang;
        if (!in_array($jenjang, ['SD', 'SMP'], true)) {
            return response()->json(['message' => 'Jenjang pendaftaran tidak valid.'], 422);
        }

        // Re-upload policy: SUCCESS and PENDING_MANUAL are blocking. REJECTED,
        // FAILED and EXPIRED are NOT — a rejected parent is expected to upload
        // corrected proof, which creates a NEW row and leaves the rejected one
        // immutable as an audit trail. Scoped to PPDB so a DAFTAR_ULANG payment
        // can never satisfy (or block) the PPDB duplicate guard.
        $storedPath = null;
        $payment = null;
        try {
            $transactionResult = DB::transaction(function () use ($request, $user, $jenjang, &$storedPath, &$payment) {
                // Serialize duplicate-check + insert, including the no-payment case.
                \App\Models\User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (Payment::hasBlockingPayment($user->id, Payment::CATEGORY_PPDB)) {
                    return response()->json(['message' => 'Pembayaran sudah tercatat.'], 409);
                }

                $priceKey = 'biaya_' . strtolower($jenjang);
                $amount = (int) (Setting::where('key', $priceKey)->value('value') ?? 0);
                if ($amount <= 0) {
                    return response()->json(['message' => 'Biaya pendaftaran belum dikonfigurasi.'], 422);
                }

                $invoiceNumber = 'MANUAL-' . date('Ymd') . '-' . strtoupper(Str::random(4));
                $storedPath = ImageMetadata::storeSanitized($request->file('bukti_bayar'), 'bukti_bayar', 'private');
                $payment = Payment::create([
                    'user_id' => $user->id,
                    'payment_category' => Payment::CATEGORY_PPDB,
                    'invoice_number' => $invoiceNumber,
                    'jenjang' => $jenjang,
                    'amount' => $amount,
                    'payment_method' => 'MANUAL',
                    'proof_of_payment' => $storedPath,
                    'status' => 'PENDING_MANUAL',
                ]);

                // Do NOT return the Eloquent model from the transaction: a
                // wasRecentlyCreated model is auto-converted to HTTP 201 by
                // Router::toResponse(), changing the endpoint contract (200).
                return response()->json([
                    'success' => true,
                    'message' => 'Bukti pembayaran berhasil diunggah.',
                ]);
            });
        } catch (\Throwable $e) {
            if ($storedPath !== null) {
                Storage::disk('private')->delete($storedPath);
            }
            throw $e;
        }

        // E.8: instructions email — dispatched AFTER the DB transaction
        // returned successfully (row committed). Bank details come from
        // admin-configured Setting rows, never client input.
        if ($payment !== null) {
            app(\App\Services\PaymentNotificationService::class)->notifyIssued($payment);
        }

        return $transactionResult;
    }

    // ADMIN: TARIK DATA MANUAL
    public function getPendingManual()
    {
        $payments = Payment::with('user:id,name,email')
            ->ppdb()
            ->where('status', 'PENDING_MANUAL')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($payments);
    }

    /** Serve payment evidence only to its owner or an administrator. */
    public function evidence(int $id)
    {
        // PPDB-scoped: an owner's DAFTAR_ULANG payment is not reachable through
        // this PPDB endpoint by swapping the id.
        $payment = Payment::with('user:id')->ppdb()->findOrFail($id);
        $user = Auth::user();

        abort_unless(
            $user->role === 'admin'
            || $user->role === 'super_admin'
            || $payment->user_id === $user->id,
            403
        );
        abort_unless(PaymentEvidence::exists($payment->proof_of_payment), 404);

        return PaymentEvidence::stream($payment->proof_of_payment);
    }

    // ADMIN: VERIFIKASI MANUAL — race-safe (lockForUpdate + re-check status).
    // SUCCESS bersifat terminal; hanya PENDING_MANUAL yang boleh diverifikasi.
    public function verifyManual($id)
    {
        return DB::transaction(function () use ($id) {
            // Category-aware: this admin endpoint verifies PPDB payments only.
            // A DAFTAR_ULANG payment returns 404 rather than being verified
            // through the PPDB flow.
            $payment = Payment::ppdb()->whereKey($id)->lockForUpdate()->first();

            if (!$payment) {
                return response()->json(['message' => 'Pembayaran tidak ditemukan.'], 404);
            }

            if ($payment->status !== 'PENDING_MANUAL') {
                return response()->json(['message' => 'Pembayaran tidak berada dalam status menunggu verifikasi.'], 409);
            }

            // Seat ownership is established during initial registration.
            // Payment verification is financial-only: it neither checks quota
            // nor creates, releases, or changes any seat allocation.
            $payment->update([
                'status'      => 'SUCCESS',
                'verifier_id' => Auth::id(),
                'verified_at' => now(),
            ]);

            // E.8: authoritative SUCCESS transition committed — notify the
            // parent. The 409 guard above makes the transition one-shot, so
            // the email is one-shot too. Delivery failure is reported, never
            // faked, and never rolls the payment back.
            app(\App\Services\PaymentNotificationService::class)->notifyVerified($payment);

            return response()->json(['success' => true, 'message' => 'Pembayaran berhasil diverifikasi.']);
        });
    }

    // ADMIN: TOLAK PEMBAYARAN MANUAL
    public function rejectManual(Request $request, $id)
    {
        $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($request, $id) {
            // Category-aware: rejection applies to PPDB payments only.
            $payment = Payment::ppdb()->whereKey($id)->lockForUpdate()->first();

            if (!$payment) {
                return response()->json(['message' => 'Pembayaran tidak ditemukan.'], 404);
            }

            if ($payment->status !== 'PENDING_MANUAL') {
                return response()->json(['message' => 'Pembayaran tidak berada dalam status menunggu verifikasi.'], 409);
            }

            $payment->update([
                'status'           => 'REJECTED',
                'verifier_id'      => Auth::id(),
                'verified_at'      => now(),
                'rejection_reason' => $request->input('reason'),
            ]);

            // E.8: authoritative REJECTED transition — one-shot by the 409
            // guard above. Includes the admin's rejection reason.
            app(\App\Services\PaymentNotificationService::class)->notifyRejected($payment);

            return response()->json(['success' => true, 'message' => 'Pembayaran ditolak.']);
        });
    }
}
