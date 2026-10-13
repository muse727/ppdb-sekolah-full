<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CbtAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'cbt_session_id',
        'user_id',
        'attempt_token',
        'started_at',
        'expires_at',
        'submitted_at',
        'status',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'expires_at'   => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(CbtSession::class, 'cbt_session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function result()
    {
        return $this->hasOne(CbtResult::class, 'cbt_attempt_id');
    }
}
