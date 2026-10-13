<?php

namespace App\Services;

use App\Models\DaftarUlangInvoice;
use App\Models\DaftarUlangWithdrawal;
use App\Models\PpdbSeatAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * B.7.1 — Daftar Ulang withdrawal (MENGUNDURKAN DIRI).
 *
 * A withdrawal releases the student's EXISTING initial PPDB seat exactly once.
 * It is NOT an academic failure: users.status_kelulusan is NEVER touched, so
 * the normal LULUS -> TIDAK_LULUS transition is not invoked. Payment financial
 * history is NEVER mutated (there is no refund/reversal domain).
 *
 * Canonical identity is the authenticated users.id; ownership of the invoice,
 * payment and seat allocation is resolved server-side and never trusted from
 * the request. The committed guard for idempotency is du_invoices.withdrawn_at,
 * the same field already read by the DU eligibility gate.
 */
class DaftarUlangWithdrawalService
{
    public const REASON_MENGUNDURKAN_DIRI = 'MENGUNDURKAN_DIRI';
    public const REASONS = [self::REASON_MENGUNDURKAN_DIRI];

    public const INITIATED_PARENT = 'PARENT';
    public const INITIATED_ADMIN = 'ADMIN';

    public function withdraw(int $authenticatedUserId, ?string $note): \Illuminate\Http\JsonResponse
    {
        return DB::transaction(function () use ($authenticatedUserId, $note) {
            // 1. Lock the canonical user row first. This serializes two concurrent
            //    withdrawals for the same user and any operation that locks users
            //    then payments (lock order users -> invoice -> allocation, then
            //    payment, mirrors the settlement service).
            $user = User::whereKey($authenticatedUserId)->lockForUpdate()->firstOrFail();
            abort_unless($user->role === 'orang_tua', 403);

            // B.7.2: TERDAFTAR is terminal. An official student cannot withdraw;
            // this also decides the continue-vs-withdraw race deterministically
            // (both flows take this users lock first). status_kelulusan and
            // payment semantics below are unchanged from B.7.1.
            if ($user->status_siswa === User::SISWA_TERDAFTAR) {
                throw ValidationException::withMessages(['status_siswa' => 'Siswa sudah resmi terdaftar; pengunduran diri tidak tersedia.']);
            }

            if ($user->status_kelulusan !== 'LULUS') {
                throw ValidationException::withMessages(['eligibility' => 'Daftar Ulang hanya untuk peserta LULUS.']);
            }

            // 2. Lock the canonical DU invoice for this user (ownership scoped to
            //    the authenticated user; an id is never taken from the request).
            $invoice = DaftarUlangInvoice::where('user_id', $user->id)
                ->orderBy('id')->lockForUpdate()->first();
            abort_unless($invoice, 404, 'Faktur Daftar Ulang tidak ditemukan.');

            // 3. Idempotent replay: the committed guard is withdrawn_at. A repeat
            //    request returns the existing state without touching seat/payment.
            if ($invoice->withdrawn_at !== null) {
                $existing = DaftarUlangWithdrawal::where('invoice_id', $invoice->id)->first();
                return $this->payload($invoice, $existing, $user, false);
            }

            // 4. Lock the seat-allocation history deterministically (stable id
            //    order, mirroring KelulusanLifecycleService) and take the ACTIVE row.
            $rows = PpdbSeatAllocation::where('user_id', $user->id)
                ->orderBy('id')->lockForUpdate()->get();
            $active = $rows->where('status', PpdbSeatAllocation::STATUS_ALLOCATED);
            if ($active->count() !== 1) {
                // No active seat to release (or an impossible >1). Fail safely with
                // zero mutation rather than releasing the wrong row.
                throw ValidationException::withMessages(['withdrawal' => 'Tidak ada kursi aktif untuk dilepas.']);
            }
            $seat = $active->first();

            // 5. Commit the withdrawal: mark the invoice, write the immutable audit
            //    record, then release exactly one allocation with reason WITHDRAWN.
            $invoice->forceFill(['withdrawn_at' => now()])->save();
            // B.7.2: the same committed instant also flips the canonical
            // official-student state NULL -> MENGUNDURKAN_DIRI (terminal; the
            // model guard rejects any later transition away from it).
            $user->forceFill(['status_siswa' => User::SISWA_MENGUNDURKAN_DIRI])->save();
            $withdrawal = DaftarUlangWithdrawal::create([
                'user_id' => $user->id,
                'invoice_id' => $invoice->id,
                'reason' => self::REASON_MENGUNDURKAN_DIRI,
                'note' => $note,
                'initiated_by' => self::INITIATED_PARENT,
            ]);
            $seat->forceFill([
                'status' => PpdbSeatAllocation::STATUS_RELEASED,
                'released_at' => now(),
                'release_reason' => PpdbCapacityService::RELEASE_WITHDRAWN,
            ])->save();

            // Payment is deliberately untouched: SUCCESS stays SUCCESS; no refund,
            // reversal, deletion or recreation of financial history occurs here.

            return $this->payload($invoice->refresh(), $withdrawal, $user, true);
        });
    }

    private function payload(DaftarUlangInvoice $invoice, ?DaftarUlangWithdrawal $withdrawal, User $user, bool $changed): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'withdrawn' => true,
            'changed' => $changed,
            'withdrawn_at' => $invoice->withdrawn_at?->toIso8601String(),
            'status' => 'MENGUNDURKAN_DIRI',
            'withdrawal' => $withdrawal ? [
                'reason' => $withdrawal->reason,
                'note' => $withdrawal->note,
                'initiated_by' => $withdrawal->initiated_by,
            ] : null,
        ])->header('Cache-Control', 'private, no-store');
    }
}
