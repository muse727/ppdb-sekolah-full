<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CbtQuestion extends Model
{
    use HasFactory;
    
    // INI KUNCINYA BRO: 'gambar' harus masuk sini biar ga dibuang Laravel
    protected $fillable = [
        'cbt_session_id', 
        'jenis_soal', 
        'pertanyaan', 
        'gambar', 
        'opsi_a', 
        'opsi_b', 
        'opsi_c', 
        'opsi_d', 
        'kunci_jawaban'
    ];
}