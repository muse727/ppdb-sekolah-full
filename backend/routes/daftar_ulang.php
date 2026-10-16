<?php

use App\Http\Controllers\DaftarUlangController;
use App\Http\Middleware\CheckRole;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', CheckRole::class.':orang_tua'])->prefix('daftar-ulang')->group(function () {
    Route::get('/pricing', [DaftarUlangController::class, 'pricing']);
    Route::post('/invoices', [DaftarUlangController::class, 'createInvoice']);
    Route::get('/invoices/active', [DaftarUlangController::class, 'activeInvoice']);
    Route::get('/invoices/{id}', [DaftarUlangController::class, 'invoice'])->whereNumber('id');
    Route::post('/invoices/{id}/agreement', [DaftarUlangController::class, 'accept'])->whereNumber('id');
    // B.6A: parent uploads their own Daftar Ulang proof of payment.
    Route::post('/payments/proof', [DaftarUlangController::class, 'submitProof']);
    // B.7.1: parent withdraws (MENGUNDURKAN DIRI); identity is auth-only.
    Route::post('/withdraw', [DaftarUlangController::class, 'withdraw']);
    // B.7.2: parent continues (MELANJUTKAN); the ONLY path to status_siswa=TERDAFTAR.
    Route::post('/continue', [DaftarUlangController::class, 'continue']);

    // B.7.4-B: parent consultation (KONSULTASI KE SEKOLAH). The third decision
    // path; it mutates ONLY du_consultations and never touches status_siswa,
    // status_kelulusan, payments or seats. Identity is auth-only.
    // B.7.4-E.2 (F-3): throttled per authenticated user. Reads and writes get
    // separate named buckets (see AppServiceProvider) so neither can starve
    // the other; the middleware runs INSIDE auth:sanctum + role so an
    // unauthenticated caller never burns an authenticated bucket.
    Route::post('/consultations', [DaftarUlangController::class, 'requestConsultation'])
        ->middleware('throttle:du-consult-parent-write');
    Route::get('/consultations', [DaftarUlangController::class, 'myConsultations'])
        ->middleware('throttle:du-consult-parent-read');
    Route::post('/consultations/{id}/cancel', [DaftarUlangController::class, 'cancelMyConsultation'])
        ->whereNumber('id')
        ->middleware('throttle:du-consult-parent-write');
});
Route::middleware(['auth:sanctum', CheckRole::class.':admin'])->prefix('admin/daftar-ulang')->group(function () {
    // B.6A-C: read-only admin participant contract (grounded in du_invoices).
    Route::get('/students', [DaftarUlangController::class, 'students']);
    Route::get('/students/{id}', [DaftarUlangController::class, 'student'])->whereNumber('id');

    // B.6A: Daftar Ulang payment settlement (category always DAFTAR_ULANG server-side).
    Route::get('/payments/pending', [DaftarUlangController::class, 'pendingPayments']);
    Route::get('/payments/{payment}/evidence', [DaftarUlangController::class, 'evidence'])->whereNumber('payment');
    Route::post('/payments/{payment}/verify', [DaftarUlangController::class, 'verifyPayment'])->whereNumber('payment');
    Route::post('/payments/{payment}/reject', [DaftarUlangController::class, 'rejectPayment'])->whereNumber('payment');

    Route::get('/pricing/default/{jenjang}', [DaftarUlangController::class, 'defaultPricing']);
    Route::get('/pricing/individual/{userId}', [DaftarUlangController::class, 'individual'])->whereNumber('userId');
    Route::put('/pricing/default/{jenjang}', [DaftarUlangController::class, 'updateDefault']);
    Route::put('/pricing/individual/{userId}', [DaftarUlangController::class, 'updateIndividual'])->whereNumber('userId');

    // B.7.4-B: admin consultation desk. The list is strictly read-only; the only
    // mutations are schedule/reschedule and the two terminal resolutions. Any
    // status value comes from server-side vocabulary, never from the client.
    // B.7.4-E.2 (F-3): throttled per authenticated admin, reads and writes in
    // separate buckets; ordinary filter browsing (60/min) cannot exhaust the
    // schedule/resolve budget (30/min).
    Route::get('/consultations', [DaftarUlangController::class, 'consultations'])
        ->middleware('throttle:du-consult-admin-read');
    Route::post('/consultations/{id}/schedule', [DaftarUlangController::class, 'scheduleConsultation'])
        ->whereNumber('id')
        ->middleware('throttle:du-consult-admin-write');
    Route::post('/consultations/{id}/resolve', [DaftarUlangController::class, 'resolveConsultation'])
        ->whereNumber('id')
        ->middleware('throttle:du-consult-admin-write');
});
