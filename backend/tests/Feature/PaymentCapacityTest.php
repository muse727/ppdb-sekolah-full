<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PpdbSeatAllocation;
use App\Models\Setting;
use App\Models\User;
use App\Services\PpdbCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Payment verification is financial-only; seat ownership and quota are unchanged. */
class PaymentCapacityTest extends TestCase
{
    use RefreshDatabase;

    private PpdbCapacityService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(PpdbCapacityService::class);
        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'BUKA']);
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['role' => 'admin'])->save();
        return $u->refresh();
    }

    private function parentUser(string $jenjang): User
    {
        $u = User::factory()->create(['jenjang' => $jenjang]);
        $u->forceFill(['role' => 'orang_tua'])->save();
        return $u->refresh();
    }

    private function payment(User $owner, string $jenjang, string $status = 'PENDING_MANUAL'): Payment
    {
        return Payment::create([
            'user_id'        => $owner->id,
            'invoice_number' => 'MANUAL-C-' . strtoupper(uniqid()),
            'jenjang'        => $jenjang,
            'amount'         => 150000,
            'payment_method' => 'MANUAL',
            'proof_of_payment' => 'bukti_bayar/p.png',
            'status'         => $status,
        ]);
    }

    private function registrationSeat(User $owner): PpdbSeatAllocation
    {
        return PpdbSeatAllocation::create([
            'user_id' => $owner->id, 'payment_id' => null, 'jenjang' => $owner->jenjang,
            'status' => 'ALLOCATED', 'allocated_at' => now(),
        ]);
    }

    private function seatSnapshot(): array
    {
        return PpdbSeatAllocation::orderBy('id')->get()->map->getAttributes()->all();
    }

    public function test_verify_preserves_registration_seat_and_quota(): void
    {
        $this->svc->increaseMaxCapacity('SD', 5);
        $owner = $this->parentUser('SD');
        $this->registrationSeat($owner);
        $p = $this->payment($owner, 'SD');
        $before = $this->seatSnapshot();
        $quota = $this->svc->publicQuota();
        $admin = $this->admin();

        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/admin/payments/{$p->id}/verify")->assertOk();

        $this->assertSame('SUCCESS', $p->fresh()->status);
        $this->assertSame($admin->id, $p->fresh()->verifier_id);
        $this->assertNotNull($p->fresh()->verified_at);
        $this->assertSame($before, $this->seatSnapshot());
        $this->assertSame($quota, $this->svc->publicQuota());
        $this->assertDatabaseHas('ppdb_seat_allocations', [
            'payment_id' => null, 'user_id' => $owner->id, 'status' => 'ALLOCATED',
        ]);
        $this->assertSame(1, PpdbSeatAllocation::count());
    }

    public function test_verify_at_zero_remaining_capacity_succeeds_without_resolving_capacity_service(): void
    {
        $this->svc->increaseMaxCapacity('SD', 1);
        $owner = $this->parentUser('SD');
        $this->registrationSeat($owner);
        $p = $this->payment($owner, 'SD');
        $before = $this->seatSnapshot();
        $quota = $this->svc->publicQuota();
        $this->assertSame(0, $quota['sd']['sisa']);
        // Any quota/reservation service dependency makes this request fail.
        $this->app->bind(PpdbCapacityService::class, function () {
            throw new \LogicException('Payment verification must not resolve capacity service.');
        });

        Sanctum::actingAs($this->admin(), ['*']);
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->postJson("/api/admin/payments/{$p->id}/verify")->assertOk();
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/ppdb_(capacities|seat_allocations)/i', $query['query']);
        }
        $this->assertSame('SUCCESS', $p->fresh()->status);
        $this->assertSame($before, $this->seatSnapshot());
        $this->assertSame($quota, $this->svc->publicQuota());
    }

    public function test_repeated_verify_preserves_seat_quota_and_financial_audit(): void
    {
        $this->svc->increaseMaxCapacity('SD', 5);
        $owner = $this->parentUser('SD');
        $this->registrationSeat($owner);
        $p = $this->payment($owner, 'SD');
        $before = $this->seatSnapshot();
        $quota = $this->svc->publicQuota();

        Sanctum::actingAs($this->admin(), ['*']);
        $this->postJson("/api/admin/payments/{$p->id}/verify")->assertOk();
        $audit = $p->fresh()->getAttributes();
        Sanctum::actingAs($this->admin(), ['*']);
        $this->postJson("/api/admin/payments/{$p->id}/verify")->assertStatus(409);

        $this->assertSame($audit, $p->fresh()->getAttributes());
        $this->assertSame($before, $this->seatSnapshot());
        $this->assertSame($quota, $this->svc->publicQuota());
        $this->assertSame(1, PpdbSeatAllocation::count());
    }

    public function test_spoofed_client_fields_do_not_change_seat_or_payment_identity(): void
    {
        $this->svc->increaseMaxCapacity('SD', 5);
        $this->svc->increaseMaxCapacity('SMP', 5);
        $owner = $this->parentUser('SD');
        $this->registrationSeat($owner);
        $p = $this->payment($owner, 'SD');
        $before = $this->seatSnapshot();
        $quota = $this->svc->publicQuota();

        Sanctum::actingAs($this->admin(), ['*']);
        $this->postJson("/api/admin/payments/{$p->id}/verify", [
            'jenjang' => 'SMP', 'user_id' => 999, 'status' => 'SUCCESS', 'amount' => 1,
        ])->assertOk();

        $this->assertSame($before, $this->seatSnapshot());
        $this->assertSame($quota, $this->svc->publicQuota());
        $this->assertSame('SD', $p->fresh()->jenjang);
        $this->assertSame($owner->id, $p->fresh()->user_id);
        $this->assertSame(150000, (int) $p->fresh()->amount);
    }

    public function test_verify_rejected_payment_creates_no_allocation(): void
    {
        $this->svc->increaseMaxCapacity('SD', 5);
        $owner = $this->parentUser('SD');
        $p = $this->payment($owner, 'SD', 'REJECTED');

        Sanctum::actingAs($this->admin(), ['*']);
        $this->postJson("/api/admin/payments/{$p->id}/verify")->assertStatus(409);
        $this->assertSame(0, PpdbSeatAllocation::count());
    }

    public function test_auto_closed_gate_reopens_when_admin_marks_candidate_not_accepted(): void
    {
        $this->svc->increaseMaxCapacity('SD', 1);
        $this->svc->increaseMaxCapacity('SMP', 1);
        $admin = $this->admin();
        $sd = $this->parentUser('SD');
        $smp = $this->parentUser('SMP');
        $sdPayment = $this->payment($sd, 'SD');
        $smpPayment = $this->payment($smp, 'SMP');

        $this->registrationSeat($sd);
        $this->registrationSeat($smp);
        $this->svc->maybeAutoCloseGlobal();

        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/admin/payments/{$sdPayment->id}/verify")->assertOk();
        $this->postJson("/api/admin/payments/{$smpPayment->id}/verify")->assertOk();
        $this->assertSame('TUTUP', Setting::where('key', 'ppdb_status')->value('value'));

        $this->postJson("/api/admin/kelulusan/{$sd->id}", ['status' => 'TIDAK_LULUS'])->assertOk();
        $this->assertSame('BUKA', Setting::where('key', 'ppdb_status')->value('value'));
        $this->assertSame('SUCCESS', $sdPayment->fresh()->status);
        $this->assertSame(0, $this->svc->activeCount('SD'));
        $this->assertSame(1, $this->svc->activeCount('SMP'));
    }

    public function test_manual_gate_is_not_reopened_by_release(): void
    {
        $this->svc->increaseMaxCapacity('SD', 1);
        $owner = $this->parentUser('SD');
        $payment = $this->payment($owner, 'SD', 'SUCCESS');
        $allocation = $this->svc->consumeSeat('SD', $owner->id, $payment->id);
        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'TUTUP']);
        Setting::updateOrCreate(['key' => 'ppdb_auto_closed'], ['value' => '0']);

        Sanctum::actingAs($this->admin(), ['*']);
        $this->postJson("/api/admin/kelulusan/{$owner->id}", ['status' => 'TIDAK_LULUS'])->assertOk();

        $this->assertSame('TUTUP', Setting::where('key', 'ppdb_status')->value('value'));
        $this->assertSame('SUCCESS', $payment->fresh()->status);
    }


    public function test_both_full_does_not_change_gate_on_verify(): void
    {
        $this->svc->increaseMaxCapacity('SD', 1);
        $this->svc->increaseMaxCapacity('SMP', 1);

        $admin = $this->admin();
        $usd = $this->parentUser('SD');
        $usmp = $this->parentUser('SMP');
        $psd = $this->payment($usd, 'SD');
        $psmp = $this->payment($usmp, 'SMP');

        $this->registrationSeat($usd);
        $this->registrationSeat($usmp);
        $before = $this->seatSnapshot();
        $quota = $this->svc->publicQuota();
        $this->assertSame(0, $quota['sd']['sisa']);
        $this->assertSame(0, $quota['smp']['sisa']);

        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/admin/payments/{$psd->id}/verify")->assertOk();
        $this->postJson("/api/admin/payments/{$psmp->id}/verify")->assertOk();
        $this->assertSame('BUKA', Setting::where('key', 'ppdb_status')->value('value'));
        $this->assertSame($before, $this->seatSnapshot());
        $this->assertSame($quota, $this->svc->publicQuota());
    }

    private function guru(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['role' => 'guru'])->save();
        return $u->refresh();
    }

    public function test_release_is_idempotent_and_requires_controlled_reason(): void
    {
        $this->svc->increaseMaxCapacity('SD', 1);
        $owner = $this->parentUser('SD');
        $payment = $this->payment($owner, 'SD', 'SUCCESS');
        $allocation = $this->svc->consumeSeat('SD', $owner->id, $payment->id);

        Sanctum::actingAs($this->admin(), ['*']);

        // Generic release is blocked even when its reason is a domain-event name.
        foreach (['arbitrary-reason', 'ADMIN_RELEASE', 'OTHER', 'WITHDRAWN', 'NOT_ACCEPTED'] as $reason) {
            $this->postJson("/api/admin/seat-allocations/{$allocation->id}/release", ['reason' => $reason])->assertStatus(409);
        }
        $this->assertSame(1, $this->svc->activeCount('SD'));
        $this->postJson("/api/admin/kelulusan/{$owner->id}", ['status' => 'TIDAK_LULUS'])->assertOk();
        $history = $allocation->fresh()->getAttributes();
        $this->postJson("/api/admin/kelulusan/{$owner->id}", ['status' => 'TIDAK_LULUS'])->assertOk();
        $this->assertSame($history, $allocation->fresh()->getAttributes());

        $this->assertSame('SUCCESS', $payment->fresh()->status);
        $this->assertSame(0, $this->svc->activeCount('SD'));
        $this->assertSame(1, $this->svc->getCapacity('SD')['sisa']);
        $this->assertSame(
            PpdbSeatAllocation::STATUS_RELEASED,
            $allocation->fresh()->status
        );
    }

    public function test_release_endpoint_requires_admin_role(): void
    {
        $this->svc->increaseMaxCapacity('SD', 1);
        $owner = $this->parentUser('SD');
        $payment = $this->payment($owner, 'SD', 'SUCCESS');
        $allocation = $this->svc->consumeSeat('SD', $owner->id, $payment->id);

        Sanctum::actingAs($this->guru(), ['*']);
        $this->postJson("/api/admin/seat-allocations/{$allocation->id}/release", [
            'reason' => 'ADMIN_RELEASE',
        ])->assertStatus(403);
        $this->assertSame(1, $this->svc->activeCount('SD'));

        Sanctum::actingAs($owner, ['*']);
        $this->postJson("/api/admin/seat-allocations/{$allocation->id}/release", [
            'reason' => 'ADMIN_RELEASE',
        ])->assertStatus(403);
        $this->assertSame(1, $this->svc->activeCount('SD'));

        // super_admin passes the admin wall (CheckRole grants it).
        $super = User::factory()->create();
        $super->forceFill(['role' => 'super_admin'])->save();
        Sanctum::actingAs($super->refresh(), ['*']);
        $this->postJson("/api/admin/seat-allocations/{$allocation->id}/release", [
            'reason' => 'ADMIN_RELEASE',
        ])->assertStatus(409);
        $this->assertSame(1, $this->svc->activeCount('SD'));
        $this->postJson("/api/admin/kelulusan/{$owner->id}", ['status' => 'TIDAK_LULUS'])->assertOk();
        $this->assertSame(0, $this->svc->activeCount('SD'));
    }

    public function test_release_endpoint_404_and_blocked_paths(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $this->postJson('/api/admin/seat-allocations/999999/release', [
            'reason' => 'ADMIN_RELEASE',
        ])->assertStatus(404);

        $this->svc->increaseMaxCapacity('SD', 1);
        $owner = $this->parentUser('SD');
        $payment = $this->payment($owner, 'SD', 'SUCCESS');
        $allocation = $this->svc->consumeSeat('SD', $owner->id, $payment->id);

        $this->postJson("/api/admin/seat-allocations/{$allocation->id}/release")->assertStatus(409);
        $this->postJson("/api/admin/seat-allocations/{$allocation->id}/release", [
            'reason' => 'arbitrary-reason',
        ])->assertStatus(409);
        $this->assertSame(1, $this->svc->activeCount('SD'));

        $listing = $this->getJson('/api/admin/seat-allocations')->assertOk()->json();
        $row = collect($listing)->firstWhere('id', $allocation->id);
        $this->assertNotNull($row);
        $this->assertSame('SD', $row['jenjang']);
        $this->assertSame('ALLOCATED', $row['status']);
    }

    public function test_released_capacity_can_admit_a_different_candidate_and_payment_stays_success(): void
    {
        $this->svc->increaseMaxCapacity('SD', 1);
        $owner = $this->parentUser('SD');
        $payment = $this->payment($owner, 'SD', 'SUCCESS');
        $allocation = $this->svc->consumeSeat('SD', $owner->id, $payment->id);

        Sanctum::actingAs($this->admin(), ['*']);
        $this->postJson("/api/admin/kelulusan/{$owner->id}", ['status' => 'TIDAK_LULUS'])->assertOk();

        $this->assertSame('SUCCESS', $payment->fresh()->status);
        $this->assertSame(1, $this->svc->getCapacity('SD')['sisa']);

        // A different candidate may now take the freed seat.
        $nextOwner = $this->parentUser('SD');
        $nextPayment = $this->payment($nextOwner, 'SD');
        $this->svc->consumeSeat('SD', $nextOwner->id, $nextPayment->id);

        $this->assertSame(0, $this->svc->getCapacity('SD')['sisa']);
        $this->assertSame(1, $this->svc->getCapacity('SD')['terpakai']);
        $this->assertSame('SUCCESS', $payment->fresh()->status);
    }

    public function test_admin_pendaftar_listing_includes_seat_allocation_state(): void
    {
        $this->svc->increaseMaxCapacity('SD', 1);
        $owner = $this->parentUser('SD');
        $payment = $this->payment($owner, 'SD', 'SUCCESS');
        $allocation = $this->svc->consumeSeat('SD', $owner->id, $payment->id);

        Sanctum::actingAs($this->admin(), ['*']);
        $row = collect($this->getJson('/api/admin/pendaftar')->assertOk()->json())
            ->firstWhere('id', $owner->id);

        $this->assertNotNull($row);
        $this->assertSame('ALLOCATED', $row['seat_allocation']['status']);
        $this->assertSame('SD', $row['seat_allocation']['jenjang']);
        $this->assertSame($allocation->id, $row['seat_allocation']['id']);

        $this->postJson("/api/admin/kelulusan/{$owner->id}", ['status' => 'TIDAK_LULUS'])->assertOk();

        $row = collect($this->getJson('/api/admin/pendaftar')->assertOk()->json())
            ->firstWhere('id', $owner->id);
        $this->assertSame('RELEASED', $row['seat_allocation']['status']);
        $this->assertSame(PpdbCapacityService::RELEASE_NOT_ACCEPTED, $row['seat_allocation']['release_reason']);
    }
}
