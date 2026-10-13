<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Token extends Model
{
    protected $fillable = [
        'code',
        'is_used',
        'user_id',
        // P3-C: explicit expiration (NULL = legacy no-expiry token).
        'expires_at',
    ];

    protected $casts = [
        'is_used'    => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * P3-C: whether this token is expired. Legacy rows (expires_at NULL)
     * predate the expiry contract and remain valid.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
