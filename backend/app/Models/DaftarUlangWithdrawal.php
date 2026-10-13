<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable Daftar Ulang withdrawal audit record (B.7.1).
 *
 * Exactly one record exists per canonical DU invoice (enforced by the
 * du_withdrawals_invoice_unique constraint). It is written once by
 * DaftarUlangWithdrawalService and never updated or deleted afterwards.
 */
class DaftarUlangWithdrawal extends Model
{
    protected $table = 'du_withdrawals';

    protected $fillable = ['user_id', 'invoice_id', 'reason', 'note', 'initiated_by'];

    public $timestamps = true;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function invoice()
    {
        return $this->belongsTo(DaftarUlangInvoice::class, 'invoice_id');
    }
}
