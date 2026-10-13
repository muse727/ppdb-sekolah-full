<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * E.12G — append-only audit trail for super_admin result overrides.
 *
 * A row is written in the SAME transaction as the hasil change and the
 * idempotency completion, so an override that failed to leave an audit
 * record fails entirely (the write is the commit gate). No update/delete
 * business operations exist for this model — it is append-only.
 */
class ObservasiOverrideLog extends Model
{
    protected $table = 'observasi_override_logs';

    protected $fillable = [
        'actor_id',
        'reason',
        'user_id',        // kandidat (hasil_observasis.user_id identity)
        'hasil_id',
        'version_before',
        'version_after',
        'idempotency_key_hash',
    ];

    protected $casts = [
        'version_before' => 'integer',
        'version_after'  => 'integer',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function kandidat()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function hasil()
    {
        return $this->belongsTo(HasilObservasi::class, 'hasil_id');
    }
}
