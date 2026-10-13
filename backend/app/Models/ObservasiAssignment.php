<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * E.12G — teacher assignment for SD observation.
 *
 * ONE active guru per SD candidate, enforced AT THE DATABASE:
 *   UNIQUE KEY uq_active (user_id, active_marker)
 * where active_marker is a PERSISTENT generated column:
 *   active_marker = IF(status='AKTIF', 1, NULL)
 * MariaDB (like MySQL) allows multiple NULLs in a unique index, so at most
 * ONE row per user_id can carry marker=1 (= AKTIF); history rows (DIGANTI /
 * DICABUT) are unlimited.
 *
 * HISTORY IS PERMANENT: business operations NEVER delete rows. A guru change
 * flips the old row to DIGANTI and inserts a new AKTIF row; a revocation
 * flips the row to DICABUT. Down() of the creating migration drops the table
 * (documented as destroying history — requires explicit owner approval).
 *
 * Status lifecycle (one-way, no reactivation of a closed period):
 *   AKTIF -> DIGANTI  (replaced by a newer AKTIF row)
 *   AKTIF -> DICABUT  (revoked, no replacement)
 */
class ObservasiAssignment extends Model
{
    public const STATUS_AKTIF   = 'AKTIF';
    public const STATUS_DIGANTI = 'DIGANTI';
    public const STATUS_DICABUT = 'DICABUT';
    public const STATUSES = [
        self::STATUS_AKTIF,
        self::STATUS_DIGANTI,
        self::STATUS_DICABUT,
    ];

    protected $table = 'observasi_assignments';

    protected $fillable = [
        'user_id',
        'guru_id',
        'status',
        'assigned_by',
        'revoked_reason',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    // active_marker is generated — never mass-assigned or written by the app.

    public function kandidat()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function guru()
    {
        return $this->belongsTo(User::class, 'guru_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * The single active assignment for a candidate, if any.
     * Callers inside the locking protocol use lockForUpdate() themselves.
     */
    public function scopeAktif($query, int $userId)
    {
        return $query->where('user_id', $userId)->where('status', self::STATUS_AKTIF);
    }
}
