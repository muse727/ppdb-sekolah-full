<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KomponenObservasi extends Model
{
    use HasFactory;

    // Ganti guarded jadi fillable
    protected $fillable = [
    'kategori_id', 'kode', 'deskripsi', 'petunjuk', 'lampiran', 'saran_jika_kurang', 'keterampilan'
];

    public function kategori()
    {
        return $this->belongsTo(KategoriObservasi::class, 'kategori_id');
    }
}