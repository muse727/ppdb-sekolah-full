<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Support\DocumentValidation;
use App\Support\ImageMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * CMS banner untuk hero slider landing page.
 *
 * Publik: GET /api/banners (read-only, hanya yang visible) dan
 * GET /api/banners/{id}/image (stream gambar, hanya untuk banner visible).
 *
 * Mutasi: hanya di bawah middleware CheckRole:admin di routes/api.php.
 * Gambar divalidasi berbasis KONTEN (magic bytes + struktur container via
 * DocumentValidation), distrip EXIF lossless (ImageMetadata), disimpan di
 * disk privat dengan nama hash server — pola yang sama dengan Berkas/bukti
 * bayar, tetapi image-only (tanpa PDF) dan batas resolusi lebih longgar.
 */
class BannerController extends Controller
{
    public const MAX_KB = 25600;  // 25 MB (gambar atau video)
    public const MAX_PX = 4000;   // sisi maksimal untuk gambar

    // ── Publik (read-only) ──────────────────────────────────────────────

    /**
     * GET /api/banners — slide yang sedang tayang, urut naik.
     * Hanya metadata aman; created_by/internal path tidak diekspos.
     */
    public function publicIndex(Request $request)
    {
        $query = Banner::visible()->orderBy('sort_order')->orderBy('id');
        if ($request->filled('target')) {
            $query->whereIn('target', [$request->input('target'), 'all']);
        } else {
            // Default untuk landing page jika tanpa parameter target
            $query->whereIn('target', ['landing', 'all']);
        }
        $banners = $query->get();

        return response()->json(
            $banners->map(fn (Banner $b) => [
                'id'          => $b->id,
                'title'       => $b->title,
                'description' => $b->description,
                'media_type'  => $b->media_type ?? 'image',
                'target'      => $b->target ?? 'landing',
                'bg_color'    => $b->bg_color ?? '#1a5f51',
                'image_url'   => $b->image_path ? $b->imageUrl() : null,
                'has_image'   => $b->image_path !== null,
                'cta_text'    => $b->cta_text,
                'cta_url'     => $b->cta_url,
            ])->values()
        );
    }

    /**
     * GET /api/banners/{id}/image — stream gambar dari disk privat.
     * Tidak melayani gambar banner yang nonaktif/di luar periode tayang:
     * preview admin memakai /api/admin/banners/{id}/image.
     */
    public function showImage(Banner $banner)
    {
        abort_unless(
            Banner::visible()->whereKey($banner->id)->exists(),
            404
        );
        return $this->streamImage($banner);
    }

    // ── Admin (di dalam group CheckRole:admin) ─────────────────────────

    /** GET /api/admin/banners — semua banner termasuk nonaktif. */
    public function adminIndex(Request $request)
    {
        $query = Banner::orderBy('sort_order')->orderBy('id');
        if ($request->filled('target')) {
            $query->where('target', $request->input('target'));
        }

        return response()->json(
            $query->get()->map(fn (Banner $b) => [
                'id'          => $b->id,
                'title'       => $b->title,
                'description' => $b->description,
                'media_type'  => $b->media_type ?? 'image',
                'target'      => $b->target ?? 'pendaftar',
                'bg_color'    => $b->bg_color ?? '#1a5f51',
                'image_url'   => '/api/admin/banners/' . $b->id . '/image',
                'has_image'   => $b->image_path !== null,
                'cta_text'    => $b->cta_text,
                'cta_url'     => $b->cta_url,
                'sort_order'  => $b->sort_order,
                'is_active'   => $b->is_active,
                'starts_at'   => $b->starts_at?->toIso8601String(),
                'ends_at'     => $b->ends_at?->toIso8601String(),
                'updated_at'  => $b->updated_at?->toIso8601String(),
            ])->values()
        );
    }

    /** GET /api/admin/banners/{id}/image — preview gambar apa pun statusnya. */
    public function adminImage(Banner $banner)
    {
        return $this->streamImage($banner, publicCache: false);
    }

    /** POST /api/admin/banners — multipart (metadata + file gambar/video). */
    public function store(Request $request)
    {
        $data = $this->validated($request, imageRequired: true);

        [$path, $mediaType, $bytesOk] = $this->storeMedia($request->file('image'));
        abort_unless($bytesOk, 422, $path);

        $banner = DB::transaction(function () use ($data, $path, $mediaType, $request) {
            return Banner::create([
                ...$data,
                'media_type' => $mediaType,
                'image_path' => $path,
                'created_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'success' => true,
            'id'      => $banner->id,
            'message' => 'Banner berhasil ditambahkan.',
        ], 201);
    }

    /** POST /api/admin/banners/{id} — multipart; media opsional (ganti). */
    public function update(Request $request, Banner $banner)
    {
        $data = $this->validated($request, imageRequired: false);

        $newPath = null;
        $mediaType = null;
        if ($request->hasFile('image')) {
            [$newPath, $mediaType, $ok] = $this->storeMedia($request->file('image'));
            abort_unless($ok, 422, $newPath);
        }

        $oldPath = $banner->image_path;

        DB::transaction(function () use ($banner, $data, $newPath, $mediaType) {
            $banner->fill($data);
            if ($newPath !== null) {
                $banner->image_path = $newPath;
                $banner->media_type = $mediaType;
            }
            $banner->save();
        });

        // Setelah commit baru hapus file lama — mencegah orphan maupun kehilangan.
        if ($newPath !== null && $oldPath && $oldPath !== $newPath
            && Storage::disk('private')->exists($oldPath)) {
            Storage::disk('private')->delete($oldPath);
        }

        return response()->json(['success' => true, 'message' => 'Banner berhasil diperbarui.']);
    }

    /** PATCH /api/admin/banners/{id}/status — toggle cepat dari daftar. */
    public function toggleStatus(Request $request, Banner $banner)
    {
        $request->validate(['is_active' => 'required|boolean']);
        $banner->update(['is_active' => $request->boolean('is_active')]);
        return response()->json(['success' => true, 'is_active' => $banner->is_active]);
    }

    /** PATCH /api/admin/banners/reorder — urutan seluruh slide sekaligus. */
    public function reorder(Request $request)
    {
        $request->validate([
            'orders'   => 'required|array|min:1',
            'orders.*' => 'required|integer|exists:banners,id',
        ]);

        DB::transaction(function () use ($request) {
            foreach (array_values($request->input('orders')) as $index => $id) {
                Banner::whereKey($id)->update(['sort_order' => $index]);
            }
        });

        return response()->json(['success' => true]);
    }

    /** DELETE /api/admin/banners/{id} — hapus banner beserta file gambarnya. */
    public function destroy(Banner $banner)
    {
        $banner->deleteStoredImage();
        $banner->delete();
        return response()->json(['success' => true, 'message' => 'Banner dihapus.']);
    }

    // ── Internal ────────────────────────────────────────────────────────

    /**
     * Validasi metadata banner. CTA URL hanya boleh path relatif atau
     * http/https absolut — skema javascript:/data:/dll ditolak di sini.
     */
    private function validated(Request $request, bool $imageRequired): array
    {
        $data = $request->validate([
            'title'       => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'target'      => 'nullable|string|in:pendaftar,landing,all',
            'bg_color'    => 'nullable|string|max:30',
            'cta_text'    => 'nullable|string|max:60',
            'cta_url'     => [
                'nullable',
                'string',
                'max:255',
                'regex:/^(https?:\/\/|\/)/i',
            ],
            'sort_order'  => 'nullable|integer|min:0|max:65535',
            'is_active'   => 'nullable|boolean',
            'starts_at'   => 'nullable|date',
            'ends_at'     => 'nullable|date|after_or_equal:starts_at',
            'image'       => ($imageRequired ? 'required' : 'nullable')
                . '|file|max:' . self::MAX_KB,
        ], [
            'title.required'      => 'Judul banner wajib diisi.',
            'image.required'      => 'File banner (gambar atau video) wajib dipilih.',
            'image.max'           => 'Ukuran file melebihi batas maksimal 25 MB.',
            'cta_url.regex'       => 'URL CTA harus berupa tautan http/https atau path internal (diawali /).',
            'ends_at.after_or_equal' => 'Periode berakhir tidak boleh sebelum periode mulai.',
        ]);

        // Normalisasi
        $data['target']     = $data['target'] ?? 'landing';
        $data['bg_color']   = $data['bg_color'] ?? '#1a5f51';
        $data['cta_text']   = $data['cta_text'] ?? null;
        $data['cta_url']    = $data['cta_url'] ?? null;
        if (!$data['cta_url']) {
            $data['cta_text'] = null;
        }
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active']  = $request->boolean('is_active');
        unset($data['image']);

        return $data;
    }

    /**
     * Validasi konten + simpan media (gambar atau video). Return [path|null, media_type, ok].
     * Saat gagal, elemen pertama berisi pesan error untuk abort 422.
     */
    private function storeMedia($upload): array
    {
        $bytes = (string) $upload->get();
        $mime = $upload->getClientMimeType() ?: $upload->getMimeType();

        // 1. Cek Magic bytes untuk Video MP4 / WebM
        $isMp4 = (strlen($bytes) >= 12 && substr($bytes, 4, 4) === 'ftyp');
        $isWebm = (str_starts_with($bytes, "\x1A\x45\xDF\xA3"));

        if ($isMp4 || $isWebm) {
            $ext = $isMp4 ? 'mp4' : 'webm';
            $path = $upload->store('banners', 'private');
            return [$path, 'video', true];
        }

        // 2. Validasi untuk Gambar (JPG / PNG / WEBP)
        $error = DocumentValidation::check($bytes);
        if ($error !== null) {
            // DocumentValidation default hanya JPG/PNG/PDF. Cek apakah WebP
            if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
                $path = $upload->store('banners', 'private');
                return [$path, 'image', true];
            }
            return ['Format file harus berupa gambar (JPG, PNG, WEBP) atau video (MP4, WEBM).', false];
        }

        $imgMime = DocumentValidation::sniffMime($bytes);
        if (!in_array($imgMime, ['image/jpeg', 'image/png'], true)) {
            return ['Format file harus berupa gambar (JPG, PNG, WEBP) atau video (MP4, WEBM).', false];
        }

        // Batas resolusi: cegah gambar absurd besar memenuhi disk.
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] > self::MAX_PX || $size[1] > self::MAX_PX) {
            return ['Resolusi gambar terlalu besar (maksimal ' . self::MAX_PX . 'x' . self::MAX_PX . ' piksel).', false];
        }

        $path = ImageMetadata::storeSanitized(
            $upload,
            'banners',
            'private',
            DocumentValidation::extensionFor($imgMime)
        );

        return [$path, 'image', true];
    }

    /** Stream gambar atau video dari disk privat. */
    private function streamImage(Banner $banner, bool $publicCache = true)
    {
        $path = $banner->image_path;
        abort_unless($path && Storage::disk('private')->exists($path), 404);

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'mp4'   => 'video/mp4',
            'webm'  => 'video/webm',
            'png'   => 'image/png',
            'webp'  => 'image/webp',
            default => 'image/jpeg',
        };

        return Storage::disk('private')->response($path, null, [
            'Content-Type'           => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => 'inline',
            'Accept-Ranges'          => 'bytes',
            // Ber-hash unik per unggahan → cache publik aman.
            'Cache-Control'          => $publicCache ? 'public, max-age=86400' : 'private, no-store',
        ]);
    }
}
