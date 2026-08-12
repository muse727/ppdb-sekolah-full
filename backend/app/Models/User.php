<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     * MENCEGAH HACKER MENYISIPKAN DATA ILEGAL (Mass Assignment Protection)
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'provider',
        'provider_id',
        'provider_token',
        'role',
        'jadwal_observasi', 
        'jam_observasi', 
        'ruang_observasi', 
        'status_kelulusan', 
        'perlengkapan',
        'jalur', 
        'jenjang' 
    ];

    /**
     * The attributes that should be hidden for serialization.
     * MENCEGAH DATA RAHASIA BOCOR KE API RESPONSE (Data Leak Protection)
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'provider_token',
    ];

    /**
     * Get the attributes that should be cast.
     * MEMASTIKAN PASSWORD SELALU DI-ENKRIPSI DENGAN AMAN
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Relasi ke tabel pembayaran
    public function payments()
    {
        return $this->hasMany(Payment::class, 'user_id', 'id');
    }

    // Relasi ke tabel biodata
    public function biodata() 
    {
        return $this->hasOne(Biodata::class);
    }

    // Relasi ke tabel berkas
    public function berkas()
    {
        return $this->hasOne(Berkas::class);
    }

    // Relasi ke tabel hasil observasi
    public function hasilObservasi()
    {
        return $this->hasOne(HasilObservasi::class);
    }
}