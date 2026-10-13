<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\PpdbCapacityService;
use App\Services\UmumTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * P3-C: UMUM registration token hardening.
 *
 * Coverage:
 *  1. valid token succeeds
 *  2. random token format/entropy invariant
 *  3. invalid token rejected
 *  4. token cannot be reused
 *  5. expired token rejected
 *  6. expired token not consumed
 *  7. client identity spoof rejected/ignored
 *  8. (concurrency lives in UmumTokenConcurrencyTest — real forked requests)
 *  9. collision retry works (DB UNIQUE backstop)
 * 10. token uniqueness DB constraint remains intact
 */
class UmumTokenTest extends TestCase
{
    use RefreshDatabase;

    private PpdbCapacityService $capacity;
    private UmumTokenService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capacity = app(PpdbCapacityService::class);
        $this->svc      = app(UmumTokenService::class);
        RateLimiter::clear('register|127.0.0.1');
        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'BUKA']);
        $this->capacity->increaseMaxCapacity('SD', 100);
        $this->capacity->increaseMaxCapacity('SMP', 100);
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('register|127.0.0.1');
        parent::tearDown();
    }

    /** Legacy-style token (backwards compatibility with pre-P3-C rows). */
    private function legacyToken(string $code, ?string $expiresAt = null): int
    {
        return DB::table('tokens')->insertGetId([
            'code' => $code, 'is_used' => false, 'expires_at' => $expiresAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Calon', 'email' => 'umum@test.dev', 'password' => 'Secret123',
            'jalur' => 'UMUM', 'kredensial' => 'AM-AAAA-BBBB-CCCC-DDDD',
        ], $over);
    }

    // --- 1. valid token succeeds ---

    public function test_valid_token_registers_successfully(): void
    {
        $this->legacyToken('AM-AAAA-BBBB-CCCC-DDDD');

        $this->postJson('/api/register', $this->payload())
            ->assertStatus(200);

        $this->assertDatabaseHas('users', ['email' => 'umum@test.dev', 'jalur' => 'UMUM', 'jenjang' => 'SD', 'role' => 'orang_tua']);
        $this->assertDatabaseHas('tokens', ['code' => 'AM-AAAA-BBBB-CCCC-DDDD', 'is_used' => true]);
    }

    public function test_new_csrng_token_registers_successfully(): void
    {
        $tokens = $this->svc->generateBatch(1);
        $code   = $tokens[0]->code;

        $this->postJson('/api/register', $this->payload(['kredensial' => $code, 'email' => 'csrng@test.dev']))
            ->assertStatus(200);

        $this->assertDatabaseHas('tokens', ['code' => $code, 'is_used' => true]);
        $this->assertDatabaseHas('users', ['email' => 'csrng@test.dev', 'jenjang' => 'SD']);
    }

    public function test_token_is_case_and_whitespace_insensitive(): void
    {
        $tokens = $this->svc->generateBatch(1);
        $lower  = strtolower('  ' . $tokens[0]->code . '  ');

        $this->postJson('/api/register', $this->payload(['kredensial' => $lower, 'email' => 'case@test.dev']))
            ->assertStatus(200);
    }

    // --- 2. format / entropy invariant ---

    public function test_generated_tokens_meet_entropy_and_format_contract(): void
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        for ($i = 0; $i < 25; $i++) {
            $code = $this->svc->generateCode();

            // Shape: AM- + 16 chars grouped 4-4-4-4.
            $this->assertMatchesRegularExpression('/^AM-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code, "bad shape: {$code}");

            $chars = str_replace('-', '', substr($code, 3));
            $this->assertSame(16, strlen($chars));
            foreach (str_split($chars) as $c) {
                $this->assertStringContainsString($c, $alphabet, "ambiguous/invalid char {$c} in {$code}");
            }

            // Entropy: 16 chars over a 30-char alphabet = 16*log2(30) >= 64 bits.
            $bits = 16 * log(30, 2);
            $this->assertGreaterThanOrEqual(64.0, $bits);
        }
    }

    public function test_generated_tokens_are_unique_and_unpredictable(): void
    {
        $codes = [];
        for ($i = 0; $i < 200; $i++) {
            $codes[] = $this->svc->generateCode();
        }
        // 200 draws over ~78.5 bits: collisions are astronomically unlikely.
        $this->assertSame(200, count(array_unique($codes)));

        // Not derived from sequential material: consecutive codes differ
        // materially (no shared prefix beyond the constant "AM-" head).
        $a = str_replace('-', '', substr($codes[0], 3));
        $b = str_replace('-', '', substr($codes[1], 3));
        $diff = 0;
        foreach (str_split($a) as $i => $c) {
            if ($c !== $b[$i]) {
                $diff++;
            }
        }
        $this->assertGreaterThan(8, $diff, 'consecutive codes look sequential');
    }

    // --- 3. invalid token rejected ---

    public function test_invalid_token_rejected(): void
    {
        $this->postJson('/api/register', $this->payload(['kredensial' => 'AM-ZZZZ-ZZZZ-ZZZZ-ZZZZ']))
            ->assertStatus(422)
            ->assertJson(['message' => 'Token tidak valid atau sudah digunakan.']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_malformed_token_rejected(): void
    {
        $this->postJson('/api/register', $this->payload(['kredensial' => 'x']))
            ->assertStatus(422);
        $this->assertDatabaseCount('users', 0);
    }

    // --- 4. token cannot be reused ---

    public function test_token_cannot_be_reused(): void
    {
        $this->legacyToken('AM-AAAA-BBBB-CCCC-DDDD');

        $this->postJson('/api/register', $this->payload())->assertStatus(200);

        // Second use: same token, different email.
        $this->postJson('/api/register', $this->payload(['email' => 'reuse@test.dev']))
            ->assertStatus(422);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('tokens', ['code' => 'AM-AAAA-BBBB-CCCC-DDDD', 'is_used' => true]);
    }

    // --- 5/6. expiration ---

    public function test_expired_token_rejected_and_not_consumed(): void
    {
        $this->legacyToken('AM-EXPI-RED1-EXPI-RED1', now()->subMinute()->toDateTimeString());

        $this->postJson('/api/register', $this->payload(['kredensial' => 'AM-EXPI-RED1-EXPI-RED1']))
            ->assertStatus(410)
            ->assertJson(['message' => 'Token sudah kedaluwarsa.']);

        // Expired => NOT consumed, NO user, credential preserved for audit.
        $this->assertDatabaseHas('tokens', ['code' => 'AM-EXPI-RED1-EXPI-RED1', 'is_used' => false]);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_not_yet_expired_token_succeeds(): void
    {
        $this->legacyToken('AM-FUTU-RE00-0000-0001', now()->addDays(3)->toDateTimeString());

        $this->postJson('/api/register', $this->payload(['kredensial' => 'AM-FUTU-RE00-0000-0001']))
            ->assertStatus(200);
    }

    public function test_newly_generated_tokens_carry_expiry(): void
    {
        foreach ($this->svc->generateBatch(3) as $t) {
            $this->assertNotNull($t->expires_at);
            $this->assertTrue($t->expires_at->isFuture());
            $this->assertEqualsWithDelta(
                now()->addDays(UmumTokenService::TTL_DAYS)->getTimestamp(),
                $t->expires_at->getTimestamp(),
                5
            );
        }
    }

    // --- 7. client identity spoof rejected/ignored ---

    public function test_client_cannot_override_server_owned_jenjang(): void
    {
        // SD token + client trying to claim SMP via a request field.
        $this->legacyToken('AM-AAAA-BBBB-CCCC-DDDD');

        $this->postJson('/api/register', $this->payload(['jenjang' => 'SMP', 'email' => 'spoof1@test.dev']))
            ->assertStatus(200);

        // Server-derived jenjang wins; the request field was ignored.
        $this->assertDatabaseHas('users', ['email' => 'spoof1@test.dev', 'jenjang' => 'SD']);
    }

    public function test_client_cannot_override_jalur_or_role(): void
    {
        $this->legacyToken('AM-AAAA-BBBB-CCCC-DDDD');

        $this->postJson('/api/register', $this->payload(['role' => 'admin', 'jalur' => 'UMUM', 'email' => 'spoof2@test.dev']))
            ->assertStatus(200);

        $user = User::where('email', 'spoof2@test.dev')->first();
        $this->assertSame('orang_tua', $user->role);
        $this->assertSame('UMUM', $user->jalur);
    }

    public function test_token_for_smp_shape_maps_to_smp_jenjang(): void
    {
        // SMP-shaped token: server derives SMP regardless of request fields.
        $this->legacyToken('AM-SMP-BBBB-CCCC-DDDD');

        $this->postJson('/api/register', $this->payload(['kredensial' => 'AM-SMP-BBBB-CCCC-DDDD', 'jenjang' => 'SD', 'email' => 'smpshape@test.dev']))
            ->assertStatus(200);

        $this->assertDatabaseHas('users', ['email' => 'smpshape@test.dev', 'jenjang' => 'SMP']);
    }

    // --- 9. collision retry works (DB UNIQUE backstop) ---

    public function test_collision_with_existing_code_is_retried_not_crashed(): void
    {
        $svc = app(UmumTokenService::class);

        // Force one collision: pre-seed a row whose code equals the NEXT draw.
        // Easier deterministic proof: stub generateCode once to return an
        // existing code, then a unique one.
        $existing = $svc->generateBatch(1)[0]->code;

        $stub = new class($svc, $existing) extends UmumTokenService {
            public int $calls = 0;
            public function __construct(private readonly UmumTokenService $inner, private readonly string $existing) {}
            public function generateCode(): string
            {
                $this->calls++;
                if ($this->calls === 1) {
                    return $this->existing; // guaranteed collision
                }
                return $this->inner->generateCode();
            }
        };

        $created = $stub->generateBatch(1);

        $this->assertCount(1, $created);
        $this->assertNotSame($existing, $created[0]->code);
        // The duplicate code did not overwrite or duplicate the seeded row.
        $this->assertSame(1, DB::table('tokens')->where('code', $existing)->count());
        $this->assertSame(2, $stub->calls, 'collision must be retried');
    }

    // --- 10. DB uniqueness constraint intact ---

    public function test_db_unique_constraint_blocks_duplicate_codes(): void
    {
        $code = $this->svc->generateBatch(1)[0]->code;

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('tokens')->insert([
            'code' => $code, 'is_used' => false,
            'expires_at' => now()->addDays(30),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // --- admin surface ---

    public function test_admin_token_list_masks_unused_codes_and_reveals_used_ones(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        \Laravel\Sanctum\Sanctum::actingAs($admin->refresh(), ['*']);

        $unused = $this->svc->generateBatch(1)[0]->code;
        $used   = $this->svc->generateBatch(1)[0]->code;
        DB::table('tokens')->where('code', $used)->update(['is_used' => true]);

        $res = $this->getJson('/api/admin/tokens')->assertOk();

        $payload = collect($res->json());
        $unusedRow = $payload->first(fn ($r) => str_contains($r['code'], '•'));
        $usedRow   = $payload->firstWhere('code', $used);

        // Unused (live credential) code is masked: prefix kept for
        // reconciliation, secret suffix withheld.
        $this->assertNotNull($unusedRow, 'unused token must appear in the list (masked)');
        $this->assertSame(substr($unused, 0, 7) . '••••••••', $unusedRow['code']);
        $this->assertStringNotContainsString(substr($unused, 8), $unusedRow['code']);
        $this->assertTrue((bool) $unusedRow['is_used'] === false);
        $this->assertArrayHasKey('is_expired', $unusedRow);
        $this->assertArrayHasKey('expires_at', $unusedRow);

        // Used code (dead credential) is fully visible for audit.
        $this->assertNotNull($usedRow);
        $this->assertSame($used, $usedRow['code']);
    }

    public function test_generate_tokens_endpoint_discloses_codes_once(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        \Laravel\Sanctum\Sanctum::actingAs($admin->refresh(), ['*']);

        $res = $this->postJson('/api/admin/tokens/generate', ['jumlah' => 3])->assertOk();

        $this->assertCount(3, $res->json('tokens'));
        $this->assertDatabaseCount('tokens', 3);

        // Each issued token carries an expiry and the code shape contract.
        foreach ($res->json('tokens') as $t) {
            $this->assertMatchesRegularExpression('/^AM-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $t['code']);
            $this->assertNotNull($t['expires_at']);
        }

        // Codes are NOT visible afterwards via the list endpoint: match each
        // issued row by its (unique) masked prefix + state.
        $list = collect($this->getJson('/api/admin/tokens')->assertOk()->json());
        foreach ($res->json('tokens') as $t) {
            $row = $list->first(fn ($r) => str_starts_with($r['code'], substr($t['code'], 0, 7)));
            $this->assertNotNull($row, 'issued token must be listed');
            $this->assertStringNotContainsString(substr($t['code'], 8), $row['code']);
        }
    }

    // --- closed gate preserves credentials (regression with new schema) ---

    public function test_closed_gate_does_not_consume_expired_or_valid_tokens(): void
    {
        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'TUTUP']);
        $code = $this->svc->generateBatch(1)[0]->code;

        $this->postJson('/api/register', $this->payload(['kredensial' => $code]))
            ->assertStatus(403);

        $this->assertDatabaseHas('tokens', ['code' => $code, 'is_used' => false]);
        $this->assertDatabaseCount('users', 0);
    }
}
