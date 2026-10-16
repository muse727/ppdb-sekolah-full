<?php

namespace App\Models;

class DaftarUlangInvoice extends ImmutableDaftarUlangRecord
{
    protected $table = 'du_invoices';
    protected $casts = ['pricing_snapshot' => 'array', 'subtotal' => 'integer',
        'discount_nominal' => 'integer', 'total' => 'integer', 'snapshot_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    protected $appends = ['financial_status', 'is_agreement_accepted'];

    /**
     * B.7.1: the pricing snapshot, amounts and identity remain immutable, but
     * `withdrawn_at` is a lifecycle marker that may transition NULL -> timestamp
     * exactly once (set only by DaftarUlangWithdrawalService). Any other update,
     * or any attempt to change/clear an already-set withdrawn_at, is rejected.
     */
    protected static function booted(): void
    {
        static::updating(function (DaftarUlangInvoice $invoice) {
            $dirty = array_keys($invoice->getDirty());
            $onlyWithdrawal = $dirty === ['withdrawn_at'] || $dirty === ['withdrawn_at', 'updated_at'];
            $setsWithdrawal = $invoice->isDirty('withdrawn_at')
                && $invoice->getOriginal('withdrawn_at') === null
                && $invoice->withdrawn_at !== null;
            throw_unless($onlyWithdrawal && $setsWithdrawal,
                new \LogicException('Historical Daftar Ulang records are immutable.'));
        });
        static::deleting(fn () => throw new \LogicException('Historical Daftar Ulang records must be preserved.'));
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class)->daftarUlang();
    }

    public function getFinancialStatusAttribute(): string
    {
        return match ($this->payment?->status) {
            'PENDING' => 'CREATED', 'PENDING_MANUAL' => 'PENDING_PAYMENT', 'SUCCESS' => 'PAID',
            'REJECTED' => 'REJECTED', 'EXPIRED' => 'EXPIRED', 'FAILED' => 'FAILED',
            default => 'CREATED',
        };
    }

    public function getIsAgreementAcceptedAttribute(): bool
    {
        $agreement = $this->pricing_snapshot['agreement'] ?? null;
        if (!is_array($agreement)) {
            return false;
        }
        return DaftarUlangAgreementAcceptance::where('invoice_id', $this->id)
            ->where('user_id', $this->user_id)
            ->where('agreement_version', $agreement['version'] ?? '')
            ->where('agreement_sha256', $agreement['sha256'] ?? '')
            ->exists();
    }
}
