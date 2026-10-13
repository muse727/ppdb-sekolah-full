<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PpdbSeatAllocation extends Model
{
    protected $table = 'ppdb_seat_allocations';

    const STATUS_ALLOCATED = 'ALLOCATED';
    const STATUS_RELEASED  = 'RELEASED';

    // 'jenjang' is deliberately NOT updatable in code — it is set once at
    // creation (server-derived) and never mutated, keeping it immutable.
    protected $fillable = [
        'user_id',
        'payment_id',
        'jenjang',
        'status',
        'allocated_at',
        'released_at',
        'release_reason',
    ];

    protected $casts = [
        'allocated_at' => 'datetime',
        'released_at'  => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class)->where('payment_category', Payment::CATEGORY_PPDB);
    }
}
