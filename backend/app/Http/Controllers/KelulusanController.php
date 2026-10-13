<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Setting;
use App\Services\KelulusanLetterService;
use App\Services\KelulusanLifecycleService;

class KelulusanController extends Controller
{
    // Mengambil data pendaftar
    public function index()
    {
        $users = User::where('users.role', 'orang_tua')
            ->leftJoin('payments', function ($join) {
                // PPDB-scoped join: a DAFTAR_ULANG payment must not supply the
                // jenjang shown in this kelulusan listing.
                $join->on('users.id', '=', 'payments.user_id')
                    ->where('payments.payment_category', '=', 'PPDB');
            })
            ->select('users.id', 'users.name', 'users.email', 'users.status_kelulusan', 'payments.jenjang')
            ->orderBy('users.created_at', 'desc')
            ->get()
            ->unique('id')
            ->values();

        return response()->json($users);
    }

    public function update(Request $request, $id, KelulusanLifecycleService $lifecycle)
    {
        $validated = $request->validate(['status' => 'required|in:LULUS,TIDAK_LULUS,BELUM']);
        $lifecycle->transition([(int) $id => $validated['status']]);
        return response()->json(['success' => true, 'message' => 'Status berhasil diubah']);
    }

    public function bulkUpdate(Request $request, KelulusanLifecycleService $lifecycle)
    {
        $validated = $request->validate([
            'user_ids' => 'required_without:updates|prohibits:updates|array|min:1',
            'user_ids.*' => 'required|integer|distinct|exists:users,id',
            'status' => 'required_with:user_ids|prohibits:updates|in:LULUS,TIDAK_LULUS,BELUM',
            'updates' => 'required_without:user_ids|prohibits:user_ids,status|array|min:1',
            'updates.*.user_id' => 'required|integer|distinct|exists:users,id',
            'updates.*.status' => 'required|in:LULUS,TIDAK_LULUS,BELUM',
        ]);
        $changes = isset($validated['updates'])
            ? array_column($validated['updates'], 'status', 'user_id')
            : array_fill_keys($validated['user_ids'], $validated['status']);
        $lifecycle->transition($changes);
        return response()->json(['success' => true, 'message' => 'Status berhasil diubah']);
    }

    public function getAllJadwalRilis()
    {
        $sd  = Setting::where('key', 'waktu_rilis_sd')->first();
        $smp = Setting::where('key', 'waktu_rilis_smp')->first();

        return response()->json([
            'SD'  => $sd  ? $sd->value  : '',
            'SMP' => $smp ? $smp->value : '',
        ]);
    }

    // Simpan pengaturan waktu rilis pengumuman
    public function saveJadwalRilis(Request $request)
    {
        $request->validate([
            'jenjang'     => 'required|in:SD,SMP',
            'waktu_rilis' => 'required|date',
        ]);

        $key = 'waktu_rilis_' . strtolower($request->jenjang);

        Setting::updateOrCreate(
            ['key'   => $key],
            ['value' => $request->waktu_rilis]
        );

        return response()->json(['success' => true, 'message' => 'Jadwal countdown terkunci']);
    }

    // =====================================================================
    // P4-CB — Surat Keputusan Kelulusan (per-jenjang).
    //
    // SD and SMP each own a separate settings row, a separate logo, a
    // separate signature image and a separate Blade/PDF template:
    //
    //   GET  /api/admin/kelulusan/setting-surat/{jenjang}
    //   POST /api/admin/kelulusan/setting-surat/{jenjang}
    //   GET  /api/admin/kelulusan/setting-surat/{jenjang}/preview?status=
    //
    // There is deliberately NO cross-jenjang fallback: an unconfigured
    // jenjang returns `null` (its own empty form), never the sibling
    // school's letterhead/logo/signature.
    // =====================================================================

    /**
     * Ambil pengaturan surat untuk satu jenjang.
     */
    public function getSuratSetting(string $jenjang, KelulusanLetterService $letters)
    {
        $jenjang = strtoupper(trim($jenjang));

        if (! in_array($jenjang, KelulusanLetterService::JENJANGS, true)) {
            return response()->json(['message' => 'Jenjang tidak valid.'], 422);
        }

        $config = $letters->getConfig($jenjang);

        if ($config === null) {
            // Belum dikonfigurasi — kirim state kosong eksplisit, bukan
            // konfigurasi jenjang lain.
            return response()->json(null);
        }

        return response()->json($this->presentConfig($jenjang, $config, $letters));
    }

    /**
     * Simpan pengaturan surat untuk satu jenjang.
     * Menulis SMP tidak pernah menulis SD, dan sebaliknya (settings.key unik).
     */
    public function saveSuratSetting(Request $request, string $jenjang, KelulusanLetterService $letters)
    {
        $jenjang = strtoupper(trim($jenjang));

        if (! in_array($jenjang, KelulusanLetterService::JENJANGS, true)) {
            return response()->json(['message' => 'Jenjang tidak valid.'], 422);
        }

        $validated = $request->validate([
            'nomor_surat'   => 'nullable|string|max:100',
            'tanggal_surat' => 'nullable|string|max:50',
            'nama_kepsek'   => 'nullable|string|max:255',
            'nama_sekolah'  => 'nullable|string|max:255',
            'tagline'       => 'nullable|string|max:255',
            'alamat'        => 'nullable|string|max:255',
            'kontak'        => 'nullable|string|max:255',
            'isi_lulus'     => 'nullable|string|max:2000',
            'isi_gagal'     => 'nullable|string|max:2000',
            // Base64 logo & TTD — ~2MB image = ~2.7MB base64 = ~2.7 juta karakter
            'logoB64'       => 'nullable|string|max:3000000',
            'ttdB64'        => 'nullable|string|max:3000000',
        ]);

        $saved = $letters->saveConfig($jenjang, $validated);

        return response()->json([
            'success' => true,
            'message' => "Format Surat Keputusan {$jenjang} diamankan",
        ] + $this->presentConfig($jenjang, $saved, $letters));
    }

    /**
     * Pratinjau PDF dari konfigurasi satu jenjang (admin).
     *
     * Pratinjau memang scoped ke konfigurasi: admin sedang mengedit surat
     * jenjang tertentu, jadi template jenjang itulah yang dirender dengan
     * identitas contoh. Ini bukan surat kandidat mana pun.
     */
    public function previewSurat(Request $request, string $jenjang, KelulusanLetterService $letters)
    {
        $jenjang = strtoupper(trim($jenjang));

        if (! in_array($jenjang, KelulusanLetterService::JENJANGS, true)) {
            return response()->json(['message' => 'Jenjang tidak valid.'], 422);
        }

        $validated = $request->validate([
            'status' => 'nullable|in:LULUS,TIDAK_LULUS',
        ]);

        $pdf = $letters->renderPreview(
            $jenjang,
            strtoupper((string) ($validated['status'] ?? 'LULUS'))
        );

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            // `inline` shows the PDF; the filename is a fixed server string so
            // config text can never inject disposition headers.
            'Content-Disposition' => 'inline; filename="pratinjau-surat-' . strtolower($jenjang) . '.pdf"',
            'Cache-Control'       => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Unduh PDF surat untuk satu kandidat.
     *
     * Template dipilih dari JENJANG KANDIDAT di server (payments/users) —
     * `request->jenjang` tidak pernah menjadi dasar pemilihan template, jadi
     * klien tidak bisa memaksa kandidat SD memakai kop SMP atau sebaliknya.
     */
    public function downloadSurat(Request $request, KelulusanLetterService $letters)
    {
        $validated = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'status'  => 'nullable|in:LULUS,TIDAK_LULUS',
            // `jenjang` sengaja TIDAK divalidasi/digunakan: parameter klien
            // tidak boleh bisa mengubah template yang dipakai.
        ]);

        $user = $request->user();

        // Orang tua hanya boleh mengunduh suratnya sendiri; admin boleh
        // mengunduh untuk kandidat mana pun (pencetakan ulang).
        $targetId = in_array($user->role, ['admin', 'super_admin'], true)
            ? (int) ($validated['user_id'] ?? $user->id)
            : (int) $user->id;

        $result = $letters->renderForUser($targetId, $validated['status'] ?? null);

        return response($result['pdf'], 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
            'Cache-Control'       => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Bentuk respons admin: konten teks + penanda ada/tidaknya gambar.
     *
     * Path gambar di disk PRIVAT tidak pernah dibocorkan; admin mengunggah
     * ulang untuk mengganti gambar. Pratinjau gambar dikirim sebagai data URI.
     */
    private function presentConfig(string $jenjang, array $config, KelulusanLetterService $letters): array
    {
        return [
            'jenjang'       => $jenjang,
            'nomor_surat'   => (string) ($config['nomor_surat'] ?? ''),
            'tanggal_surat' => (string) ($config['tanggal_surat'] ?? ''),
            'nama_kepsek'   => (string) ($config['nama_kepsek'] ?? ''),
            'nama_sekolah'  => (string) ($config['nama_sekolah'] ?? ''),
            'tagline'       => (string) ($config['tagline'] ?? ''),
            'alamat'        => (string) ($config['alamat'] ?? ''),
            'kontak'        => (string) ($config['kontak'] ?? ''),
            'isi_lulus'     => (string) ($config['isi_lulus'] ?? ''),
            'isi_gagal'     => (string) ($config['isi_gagal'] ?? ''),
            'logoPreview'   => $letters->logoPreviewUri($config),
            'ttdPreview'    => $letters->ttdPreviewUri($config),
            'has_logo'      => ! empty($config['logo_path']),
            'has_ttd'       => ! empty($config['ttd_path']),
        ];
    }

    // API untuk ortu — cek status kelulusan dengan proteksi waktu rilis
    public function getJadwalRilisAndStatus($jenjang, Request $request)
    {
        // Validasi parameter $jenjang — cegah arbitrary key injection
        if (!in_array(strtoupper($jenjang), ['SD', 'SMP'])) {
            return response()->json(['message' => 'Jenjang tidak valid.'], 422);
        }

        $jenjang = strtoupper($jenjang);
        $key     = 'waktu_rilis_' . strtolower($jenjang);
        $setting = Setting::where('key', $key)->first();

        $user         = $request->user();
        $waktuRilis   = $setting ? $setting->value : null;
        $statusKelulusan = 'BELUM';

        if ($waktuRilis && time() >= strtotime($waktuRilis)) {
            // Waktu rilis sudah lewat — boleh tampilkan status asli
            $statusKelulusan = $user->status_kelulusan ?? 'BELUM';
        }

        return response()->json([
            'jenjang'        => $jenjang,
            'waktu'          => $waktuRilis,
            'secured_status' => $statusKelulusan,
        ]);
    }
}
