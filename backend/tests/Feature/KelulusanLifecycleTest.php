<?php

namespace Tests\Feature;

use App\Exceptions\CapacityException;
use App\Models\Payment;
use App\Models\PpdbSeatAllocation;
use App\Models\User;
use App\Services\PpdbCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KelulusanLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private PpdbCapacityService $capacity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capacity = app(PpdbCapacityService::class);
        $this->capacity->increaseMaxCapacity('SD', 20);
        $this->capacity->increaseMaxCapacity('SMP', 20);
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($admin);
    }

    private function candidate(string $status = 'BELUM', string $jenjang = 'SD'): array
    {
        $user = User::factory()->create(['jenjang' => $jenjang]);
        $user->forceFill(['role' => 'orang_tua', 'status_kelulusan' => $status])->save();
        $seat = PpdbSeatAllocation::create(['user_id' => $user->id, 'payment_id' => null,
            'jenjang' => $jenjang, 'status' => 'ALLOCATED', 'allocated_at' => now()]);
        foreach (Payment::CATEGORIES as $category) {
            Payment::create(['user_id' => $user->id, 'payment_category' => $category,
                'invoice_number' => $category.'-'.uniqid(), 'jenjang' => $jenjang,
                'amount' => 175000, 'status' => 'SUCCESS']);
        }
        return [$user, $seat];
    }

    private function payments(): array
    {
        return Payment::orderBy('id')->get()->map->getAttributes()->all();
    }

    private function update(User $user, string $status)
    {
        return $this->postJson('/api/admin/kelulusan/'.$user->id, ['status' => $status]);
    }

    public function test_belum_and_lulus_transitions_preserve_the_existing_active_seat(): void
    {
        foreach ([['BELUM', 'LULUS'], ['LULUS', 'BELUM'], ['LULUS', 'LULUS'], ['BELUM', 'BELUM']] as [$from, $to]) {
            [$user, $seat] = $this->candidate($from);
            $before = $seat->fresh()->getAttributes();
            $quota = $this->capacity->publicQuota();
            $payments = $this->payments();
            $this->update($user, $to)->assertOk();
            $this->assertSame($to, $user->fresh()->status_kelulusan);
            $this->assertSame($before, $seat->fresh()->getAttributes());
            $this->assertSame($quota, $this->capacity->publicQuota());
            $this->assertSame($payments, $this->payments());
            $this->assertSame(1, PpdbSeatAllocation::where('user_id', $user->id)->count());
        }
    }

    public function test_belum_and_lulus_to_tidak_lulus_release_exactly_once_with_immutable_payment_and_history(): void
    {
        foreach (['BELUM', 'LULUS'] as $from) {
            [$user, $seat] = $this->candidate($from);
            $remaining = $this->capacity->getCapacity('SD')['sisa'];
            $payments = $this->payments();
            $allocatedAt = $seat->fresh()->allocated_at;
            $this->update($user, 'TIDAK_LULUS')->assertOk();
            $this->assertSame('TIDAK_LULUS', $user->fresh()->status_kelulusan);
            $this->assertSame('RELEASED', $seat->fresh()->status);
            $this->assertSame('NOT_ACCEPTED', $seat->fresh()->release_reason);
            $this->assertNotNull($seat->fresh()->released_at);
            $this->assertEquals($allocatedAt, $seat->fresh()->allocated_at);
            $this->assertNull($seat->fresh()->payment_id);
            $this->assertSame($remaining + 1, $this->capacity->getCapacity('SD')['sisa']);
            $history = $seat->fresh()->getAttributes();
            $userBefore = $user->fresh()->getAttributes();
            $this->travel(10)->minutes();
            $this->update($user, 'TIDAK_LULUS')->assertOk();
            $this->assertSame($history, $seat->fresh()->getAttributes());
            $this->assertSame($userBefore, $user->fresh()->getAttributes());
            $this->assertSame($remaining + 1, $this->capacity->getCapacity('SD')['sisa']);
            $this->assertSame($payments, $this->payments());
            $this->assertSame(1, PpdbSeatAllocation::where('user_id', $user->id)->count());
        }
    }

    public function test_bulk_releases_two_seats_exactly_once_and_repeated_bulk_is_a_noop(): void
    {
        [$a, $seatA] = $this->candidate();
        [$b, $seatB] = $this->candidate('LULUS');
        $remaining = $this->capacity->getCapacity('SD')['sisa'];
        $payments = $this->payments();
        $payload = ['user_ids' => [$b->id, $a->id], 'status' => 'TIDAK_LULUS'];
        $this->postJson('/api/admin/kelulusan/bulk', $payload)->assertOk();
        $history = PpdbSeatAllocation::orderBy('id')->get()->map->getAttributes()->all();
        $this->travel(10)->minutes();
        $this->postJson('/api/admin/kelulusan/bulk', $payload)->assertOk();
        $this->assertSame($remaining + 2, $this->capacity->getCapacity('SD')['sisa']);
        $this->assertSame($history, PpdbSeatAllocation::orderBy('id')->get()->map->getAttributes()->all());
        foreach ([$a, $b] as $user) {
            $this->assertSame('TIDAK_LULUS', $user->fresh()->status_kelulusan);
        }
        foreach ([$seatA, $seatB] as $seat) {
            $this->assertSame('RELEASED', $seat->fresh()->status);
        }
        $this->assertSame($payments, $this->payments());
        $this->assertSame(2, PpdbSeatAllocation::count());
    }

    public function test_mixed_bulk_releases_a_preserves_b_lulus_and_leaves_c_unchanged(): void
    {
        [$a, $seatA] = $this->candidate();
        [$b, $seatB] = $this->candidate('BELUM', 'SMP');
        [$c, $seatC] = $this->candidate('LULUS');
        $bBefore = $seatB->fresh()->getAttributes();
        $cBefore = [$c->fresh()->getAttributes(), $seatC->fresh()->getAttributes()];
        $quota = $this->capacity->publicQuota();
        $payments = $this->payments();
        $this->postJson('/api/admin/kelulusan/bulk', ['updates' => [
            ['user_id' => $b->id, 'status' => 'LULUS'],
            ['user_id' => $a->id, 'status' => 'TIDAK_LULUS'],
            ['user_id' => $c->id, 'status' => 'LULUS'],
        ]])->assertOk();
        $this->assertSame('RELEASED', $seatA->fresh()->status);
        $this->assertSame('TIDAK_LULUS', $a->fresh()->status_kelulusan);
        $this->assertSame('LULUS', $b->fresh()->status_kelulusan);
        $this->assertSame($bBefore, $seatB->fresh()->getAttributes());
        $this->assertSame($cBefore, [$c->fresh()->getAttributes(), $seatC->fresh()->getAttributes()]);
        $this->assertSame($quota['sd']['sisa'] + 1, $this->capacity->publicQuota()['sd']['sisa']);
        $this->assertSame($quota['smp'], $this->capacity->publicQuota()['smp']);
        $this->assertSame($payments, $this->payments());
        $this->assertSame(3, PpdbSeatAllocation::count());
    }

    public function test_single_and_bulk_reversals_are_rejected_without_any_reallocation(): void
    {
        [$user, $seat] = $this->candidate();
        $this->update($user, 'TIDAK_LULUS')->assertOk();
        $before = [$user->fresh()->getAttributes(), $seat->fresh()->getAttributes(), $this->capacity->publicQuota(), $this->payments()];
        foreach (['LULUS', 'BELUM'] as $status) {
            $this->update($user, $status)->assertUnprocessable()->assertJsonValidationErrors('status');
            $this->postJson('/api/admin/kelulusan/bulk', ['user_ids' => [$user->id], 'status' => $status])->assertUnprocessable();
            $this->postJson('/api/admin/kelulusan/bulk', ['updates' => [['user_id' => $user->id, 'status' => $status]]])->assertUnprocessable();
        }
        $this->assertSame($before, [$user->fresh()->getAttributes(), $seat->fresh()->getAttributes(), $this->capacity->publicQuota(), $this->payments()]);
        $this->assertSame(1, PpdbSeatAllocation::count());
    }

    public function test_invalid_mixed_batch_rolls_back_all_other_valid_requests(): void
    {
        [$a, $seatA] = $this->candidate();
        [$b, $seatB] = $this->candidate();
        $this->update($b, 'TIDAK_LULUS')->assertOk();
        $before = [$a->fresh()->getAttributes(), $seatA->fresh()->getAttributes(), $seatB->fresh()->getAttributes(), $this->capacity->publicQuota(), $this->payments()];
        $this->postJson('/api/admin/kelulusan/bulk', ['updates' => [
            ['user_id' => $a->id, 'status' => 'TIDAK_LULUS'], ['user_id' => $b->id, 'status' => 'LULUS'],
        ]])->assertUnprocessable();
        $this->assertSame($before, [$a->fresh()->getAttributes(), $seatA->fresh()->getAttributes(), $seatB->fresh()->getAttributes(), $this->capacity->publicQuota(), $this->payments()]);
        // The homogeneous bulk form cannot silently ignore an invalid reversal either.
        $this->postJson('/api/admin/kelulusan/bulk', ['user_ids' => [$a->id, $b->id], 'status' => 'LULUS'])->assertUnprocessable();
        $this->assertSame('BELUM', $a->fresh()->status_kelulusan);
    }

    public function test_status_write_failure_rolls_back_an_already_attempted_seat_release(): void
    {
        [$user, $seat] = $this->candidate();
        $before = [$user->fresh()->getAttributes(), $seat->fresh()->getAttributes(), $this->capacity->publicQuota(), $this->payments()];
        $dispatcher = User::getEventDispatcher();
        User::setEventDispatcher(clone $dispatcher);
        User::updating(fn () => throw new \RuntimeException('Simulated status persistence failure'));
        try {
            app(\App\Services\KelulusanLifecycleService::class)->transition([$user->id => 'TIDAK_LULUS']);
            $this->fail('Simulated persistence failure must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated status persistence failure', $e->getMessage());
        } finally {
            User::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, [$user->fresh()->getAttributes(), $seat->fresh()->getAttributes(), $this->capacity->publicQuota(), $this->payments()]);
    }

    public function test_missing_seat_cannot_be_marked_lulus_and_is_not_silently_repaired(): void
    {
        $user = User::factory()->create(['jenjang' => 'SD']);
        $user->forceFill(['role' => 'orang_tua'])->save();
        foreach (['LULUS', 'BELUM', 'TIDAK_LULUS'] as $status) {
            $this->update($user, $status)->assertUnprocessable();
        }
        $this->assertSame('BELUM', $user->fresh()->status_kelulusan);
        $this->assertDatabaseCount('ppdb_seat_allocations', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_arbitrary_release_is_blocked_in_domain_even_after_release(): void
    {
        [$user, $seat] = $this->candidate();
        foreach (['ADMIN_RELEASE', 'OTHER', 'WITHDRAWN', 'DAFTAR_ULANG_WITHDRAWAL'] as $reason) {
            try {
                $this->capacity->releaseSeat($seat, $reason);
                $this->fail('Reason must not be enabled: '.$reason);
            } catch (CapacityException $e) {
                $this->assertSame(CapacityException::INVALID_RELEASE_REASON, $e->reasonCode);
            }
            $this->assertSame('ALLOCATED', $seat->fresh()->status);
        }
        $this->capacity->releaseSeat($seat, 'NOT_ACCEPTED');
        $this->assertSame('TIDAK_LULUS', $user->fresh()->status_kelulusan);
        $history = $seat->fresh()->getAttributes();
        $this->capacity->releaseSeatForUser($user->id, 'NOT_ACCEPTED');
        $this->assertSame($history, $seat->fresh()->getAttributes());
    }

    public function test_historical_allocations_and_their_old_reasons_are_never_rewritten(): void
    {
        $user = User::factory()->create(['jenjang' => 'SD']);
        $user->forceFill(['role' => 'orang_tua'])->save();
        $old = PpdbSeatAllocation::create(['user_id' => $user->id, 'jenjang' => 'SD', 'status' => 'RELEASED',
            'allocated_at' => now()->subYear(), 'released_at' => now()->subMonth(), 'release_reason' => 'OTHER']);
        $current = PpdbSeatAllocation::create(['user_id' => $user->id, 'jenjang' => 'SD', 'status' => 'ALLOCATED', 'allocated_at' => now()]);
        $before = $old->fresh()->getAttributes();
        $this->update($user, 'TIDAK_LULUS')->assertOk();
        $this->update($user, 'TIDAK_LULUS')->assertOk();
        $this->assertSame($before, $old->fresh()->getAttributes());
        $this->assertSame('RELEASED', $current->fresh()->status);
        $this->assertDatabaseCount('ppdb_seat_allocations', 2);
    }

    public function test_parent_and_guru_cannot_trigger_single_bulk_or_release(): void
    {
        [$user, $seat] = $this->candidate();
        $before = [$user->fresh()->getAttributes(), $seat->fresh()->getAttributes(), $this->payments()];
        foreach (['orang_tua', 'guru'] as $role) {
            $caller = $role === 'orang_tua' ? $user : User::factory()->create();
            $caller->forceFill(['role' => $role])->save();
            Sanctum::actingAs($caller);
            $this->update($user, 'TIDAK_LULUS')->assertForbidden();
            $this->postJson('/api/admin/kelulusan/bulk', ['user_ids' => [$user->id], 'status' => 'TIDAK_LULUS'])->assertForbidden();
            $this->postJson('/api/admin/seat-allocations/'.$seat->id.'/release', ['reason' => 'NOT_ACCEPTED'])->assertForbidden();
        }
        $this->assertSame($before, [$user->fresh()->getAttributes(), $seat->fresh()->getAttributes(), $this->payments()]);
    }

    public function test_super_admin_retains_single_and_bulk_kelulusan_authorization(): void
    {
        [$a, $seatA] = $this->candidate();
        [$b, $seatB] = $this->candidate();
        $super = User::factory()->create();
        $super->forceFill(['role' => 'super_admin'])->save();
        Sanctum::actingAs($super);
        $this->update($a, 'LULUS')->assertOk();
        $this->assertSame('ALLOCATED', $seatA->fresh()->status);
        $this->postJson('/api/admin/kelulusan/bulk', ['user_ids' => [$a->id, $b->id], 'status' => 'TIDAK_LULUS'])->assertOk();
        $this->assertSame('RELEASED', $seatA->fresh()->status);
        $this->assertSame('RELEASED', $seatB->fresh()->status);
        $this->postJson('/api/admin/seat-allocations/'.$seatA->id.'/release', ['reason' => 'NOT_ACCEPTED'])->assertStatus(409);
    }

    public function test_bulk_persistence_failure_rolls_back_prior_students_and_releases(): void
    {
        [$a, $seatA] = $this->candidate();
        [$b, $seatB] = $this->candidate();
        $before = [$a->fresh()->getAttributes(), $b->fresh()->getAttributes(),
            $seatA->fresh()->getAttributes(), $seatB->fresh()->getAttributes(), $this->capacity->publicQuota(), $this->payments()];
        $dispatcher = User::getEventDispatcher();
        User::setEventDispatcher(clone $dispatcher);
        User::updating(function (User $user) use ($b) {
            if ($user->id === $b->id) {
                throw new \RuntimeException('Second student write failed');
            }
        });
        try {
            app(\App\Services\KelulusanLifecycleService::class)->transition([$a->id => 'TIDAK_LULUS', $b->id => 'TIDAK_LULUS']);
            $this->fail('The complete batch must roll back.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Second student write failed', $e->getMessage());
        } finally {
            User::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes(),
            $seatA->fresh()->getAttributes(), $seatB->fresh()->getAttributes(), $this->capacity->publicQuota(), $this->payments()]);
    }

    public function test_bulk_validation_rejects_duplicates_empty_batches_and_non_parent_targets_atomically(): void
    {
        [$user, $seat] = $this->candidate();
        foreach ([['user_ids' => [], 'status' => 'TIDAK_LULUS'],
            ['user_ids' => [$user->id, $user->id], 'status' => 'TIDAK_LULUS'],
            ['updates' => []], ['updates' => [['user_id' => $user->id, 'status' => 'RESMI']]],
            ['updates' => [['user_id' => $user->id, 'status' => 'LULUS']], 'user_ids' => [$user->id], 'status' => 'LULUS'],
        ] as $payload) {
            $this->postJson('/api/admin/kelulusan/bulk', $payload)->assertUnprocessable();
        }
        $guru = User::factory()->create();
        $guru->forceFill(['role' => 'guru'])->save();
        $this->postJson('/api/admin/kelulusan/bulk', ['user_ids' => [$user->id, $guru->id], 'status' => 'TIDAK_LULUS'])->assertNotFound();
        $this->assertSame('BELUM', $user->fresh()->status_kelulusan);
        $this->assertSame('ALLOCATED', $seat->fresh()->status);
    }
}
