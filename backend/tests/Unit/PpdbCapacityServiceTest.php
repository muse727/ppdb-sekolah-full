<?php

namespace Tests\Unit;

use App\Exceptions\CapacityException;
use App\Models\Payment;
use App\Models\PpdbCapacity;
use App\Models\PpdbSeatAllocation;
use App\Models\User;
use App\Services\PpdbCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Capacity domain logic: independence, derived remaining, invariants,
 * release idempotency, max-lower rejection.
 */
class PpdbCapacityServiceTest extends TestCase
{
    use RefreshDatabase;

    private PpdbCapacityService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(PpdbCapacityService::class);
    }

    private function parentUser(string $jenjang = 'SD'): User
    {
        $u = User::factory()->create(['jenjang' => $jenjang]);
        $u->forceFill(['role' => 'orang_tua'])->save();
        return $u->refresh();
    }

    private function payment(User $owner, string $jenjang, string $status = 'PENDING_MANUAL'): Payment
    {
        return Payment::create([
            'user_id'        => $owner->id,
            'invoice_number' => 'MANUAL-T-' . strtoupper(uniqid()),
            'jenjang'        => $jenjang,
            'amount'         => 150000,
            'payment_method' => 'MANUAL',
            'status'         => $status,
        ]);
    }

    public function test_remaining_is_derived_not_stored(): void
    {
        $this->svc->increaseMaxCapacity('SD', 10);
        $cap = $this->svc->getCapacity('SD');
        $this->assertSame(10, $cap['total']);
        $this->assertSame(0, $cap['terpakai']);
        $this->assertSame(10, $cap['sisa']);
        $this->assertTrue($cap['tersedia']);
    }

    public function test_sd_and_smp_are_independent(): void
    {
        $this->svc->increaseMaxCapacity('SD', 1);
        $this->svc->increaseMaxCapacity('SMP', 5);

        $u = $this->parentUser('SD');
        $p = $this->payment($u, 'SD');
        $this->svc->consumeSeat('SD', $u->id, $p->id);

        // SD now full, SMP untouched.
        $this->assertSame(0, $this->svc->getCapacity('SD')['sisa']);
        $this->assertFalse($this->svc->getCapacity('SD')['tersedia']);
        $this->assertSame(5, $this->svc->getCapacity('SMP')['sisa']);
        $this->assertTrue($this->svc->getCapacity('SMP')['tersedia']);
    }

    public function test_consume_decrements_remaining(): void
    {
        $this->svc->increaseMaxCapacity('SD', 3);
        $u = $this->parentUser('SD');
        $p = $this->payment($u, 'SD');
        $this->svc->consumeSeat('SD', $u->id, $p->id);

        $cap = $this->svc->getCapacity('SD');
        $this->assertSame(1, $cap['terpakai']);
        $this->assertSame(2, $cap['sisa']);
    }

    public function test_consume_throws_when_full(): void
    {
        $this->svc->increaseMaxCapacity('SD', 1);
        $u1 = $this->parentUser('SD');
        $this->svc->consumeSeat('SD', $u1->id, $this->payment($u1, 'SD')->id);

        $u2 = $this->parentUser('SD');
        $this->expectException(CapacityException::class);
        try {
            $this->svc->consumeSeat('SD', $u2->id, $this->payment($u2, 'SD')->id);
        } catch (CapacityException $e) {
            $this->assertSame(CapacityException::JENJANG_FULL, $e->reasonCode);
            throw $e;
        }
    }

    public function test_duplicate_allocation_for_same_payment_is_prevented(): void
    {
        $this->svc->increaseMaxCapacity('SD', 5);
        $u = $this->parentUser('SD');
        $p = $this->payment($u, 'SD');
        $this->svc->consumeSeat('SD', $u->id, $p->id);

        $this->expectException(CapacityException::class);
        try {
            $this->svc->consumeSeat('SD', $u->id, $p->id);
        } catch (CapacityException $e) {
            $this->assertSame(CapacityException::DUPLICATE_ALLOCATION, $e->reasonCode);
            throw $e;
        }
    }

    public function test_one_user_one_active_seat(): void
    {
        $this->svc->increaseMaxCapacity('SD', 5);
        $this->svc->increaseMaxCapacity('SMP', 5);
        $u = $this->parentUser('SD');
        $this->svc->consumeSeat('SD', $u->id, $this->payment($u, 'SD')->id);

        // Same user tries to take an SMP seat with a second payment.
        $this->expectException(CapacityException::class);
        try {
            $this->svc->consumeSeat('SMP', $u->id, $this->payment($u, 'SMP')->id);
        } catch (CapacityException $e) {
            $this->assertSame(CapacityException::USER_ALREADY_HAS_SEAT, $e->reasonCode);
            throw $e;
        }
    }

    public function test_cannot_lower_max_below_active(): void
    {
        $this->svc->increaseMaxCapacity('SD', 3);
        $u = $this->parentUser('SD');
        $this->svc->consumeSeat('SD', $u->id, $this->payment($u, 'SD')->id);
        $this->assertSame(1, $this->svc->activeCount('SD'));

        $this->expectException(CapacityException::class);
        try {
            $this->svc->increaseMaxCapacity('SD', 0);
        } catch (CapacityException $e) {
            $this->assertSame(CapacityException::MAX_BELOW_ACTIVE, $e->reasonCode);
            throw $e;
        }
    }

    public function test_raise_capacity_is_allowed(): void
    {
        $this->svc->increaseMaxCapacity('SD', 2);
        $cap = $this->svc->increaseMaxCapacity('SD', 50);
        $this->assertSame(50, $cap['total']);
    }

    public function test_release_is_idempotent_and_not_counted_active(): void
    {
        $this->svc->increaseMaxCapacity('SD', 1);
        $u = $this->parentUser('SD');
        $p = $this->payment($u, 'SD');
        $alloc = $this->svc->consumeSeat('SD', $u->id, $p->id);
        $this->assertSame(0, $this->svc->getCapacity('SD')['sisa']);

        $this->svc->releaseSeat($alloc, PpdbCapacityService::RELEASE_NOT_ACCEPTED);
        $this->assertSame(1, $this->svc->getCapacity('SD')['sisa']); // freed
        $released = $alloc->fresh();
        $this->assertSame(PpdbSeatAllocation::STATUS_RELEASED, $released->status);
        $this->assertNotNull($released->released_at);
        $this->assertSame(PpdbCapacityService::RELEASE_NOT_ACCEPTED, $released->release_reason);
        $this->assertSame('PENDING_MANUAL', $p->fresh()->status);

        // second release = no-op, still RELEASED, still one auditable row
        $this->svc->releaseSeat($released, PpdbCapacityService::RELEASE_NOT_ACCEPTED);
        $this->assertSame(1, $this->svc->getCapacity('SD')['sisa']);
        $this->assertSame(PpdbCapacityService::RELEASE_NOT_ACCEPTED, $released->fresh()->release_reason);
        $this->assertSame(1, PpdbSeatAllocation::count());
    }

    public function test_released_history_prevents_second_allocation(): void
    {
        $this->svc->increaseMaxCapacity('SD', 5);
        $u = $this->parentUser('SD');
        $p1 = $this->payment($u, 'SD');
        $alloc = $this->svc->consumeSeat('SD', $u->id, $p1->id);
        $this->svc->releaseSeat($alloc, PpdbCapacityService::RELEASE_NOT_ACCEPTED);
        $before = $alloc->fresh()->getAttributes();
        $quota = $this->svc->publicQuota();
        $p2 = $this->payment($u, 'SD');
        try {
            $this->svc->consumeSeat('SD', $u->id, $p2->id);
            $this->fail('Released candidate must not receive a second allocation.');
        } catch (CapacityException $e) {
            $this->assertSame(CapacityException::USER_ALREADY_HAS_SEAT, $e->reasonCode);
        }
        $this->assertSame($before, $alloc->fresh()->getAttributes());
        $this->assertSame($quota, $this->svc->publicQuota());
        $this->assertSame(1, PpdbSeatAllocation::count());
        $this->assertSame('TIDAK_LULUS', $u->fresh()->status_kelulusan);
    }

    public function test_invalid_jenjang_rejected(): void
    {
        $this->expectException(CapacityException::class);
        try {
            $this->svc->consumeSeat('TK', 1, 1);
        } catch (CapacityException $e) {
            $this->assertSame(CapacityException::INVALID_JENJANG, $e->reasonCode);
            throw $e;
        }
    }

    public function test_release_sd_does_not_affect_smp_and_release_smp_does_not_affect_sd(): void
    {
        $this->svc->increaseMaxCapacity('SD', 2);
        $this->svc->increaseMaxCapacity('SMP', 3);

        $sdUser = $this->parentUser('SD');
        $sdPayment = $this->payment($sdUser, 'SD', 'SUCCESS');
        $sdAllocation = $this->svc->consumeSeat('SD', $sdUser->id, $sdPayment->id);

        $smpUser = $this->parentUser('SMP');
        $smpPayment = $this->payment($smpUser, 'SMP', 'SUCCESS');
        $smpAllocation = $this->svc->consumeSeat('SMP', $smpUser->id, $smpPayment->id);

        $beforeSmp = $this->svc->getCapacity('SMP');
        $this->svc->releaseSeat($sdAllocation, PpdbCapacityService::RELEASE_NOT_ACCEPTED);
        $this->assertSame(2, $this->svc->getCapacity('SD')['sisa']);
        $this->assertSame($beforeSmp['terpakai'], $this->svc->getCapacity('SMP')['terpakai']);
        $this->assertSame($beforeSmp['sisa'], $this->svc->getCapacity('SMP')['sisa']);
        $this->assertSame('SUCCESS', $sdPayment->fresh()->status);

        $beforeSd = $this->svc->getCapacity('SD');
        $this->svc->releaseSeat($smpAllocation, PpdbCapacityService::RELEASE_NOT_ACCEPTED);
        $this->assertSame($beforeSd['terpakai'], $this->svc->getCapacity('SD')['terpakai']);
        $this->assertSame($beforeSd['sisa'], $this->svc->getCapacity('SD')['sisa']);
        $this->assertSame('SUCCESS', $smpPayment->fresh()->status);
    }

    public function test_release_requires_controlled_reason_and_rejects_missing_active_seat(): void
    {
        $this->svc->increaseMaxCapacity('SD', 1);
        $user = $this->parentUser('SD');
        $payment = $this->payment($user, 'SD', 'SUCCESS');
        $allocation = $this->svc->consumeSeat('SD', $user->id, $payment->id);

        try {
            $this->svc->releaseSeat($allocation, 'arbitrary');
            $this->fail('Uncontrolled release reason should fail.');
        } catch (CapacityException $exception) {
            $this->assertSame(CapacityException::INVALID_RELEASE_REASON, $exception->reasonCode);
        }

        $this->svc->releaseSeat($allocation, PpdbCapacityService::RELEASE_NOT_ACCEPTED);
        $this->assertSame($allocation->id, $this->svc->releaseSeatForUser($user->id, PpdbCapacityService::RELEASE_NOT_ACCEPTED)->id);
        $this->assertSame('TIDAK_LULUS', $user->fresh()->status_kelulusan);
        $this->assertSame('SUCCESS', $payment->fresh()->status);
    }

    public function test_manual_global_gate_is_not_reopened_by_release(): void
    {
        \App\Models\Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'TUTUP']);
        \App\Models\Setting::updateOrCreate(['key' => 'ppdb_auto_closed'], ['value' => '0']);
        $this->svc->increaseMaxCapacity('SD', 1);
        $user = $this->parentUser('SD');
        $payment = $this->payment($user, 'SD', 'SUCCESS');
        $allocation = $this->svc->consumeSeat('SD', $user->id, $payment->id);

        $this->svc->releaseSeat($allocation, PpdbCapacityService::RELEASE_NOT_ACCEPTED);
        $this->assertSame('TUTUP', $this->svc->publicQuota()['status']);
    }

    public function test_automatic_global_close_is_reopened_after_release(): void
    {
        \App\Models\Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'BUKA']);
        $this->svc->increaseMaxCapacity('SD', 1);
        $this->svc->increaseMaxCapacity('SMP', 1);
        $sd = $this->parentUser('SD');
        $smp = $this->parentUser('SMP');
        $sdAllocation = $this->svc->consumeSeat('SD', $sd->id, $this->payment($sd, 'SD')->id);
        $this->svc->consumeSeat('SMP', $smp->id, $this->payment($smp, 'SMP')->id);
        $this->svc->maybeAutoCloseGlobal();
        $this->assertSame('1', \App\Models\Setting::where('key', 'ppdb_auto_closed')->value('value'));

        $this->svc->releaseSeat($sdAllocation, PpdbCapacityService::RELEASE_NOT_ACCEPTED);
        $this->assertSame('BUKA', $this->svc->publicQuota()['status']);
        $this->assertSame('0', \App\Models\Setting::where('key', 'ppdb_auto_closed')->value('value'));
    }

    public function test_auto_close_only_when_both_full(): void
    {
        \App\Models\Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'BUKA']);
        $this->svc->increaseMaxCapacity('SD', 1);
        $this->svc->increaseMaxCapacity('SMP', 1);

        // Fill SD only -> gate stays open
        $usd = $this->parentUser('SD');
        $this->svc->consumeSeat('SD', $usd->id, $this->payment($usd, 'SD')->id);
        $this->assertSame('BUKA', $this->svc->maybeAutoCloseGlobal());

        // Fill SMP too -> gate closes
        $usmp = $this->parentUser('SMP');
        $this->svc->consumeSeat('SMP', $usmp->id, $this->payment($usmp, 'SMP')->id);
        $this->assertSame('TUTUP', $this->svc->maybeAutoCloseGlobal());
    }
}
