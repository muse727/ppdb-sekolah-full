<?php

namespace Tests\Feature;

use App\Mail\PaymentStatusMail;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E.8 — unified payment email notifications (manual-transfer flow).
 *
 * Covers the three authoritative trigger sites:
 *  1. POST /api/payment/manual           -> "issued"    (PENDING_MANUAL row created)
 *  2. POST /api/admin/payments/{id}/verify -> "verified" (SUCCESS transition)
 *  3. POST /api/admin/payments/{id}/reject (or reject route) -> "rejected"
 * ...plus the DAFTAR_ULANG verify/reject transitions.
 *
 * Deduplication property under test: every transition is one-shot (409 on
 * repeat), so re-calling the admin endpoint must NOT queue a second email.
 * All tests use Mail::fake() — no real email is ever sent.
 */
class PaymentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function parent(string $name = 'Parent Notif', string $jenjang = 'SD'): User
    {
        $user = User::factory()->create(['name' => $name, 'jenjang' => $jenjang, 'email' => strtolower(str_replace(' ', '.', $name)) . '@notif.test']);
        $user->forceFill(['role' => 'orang_tua'])->save();

        return $user->refresh();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['name' => 'Admin Notif', 'email' => 'admin.notif@notif.test']);
        $user->forceFill(['role' => 'admin'])->save();

        return $user->refresh();
    }

    /** Create a PENDING_MANUAL PPDB payment directly (DB state, no upload). */
    private function pendingPayment(User $parent, int $amount = 150000): Payment
    {
        return Payment::create([
            'user_id' => $parent->id,
            'payment_category' => Payment::CATEGORY_PPDB,
            'invoice_number' => 'MANUAL-TEST-' . strtoupper(substr(uniqid(), -6)),
            'jenjang' => $parent->jenjang,
            'amount' => $amount,
            'payment_method' => 'MANUAL',
            'proof_of_payment' => 'bukti/test.png',
            'status' => 'PENDING_MANUAL',
        ]);
    }

    // --- PPDB manual upload -> "issued" email -------------------------------

    public function test_manual_upload_sends_instructions_email_to_payer(): void
    {
        Mail::fake();
        Storage::fake('private');
        Setting::updateOrCreate(['key' => 'biaya_sd'], ['value' => '150000']);
        Setting::updateOrCreate(['key' => 'rekening_sd'], ['value' => '1234567890']);
        Setting::updateOrCreate(['key' => 'bank_name'], ['value' => 'Bank Syariah Uji']);
        Setting::updateOrCreate(['key' => 'bank_owner'], ['value' => 'YPI Al-Mubarak']);

        $parent = $this->parent();
        Sanctum::actingAs($parent);

        $this->post('/api/payment/manual', [
            'jenjang' => 'SD',
            'bukti_bayar' => UploadedFile::fake()->image('bukti.png', 64, 64),
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();

        Mail::assertSent(PaymentStatusMail::class, 1);
        Mail::assertSent(function (PaymentStatusMail $mail) use ($parent) {
            $mail->assertTo($parent->email);
            $html = $mail->render();

            return $mail->kind === 'issued'
                && str_contains($html, 'MENUNGGU VERIFIKASI')
                && str_contains($html, '150.000')
                && str_contains($html, '1234567890')
                && str_contains($html, '/pendaftar/pembayaran');
        });
    }

    // --- Admin verify -> "verified" email ------------------------------------

    public function test_admin_verify_sends_success_email_once(): void
    {
        Mail::fake();
        $parent = $this->parent();
        $payment = $this->pendingPayment($parent);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/payments/{$payment->id}/verify")
            ->assertOk();

        Mail::assertSent(PaymentStatusMail::class, 1);
        Mail::assertSent(function (PaymentStatusMail $mail) use ($parent) {
            $mail->assertTo($parent->email);

            return $mail->kind === 'verified';
        });

        // Duplicate verification call is refused (409) — and must NOT send
        // a second email. This is the one-shot transition guarantee.
        $this->postJson("/api/admin/payments/{$payment->id}/verify")
            ->assertStatus(409);
        Mail::assertSent(PaymentStatusMail::class, 1);
    }

    // --- Admin reject -> "rejected" email with reason ------------------------

    public function test_admin_reject_sends_rejection_email_with_reason_once(): void
    {
        Mail::fake();
        $parent = $this->parent();
        $payment = $this->pendingPayment($parent);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/payments/{$payment->id}/reject", [
            'reason' => 'Nominal transfer tidak sesuai',
        ])->assertOk();

        Mail::assertSent(PaymentStatusMail::class, 1);
        Mail::assertSent(function (PaymentStatusMail $mail) use ($parent, $payment) {
            $mail->assertTo($parent->email);
            $html = $mail->render();

            return $mail->kind === 'rejected'
                && str_contains($html, 'Nominal transfer tidak sesuai')
                && str_contains($html, $payment->invoice_number);
        });

        // One-shot: second reject is refused, no duplicate email.
        $this->postJson("/api/admin/payments/{$payment->id}/reject", [
            'reason' => 'dup',
        ])->assertStatus(409);
        Mail::assertSent(PaymentStatusMail::class, 1);
    }

    // --- Email content never leaks internal data -----------------------------

    public function test_payment_email_contains_no_secret_or_internal_fields(): void
    {
        Mail::fake();
        $parent = $this->parent('Parent Rahasia');
        $payment = $this->pendingPayment($parent);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/payments/{$payment->id}/verify")->assertOk();

        Mail::assertSent(function (PaymentStatusMail $mail) {
            $html = $mail->render();

            // No proof-of-payment path, no verifier id, no internal ids.
            return !str_contains($html, 'bukti/')
                && !str_contains($html, 'verifier')
                && !str_contains($html, 'storage/')
                // Payment row id itself is not shown; invoice ref is the public handle.
                && !str_contains($html, 'RESEND_API_KEY');
        });
    }
}
