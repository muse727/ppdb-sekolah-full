<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Import Controllers
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\BiodataController;
use App\Http\Controllers\BerkasController;
use App\Http\Controllers\CbtController; 
use App\Http\Controllers\JadwalObservasiController;

// Import Controller Guru
use App\Http\Controllers\InstrumenController; 
use App\Http\Controllers\ObservasiController;
use App\Http\Middleware\CheckRole;


Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/auth/{provider}/redirect', [AuthController::class, 'redirectToProvider']);
Route::get('/auth/{provider}/callback', [AuthController::class, 'handleProviderCallback']);
Route::post('/doku/webhook', [PaymentController::class, 'webhook']);
Route::post('/cbt/login', [CbtController::class, 'loginCbt']);
Route::post('/cbt/submit', [CbtController::class, 'submitCbt']);



Route::middleware('auth:sanctum')->group(function () {
    
    // 1. UMUM
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::get('/prices', [PaymentController::class, 'getPrices']); 

    Route::get('/payment/status', [PaymentController::class, 'checkStatus']);
    Route::post('/checkout', [PaymentController::class, 'createInvoice']);
    Route::post('/payment/manual', [PaymentController::class, 'manualPayment']);
    Route::get('/biodata', [BiodataController::class, 'show']);
    Route::post('/biodata', [BiodataController::class, 'store']);
    Route::get('/berkas', [BerkasController::class, 'getBerkas']);
    Route::post('/berkas/upload', [BerkasController::class, 'uploadBerkas']);
   
    Route::get('/pengumuman/waktu-rilis/{jenjang}', [\App\Http\Controllers\KelulusanController::class, 'getJadwalRilisAndStatus']);

   
    Route::get('/settings/biaya', [SettingController::class, 'getBiaya']);


   
   Route::middleware([CheckRole::class.':admin'])->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);

        // POST BIAYA TETAP DISINI KARENA CUMA ADMIN YANG BOLEH UBAH DATA
        Route::post('/settings/biaya', [SettingController::class, 'updateBiaya']);

        Route::get('/admin/payments/manual', [PaymentController::class, 'getPendingManual']);
        Route::post('/admin/payments/{id}/verify', [PaymentController::class, 'verifyManual']);
        Route::get('/admin/pendaftar', [AdminController::class, 'getAllPendaftar']);
        
        //  JADWAL OBSERVASI 
        Route::get('/admin/jadwal-observasi', [JadwalObservasiController::class, 'index']);
        Route::post('/admin/jadwal-observasi/bulk', [JadwalObservasiController::class, 'bulkUpdate']); 
        Route::post('/admin/jadwal-observasi/{id}', [JadwalObservasiController::class, 'update']);      
        
        //  MANAJEMEN KELULUSAN 
        Route::get('/admin/kelulusan', [\App\Http\Controllers\KelulusanController::class, 'index']);
        Route::get('/admin/kelulusan/setting-jadwal', [\App\Http\Controllers\KelulusanController::class, 'getAllJadwalRilis']);
        Route::post('/admin/kelulusan/setting-jadwal', [\App\Http\Controllers\KelulusanController::class, 'saveJadwalRilis']);
        Route::post('/admin/kelulusan/bulk', [\App\Http\Controllers\KelulusanController::class, 'bulkUpdate']);
        Route::post('/admin/kelulusan/{id}', [\App\Http\Controllers\KelulusanController::class, 'update']);

        //  PENGATURAN SISTEM & JALUR INTERNAL 
        Route::get('/admin/system-config', [\App\Http\Controllers\SystemConfigController::class, 'getSettings']);
        Route::post('/admin/system-config', [\App\Http\Controllers\SystemConfigController::class, 'saveSettings']);
        Route::get('/admin/whitelists', [\App\Http\Controllers\SystemConfigController::class, 'getWhitelists']);
        Route::post('/admin/whitelists', [\App\Http\Controllers\SystemConfigController::class, 'storeWhitelist']);
        Route::post('/admin/whitelists/import', [\App\Http\Controllers\SystemConfigController::class, 'importWhitelist']);
        Route::delete('/admin/whitelists/{id}', [\App\Http\Controllers\SystemConfigController::class, 'deleteWhitelist']);

        Route::get('/admin/tokens', [\App\Http\Controllers\SystemConfigController::class, 'getTokens']);
        Route::post('/admin/tokens/generate', [\App\Http\Controllers\SystemConfigController::class, 'generateTokens']);
        Route::delete('/admin/tokens/{id}', [\App\Http\Controllers\SystemConfigController::class, 'deleteToken']);
    });

  
    Route::middleware([CheckRole::class.':guru'])->group(function () {
        // Master Instrumen
        Route::get('/guru/instrumen/kategori', [InstrumenController::class, 'getKategori']);
        Route::post('/guru/instrumen/kategori', [InstrumenController::class, 'storeKategori']);
        Route::post('/guru/instrumen/komponen', [InstrumenController::class, 'storeKomponen']);
        Route::delete('/guru/instrumen/kategori/{id}', [InstrumenController::class, 'destroyKategori']);
        Route::delete('/guru/instrumen/komponen/{id}', [InstrumenController::class, 'destroyKomponen']);
        
        // Tes Observasi
        Route::get('/guru/antrean-observasi', [ObservasiController::class, 'getAntrean']);
        Route::post('/guru/observasi/submit', [ObservasiController::class, 'submitHasil']);
        Route::get('/guru/rekapan-observasi', [ObservasiController::class, 'getHasil']);
        
        // MANAJEMEN CBT (SMP)
        Route::get('/guru/cbt-sessions', [CbtController::class, 'getSessions']);
        Route::post('/guru/cbt-sessions', [CbtController::class, 'storeSession']);
        Route::delete('/guru/cbt-sessions/{id}', [CbtController::class, 'destroySession']);

        Route::get('/guru/cbt-sessions/{id}/questions', [CbtController::class, 'getQuestions']);
        Route::post('/guru/cbt-sessions/{id}/questions', [CbtController::class, 'storeQuestion']);
        Route::delete('/guru/cbt-questions/{id}', [CbtController::class, 'destroyQuestion']);
        Route::get('/guru/cbt-results', [CbtController::class, 'getResults']);
        Route::post('/guru/cbt-results/{id}/esai', [CbtController::class, 'updateSkorEsai']);
    });

});