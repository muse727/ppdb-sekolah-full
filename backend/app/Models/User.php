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

    // Kolom yang boleh diisi via mass assignment.
    // PENTING: 'role' dan 'status_kelulusan' TIDAK boleh ada di sini.
    protected $fillable = [
        'name',
        'email',
        'password',
        'provider',
        'provider_id',
        'provider_token',
        'jalur',
        'jenjang',
    ];

    // Kolom privileged — hanya boleh diubah lewat forceFill() atau update() eksplisit di controller admin.
    protected $guarded = [
        'role',
        'status_kelulusan',
        'status_siswa',
        'continued_at',
        'jadwal_observasi',
        'jam_observasi',
        'ruang_observasi',
        'perlengkapan',
    ];

    // ── B.7.2: canonical official-student lifecycle ─────────
    public const SISWA_TERDAFTAR = 'TERDAFTAR';
    public const SISWA_MENGUNDURKAN_DIRI = 'MENGUNDURKAN_DIRI';
    public const SISWA_STATES = [self::SISWA_TERDAFTAR, self::SISWA_MENGUNDURKAN_DIRI];

    // Sembunyikan field sensitif dari API response
    protected $hidden = [
        'password',
        'remember_token',
        'provider_token',
        'provider_id',
        'provider',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'continued_at'      => 'datetime',
        ];
    }

    /**
     * B.7.2 — status_siswa is a one-way, terminal lifecycle:
     *   NULL -> TERDAFTAR          (only DaftarUlangCompletionService)
     *   NULL -> MENGUNDURKAN_DIRI  (only the withdrawal flow)
     * Every other transition (including clearing back to NULL) is rejected at
     * the model layer. The guard is deliberately narrow: it only intercepts
     * updates that dirty status_siswa/continued_at and leaves all unrelated
     * user updates untouched.
     */
    protected static function booted(): void
    {
        static::updating(function (User $user) {
            if ($user->isDirty('status_siswa')) {
                throw_unless($user->getOriginal('status_siswa') === null
                    && in_array($user->status_siswa, self::SISWA_STATES, true),
                    new \LogicException('status_siswa transitions only NULL -> TERDAFTAR | MENGUNDURKAN_DIRI.'));
            }
            if ($user->isDirty('continued_at')) {
                throw_unless($user->getOriginal('continued_at') === null && $user->continued_at !== null,
                    new \LogicException('continued_at is set exactly once and never overwritten.'));
            }
        });
    }

    // ── Relasi ──────────────────────────────────────────────

    public function payments()
    {
        return $this->hasMany(Payment::class, 'user_id', 'id');
    }

    public function biodata()
    {
        return $this->hasOne(Biodata::class);
    }

    public function berkas()
    {
        return $this->hasOne(Berkas::class);
    }

    public function hasilObservasi()
    {
        return $this->hasOne(HasilObservasi::class);
    }

    public function token()
    {
        return $this->hasOne(Token::class);
    }
}
