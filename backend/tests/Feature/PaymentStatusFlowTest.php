<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pin the payment-state contract after the gateway removal:
 *  - checkStatus reports the true latest state (ordering must be deterministic)
 *  - REJECTED surfaces the reason to its owner but never the reviewer identity
 *  - REJECTED intentionally allows a fresh upload (distinct new record)
 *  - the rejected record stays immutable; SUCCESS is terminal
 *  - foreign users are still locked out
 */
class PaymentStatusFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Verification now consumes a seat; seed capacity so these tests exercise the
        // payment state machine rather than the full-jenjang 409 path.
        app(\App\Services\PpdbCapacityService::class)->increaseMaxCapacity('SD', 1000);
        app(\App\Services\PpdbCapacityService::class)->increaseMaxCapacity('SMP', 1000);
    }

    private function parent(string $name = 'Parent'): User
    {
        $u = User::factory()->create(['name' => $name, 'jenjang' => 'SD']);
        $u->forceFill(['role' => 'orang_tua'])->save();

        return $u->refresh();
    }

    private function admin(string $name = 'Admin'): User
    {
        $u = User::factory()->create(['name' => $name]);
        $u->forceFill(['role' => 'admin'])->save();

        return $u->refresh();
    }

    private function price(): void
    {
        Setting::updateOrCreate(['key' => 'biaya_sd'], ['value' => '175000']);
    }

    private function upload(): UploadedFile
    {
        return UploadedFile::fake()->image('proof.jpg', 64, 48);
    }

    /**
     * Caller must Storage::fake('private') exactly once per test: calling
     * Storage::fake() twice replaces the fake disk and wipes earlier files.
     */
    private function submitProof(User $u): Payment
    {
        Sanctum::actingAs($u);
        $this->price();

        $this->post('/api/payment/manual', [
            'jenjang' => 'SD',
            'bukti_bayar' => $this->upload(),
        ], ['Accept' => 'application/json'])->assertOk();

        return Payment::where('user_id', $u->id)->latest('id')->first();
    }

    // == CHECKSTATUS: the parent's view of their own payment ==

    public function test_check_status_reports_each_state_faithfully(): void
    {
        Storage::fake('private');
        $this->price();
        $u = $this->parent();
        Sanctum::actingAs($u);

        // No payment yet.
        $this->getJson('/api/payment/status')
            ->assertOk()
            ->assertJson(['has_payment' => false, 'step' => 1]);

        $p1 = $this->submitProof($u);
        Sanctum::actingAs($u);
        $r = $this->getJson('/api/payment/status')->assertOk()->json();
        $this->assertTrue($r['has_payment']);
        $this->assertSame('PENDING_MANUAL', $r['payment']['status']);
        $this->assertSame(1, $r['step']);
        $this->assertSame($p1->proof_of_payment, $r['payment']['proof_of_payment']);

        // SUCCESS stays SUCCESS and moves to step 2.
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/payments/{$p1->id}/verify")->assertOk();

        Sanctum::actingAs($u);
        $r = $this->getJson('/api/payment/status')->assertOk()->json();
        $this->assertSame('SUCCESS', $r['payment']['status']);
        $this->assertSame(2, $r['step']);
    }

    public function test_check_status_never_leaks_the_reviewer_identity(): void
    {
        Storage::fake('private');
        $u = $this->parent();
        $p = $this->submitProof($u);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/payments/{$p->id}/reject", ['reason' => 'Nominal tidak sesuai'])->assertOk();

        Sanctum::actingAs($u);
        $payload = $this->getJson('/api/payment/status')->assertOk()->json('payment');

        $this->assertArrayNotHasKey('verifier_id', $payload, 'reviewer id must not reach the parent');
        $this->assertArrayNotHasKey('user_id', $payload, 'internal user_id is not needed by the client');
        $this->assertSame('REJECTED', $payload['status']);
        $this->assertSame('Nominal tidak sesuai', $payload['rejection_reason']);
    }

    public function test_rejection_reason_is_only_surfaced_for_rejected_payments(): void
    {
        Storage::fake('private');
        $u = $this->parent();
        $p = $this->submitProof($u);

        // While still pending, the reason must read null even if stale data existed.
        $p->forceFill(['rejection_reason' => 'stale from an earlier cycle'])->save();

        Sanctum::actingAs($u);
        $payload = $this->getJson('/api/payment/status')->assertOk()->json('payment');
        $this->assertNull($payload['rejection_reason']);
    }

    // == ORDERING: deterministic "latest" ==

    public function test_same_second_uploads_resolve_to_the_newest_record(): void
    {
        Storage::fake('private');
        $u = $this->parent();

        $old = $this->submitProof($u);
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/payments/{$old->id}/reject", ['reason' => 'blurry'])->assertOk();

        // Second proof created within the SAME second-resolution timestamp.
        $new = $this->submitProof($u);

        Payment::whereKey($old->id)->update(['created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        Payment::whereKey($new->id)->update(['created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);

        Sanctum::actingAs($u);
        $payload = $this->getJson('/api/payment/status')->assertOk()->json('payment');
        $this->assertSame($new->id, $payload['id'], 'status must reflect the newest upload, not an arbitrary tie-break');
        $this->assertSame('PENDING_MANUAL', $payload['status']);
    }

    // == REJECTED POLICY ==

    public function test_rejected_allows_a_fresh_upload_as_a_distinct_record(): void
    {
        Storage::fake('private');
        $u = $this->parent();
        $first = $this->submitProof($u);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/payments/{$first->id}/reject", ['reason' => 'tidak terbaca'])->assertOk();

        // Policy A: rejected => the parent may upload corrected proof.
        $second = $this->submitProof($u);
        $this->assertNotSame($first->id, $second->id, 'a new upload must create a distinct payment record');

        $this->assertDatabaseHas('payments', ['id' => $first->id, 'status' => 'REJECTED']);
        $this->assertDatabaseHas('payments', ['id' => $second->id, 'status' => 'PENDING_MANUAL']);

        // The rejected record is immutable history: its fields are untouched.
        $this->assertSame('tidak terbaca', $first->fresh()->rejection_reason);
        $this->assertNotNull($first->fresh()->verified_at);
        $this->assertSame($first->proof_of_payment, $first->fresh()->proof_of_payment);

        // Its evidence file is still on disk for the owner/admin.
        Storage::disk('private')->assertExists($first->proof_of_payment);
    }

    public function test_rejected_evidence_remains_accessible_to_owner_and_admin_but_not_others(): void
    {
        Storage::fake('private');
        $u = $this->parent();
        $rejected = $this->submitProof($u);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/payments/{$rejected->id}/reject", ['reason' => 'x'])->assertOk();
        $adminId = auth()->id();

        // Owner can still fetch the rejected evidence.
        Sanctum::actingAs($u);
        $this->get("/api/payments/{$rejected->id}/evidence")->assertOk();

        // Admin can too.
        Sanctum::actingAs($this->admin());
        $this->get("/api/payments/{$rejected->id}/evidence")->assertOk();

        // Another parent cannot.
        Sanctum::actingAs($this->parent('Intruder'));
        $this->get("/api/payments/{$rejected->id}/evidence")->assertForbidden();
    }

    public function test_rejection_does_not_delete_the_evidence_file(): void
    {
        Storage::fake('private');
        $u = $this->parent();
        $p = $this->submitProof($u);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/payments/{$p->id}/reject", ['reason' => 'x'])->assertOk();

        Storage::disk('private')->assertExists($p->proof_of_payment);
    }

    // == SUCCESS IS TERMINAL ==

    public function test_success_cannot_be_rejected_reverified_or_replaced_by_a_new_upload(): void
    {
        Storage::fake('private');
        $u = $this->parent();
        $paid = $this->submitProof($u);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/payments/{$paid->id}/verify")->assertOk();

        $this->postJson("/api/admin/payments/{$paid->id}/reject", ['reason' => 'terlambat'])->assertStatus(409);
        $this->postJson("/api/admin/payments/{$paid->id}/verify")->assertStatus(409);

        Sanctum::actingAs($u);
        $this->post('/api/payment/manual', [
            'jenjang' => 'SD',
            'bukti_bayar' => $this->upload(),
        ], ['Accept' => 'application/json'])->assertStatus(409);

        $this->assertSame('SUCCESS', $paid->fresh()->status);
        $this->assertSame(1, Payment::where('user_id', $u->id)->count(), 'SUCCESS must not be replaceable by a new upload');
    }

    // == ADMIN LISTING ==

    public function test_admin_listing_exposes_rejection_reason_but_not_reviewer_id(): void
    {
        Storage::fake('private');
        $u = $this->parent();
        $p = $this->submitProof($u);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/payments/{$p->id}/reject", ['reason' => 'nominal salah'])->assertOk();

        $rows = $this->getJson('/api/admin/pendaftar')->assertOk()->json();
        $row = collect($rows)->firstWhere('id', $u->id);

        $this->assertNotNull($row);
        $this->assertSame('Ditolak', $row['status_bayar']);
        $this->assertSame('nominal salah', $row['rejection_reason']);
        $this->assertNotNull($row['verified_at']);
        $this->assertArrayNotHasKey('verifier_id', $row, 'reviewer id must not leak into the admin listing');
    }

    public function test_admin_listing_reflects_the_newest_payment_after_reupload(): void
    {
        Storage::fake('private');
        $u = $this->parent();
        $old = $this->submitProof($u);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/payments/{$old->id}/reject", ['reason' => 'blurry'])->assertOk();

        $new = $this->submitProof($u);

        Payment::whereKey($old->id)->update(['created_at' => '2026-01-01 00:00:00']);
        Payment::whereKey($new->id)->update(['created_at' => '2026-01-01 00:00:00']);

        Sanctum::actingAs($this->admin());
        $rows = $this->getJson('/api/admin/pendaftar')->assertOk()->json();
        $row = collect($rows)->firstWhere('id', $u->id);

        $this->assertSame('Menunggu Verifikasi', $row['status_bayar'], 'after re-upload the admin must see the new pending proof, not the stale rejected row');
        $this->assertSame($new->proof_of_payment, $row['proof_of_payment']);
    }
}
