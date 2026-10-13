<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression coverage for the manual payment VERIFICATION state machine
 * (POST /api/admin/payments/{id}/verify and /reject).
 *
 * Hardens:
 *  - explicit, authorized, race-safe transitions (lockForUpdate + re-check)
 *  - SUCCESS is terminal (repeated approve => 409, no double side effects)
 *  - REJECTED is terminal for approval (REJECTED -> SUCCESS forbidden)
 *  - audit fields (verifier_id, verified_at, rejection_reason) recorded
 *  - evidence file preserved on rejection
 *  - no client-supplied status/user_id/amount accepted during verification
 */
class ManualPaymentVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Capacity is now REQUIRED for verification to succeed (a seat is consumed
        // on PENDING_MANUAL -> SUCCESS). Seed generous capacity so these pre-existing
        // state-machine tests exercise the verify transition, not the full-jenjang path.
        app(\App\Services\PpdbCapacityService::class)->increaseMaxCapacity('SD', 1000);
        app(\App\Services\PpdbCapacityService::class)->increaseMaxCapacity('SMP', 1000);
    }

    private function admin(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user->refresh();
    }

    private function parent(): User
    {
        $user = User::factory()->create(['jenjang' => 'SD']);
        $user->forceFill(['role' => 'orang_tua'])->save();

        return $user->refresh();
    }

    private function payment(User $owner, string $status = 'PENDING_MANUAL'): Payment
    {
        return Payment::create([
            'user_id'          => $owner->id,
            'invoice_number'   => 'MANUAL-TEST-' . strtoupper(uniqid()),
            'jenjang'          => 'SD',
            'amount'           => 150000,
            'payment_method'   => 'MANUAL',
            'proof_of_payment' => 'bukti_bayar/proof.png',
            'status'           => $status,
        ]);
    }

    // ===================== AUTHORIZATION (Phase 6) =====================

    public function test_unauthenticated_verifier_gets_401(): void
    {
        $payment = $this->payment($this->parent());

        $this->postJson("/api/admin/payments/{$payment->id}/verify")
            ->assertStatus(401);

        $this->assertSame('PENDING_MANUAL', $payment->fresh()->status);
    }

    public function test_unauthorized_role_gets_403(): void
    {
        $payment = $this->payment($this->parent());

        Sanctum::actingAs($this->parent(), ['*']); // orang_tua, bukan admin

        $this->postJson("/api/admin/payments/{$payment->id}/verify")
            ->assertStatus(403);
        $this->postJson("/api/admin/payments/{$payment->id}/reject")
            ->assertStatus(403);

        $this->assertSame('PENDING_MANUAL', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->verifier_id);
    }

    public function test_guru_role_cannot_verify(): void
    {
        $payment = $this->payment($this->parent());

        Sanctum::actingAs($this->admin('guru'), ['*']);

        $this->postJson("/api/admin/payments/{$payment->id}/verify")
            ->assertStatus(403);
    }

    public function test_super_admin_can_verify(): void
    {
        $payment = $this->payment($this->parent());

        Sanctum::actingAs($this->admin('super_admin'), ['*']);

        $this->postJson("/api/admin/payments/{$payment->id}/verify")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('SUCCESS', $payment->fresh()->status);
    }

    public function test_payment_owner_cannot_verify_their_own_payment(): void
    {
        $owner = $this->parent();
        $payment = $this->payment($owner);

        Sanctum::actingAs($owner, ['*']);

        // Owner IS authenticated but role-checking middleware denies (403).
        $this->postJson("/api/admin/payments/{$payment->id}/verify")
            ->assertStatus(403);

        $this->assertSame('PENDING_MANUAL', $payment->fresh()->status);
    }

    public function test_client_cannot_spoof_user_id_or_status(): void
    {
        $payment = $this->payment($this->parent());

        Sanctum::actingAs($this->admin(), ['*']);

        // Client-supplied status/user_id/amount are ignored entirely — the
        // endpoint takes no body and derives everything from the record.
        $this->postJson("/api/admin/payments/{$payment->id}/verify", [
            'status'  => 'SUCCESS',
            'user_id' => 999999,
            'amount'  => 1,
        ])->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame('SUCCESS', $fresh->status);
        $this->assertSame($payment->user_id, $fresh->user_id, 'user_id must not change');
        $this->assertSame($payment->amount, $fresh->amount, 'amount must not change');
        $this->assertSame(150000, $fresh->amount);
    }

    // ===================== TRANSITION MATRIX (Phase 7) =====================

    public function test_pending_manual_to_success_is_allowed(): void
    {
        $admin = $this->admin();
        $payment = $this->payment($this->parent());
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/admin/payments/{$payment->id}/verify");
        $response->assertOk()->assertJson([
            'success' => true,
            'message' => 'Pembayaran berhasil diverifikasi.',
        ]);

        $fresh = $payment->fresh();
        $this->assertSame('SUCCESS', $fresh->status);
        $this->assertSame($admin->id, $fresh->verifier_id, 'verifier must be recorded');
        $this->assertNotNull($fresh->verified_at, 'verified_at must be recorded');
        $this->assertNull($fresh->rejection_reason);
    }

    public function test_pending_manual_to_rejected_is_allowed(): void
    {
        $admin = $this->admin();
        $payment = $this->payment($this->parent());
        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/admin/payments/{$payment->id}/reject", [
            'reason' => 'Nominal tidak sesuai',
        ])->assertOk()->assertJson(['success' => true, 'message' => 'Pembayaran ditolak.']);

        $fresh = $payment->fresh();
        $this->assertSame('REJECTED', $fresh->status);
        $this->assertSame($admin->id, $fresh->verifier_id);
        $this->assertNotNull($fresh->verified_at);
        $this->assertSame('Nominal tidak sesuai', $fresh->rejection_reason);
    }

    public function test_reject_without_reason_is_valid(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $payment = $this->payment($this->parent());

        $this->postJson("/api/admin/payments/{$payment->id}/reject")
            ->assertOk();

        $this->assertSame('REJECTED', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->rejection_reason);
    }

    public function test_success_to_success_is_idempotent_conflict(): void
    {
        // Repeated approval must NOT create duplicate side effects, and must
        // NOT silently return 200 — it is a state conflict (409).
        $admin = $this->admin();
        $payment = $this->payment($this->parent());
        Sanctum::actingAs($admin, ['*']);

        // First approval succeeds.
        $this->postJson("/api/admin/payments/{$payment->id}/verify")->assertOk();
        $first = $payment->fresh();

        // Stale/repeated approval conflicts.
        $this->postJson("/api/admin/payments/{$payment->id}/verify")
            ->assertStatus(409);

        $second = $payment->fresh();
        $this->assertSame('SUCCESS', $second->status);
        $this->assertSame($first->verifier_id, $second->verifier_id, 'verifier must not be overwritten');
        $this->assertSame($first->verified_at->toIso8601String(), $second->verified_at->toIso8601String(), 'verified_at must not be overwritten');
        $this->assertSame(1, Payment::where('user_id', $payment->user_id)->count(), 'no duplicate payment row');
    }

    public function test_success_to_rejected_is_forbidden(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $payment = $this->payment($this->parent(), 'SUCCESS');
        $this->assertNotNull($payment->fresh());

        $this->postJson("/api/admin/payments/{$payment->id}/reject", ['reason' => 'x'])
            ->assertStatus(409);

        $this->assertSame('SUCCESS', $payment->fresh()->status, 'SUCCESS is terminal');
        $this->assertNull($payment->fresh()->rejection_reason);
    }

    public function test_rejected_to_success_is_forbidden(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $payment = $this->payment($this->parent(), 'REJECTED');

        $this->postJson("/api/admin/payments/{$payment->id}/verify")
            ->assertStatus(409);

        $this->assertSame('REJECTED', $payment->fresh()->status, 'REJECTED cannot become SUCCESS via approve');
    }

    public function test_rejected_to_rejected_is_idempotent_conflict(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin, ['*']);
        $payment = $this->payment($this->parent());
        $this->postJson("/api/admin/payments/{$payment->id}/reject", ['reason' => 'awal'])->assertOk();
        $first = $payment->fresh();

        $this->postJson("/api/admin/payments/{$payment->id}/reject", ['reason' => 'kedua'])
            ->assertStatus(409);

        $fresh = $payment->fresh();
        $this->assertSame('REJECTED', $fresh->status);
        $this->assertSame('awal', $fresh->rejection_reason, 'rejection metadata must not be overwritten');
        $this->assertSame($admin->id, $fresh->verifier_id);
    }

    public function test_non_pending_manual_status_cannot_be_verified_or_rejected(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $payment = $this->payment($this->parent(), 'PENDING');

        // Only PENDING_MANUAL enters the manual verification workflow; every
        // other status is rejected rather than silently approved.
        $this->postJson("/api/admin/payments/{$payment->id}/verify")->assertStatus(409);
        $this->postJson("/api/admin/payments/{$payment->id}/reject")->assertStatus(409);

        $this->assertSame('PENDING', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->verifier_id);
    }

    public function test_expired_or_failed_cannot_be_verified(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        foreach (['EXPIRED', 'FAILED'] as $status) {
            $payment = $this->payment($this->parent(), $status);
            $this->postJson("/api/admin/payments/{$payment->id}/verify")->assertStatus(409);
            $this->assertSame($status, $payment->fresh()->status);
        }
    }

    // ===================== NOT-FOUND / EDGE CASES =====================

    public function test_nonexistent_payment_returns_404(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $this->postJson('/api/admin/payments/999999/verify')->assertStatus(404);
        $this->postJson('/api/admin/payments/999999/reject')->assertStatus(404);
    }

    public function test_rejection_preserves_evidence_file(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('bukti_bayar/proof.png', 'evidence');

        Sanctum::actingAs($this->admin(), ['*']);
        $payment = $this->payment($this->parent());

        $this->postJson("/api/admin/payments/{$payment->id}/reject", ['reason' => 'blur'])
            ->assertOk();

        $this->assertSame('REJECTED', $payment->fresh()->status);
        Storage::disk('private')->assertExists('bukti_bayar/proof.png');
        $this->assertSame('bukti_bayar/proof.png', $payment->fresh()->proof_of_payment, 'evidence path must be preserved');
    }

    public function test_reject_reason_over_max_length_returns_422(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $payment = $this->payment($this->parent());

        $this->postJson("/api/admin/payments/{$payment->id}/reject", [
            'reason' => str_repeat('x', 1001),
        ])->assertStatus(422);

        $this->assertSame('PENDING_MANUAL', $payment->fresh()->status, 'validation failure must not mutate state');
    }

    public function test_reject_reason_must_be_string(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $payment = $this->payment($this->parent());

        $this->postJson("/api/admin/payments/{$payment->id}/reject", [
            'reason' => ['array' => 'injection'],
        ])->assertStatus(422);

        $this->assertSame('PENDING_MANUAL', $payment->fresh()->status);
    }

    // ===================== SIDE-EFFECT SAFETY (Phase 9) =====================

    public function test_verification_does_not_mutate_unrelated_fields(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $owner = $this->parent();
        $payment = $this->payment($owner);

        $this->postJson("/api/admin/payments/{$payment->id}/verify")->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame($payment->user_id, $fresh->user_id);
        $this->assertSame($payment->invoice_number, $fresh->invoice_number);
        $this->assertSame($payment->jenjang, $fresh->jenjang);
        $this->assertSame($payment->amount, $fresh->amount);
        $this->assertSame($payment->payment_method, $fresh->payment_method);
        $this->assertSame($payment->proof_of_payment, $fresh->proof_of_payment);
        $this->assertSame(1, Payment::count());
        $this->assertSame('orang_tua', $owner->fresh()->role, 'user identity must not be altered');
    }

    public function test_admin_can_verify_payment_of_any_owner(): void
    {
        // Admins are authorized to verify any owner's payment (existing role model).
        $ownerA = $this->parent();
        $ownerB = $this->parent();
        $paymentA = $this->payment($ownerA);
        $paymentB = $this->payment($ownerB);

        Sanctum::actingAs($this->admin(), ['*']);

        $this->postJson("/api/admin/payments/{$paymentA->id}/verify")->assertOk();
        $this->assertSame('SUCCESS', $paymentA->fresh()->status);
        $this->assertSame('PENDING_MANUAL', $paymentB->fresh()->status, 'other payments untouched');
    }

    public function test_verify_and_reject_on_same_payment_only_one_wins(): void
    {
        // Sequential competing operations: approve then reject (and vice versa)
        // must leave exactly ONE terminal state — the second is a conflict.
        Sanctum::actingAs($this->admin(), ['*']);
        $payment = $this->payment($this->parent());

        // Approve wins.
        $this->postJson("/api/admin/payments/{$payment->id}/verify")->assertOk();
        $this->postJson("/api/admin/payments/{$payment->id}/reject", ['reason' => 'late'])->assertStatus(409);
        $this->assertSame('SUCCESS', $payment->fresh()->status);

        // Fresh payment: reject wins.
        $payment2 = $this->payment($this->parent());
        $this->postJson("/api/admin/payments/{$payment2->id}/reject", ['reason' => 'x'])->assertOk();
        $this->postJson("/api/admin/payments/{$payment2->id}/verify")->assertStatus(409);
        $this->assertSame('REJECTED', $payment2->fresh()->status);
    }
}
