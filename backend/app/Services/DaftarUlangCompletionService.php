<?php

namespace App\Services;

use App\Models\DaftarUlangAgreementAcceptance;
use App\Models\DaftarUlangInvoice;
use App\Models\PpdbSeatAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * B.7.2 — MELANJUTKAN completion: the ONLY path to status_siswa = TERDAFTAR.
 *
 * TERDAFTAR is the canonical official-student state and belongs to users.id
 * (no students/applicants/children table exists). LULUS, payment SUCCESS and
 * agreement acceptance are prerequisites from separate domains — none of them
 * alone makes a student official. The explicit transition NULL -> TERDAFTAR
 * happens exclusively here; legacy users with NULL stay NULL until they
 * explicitly continue.
 *
 * Canonical identity is the authenticated users.id. The DU invoice, its
 * agreement snapshot, the DU payment and the seat allocation are all resolved
 * server-side; nothing is trusted from the request.
 *
 * Lock order mirrors the withdrawal/settlement evidence (users first, then
 * the DU invoice, then payment, then the seat allocation), so a concurrent
 * withdrawal serializes on the users row: withdrawal-first commits make the
 * continuation fail (withdrawn invoice / MENGUNDURKAN_DIRI), a committed
 * TERDAFTAR makes the later withdrawal fail (terminal official state / no
 * active seat to release).
 *
 * MELANJUTKAN consumes no seat, creates no allocation, touches no capacity,
 * and never modifies the invoice, the payment, the agreement acceptance or
 * any PPDB record. The single committed mutation is
 * users.status_siswa = TERDAFTAR + users.continued_at (set exactly once;
 * idempotent replay returns the original timestamp).
 */
class DaftarUlangCompletionService
{
    public function continue(int $authenticatedUserId): \Illuminate\Http\JsonResponse
    {
        return DB::transaction(function () use ($authenticatedUserId) {
            // 1. Lock the canonical user row FIRST. This serializes continue vs
            //    continue and continue vs withdraw (both take the users lock
            //    first), making the terminal-state race deterministic.
            $user = User::whereKey($authenticatedUserId)->lockForUpdate()->firstOrFail();
            abort_unless($user->role === 'orang_tua', 403);

            // 2. Idempotent replay: TERDAFTAR is terminal. A repeat request
            //    returns the committed state WITHOUT touching continued_at,
            //    invoice, payment, seat or capacity.
            if ($user->status_siswa === User::SISWA_TERDAFTAR) {
                return $this->payload($user, false);
            }
            // A withdrawn student must never become TERDAFTAR; the state is
            // terminal, so no reinstatement semantics exist.
            if ($user->status_siswa === User::SISWA_MENGUNDURKAN_DIRI) {
                throw ValidationException::withMessages(['status_siswa' => 'Siswa telah mengundurkan diri; tidak dapat melanjutkan.']);
            }

            // 3. Academic gate: only LULUS may continue.
            if ($user->status_kelulusan !== 'LULUS') {
                throw ValidationException::withMessages(['eligibility' => 'Melanjutkan hanya untuk peserta LULUS.']);
            }

            // 4. Resolve the canonical DU invoice for THIS user (id never comes
            //    from the request) and reject a withdrawn invoice.
            $invoice = DaftarUlangInvoice::where('user_id', $user->id)
                ->orderBy('id')->lockForUpdate()->first();
            abort_unless($invoice, 404, 'Faktur Daftar Ulang tidak ditemukan.');
            if ($invoice->withdrawn_at !== null) {
                throw ValidationException::withMessages(['withdrawn' => 'Faktur Daftar Ulang telah ditarik; tidak dapat melanjutkan.']);
            }

            // 5. Agreement acceptance must exist for the invoice's authoritative
            //    agreement snapshot (version + sha256 from the stored snapshot,
            //    never from the client).
            $agreement = $invoice->pricing_snapshot['agreement'] ?? null;
            $accepted = is_array($agreement)
                && DaftarUlangAgreementAcceptance::where('invoice_id', $invoice->id)
                    ->where('user_id', $user->id)
                    ->where('agreement_version', $agreement['version'] ?? '')
                    ->where('agreement_sha256', $agreement['sha256'] ?? '')
                    ->exists();
            if (!$accepted) {
                throw ValidationException::withMessages(['agreement' => 'Persetujuan Daftar Ulang untuk versi resmi faktur ini belum diterima.']);
            }

            // 6. Canonical DU payment must exist, be DAFTAR_ULANG (enforced by
            //    the invoice->payment() category scope) and SUCCESS.
            $payment = $invoice->payment()->lockForUpdate()->first();
            abort_unless($payment, 404, 'Pembayaran Daftar Ulang tidak ditemukan.');
            if ($payment->status !== 'SUCCESS') {
                throw ValidationException::withMessages(['payment' => 'Pembayaran Daftar Ulang belum lunas.']);
            }

            // 7. The existing initial PPDB seat must still be ALLOCATED. A
            //    withdrawal (which runs first in a race) releases it, so the
            //    continuation deterministically fails here.
            $seat = PpdbSeatAllocation::where('user_id', $user->id)
                ->where('status', PpdbSeatAllocation::STATUS_ALLOCATED)
                ->orderBy('id')->lockForUpdate()->first();
            if (!$seat) {
                throw ValidationException::withMessages(['seat' => 'Tidak ada alokasi kursi aktif untuk siswa ini.']);
            }

            // 8. The one-way transition. status_siswa/continued_at are guarded
            //    (forceFill only) and the model enforces the terminal
            //    NULL -> TERDAFTAR edge plus set-once continued_at.
            $user->forceFill([
                'status_siswa' => User::SISWA_TERDAFTAR,
                'continued_at' => now(),
            ])->save();

            // Deliberately untouched: the invoice, the payment (SUCCESS stays),
            // the agreement acceptance, the seat allocation, ppdb_capacities
            // and every PPDB-domain record. No seat is consumed or created.

            return $this->payload($user, true);
        });
    }

    private function payload(User $user, bool $changed): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'status_siswa' => $user->status_siswa,
            'continued_at' => $user->continued_at?->toIso8601String(),
            'changed' => $changed,
        ])->header('Cache-Control', 'private, no-store');
    }
}
