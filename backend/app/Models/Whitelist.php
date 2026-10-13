<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Whitelist extends Model
{
    protected $fillable = [
        'nama_siswa',
        'asal_sekolah',
        'jenjang',
        'registration_number',
        'is_used',
        'user_id',
    ];

    protected $casts = [
        'is_used' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
