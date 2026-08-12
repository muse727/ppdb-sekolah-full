<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CbtResult extends Model
{
    protected $fillable = ['cbt_session_id', 'nama_siswa', 'jawaban_json', 'benar', 'salah', 'skor', 'skor_esai', 'is_koreksi_esai', 'jumlah_pelanggaran'];
}
