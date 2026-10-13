<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Support\SuratKelulusanFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * P4-CB — Graduation-letter (Surat Keputusan Kelulusan) engine.
 *
 * Architecture
 * ------------
 *   SD candidate  → config key surat_kelulusan_config_sd
 *                 → resources/views/pdf/kelulusan/sd.blade.php
 *   SMP candidate → config key surat_kelulusan_config_smp
 *                 → resources/views/pdf/kelulusan/smp.blade.php
 *
 * There is intentionally NO cross-jenjang fallback: an unconfigured jenjang
 * fails validation loudly instead of silently borrowing the other school's
 * letterhead, logo, or signature.
 *
 * The template is selected from the CANDIDATE'S authoritative jenjang
 * (payments/users tables), never from a request parameter, so a client can
 * never coerce an SD candidate onto the SMP letterhead (or vice versa).
 */
class KelulusanLetterService
{
    public const JENJANGS = ['SD', 'SMP'];

    /** School-identity defaults. Visual structure lives in the templates;
     *  these are only the configurable content values. */
    private const IDENTITY_DEFAULTS = [
        'SD' => [
            'nama_sekolah' => 'SDIT AL MUBARAK',
            'tagline'      => 'Bertakwa - Mandiri - Kreatif - Cerdas',
            'alamat'       => 'Jl. Pramuka Sari III/24 Rawasari - Jakarta Pusat',
            'kontak'       => 'Telp/Faks. 021 - 4269246 — www.sitalmubarak.com / e-mail: almubaraksdit@gmail.com',
        ],
        'SMP' => [
            'nama_sekolah' => 'SMPIT AL MUBARAK',
            'tagline'      => 'Sholeh - Tangguh - Berdaya Guna',
            'alamat'       => 'Jl. Pramuka Sari III/24 Rawasari - Jakarta Pusat',
            'kontak'       => 'Telp/Faks. 021 - 4269246 — www.sitalmubarak.com',
        ],
    ];

    // ------------------------------------------------------------------ I/O

    public static function configKey(string $jenjang): string
    {
        return 'surat_kelulusan_config_' . strtolower(self::assertJenjang($jenjang));
    }

    /**
     * Load the stored configuration for one jenjang.
     *
     * @return array<string, string>|null  Decoded config, or null when the
     *                                     jenjang has never been configured.
     */
    public function getConfig(string $jenjang): ?array
    {
        $row = Setting::where('key', self::configKey($jenjang))->first();

        if (! $row) {
            return null;
        }

        $decoded = json_decode((string) $row->value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Persist a jenjang-scoped configuration. `settings.key` is unique, so
     * writing one jenjang can never touch the other's row.
     *
     * @param array<string, mixed> $data Validated payload.
     */
    public function saveConfig(string $jenjang, array $data): array
    {
        $jenjang = self::assertJenjang($jenjang);

        $payload = [
            'nomor_surat'   => (string) ($data['nomor_surat'] ?? ''),
            'tanggal_surat' => (string) ($data['tanggal_surat'] ?? ''),
            'nama_kepsek'   => (string) ($data['nama_kepsek'] ?? ''),
            'nama_sekolah'  => (string) ($data['nama_sekolah'] ?? ''),
            'tagline'       => (string) ($data['tagline'] ?? ''),
            'alamat'        => (string) ($data['alamat'] ?? ''),
            'kontak'        => (string) ($data['kontak'] ?? ''),
            'isi_lulus'     => (string) ($data['isi_lulus'] ?? ''),
            'isi_gagal'     => (string) ($data['isi_gagal'] ?? ''),
        ];

        // Merge over the existing row so an upload-free save never wipes the
        // stored image references, and vice versa.
        $existing = $this->getConfig($jenjang) ?? [];
        $payload['logo_path'] = (string) ($existing['logo_path'] ?? '');
        $payload['ttd_path']  = (string) ($existing['ttd_path'] ?? '');

        if ($logo = $this->storeImage($jenjang, 'logo', $data['logoB64'] ?? null)) {
            $this->deleteImage($payload['logo_path']);
            $payload['logo_path'] = $logo;
        }

        if ($ttd = $this->storeImage($jenjang, 'ttd', $data['ttdB64'] ?? null)) {
            $this->deleteImage($payload['ttd_path']);
            $payload['ttd_path'] = $ttd;
        }

        Setting::updateOrCreate(
            ['key' => self::configKey($jenjang)],
            ['value' => json_encode($payload)]
        );

        return $payload;
    }

    /**
     * Admin preview. The preview is config-scoped, not candidate-scoped: the
     * admin is editing one jenjang's letter, so THAT jenjang's configuration
     * (plus a clearly-marked sample identity) is rendered on that jenjang's
     * template.
     */
    public function renderPreview(string $jenjang, string $status): string
    {
        $jenjang = self::assertJenjang($jenjang);
        $status  = $this->assertStatus($status);
        $config  = $this->requireConfig($jenjang);

        return $this->render($jenjang, $config, [
            'nama_murid'  => 'Nama Contoh Calon Murid (Pratinjau)',
            'no_peserta'  => 'PRATINJAU-001',
            'asal_sekolah'=> 'Contoh Asal Sekolah',
        ], $status);
    }

    /**
     * Real letter for a candidate. The jenjang is resolved from the server
     * state (payment row first, then the user column) — the request can only
     * choose WHICH candidate, never which template.
     *
     * @throws ValidationException 422 when the candidate's jenjang letter is
     *                              not configured yet (no silent fallback).
     */
    public function renderForUser(int $userId, ?string $requestedStatus = null): array
    {
        $user = User::where('id', $userId)->where('role', 'orang_tua')->firstOrFail();

        $jenjang = $this->resolveCandidateJenjang($user);
        $config  = $this->requireConfig($jenjang);

        $status = $user->status_kelulusan ?? 'BELUM';

        // A letter only exists once the candidate's result is final. An admin
        // may still force a status (e.g. re-printing an acceptance letter),
        // but never for an undecided candidate without choosing explicitly.
        if ($requestedStatus !== null) {
            $status = $this->assertStatus($requestedStatus);
        }

        if (! in_array($status, ['LULUS', 'TIDAK_LULUS'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Surat hanya dapat dicetak setelah keputusan kelulusan final.'],
            ]);
        }

        $biodata = $user->biodata;

        $pdf = $this->render($jenjang, $config, [
            'nama_murid'   => $biodata->nama_lengkap ?? $user->name,
            'no_peserta'   => $biodata->nisn ?? null,
            'asal_sekolah' => $biodata->asal_sekolah ?? null,
        ], $status);

        return [
            'pdf'      => $pdf,
            'jenjang'  => $jenjang,
            'status'   => $status,
            'filename' => sprintf(
                'surat-kelulusan-%s-%s.pdf',
                strtolower($jenjang),
                Str::slug($biodata->nama_lengkap ?? $user->name) ?: 'kandidat'
            ),
        ];
    }

    // ------------------------------------------------------------- internal

    /**
     * Public read-only accessor for the admin editor: inline a jenjang's
     * stored logo as a data URI (or null when none is stored).
     */
    public function logoPreviewUri(array $config): ?string
    {
        return $this->imageDataUri($config['logo_path'] ?? '');
    }

    /**
     * Public read-only accessor for the admin editor: inline a jenjang's
     * stored signature as a data URI (or null when none is stored).
     */
    public function ttdPreviewUri(array $config): ?string
    {
        return $this->imageDataUri($config['ttd_path'] ?? '');
    }

    /**
     * Build and render the PDF binary for one jenjang.
     *
     * @param array<string, string>      $config  Decoded per-jenjang config.
     * @param array<string, string|null> $student Escaped-by-blade fields.
     */
    private function render(string $jenjang, array $config, array $student, string $status): string
    {
        $identity = self::IDENTITY_DEFAULTS[$jenjang];

        $view = $jenjang === 'SMP'
            ? 'pdf.kelulusan.smp'
            : 'pdf.kelulusan.sd';

        $namaSekolah = trim((string) ($config['nama_sekolah'] ?? '')) !== ''
            ? trim((string) $config['nama_sekolah'])
            : $identity['nama_sekolah'];

        $isiMentah = $status === 'LULUS'
            ? (string) ($config['isi_lulus'] ?? '')
            : (string) ($config['isi_gagal'] ?? '');

        return Pdf::loadView($view, [
            'jenjang'      => $jenjang,
            'status'       => $status,
            'namaSekolah'  => $namaSekolah,
            'tagline'      => $this->fallback($config['tagline'] ?? '', $identity['tagline']),
            'alamat'       => $this->fallback($config['alamat'] ?? '', $identity['alamat']),
            'kontak'       => $this->fallback($config['kontak'] ?? '', $identity['kontak']),
            'nomorSurat'   => (string) ($config['nomor_surat'] ?? ''),
            'tanggalSurat' => (string) ($config['tanggal_surat'] ?? ''),
            'namaKepsek'   => (string) ($config['nama_kepsek'] ?? ''),
            'logoDataUri'  => $this->imageDataUri($config['logo_path'] ?? ''),
            'ttdDataUri'   => $this->imageDataUri($config['ttd_path'] ?? ''),
            'isiHtml'      => SuratKelulusanFormatter::toHtml($isiMentah),
            'namaMurid'    => (string) ($student['nama_murid'] ?? ''),
            'noPeserta'    => $student['no_peserta'] ?? null,
            'asalSekolah'  => $student['asal_sekolah'] ?? null,
        ])->setPaper('A4', 'portrait')->output();
    }

    /**
     * Authoritative jenjang resolution: the verified payment row wins, the
     * user column is the fallback. The HTTP request is never consulted.
     */
    private function resolveCandidateJenjang(User $user): string
    {
        $jenjang = DB::table('payments')
            ->where('user_id', $user->id)
            ->where('payment_category', 'PPDB')
            ->whereIn('jenjang', self::JENJANGS)
            ->latest('id')
            ->value('jenjang');

        if (! $jenjang && in_array($user->jenjang, self::JENJANGS, true)) {
            $jenjang = $user->jenjang;
        }

        if (! $jenjang) {
            throw ValidationException::withMessages([
                'jenjang' => ['Jenjang kandidat tidak dapat ditentukan dari data server.'],
            ]);
        }

        return strtoupper((string) $jenjang);
    }

    private function requireConfig(string $jenjang): array
    {
        $config = $this->getConfig($jenjang);

        if ($config === null) {
            // Fail loudly. Rendering the OTHER jenjang's letterhead would be a
            // correctness bug, not a graceful degradation.
            throw ValidationException::withMessages([
                'konfigurasi' => ["Konfigurasi surat kelulusan {$jenjang} belum diisi. Lengkapi pengaturan surat {$jenjang} terlebih dahulu."],
            ]);
        }

        return $config;
    }

    /**
     * Persist an uploaded base64 image onto the PRIVATE disk under a
     * jenjang-scoped path. The payload is validated as a real image and
     * re-encoded from its decoded pixel data, which strips every metadata
     * block (EXIF/IPTC/XMP/ICC/comments) by construction.
     *
     * Returns the storage path, or null when no new image was supplied.
     */
    private function storeImage(string $jenjang, string $kind, mixed $b64): ?string
    {
        if (! is_string($b64) || trim($b64) === '') {
            return null;
        }

        if (strlen($b64) > 3_000_000) {
            throw ValidationException::withMessages([
                $kind => ['Ukuran gambar terlalu besar (maksimal ±2 MB).'],
            ]);
        }

        if (! preg_match('/^data:image\/(png|jpeg);base64,([A-Za-z0-9+\/]+={0,2})$/', $b64, $m)) {
            throw ValidationException::withMessages([
                $kind => ['Format gambar tidak valid. Gunakan PNG atau JPEG.'],
            ]);
        }

        $binary = base64_decode($m[2], true);

        if ($binary === false) {
            throw ValidationException::withMessages([$kind => ['Gambar tidak dapat didekode.']]);
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            throw ValidationException::withMessages([
                $kind => ['Berkas bukan gambar yang valid.'],
            ]);
        }

        $path = sprintf('surat_kelulusan/%s/%s_%s.png', strtolower($jenjang), $kind, Str::random(24));

        // Re-encode to PNG from decoded pixels: lossless, keeps the alpha
        // channel, and contains zero metadata chunks from the source file.
        ob_start();
        imagesavealpha($image, true);
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('local')->put($path, $png);

        return $path;
    }

    private function deleteImage(?string $path): void
    {
        if ($path && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * Inline a stored private image as a data URI for Dompdf. All configured
     * images are re-encoded to PNG on upload, so the MIME is fixed — no
     * extension sniffing, no external references in the PDF.
     */
    private function imageDataUri(mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode((string) $disk->get($path));
    }

    private function fallback(mixed $value, string $default): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
    }

    private static function assertJenjang(string $jenjang): string
    {
        $jenjang = strtoupper(trim($jenjang));

        if (! in_array($jenjang, self::JENJANGS, true)) {
            throw ValidationException::withMessages([
                'jenjang' => ['Jenjang harus SD atau SMP.'],
            ]);
        }

        return $jenjang;
    }

    private function assertStatus(string $status): string
    {
        $status = strtoupper(trim($status));

        if (! in_array($status, ['LULUS', 'TIDAK_LULUS'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Status surat harus LULUS atau TIDAK_LULUS.'],
            ]);
        }

        return $status;
    }
}
