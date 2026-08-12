<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KategoriObservasi extends Model
{
    use HasFactory;

    // Ganti guarded jadi fillable
    protected $fillable = [
        'nama_kategori',
        'jenjang',
        'urutan'
    ];

    public function komponen()
    {
        return $this->hasMany(KomponenObservasi::class, 'kategori_id');
    }
}