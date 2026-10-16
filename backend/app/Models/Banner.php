<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Landing-page hero banner (CMS).
 *
 * Visibilitas publik ditentukan di sini, bukan di controller: aktif AND
 * (belum mulai OR mulai sudah lewat) AND (belum berakhir OR berakhir belum
 * lewat). Frontend hanya merender, tidak mengubah jadwal tayang.
 */
class Banner extends Model
{
    protected $fillable = [
        'title',
        'description',
        'media_type',
        'target',
        'image_path',
        'cta_text',
        'cta_url',
        'bg_color',
        'sort_order',
        'is_active',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at'   => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Banner yang saat ini layak tampil di landing page publik. */
    public function scopeVisible($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            });
    }

    /** Storage path pada disk privat; null bila banner belum punya gambar. */
    public function imagePath(): ?string
    {
        return $this->image_path;
    }

    /** Serve URL relatif; frontend mem-prefix NEXT_PUBLIC_API_URL sendiri. */
    public function imageUrl(): string
    {
        return '/api/banners/' . $this->id . '/image';
    }

    public function isVideo(): bool
    {
        return $this->media_type === 'video';
    }

    /** Hapus file gambar yang tersimpan (dipakai saat ganti/hapus banner). */
    public function deleteStoredImage(): void
    {
        if ($this->image_path && Storage::disk('private')->exists($this->image_path)) {
            Storage::disk('private')->delete($this->image_path);
        }
    }
}
