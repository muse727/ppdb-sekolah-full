<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * E.12G — idempotency records for observasi writes (submit / rekapan edit /
 * super_admin override).
 *
 * DESIGN (final consistency report):
 *  - key_hash = SHA-256(endpoint_class | actor_id | client_key) — namespaced
 *    per actor+endpoint so the same client key can never collide across users
 *    or operation classes.
 *  - payload_hash = SHA-256(canonical JSON payload), fixed at CLAIM time.
 *  - claim happens INSIDE the business transaction, AFTER all business locks
 *    (users kandidat -> hasil -> assignment -> idempotency). A concurrent
 *    duplicate insert hits UNIQUE(key_hash), then lockForUpdate-waits on the
 *    holder's row: after COMMIT it replays the stored response; after
 *    ROLLBACK the row is invisible to its snapshot and it re-claims.
 *  - There is deliberately NO "CLAIMED" state: the row only becomes visible
 *    to other transactions when the owning transaction commits, and its
 *    completion columns are written in that same transaction. No orphaned
 *    in-flight rows can exist.
 *  - Retention: 24h (expires_at). After expiry a retry falls back to the
 *    optimistic-concurrency path (409 on stale version) — replay is only
 *    guaranteed inside the retention window.
 */
class IdempotencyKey extends Model
{
    public const CLASS_SUBMIT   = 'observasi_submit';
    public const CLASS_OVERRIDE = 'observasi_override';

    public const RETENTION_HOURS = 24;

    protected $table = 'idempotency_keys';

    protected $fillable = [
        'key_hash',
        'endpoint_class',
        'actor_id',
        'payload_hash',
        'response_code',
        'response_body',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeUnexpired($query)
    {
        return $query->where('expires_at', '>', now());
    }
}
