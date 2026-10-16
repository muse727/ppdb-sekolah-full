<?php

namespace App\Services;

use App\Models\DaftarUlangIndividualPricing;
use App\Models\DaftarUlangInvoice;
use App\Models\DaftarUlangPricingRevision;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DaftarUlangPricingService
{
    public function __construct(private DaftarUlangMoney $money) {}

    private function authorize(int $actorId): void
    {
        $role = User::whereKey($actorId)->value('role');
        abort_unless(in_array($role, ['admin', 'super_admin'], true), 403);
    }

    public function updateDefault(int $actorId, string $jenjang, array $items, mixed $discount): DaftarUlangPricingRevision
    {
        return DB::transaction(function () use ($actorId, $jenjang, $items, $discount) {
            $this->authorize($actorId);
            abort_unless(in_array($jenjang, ['SD', 'SMP'], true), 422);
            $head = DB::table('du_default_pricings')->where('jenjang', $jenjang)->lockForUpdate()->first();
            $before = $head->pricing_revision_id;
            // Locking read = current read. A plain snapshot read under REPEATABLE
            // READ could miss a revision committed by a concurrent worker after
            // this transaction's read view opened.
            $previous = $before ? DaftarUlangPricingRevision::whereKey($before)->lockForUpdate()->firstOrFail() : null;
            $revision = DaftarUlangPricingRevision::create([
                'pricing_key' => $jenjang, 'revision' => ($previous?->revision ?? 0) + 1,
                'source' => 'DEFAULT', 'jenjang' => $jenjang, 'user_id' => null,
                ...$this->money->calculate($items, $discount), 'created_by' => $actorId, 'updated_by' => $actorId,
            ]);
            DB::table('du_default_pricings')->where('jenjang', $jenjang)->update([
                'pricing_revision_id' => $revision->id, 'updated_at' => now(),
            ]);
            $this->audit($actorId, 'DEFAULT_REVISION', null, $jenjang, ['before_revision_id' => $before, 'after_revision_id' => $revision->id]);
            return $revision;
        });
    }

    public function updateIndividual(int $actorId, int $userId, array $data): DaftarUlangIndividualPricing
    {
        return DB::transaction(function () use ($actorId, $userId, $data) {
            $this->authorize($actorId);
            if (array_diff(array_keys($data), ['mode', 'active', 'items', 'discount_nominal']) || !$data) {
                throw ValidationException::withMessages(['pricing' => 'Field atau perubahan tidak valid.']);
            }
            $user = User::whereKey($userId)->where('role', 'orang_tua')->lockForUpdate()->firstOrFail();
            abort_unless(in_array($user->jenjang, ['SD', 'SMP'], true), 422);
            $head = DaftarUlangIndividualPricing::where('user_id', $userId)->lockForUpdate()->first();
            $before = $head?->getAttributes();
            $mode = $data['mode'] ?? $head?->mode ?? 'DEFAULT';
            $active = $data['active'] ?? $head?->active ?? ($mode === 'CUSTOM');
            if (!in_array($mode, ['DEFAULT', 'CUSTOM'], true) || !is_bool($active)) {
                throw ValidationException::withMessages(['pricing' => 'Mode atau active tidak valid.']);
            }
            $revisionId = $head?->pricing_revision_id;
            if (array_key_exists('items', $data) || array_key_exists('discount_nominal', $data)) {
                if ($mode !== 'CUSTOM' || !isset($data['items']) || !array_key_exists('discount_nominal', $data)) {
                    throw ValidationException::withMessages(['items' => 'CUSTOM memerlukan items dan diskon lengkap.']);
                }
                // Locking read = current read, matching the head lock above. A
                // plain snapshot read could miss a revision committed by a
                // concurrent worker after this transaction's read view opened.
                // Revisions are immutable, so locking them is always safe.
                $previous = $revisionId ? DaftarUlangPricingRevision::whereKey($revisionId)->lockForUpdate()->firstOrFail() : null;
                $revision = DaftarUlangPricingRevision::create([
                    'pricing_key' => 'USER-'.$userId, 'revision' => ($previous?->revision ?? 0) + 1,
                    'source' => 'CUSTOM', 'jenjang' => $user->jenjang, 'user_id' => $userId,
                    ...$this->money->calculate($data['items'], $data['discount_nominal']),
                    'created_by' => $actorId, 'updated_by' => $actorId,
                ]);
                $revisionId = $revision->id;
            }
            if ($mode === 'CUSTOM' && !$revisionId) {
                throw ValidationException::withMessages(['items' => 'Harga CUSTOM belum dikonfigurasi.']);
            }
            $head ??= new DaftarUlangIndividualPricing(['user_id' => $userId, 'created_by' => $actorId]);
            $head->fill(['mode' => $mode, 'active' => $active, 'pricing_revision_id' => $revisionId, 'updated_by' => $actorId])->save();
            $this->audit($actorId, 'INDIVIDUAL_PRICING', $userId, $user->jenjang, ['before' => $before, 'after' => $head->getAttributes()]);

            // Sync existing invoice & pending payment if present
            $invoice = DaftarUlangInvoice::where('user_id', $userId)->first();
            if ($invoice) {
                $payment = Payment::daftarUlang()->whereKey($invoice->payment_id)->first();
                if ($payment && $payment->status === 'SUCCESS') {
                    throw ValidationException::withMessages(['pricing' => 'Siswa sudah menyelesaikan pembayaran daftar ulang. Nominal tidak dapat diubah lagi.']);
                }
                if ($payment && $payment->status === 'PENDING_MANUAL') {
                    throw ValidationException::withMessages(['pricing' => 'Siswa sedang menunggu verifikasi pembayaran. Tolak bukti pembayaran terlebih dahulu jika ingin merevisi nominal.']);
                }

                if (!$payment || $payment->status === 'PENDING') {
                    $snapshot = $this->resolveLocked($user);
                    if (isset($invoice->pricing_snapshot['agreement'])) {
                        $snapshot['agreement'] = $invoice->pricing_snapshot['agreement'];
                    }
                    $snapshot['snapshot_at'] = now()->toIso8601String();

                    DB::table('du_invoices')->where('id', $invoice->id)->update([
                        'pricing_revision_id' => $snapshot['pricing_revision_id'],
                        'pricing_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                        'subtotal' => $snapshot['subtotal'],
                        'discount_nominal' => $snapshot['discount_nominal'],
                        'total' => $snapshot['total'],
                        'snapshot_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($payment && $payment->status === 'PENDING') {
                        $payment->update([
                            'amount' => $snapshot['total'],
                        ]);
                    }
                }
            }

            return $head->load('revision');
        });
    }

    /** Called with the canonical user locked inside the caller's transaction. */
    public function resolveLocked(User $user): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Pricing resolution requires a transaction.');
        }
        abort_unless(in_array($user->jenjang, ['SD', 'SMP'], true), 422);
        $default = DB::table('du_default_pricings')->where('jenjang', $user->jenjang)->lockForUpdate()->first();
        if (!$default?->pricing_revision_id) {
            throw ValidationException::withMessages(['pricing' => 'Harga default belum dikonfigurasi.']);
        }
        $custom = DaftarUlangIndividualPricing::where('user_id', $user->id)->lockForUpdate()->first();
        $source = $custom && $custom->active && $custom->mode === 'CUSTOM' ? 'CUSTOM' : 'DEFAULT';
        $id = $source === 'CUSTOM' ? $custom->pricing_revision_id : $default->pricing_revision_id;
        $revision = DaftarUlangPricingRevision::whereKey($id)->lockForUpdate()->firstOrFail();
        if ($revision->source !== $source || $revision->jenjang !== $user->jenjang
            || ($source === 'CUSTOM' && $revision->user_id !== $user->id)) {
            throw ValidationException::withMessages(['pricing' => 'Revisi harga tidak cocok dengan peserta.']);
        }
        $inputs = array_map(fn ($i) => array_intersect_key($i, array_flip(['id', 'name', 'quantity', 'unit_amount'])), $revision->items);
        $computed = $this->money->calculate($inputs, $revision->discount_nominal);
        if ($computed['items'] != $revision->items || $computed['total'] !== $revision->total || $computed['subtotal'] !== $revision->subtotal) {
            throw ValidationException::withMessages(['pricing' => 'Revisi harga tidak konsisten.']);
        }
        return ['source' => $source, 'pricing_revision_id' => $revision->id, 'revision' => $revision->revision,
            'default_revision_id' => $default->pricing_revision_id, 'jenjang' => $user->jenjang, ...$computed];
    }

    private function audit(int $actorId, string $action, ?int $userId, string $jenjang, array $data): void
    {
        DB::table('du_pricing_audits')->insert(['actor_id' => $actorId, 'action' => $action, 'user_id' => $userId,
            'jenjang' => $jenjang, 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
