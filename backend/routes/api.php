<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\BiodataController;
use App\Http\Controllers\BerkasController;
use App\Http\Controllers\CbtController;
use App\Http\Controllers\JadwalObservasiController;
use App\Http\Controllers\KelulusanController;
use App\Http\Controllers\InstrumenController;
use App\Http\Controllers\ObservasiController;
use App\Http\Controllers\ObservasiAssignmentController;
use App\Http\Controllers\SmpEmailTokenController;
use App\Http\Controllers\SystemConfigController;
use App\Http\Controllers\PublicQuotaController;
use App\Http\Controllers\PpdbSeatAllocationController;
use App\Http\Controllers\BannerController;
use App\Http\Middleware\CheckRole;


Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

// E.10: password reset via email (Laravel Password Broker; public, throttled
// by IP in the controller and per-email by the broker; generic responses).
Route::post('/forgot-password', [PasswordResetController::class, 'request']);
Route::post('/reset-password',   [PasswordResetController::class, 'reset']);

// P3-C: SMP email-token lifecycle (public, separately throttled).
// The raw secret is only ever disclosed inside the email itself.
Route::post('/smp/email-token/request', [SmpEmailTokenController::class, 'request']);
Route::post('/smp/email-token/verify',  [SmpEmailTokenController::class, 'verify']);

// Public, backend-authoritative quota (no auth, aggregate numbers only).
Route::get('/public/kuota', [PublicQuotaController::class, 'show']);

// Public hero-banner slides (read-only). Only currently-visible banners are
// listed; the image stream likewise refuses nonaktif/expired banners.
Route::get('/banners', [BannerController::class, 'publicIndex']);
Route::get('/banners/{banner}/image', [BannerController::class, 'showImage']);

// OAuth social login
Route::get('/auth/{provider}/redirect', [AuthController::class, 'redirectToProvider']);
Route::get('/auth/{provider}/callback', [AuthController::class, 'handleProviderCallback']);

// CBT student actions require an authenticated account.
Route::middleware(['auth:sanctum', CheckRole::class . ':orang_tua'])->group(function () {
    Route::post('/cbt/login',  [CbtController::class, 'loginCbt']);
    Route::post('/cbt/submit', [CbtController::class, 'submitCbt']);
});


Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    
    Route::get('/user', function (Request $request) {
        // Deliberate allowlist: expose only the identity + lifecycle fields the
        // frontend AuthenticatedUser contract reads, never privileged/audit columns.
        // status_siswa / continued_at back the B.7.3 terminal-state restore on reload;
        // status_kelulusan / *_observasi back the existing pendaftar & guru gating.
        $user = $request->user();
        return response()->json([
            'id'                => $user->id,
            'name'              => $user->name,
            'email'             => $user->email,
            'role'              => $user->role,
            'jalur'             => $user->jalur,
            'jenjang'           => $user->jenjang,
            'status_kelulusan'  => $user->status_kelulusan,
            'status_siswa'      => $user->status_siswa,
            'continued_at'      => $user->continued_at?->toIso8601String(),
            'jadwal_observasi'  => $user->jadwal_observasi,
            'jam_observasi'     => $user->jam_observasi,
            'ruang_observasi'   => $user->ruang_observasi,
            'perlengkapan'      => $user->perlengkapan,
        ]);
    });

    Route::get('/prices',          [PaymentController::class, 'getPrices']);
    Route::get('/payment/status',  [PaymentController::class, 'checkStatus']);
    Route::post('/payment/manual', [PaymentController::class, 'manualPayment']);

    Route::get('/biodata',        [BiodataController::class, 'show']);
    Route::post('/biodata',       [BiodataController::class, 'store']);
    Route::get('/berkas',         [BerkasController::class, 'getBerkas']);
    Route::post('/berkas/upload', [BerkasController::class, 'uploadBerkas']);
    Route::get('/berkas/file/{userId}/{jenis}', [BerkasController::class, 'serveBerkas']);

    Route::get('/pengumuman/waktu-rilis/{jenjang}', [KelulusanController::class, 'getJadwalRilisAndStatus']);
    Route::get('/settings/biaya', [SettingController::class, 'getBiaya'])
        ->middleware(CheckRole::class . ':admin');
    Route::post('/settings/biaya', [SettingController::class, 'updateBiaya'])
        ->middleware([CheckRole::class . ':super_admin', 'throttle:10,1']);
    // Payment instructions are read-only and derive account selection from the authenticated user.
    Route::get('/payment/instructions', [SettingController::class, 'paymentInstructions']);

    // P4-CB: unduh Surat Keputusan Kelulusan (PDF). Jenjang/template selalu
    // ditentukan server dari data kandidat — parameter klien tidak berpengaruh.
    Route::get('/kelulusan/surat', [KelulusanController::class, 'downloadSurat']);

    // Payment evidence — owner or admin (authorization enforced in controller).
    Route::get('/payments/{id}/evidence', [PaymentController::class, 'evidence']);

    Route::middleware([CheckRole::class . ':admin'])->group(function () {

        Route::get('/users',    [UserController::class, 'index']);
        Route::post('/users',   [UserController::class, 'store']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);

        Route::get('/admin/payments/manual',        [PaymentController::class, 'getPendingManual']);
        Route::post('/admin/payments/{id}/verify',  [PaymentController::class, 'verifyManual']);
        Route::post('/admin/payments/{id}/reject',  [PaymentController::class, 'rejectManual']);
        Route::get('/admin/seat-allocations', [PpdbSeatAllocationController::class, 'index']);
        Route::post('/admin/seat-allocations/{id}/release', [PpdbSeatAllocationController::class, 'release']);

        // P5: read-only reports. Export precedes the numeric detail route.
        Route::get('/admin/data-pembayaran/{userId}/payments/{paymentId}/proof', [\App\Http\Controllers\AdminReportController::class, 'proof'])
            ->whereNumber(['userId', 'paymentId']);
        Route::get('/admin/data-{kind}/export', [\App\Http\Controllers\AdminReportController::class, 'export'])
            ->where('kind', 'kelulusan|pembayaran');
        Route::get('/admin/data-{kind}/{userId}', [\App\Http\Controllers\AdminReportController::class, 'show'])
            ->where('kind', 'kelulusan|pembayaran')->whereNumber('userId');
        Route::get('/admin/data-{kind}', [\App\Http\Controllers\AdminReportController::class, 'index'])
            ->where('kind', 'kelulusan|pembayaran');

        Route::get('/admin/pendaftar',             [AdminController::class, 'getAllPendaftar']);

        // CMS banner hero (mutasi terproteksi role admin; reorder/status pakai
        // PATCH, create/update pakai POST multipart — konsisten dengan pola
        // endpoint admin lain yang memakai POST untuk update ber-file).
        Route::get('/admin/banners',                    [BannerController::class, 'adminIndex']);
        Route::post('/admin/banners',                   [BannerController::class, 'store']);
        Route::get('/admin/banners/{banner}/image',     [BannerController::class, 'adminImage']);
        Route::post('/admin/banners/{banner}',          [BannerController::class, 'update']);
        Route::patch('/admin/banners/{banner}/status',  [BannerController::class, 'toggleStatus']);
        Route::patch('/admin/banners/reorder',          [BannerController::class, 'reorder']);
        Route::delete('/admin/banners/{banner}',        [BannerController::class, 'destroy']);

        // Jadwal Observasi
        Route::get('/admin/jadwal-observasi',          [JadwalObservasiController::class, 'index']);
        Route::post('/admin/jadwal-observasi/bulk',    [JadwalObservasiController::class, 'bulkUpdate']);
        Route::post('/admin/jadwal-observasi/{id}',    [JadwalObservasiController::class, 'update']);

        // E.12G — Penugasan Guru Observasi SD (terpisah dari jadwal: penugasan =
        // otoritas SIAPA yang boleh menilai; jadwal = logistik kapan/di mana).
        // Path {assignment} pada ganti/cabut berfungsi sekaligus sebagai
        // expected_assignment_id (proteksi stale/ABA → 409 assignment_conflict).
        Route::get('/admin/observasi-assignments',                      [ObservasiAssignmentController::class, 'index']);
        Route::get('/admin/observasi-assignments/options',              [ObservasiAssignmentController::class, 'options']);
        Route::get('/admin/observasi-assignments/history/{userId}',    [ObservasiAssignmentController::class, 'history']);
        Route::post('/admin/observasi-assignments',                     [ObservasiAssignmentController::class, 'store']);
        Route::post('/admin/observasi-assignments/{assignment}/ganti',  [ObservasiAssignmentController::class, 'ganti']);
        Route::post('/admin/observasi-assignments/{assignment}/cabut',  [ObservasiAssignmentController::class, 'cabut']);

        // Kelulusan
        Route::get('/admin/kelulusan',                      [KelulusanController::class, 'index']);
        Route::post('/admin/kelulusan/bulk',                [KelulusanController::class, 'bulkUpdate']);
        Route::get('/admin/kelulusan/setting-jadwal',       [KelulusanController::class, 'getAllJadwalRilis']);
        Route::post('/admin/kelulusan/setting-jadwal',      [KelulusanController::class, 'saveJadwalRilis']);
        // P4-CB: Surat Keputusan — konfigurasi, logo, tanda tangan, dan
        // template per-jenjang (SD dan SMP tidak pernah saling fallback).
        Route::get('/admin/kelulusan/setting-surat/{jenjang}',       [KelulusanController::class, 'getSuratSetting']);
        Route::post('/admin/kelulusan/setting-surat/{jenjang}',      [KelulusanController::class, 'saveSuratSetting']);
        Route::get('/admin/kelulusan/setting-surat/{jenjang}/preview', [KelulusanController::class, 'previewSurat']);
        Route::post('/admin/kelulusan/{id}',                [KelulusanController::class, 'update']); // harus paling bawah — {id} bersifat wildcard

        // System Config & Jalur Internal
        Route::get('/admin/system-config',           [SystemConfigController::class, 'getSettings']);
        Route::post('/admin/system-config',          [SystemConfigController::class, 'saveSettings']);
        Route::get('/admin/whitelists',              [SystemConfigController::class, 'getWhitelists']);
        Route::post('/admin/whitelists',             [SystemConfigController::class, 'storeWhitelist']);
        Route::post('/admin/whitelists/import',      [SystemConfigController::class, 'importWhitelist']);
        // READ-ONLY disclosure of the CURRENT number — backs "Salin Nomor".
        // Zero mutation: never regenerates, never touches is_used/user_id.
        Route::get('/admin/whitelists/{id}/registration-number', [SystemConfigController::class, 'showWhitelistRegistrationNumber']);
        // The ONLY replacement path — explicit, POST, invalidates the old number.
        Route::post('/admin/whitelists/{id}/regenerate-number',  [SystemConfigController::class, 'generateWhitelistCode']);
        // Legacy alias kept for backwards compatibility (same handler/semantics).
        Route::post('/admin/whitelists/{id}/code',   [SystemConfigController::class, 'generateWhitelistCode']);
        Route::delete('/admin/whitelists/{id}',      [SystemConfigController::class, 'deleteWhitelist']);
        Route::get('/admin/tokens',                  [SystemConfigController::class, 'getTokens']);
        Route::post('/admin/tokens/generate',        [SystemConfigController::class, 'generateTokens']);
        Route::delete('/admin/tokens/{id}',          [SystemConfigController::class, 'deleteToken']);

        // P3-C: SMP email-token audit view. Masked metadata only — never the
        // digest and never the raw code.
        Route::get('/admin/smp-email-tokens', [SmpEmailTokenController::class, 'adminIndex']);
    });

    
    Route::middleware([CheckRole::class . ':guru'])->group(function () {

        // Master Instrumen
        Route::get('/guru/instrumen/kategori',          [InstrumenController::class, 'getKategori']);
        Route::post('/guru/instrumen/kategori',         [InstrumenController::class, 'storeKategori']);
        Route::post('/guru/instrumen/komponen',         [InstrumenController::class, 'storeKomponen']);
        Route::delete('/guru/instrumen/kategori/{id}',  [InstrumenController::class, 'destroyKategori']);
        Route::delete('/guru/instrumen/komponen/{id}',  [InstrumenController::class, 'destroyKomponen']);

        // Tes Observasi
        Route::get('/guru/antrean-observasi',    [ObservasiController::class, 'getAntrean']);
        Route::post('/guru/observasi/submit',    [ObservasiController::class, 'submitHasil']);
        Route::get('/guru/rekapan-observasi',    [ObservasiController::class, 'getHasil']);

        // CBT
        Route::get('/guru/cbt-sessions',                  [CbtController::class, 'getSessions']);
        // P3-C: server-side CSPRNG exam-PIN generator (replaces client Math.random).
        Route::get('/guru/cbt-sessions/token',            [CbtController::class, 'generateSessionToken']);
        Route::post('/guru/cbt-sessions',                 [CbtController::class, 'storeSession']);
        Route::delete('/guru/cbt-sessions/{id}',          [CbtController::class, 'destroySession']);
        Route::get('/guru/cbt-sessions/{id}/questions',   [CbtController::class, 'getQuestions']);
        Route::post('/guru/cbt-sessions/{id}/questions',  [CbtController::class, 'storeQuestion']);
        Route::delete('/guru/cbt-questions/{id}',         [CbtController::class, 'destroyQuestion']);
        Route::get('/guru/cbt-results',                   [CbtController::class, 'getResults']);
        Route::post('/guru/cbt-results/{id}/esai',        [CbtController::class, 'updateSkorEsai']);
    });
});

require __DIR__.'/daftar_ulang.php';
