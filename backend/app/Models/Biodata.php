<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Biodata extends Model
{
    use HasFactory;

    public const JALUR_PENDAFTARAN = [
        'PUTRA_PUTRI_GURU', 'YAYASAN', 'INTERNAL_TK', 'UMUM',
    ];

    // Eksplisit fillable — user_id tidak boleh bisa di-override dari request
    protected $fillable = [
        'user_id',
        'jalur_pendaftaran',
        'asal_sekolah',
        'nama_lengkap',
        'nama_panggilan',
        'jenis_kelamin',
        'nisn',
        'nik_siswa',
        'no_kk',
        'tempat_lahir',
        'tanggal_lahir',
        'anak_ke',
        'status_peserta_didik',
        'nama_ibu',
        'nik_ibu',
        'pendidikan_ibu',
        'pekerjaan_ibu',
        'penghasilan_ibu',
        'nama_ayah',
        'nik_ayah',
        'pendidikan_ayah',
        'pekerjaan_ayah',
        'penghasilan_ayah',
        'nama_wali',
        'alamat_lengkap',
        'kota',
        'kecamatan',
        'kelurahan',
        'rt',
        'rw',
        'no_hp_ortu',
        'email_siswa',
        'no_hp_wali',
    ];
    
    public function user() {
        return $this->belongsTo(User::class);
    }
}