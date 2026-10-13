<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Berkas;
use App\Support\DocumentValidation;
use App\Support\ImageMetadata;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BerkasController extends Controller
{
    public function getBerkas()
    {
        $berkas = Berkas::where('user_id', Auth::id())->first();
        return response()->json($berkas);
    }

    public function uploadBerkas(Request $request)
    {
        $user = Auth::user();

        // Unggahan berkas menulis file (maks 5MB) ke disk privat pada setiap
        // request valid; file lama tidak dihapus sehingga retry menumpuk file
        // orphan. Tanpa throttle seorang user bisa membanjiri disk. Kunci =
        // user id + IP (server-observable). Dicek SEBELUM validasi/tulis file
        // supaya 429 tidak meninggalkan artifact. Limit longgar (10/menit).
        $throttleKey = 'berkas-upload|' . $user->id . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'message' => "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.",
            ], 429);
        }
        RateLimiter::hit($throttleKey, 60);

        $request->validate([
            'jenis' => 'required|in:kartu_keluarga,akte_kelahiran',
            // E.4: pesan ramah untuk 422; struktur konten dicek setelah ini.
            'file'  => 'required|file|max:5120',
        ], [
            'file.required' => 'File wajib dipilih.',
            'file.max'      => 'Ukuran file melebihi batas maksimal 5 MB.',
            'file.file'     => 'File tidak terbaca. Silakan pilih ulang file Anda.',
        ]);

        $upload = $request->file('file');
        $bytes  = (string) $upload->get();

        // E.4: content-based validation — the sniffed magic bytes and the
        // container structure decide, never the client Content-Type or
        // extension. Rejects empty, truncated, fake-extension, polyglot-ish
        // payloads, encrypted/oversized PDFs, and over-limit resolutions.
        $error = DocumentValidation::check($bytes);
        if ($error !== null) {
            return response()->json([
                'message' => $error,
                'errors'  => ['file' => [$error]],
            ], 422);
        }

        $mime = DocumentValidation::sniffMime($bytes);

        // Store on the private disk — never the public web root. These are
        // photos of Kartu Keluarga / Akte Kelahiran taken on a phone, so the
        // same EXIF GPS / device metadata concern applies as with payment
        // evidence; strip it before persisting (lossless, fail-safe).
        // PDFs have no EXIF to strip and are stored with a server-generated
        // random name (hashName) — never the client filename.
        if ($mime === 'application/pdf') {
            $path = $upload->hashName('berkas');
            $path = preg_replace('/\.(\w+)$/', '.pdf', $path) ?? $path;
            Storage::disk('private')->put($path, $bytes);
        } else {
            $path = ImageMetadata::storeSanitized(
                $upload, 'berkas', 'private', DocumentValidation::extensionFor($mime)
            );
        }

        $berkas = Berkas::where('user_id', $user->id)->first();
        $oldPath = $berkas?->{$request->jenis};

        // E.5: pemeriksaan teknis otomatis — validasi konten di atas (format,
        // ukuran, struktur) sudah lulus, jadi berkas langsung diterima dengan
        // status LOLOS_TEKNIS. Ini BUKAN pernyataan keaslian dokumen: OCR
        // keyword di browser hanya advisory dan tidak pernah dikirim ke
        // server. Verifikasi manual panitia tidak lagi diperlukan.
        $berkas = Berkas::updateOrCreate(
            ['user_id' => $user->id],
            [
                $request->jenis => $path,
                'status'        => 'LOLOS_TEKNIS',
            ]
        );

        // E.3: hapus file lama milik user ini (jenis yang sama) setelah record
        // DB konsisten — mencegah file yatim menumpuk di private disk saat
        // ganti berkas. Hanya path yang berbeda dan benar-benar ada.
        if ($berkas && $oldPath && $oldPath !== $path
            && Storage::disk('private')->exists($oldPath)) {
            Storage::disk('private')->delete($oldPath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Berkas berhasil diunggah dan lolos pemeriksaan teknis otomatis.',
        ]);
    }

    /**
     * Stream an identity document only to its owner or an administrator.
     * Prevents unauthenticated/public access to NIK-bearing files.
     */
    public function serveBerkas(int $userId, string $jenis): StreamedResponse
    {
        abort_unless(in_array($jenis, ['kartu_keluarga', 'akte_kelahiran'], true), 404);

        $user = Auth::user();
        abort_unless(
            $user->role === 'admin'
            || $user->role === 'super_admin'
            || (int) $user->id === (int) $userId,
            403
        );

        $berkas = Berkas::where('user_id', $userId)->first();
        $path   = $berkas?->{$jenis};

        abort_unless($path && Storage::disk('private')->exists($path), 404);

        // E.4: served inline only for image/PDF types we validated at upload;
        // hashName extension comes from our own storage layer, never client
        // input. disposition() with a fixed ASCII name avoids header injection.
        return Storage::disk('private')->response($path, "dokumen-{$jenis}", [
            'Content-Type' => self::serveMime($path),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function serveMime(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf'  => 'application/pdf',
            'png'  => 'image/png',
            default => 'image/jpeg',
        };
    }
}
