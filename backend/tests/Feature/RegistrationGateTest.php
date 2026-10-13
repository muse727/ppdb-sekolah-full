<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\PpdbCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Registration gate: global ppdb_status + per-jenjang capacity.
 * Verifies the backend blocks registration when closed/full, and that a
 * blocked registration NEVER consumes the token/whitelist credential.
 */
class RegistrationGateTest extends TestCase
{
    use RefreshDatabase;

    private PpdbCapacityService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(PpdbCapacityService::class);
        RateLimiter::clear('register|127.0.0.1');
        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'BUKA']);
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('register|127.0.0.1');
        parent::tearDown();
    }

    private function openGate(): void
    {
        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'BUKA']);
    }

    private function closeGate(): void
    {
        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'TUTUP']);
    }

    private function makeToken(string $code): void
    {
        DB::table('tokens')->insert(['code' => $code, 'is_used' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function payload(string $cred, string $email): array
    {
        return ['name' => 'Calon', 'email' => $email, 'password' => 'Secret123', 'jalur' => 'UMUM', 'kredensial' => $cred];
    }

    public function test_open_gate_allows_registration(): void
    {
        $this->openGate();
        $this->svc->increaseMaxCapacity('SD', 5);
        $this->makeToken('AM-ABCDEF');

        $this->postJson('/api/register', $this->payload('AM-ABCDEF', 'a@test.dev'))
            ->assertStatus(200);

        $this->assertDatabaseHas('tokens', ['code' => 'AM-ABCDEF', 'is_used' => true]);
    }

    public function test_closed_gate_blocks_registration_and_preserves_token(): void
    {
        $this->closeGate();
        $this->svc->increaseMaxCapacity('SD', 5);
        $this->makeToken('AM-AAAA11');

        $this->postJson('/api/register', $this->payload('AM-AAAA11', 'b@test.dev'))
            ->assertStatus(403);

        // Token NOT consumed; user NOT created.
        $this->assertDatabaseHas('tokens', ['code' => 'AM-AAAA11', 'is_used' => false]);
        $this->assertDatabaseMissing('users', ['email' => 'b@test.dev']);
    }

    public function test_sd_full_blocks_sd_registration_and_preserves_token(): void
    {
        $this->openGate();
        $this->svc->increaseMaxCapacity('SD', 0); // SD full
        $this->makeToken('AM-SD0001'); // SD-derived (no AM-SMP- substring)

        $this->postJson('/api/register', $this->payload('AM-SD0001', 'sd@test.dev'))
            ->assertStatus(409);

        $this->assertDatabaseHas('tokens', ['code' => 'AM-SD0001', 'is_used' => false]);
        $this->assertDatabaseMissing('users', ['email' => 'sd@test.dev']);
    }

    public function test_smp_remains_usable_when_sd_full(): void
    {
        $this->openGate();
        $this->svc->increaseMaxCapacity('SD', 0);  // SD full
        $this->svc->increaseMaxCapacity('SMP', 5); // SMP open
        $this->makeToken('AM-SMP-XY99');

        $this->postJson('/api/register', $this->payload('AM-SMP-XY99', 'smp@test.dev'))
            ->assertStatus(200);

        $this->assertDatabaseHas('users', ['email' => 'smp@test.dev', 'jenjang' => 'SMP']);
    }

    public function test_smp_full_blocks_smp_but_sd_remains_usable(): void
    {
        $this->openGate();
        $this->svc->increaseMaxCapacity('SMP', 0); // SMP full
        $this->svc->increaseMaxCapacity('SD', 5);  // SD open
        $this->makeToken('AM-SMP-ZZ11');
        $this->makeToken('AM-SD2222');

        // SMP blocked
        $this->postJson('/api/register', $this->payload('AM-SMP-ZZ11', 'smpx@test.dev'))
            ->assertStatus(409);
        $this->assertDatabaseHas('tokens', ['code' => 'AM-SMP-ZZ11', 'is_used' => false]);

        // SD still allowed
        $this->postJson('/api/register', $this->payload('AM-SD2222', 'sdx@test.dev'))
            ->assertStatus(200);
    }

    public function test_direct_post_cannot_bypass_closed_gate(): void
    {
        $this->closeGate();
        $this->svc->increaseMaxCapacity('SD', 100);
        $this->makeToken('AM-DIRECT');

        // No session, raw POST — gate still enforced.
        $this->postJson('/api/register', $this->payload('AM-DIRECT', 'hax@test.dev'))
            ->assertStatus(403);
        $this->assertDatabaseHas('tokens', ['code' => 'AM-DIRECT', 'is_used' => false]);
    }

    public function test_internal_jenjang_capacity_blocks_and_preserves_number(): void
    {
        $this->openGate();
        // Candidate with explicit SD jenjang; make SD full.
        $this->svc->increaseMaxCapacity('SD', 0);
        $number = app(\App\Services\InternalAccessCodeService::class)->generateCode();
        DB::table('whitelists')->insert([
            'nama_siswa' => 'Anak', 'jenjang' => 'SD',
            'asal_sekolah' => 'TK IT Al-Mubarak', 'registration_number' => $number,
            'is_used' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/register', [
            'name' => 'Ortu', 'email' => 'int@test.dev', 'password' => 'Secret123',
            'jalur' => 'INTERNAL', 'kredensial' => 'ignored', 'nomor_internal' => $number,
        ])->assertStatus(409);

        // Credential not consumed
        $this->assertDatabaseHas('whitelists', ['registration_number' => $number, 'is_used' => false]);
    }
}
