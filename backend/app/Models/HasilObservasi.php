<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HasilObservasi extends Model
{
    use HasFactory;

    // Ganti guarded jadi fillable
    protected $fillable = [
        'user_id',
        'penilai_id',
        'jawaban_json',
        'catatan_tambahan',
        'rekomendasi_akhir'
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