<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HasilObservasi extends Model
{
    use HasFactory;

    // Ganti guarded jadi fillable
    // E.12G Phase 3: 'version' — explicit optimistic-concurrency counter.
    // Without it in $fillable the create()/update() mass-assignment silently
    // DROPS the version write (the exact guarded/fillable hazard E.12C fixed
    // for jadwal columns) and the DB default (1) would mask the bug.
    protected $fillable = [
        'user_id',
        'penilai_id',
        'jawaban_json',
        'catatan_tambahan',
        'rekomendasi_akhir',
        'version'
    ];
    
    protected $casts = [
        'jawaban_json' => 'array', 
    ];

    public function pendaftar()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function penilai()
    {
        return $this->belongsTo(User::class, 'penilai_id');
    }
}