<?php

namespace App\Services;

use App\Models\DaftarUlangAgreementAcceptance;
use App\Models\DaftarUlangInvoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DaftarUlangService
{
    public function __construct(private DaftarUlangPricingService $pricing) {}

    private function eligibleLocked(int $userId): User
    {
        $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
        abort_unless($user->role === 'orang_tua', 403);
        if ($user->status_kelulusan !== 'LULUS'
            || DaftarUlangInvoice::where('user_id', $userId)->whereNotNull('withdrawn_at')->exists()) {
            throw ValidationException::withMessages(['eligibility' => 'Daftar Ulang hanya untuk peserta LULUS yang tidak mengundurkan diri.']);
        }
        return $user;
    }

    public function resolve(int $authenticatedUserId): array
    {
        return DB::transaction(function () use ($authenticatedUserId) {
            $user = $this->eligibleLocked($authenticatedUserId);
            return [...$this->pricing->resolveLocked($user), 'account' => $this->account($user), 'agreement_required' => true];
        });
    }

    public function createInvoice(int $authenticatedUserId): DaftarUlangInvoice
    {
        return DB::transaction(function () use ($authenticatedUserId) {
            $user = $this->eligibleLocked($authenticatedUserId);
            $existing = DaftarUlangInvoice::where('user_id', $user->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing->load('payment');
            }
            // Do not attach an unrelated legacy Daftar Ulang payment to a new invoice.
            if (Payment::daftarUlang()->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Pembayaran Daftar Ulang sudah ada; diperlukan rekonsiliasi.']);
            }
            $snapshot = $this->pricing->resolveLocked($user);
            $agreement = config('daftar_ulang.agreement');
            if (!is_array($agreement) || !is_string($agreement['version'] ?? null)
                || trim($agreement['version']) === '' || strlen($agreement['version']) > 50
                || !is_string($agreement['text'] ?? null) || trim($agreement['text']) === '') {
                throw ValidationException::withMessages(['agreement' => 'Dokumen persetujuan resmi belum dikonfigurasi.']);
            }
            $snapshot['agreement'] = [...$agreement, 'sha256' => hash('sha256', $agreement['text'])];
            $snapshot['snapshot_at'] = now()->toIso8601String();
            $number = 'DU-'.Str::uuid();
            $payment = Payment::create(['user_id' => $user->id, 'payment_category' => Payment::CATEGORY_DAFTAR_ULANG,
                'invoice_number' => $number, 'jenjang' => $user->jenjang, 'amount' => $snapshot['total'],
                'status' => 'PENDING', 'payment_method' => 'MANUAL']);
            return DaftarUlangInvoice::create(['user_id' => $user->id, 'payment_id' => $payment->id,
                'invoice_number' => $number, 'jenjang' => $user->jenjang, 'student_name' => $user->biodata?->nama_lengkap ?? $user->name,
                'jalur_pendaftaran' => $user->biodata?->jalur_pendaftaran,
                'pricing_revision_id' => $snapshot['pricing_revision_id'], 'pricing_snapshot' => $snapshot,
                'subtotal' => $snapshot['subtotal'], 'discount_nominal' => $snapshot['discount_nominal'],
                'total' => $snapshot['total'], 'snapshot_at' => now(),
            ])->load('payment');
        });
    }

    public function activeInvoice(int $authenticatedUserId): ?DaftarUlangInvoice
    {
        return DB::transaction(function () use ($authenticatedUserId) {
            $this->eligibleLocked($authenticatedUserId);
            return DaftarUlangInvoice::where('user_id', $authenticatedUserId)
                ->latest('id')
                ->first()?->load('payment');
        });
    }

    public function invoice(int $authenticatedUserId, int $invoiceId): DaftarUlangInvoice
    {
        return DB::transaction(function () use ($authenticatedUserId, $invoiceId) {
            $this->eligibleLocked($authenticatedUserId);
            return DaftarUlangInvoice::whereKey($invoiceId)->where('user_id', $authenticatedUserId)->firstOrFail()->load('payment');
        });
    }

    public function accept(int $authenticatedUserId, int $invoiceId): DaftarUlangAgreementAcceptance
    {
        return DB::transaction(function () use ($authenticatedUserId, $invoiceId) {
            $this->eligibleLocked($authenticatedUserId);
            $invoice = DaftarUlangInvoice::whereKey($invoiceId)->where('user_id', $authenticatedUserId)->lockForUpdate()->firstOrFail();
            $agreement = $invoice->pricing_snapshot['agreement'];
            return DaftarUlangAgreementAcceptance::firstOrCreate([
                'invoice_id' => $invoice->id, 'agreement_version' => $agreement['version'],
            ], ['user_id' => $authenticatedUserId, 'agreement_sha256' => $agreement['sha256'], 'agreed_at' => now()]);
        });
    }

    private function account(User $user): array
    {
        $settings = Setting::whereIn('key', ['rekening_'.strtolower($user->jenjang), 'bank_name', 'bank_owner'])->pluck('value', 'key');
        return ['jenjang' => $user->jenjang, 'rekening' => $settings['rekening_'.strtolower($user->jenjang)] ?? null,
            'bank_name' => $settings['bank_name'] ?? null, 'bank_owner' => $settings['bank_owner'] ?? null];
    }
}
