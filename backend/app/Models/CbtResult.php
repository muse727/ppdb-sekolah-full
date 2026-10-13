<?php

namespace App\Models;

use App\Models\CbtAttempt;
use App\Models\CbtSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CbtResult extends Model
{
    protected $fillable = [
        'cbt_session_id',
        'user_id',
        'cbt_attempt_id',
        'nama_siswa',
        'jawaban_json',
        'benar',
        'salah',
        'skor',
        'skor_esai',
        'is_koreksi_esai',
        'jumlah_pelanggaran',
    ];

    protected $casts = [
        'is_koreksi_esai' => 'boolean',
        'skor'            => 'float',
        'skor_esai'       => 'float',
    ];

    public function session()
    {
        return $this->belongsTo(CbtSession::class, 'cbt_session_id');
    }

    public function attempt()
    {
        return $this->belongsTo(CbtAttempt::class, 'cbt_attempt_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
