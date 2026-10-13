<?php

namespace App\Services;

use App\Models\DaftarUlangInvoice;
use App\Models\Payment;
use App\Support\PaymentEvidence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Daftar Ulang MANUAL payment settlement (B.6A).
 *
 * This class handles ONLY the DAFTAR_ULANG payment_category. Every query is
 * pinned to Payment::CATEGORY_DAFTAR_ULANG server-side; the category is never
 * read from the request. PPDB behaviour lives in PaymentController and is
 * untouched here.
 *
 * Financial-only guarantee: settlement NEVER touches seats or capacity. It does
 * not call consumeSeat()/allocateSeat()/releaseSeat()/lockRegistrationCapacity()
 * and never reads or writes ppdb_seat_allocations / ppdb_capacities.
 */
class DaftarUlangPaymentService
{
    /**
     * Resolve the DAFTAR_ULANG payment owned by this parent AND backed by a
     * Daftar Ulang invoice, locking the row for update. Returns null when the
     * parent has no such payment (never crosses into PPDB rows).
     */
    private function ownedDaftarUlangPaymentLocked(int $userId): ?Payment
    {
        return Payment::daftarUlang()
            ->where('user_id', $userId)
            ->whereIn('id', DaftarUlangInvoice::query()->select('payment_id'))
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }

    /**
     * Resolve a DAFTAR_ULANG payment by id, guaranteeing it is backed by a real
     * Daftar Ulang invoice row. Returns null for PPDB rows or orphaned ids, so
     * category isolation can never be bypassed by guessing an id.
     */
    private function daftarUlangPaymentLocked(int $paymentId): ?Payment
    {
        return Payment::daftarUlang()
            ->whereKey($paymentId)
            ->whereIn('id', DaftarUlangInvoice::query()->select('payment_id'))
            ->lockForUpdate()
            ->first();
    }

    /**
     * Authenticated parent uploads their own Daftar Ulang proof.
     *
     * Server-derived: user_id (Auth), payment_category (DAFTAR_ULANG), jenjang,
     * amount, the invoice link and the status. The request carries ONLY the
     * upload payload — validated by the controller before this runs.
     */
    public function submitProof(int $authenticatedUserId, Request $request): \Illuminate\Http\JsonResponse
    {
        $storedPath = null;
        try {
            return DB::transaction(function () use ($authenticatedUserId, $request, &$storedPath) {
                $payment = $this->ownedDaftarUlangPaymentLocked($authenticatedUserId);

                // No DAFTAR_ULANG payment backed by an invoice for this parent.
                abort_unless($payment, 404, 'Pembayaran Daftar Ulang tidak ditemukan.');

                // The invoice is already bound to this payment and this user
                // server-side; prove the link rather than trusting any input.
                abort_unless(
                    DaftarUlangInvoice::where('payment_id', $payment->id)
                        ->where('user_id', $authenticatedUserId)->exists(),
                    404,
                    'Faktur Daftar Ulang tidak ditemukan.'
                );

                // Only a fresh invoice (no proof yet) or a REJECTED one may be
                // (re)submitted. SUCCESS / PENDING_MANUAL block a duplicate upload.
                abort_unless(
                    in_array($payment->status, ['PENDING', 'REJECTED'], true),
                    409,
                    'Pembayaran sudah tercatat.'
                );

                $oldPath = $payment->proof_of_payment;
                $storedPath = PaymentEvidence::store($request->file('bukti_bayar'), 'bukti_bayar');

                $payment->update([
                    'proof_of_payment' => $storedPath,
                    'status'           => 'PENDING_MANUAL',
                    // A re-submission after rejection clears the prior review trail.
                    'verifier_id'      => null,
                    'verified_at'      => null,
                    'rejection_reason' => null,
                ]);

                if ($oldPath && $oldPath !== $storedPath) {
                    Storage::disk('private')->delete($oldPath);
                }

                return response()->json(['success' => true, 'message' => 'Bukti pembayaran berhasil diunggah.']);
            });
        } catch (\Throwable $e) {
            // Roll back the transaction AND any orphaned file written this request.
            if ($storedPath !== null) {
                Storage::disk('private')->delete($storedPath);
            }
            throw $e;
        }
    }

    /** Admin: list ONLY DAFTAR_ULANG manual payments awaiting verification. */
    public function pendingManual()
    {
        return Payment::with('user:id,name,email')
            ->daftarUlang()
            ->where('status', 'PENDING_MANUAL')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Admin: stream a DAFTAR_ULANG payment's evidence from private storage.
     * A PPDB payment id returns 404 (category isolation), never the bytes.
     */
    public function evidence(int $paymentId): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $payment = $this->daftarUlangPaymentLocked($paymentId);

        abort_unless($payment, 404);
        abort_unless(PaymentEvidence::exists($payment->proof_of_payment), 404);

        return PaymentEvidence::stream($payment->proof_of_payment);
    }

    /**
     * Admin: verify a DAFTAR_ULANG manual payment. Race-safe via row lock +
     * status re-check. Financial-only — never touches seats or capacity.
     */
    public function verify(int $paymentId): \Illuminate\Http\JsonResponse
    {
        return DB::transaction(function () use ($paymentId) {
            $payment = $this->daftarUlangPaymentLocked($paymentId);

            abort_unless($payment, 404, 'Pembayaran tidak ditemukan.');
            abort_unless($payment->status === 'PENDING_MANUAL', 409, 'Pembayaran tidak berada dalam status menunggu verifikasi.');

            $payment->update([
                'status'      => 'SUCCESS',
                'verifier_id' => Auth::id(),
                'verified_at' => now(),
            ]);

            // E.8: authoritative SUCCESS transition — one-shot by the 409
            // abort above. Notify the parent (DAFTAR_ULANG has no bank
            // instructions block in the email).
            app(\App\Services\PaymentNotificationService::class)->notifyVerified($payment);

            return response()->json(['success' => true, 'message' => 'Pembayaran berhasil diverifikasi.']);
        });
    }

    /**
     * Admin: reject a DAFTAR_ULANG manual payment. Race-safe via row lock +
     * status re-check. Financial-only — never touches seats or capacity.
     */
    public function reject(int $paymentId, ?string $reason): \Illuminate\Http\JsonResponse
    {
        return DB::transaction(function () use ($paymentId, $reason) {
            $payment = $this->daftarUlangPaymentLocked($paymentId);

            abort_unless($payment, 404, 'Pembayaran tidak ditemukan.');
            abort_unless($payment->status === 'PENDING_MANUAL', 409, 'Pembayaran tidak berada dalam status menunggu verifikasi.');

            $payment->update([
                'status'           => 'REJECTED',
                'verifier_id'      => Auth::id(),
                'verified_at'      => now(),
                'rejection_reason' => $reason,
            ]);

            // E.8: authoritative REJECTED transition — one-shot by the 409
            // abort above. Notify the parent with the rejection reason.
            app(\App\Services\PaymentNotificationService::class)->notifyRejected($payment);

            return response()->json(['success' => true, 'message' => 'Pembayaran ditolak.']);
        });
    }
}
