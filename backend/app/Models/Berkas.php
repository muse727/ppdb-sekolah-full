<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Berkas extends Model
{
    use HasFactory;

    // Ngasih tau Laravel kalau kolom ini boleh diisi otomatis
    protected $fillable = [
        'user_id',
        'kartu_keluarga',
        'akte_kelahiran',
        'status'
    ];

    // Relasi balik ke tabel User
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}