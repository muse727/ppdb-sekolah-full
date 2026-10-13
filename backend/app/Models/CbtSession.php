<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CbtSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'nama_ujian',
        'tanggal_ujian',
        'jam_mulai',
        'jam_selesai',
        'ruangan',
        'token',
        'is_active',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'tanggal_ujian' => 'date',
    ];

    public function questions()
    {
        return $this->hasMany(CbtQuestion::class, 'cbt_session_id');
    }

    public function results()
    {
        return $this->hasMany(CbtResult::class, 'cbt_session_id');
    }
}
