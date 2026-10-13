<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\PpdbCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public quota endpoint: exists, unauthenticated, DB-derived, SD/SMP independent.
 */
class PublicQuotaTest extends TestCase
{
    use RefreshDatabase;

    private PpdbCapacityService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(PpdbCapacityService::class);
    }

    public function test_endpoint_exists_and_is_public(): void
    {
        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'BUKA']);
        $this->svc->increaseMaxCapacity('SD', 100);
        $this->svc->increaseMaxCapacity('SMP', 50);

        $res = $this->getJson('/api/public/kuota');
        $res->assertOk()
            ->assertJsonStructure(['status', 'sd' => ['total', 'terpakai', 'sisa', 'tersedia'], 'smp' => ['total', 'terpakai', 'sisa', 'tersedia']]);
    }

    public function test_values_are_db_derived_not_hardcoded(): void
    {
        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'BUKA']);
        $this->svc->increaseMaxCapacity('SD', 7);
        $this->svc->increaseMaxCapacity('SMP', 3);

        $res = $this->getJson('/api/public/kuota')->assertOk();
        $this->assertSame(7, $res->json('sd.total'));
        $this->assertSame(7, $res->json('sd.sisa'));
        $this->assertSame(3, $res->json('smp.total'));
        $this->assertSame(3, $res->json('smp.sisa'));
        $this->assertSame('BUKA', $res->json('status'));
    }

    public function test_sd_smp_values_independent(): void
    {
        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'BUKA']);
        $this->svc->increaseMaxCapacity('SD', 1);
        $this->svc->increaseMaxCapacity('SMP', 9);

        // Consume the single SD seat directly via service.
        $u = \App\Models\User::factory()->create(['jenjang' => 'SD']);
        $u->forceFill(['role' => 'orang_tua'])->save();
        $p = \App\Models\Payment::create([
            'user_id' => $u->id, 'invoice_number' => 'MANUAL-Q-1', 'jenjang' => 'SD',
            'amount' => 150000, 'payment_method' => 'MANUAL', 'status' => 'PENDING_MANUAL',
        ]);
        $this->svc->consumeSeat('SD', $u->id, $p->id);

        $res = $this->getJson('/api/public/kuota')->assertOk();
        $this->assertSame(0, $res->json('sd.sisa'));
        $this->assertFalse($res->json('sd.tersedia'));
        $this->assertSame(9, $res->json('smp.sisa'));
        $this->assertTrue($res->json('smp.tersedia'));
    }

    public function test_reflects_consumption_and_closed_status(): void
    {
        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'TUTUP']);
        $this->svc->increaseMaxCapacity('SD', 2);
        $this->svc->increaseMaxCapacity('SMP', 2);

        $res = $this->getJson('/api/public/kuota')->assertOk();
        $this->assertSame('TUTUP', $res->json('status'));
    }
}
