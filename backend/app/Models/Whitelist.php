<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Whitelist extends Model
{
    protected $fillable = ['nisn', 'nama_siswa', 'asal_sekolah', 'is_used'];
}