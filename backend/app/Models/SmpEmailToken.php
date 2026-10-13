<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmpEmailToken extends Model
{
    protected $fillable = [
        'token_digest',
        'email',
        'nama',
        'jenjang',
        'issued_at',
        'expires_at',
        'consumed_at',
        'verified_at',
        'used_by_user_id',
    ];

    protected $casts = [
        'issued_at'   => 'datetime',
        'expires_at'  => 'datetime',
        'consumed_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    // Never serialize the digest (a verifier, but still credential material).
    protected $hidden = ['token_digest'];

    public function user()
    {
        return $this->belongsTo(User::class, 'used_by_user_id');
    }
}
