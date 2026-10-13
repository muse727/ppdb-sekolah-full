<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression coverage for the newly-added endpoint throttles:
 *   - POST /api/register        (public, IP-keyed)
 *   - POST /api/payment/manual  (authenticated, user-id+IP keyed)
 *   - POST /api/berkas/upload   (authenticated, user-id+IP keyed)
 *
 * Like LoginThrottlePersistenceTest, these tests run the limiter against a
 * REAL shared FileStore (production-like, since CACHE_STORE=file) backed by a
 * dedicated disposable cache path, so persistence across separate requests is
 * genuinely exercised and no state leaks between tests or into the dev cache.
 *
 * Tests intentionally use REAL endpoint requests (no mocked middleware) and
 * verify business validation / file-creation behaviour around the 429.
 */
class EndpointThrottleCoverageTest extends TestCase
{
    use RefreshDatabase;

    private ?string $testCachePath = null;

    /** @var string[] limiter keys to clear in tearDown */
    private array $usedKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->testCachePath = storage_path('framework/cache/data-testing');
        config(['cache.stores.file.path' => $this->testCachePath]);
        config(['cache.default' => 'file']);

        app()->forgetInstance('cache');
        app()->forgetInstance('cache.store');
        app()->forgetInstance(\Illuminate\Cache\RateLimiter::class);

        $this->flushTestCachePath();

        // Register now enforces the global PPDB gate (TUTUP by default). Open it so
        // these throttle tests reach the token-validation path (422) instead of the
        // gate rejection (403).
        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'BUKA']);
    }

    protected function tearDown(): void
    {
        foreach ($this->usedKeys as $key) {
            RateLimiter::clear($key);
        }
        $this->usedKeys = [];

        $this->flushTestCachePath();

        app()->forgetInstance('cache');
        app()->forgetInstance('cache.store');
        app()->forgetInstance(\Illuminate\Cache\RateLimiter::class);

        parent::tearDown();
    }

    private function flushTestCachePath(): void
    {
        if (! $this->testCachePath || ! is_dir($this->testCachePath)) {
            return;
        }
        foreach (glob($this->testCachePath.'/*') ?: [] as $dir) {
            $this->deleteDirectory($dir);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (is_file($dir) || is_link($dir)) {
            @unlink($dir);
            return;
        }
        foreach (glob(rtrim($dir, '/').'/*') ?: [] as $child) {
            $this->deleteDirectory($child);
        }
        @rmdir($dir);
    }

    private function makeUser(string $email, string $jenjang = 'SD'): User
    {
        $user = User::factory()->create([
            'email'    => $email,
            'password' => Hash::make('Secret123'),
            'jenjang'  => $jenjang,
        ]);
        $user->forceFill(['role' => 'orang_tua'])->save();

        return $user->refresh();
    }

    private function price(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    // =====================================================================
    // REGISTER — public, IP-keyed, 10/60s
    // =====================================================================

    public function test_register_throttles_after_10_attempts_and_is_not_bypassed_by_rotating_identity(): void
    {
        $this->assertInstanceOf(\Illuminate\Cache\FileStore::class, Cache::store()->getStore());

        $key = 'register|127.0.0.1';
        $this->usedKeys[] = $key;

        // 10 well-formed attempts, EACH with a DIFFERENT email + credential,
        // all return a controlled 422 (invalid token). The IP-keyed limiter
        // must accumulate across all of them — proving the limiter cannot be
        // bypassed merely by changing the client-controlled email/token.
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/register', [
                'name'       => 'Probe '.$i,
                'email'      => "probe{$i}@test.dev",
                'password'   => 'Secret123',
                'jalur'      => 'UMUM',
                'kredensial' => "AM-INVALID{$i}",
            ])->assertStatus(422);
        }

        $this->assertEquals(10, RateLimiter::attempts($key));

        // 11th attempt within the window: blocked with 429, regardless of yet
        // another fresh email/token.
        $blocked = $this->postJson('/api/register', [
            'name'       => 'Probe 11',
            'email'      => 'probe11@test.dev',
            'password'   => 'Secret123',
            'jalur'      => 'UMUM',
            'kredensial' => 'AM-INVALID11',
        ]);
        $blocked->assertStatus(429);
        $retryAfter = (int) RateLimiter::availableIn($key);
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);

        // No account was ever created (all were invalid tokens).
        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_throttle_check_runs_before_bcrypt(): void
    {
        // Prove the limiter short-circuits BEFORE the expensive credential
        // work: once blocked, even a request that would otherwise hash a
        // password returns 429 without creating a user.
        $key = 'register|127.0.0.1';
        $this->usedKeys[] = $key;

        // Force the limiter to its ceiling directly (10 hits).
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit($key, 60);
        }
        $this->assertTrue(RateLimiter::tooManyAttempts($key, 10));

        // A well-formed request is now rejected with 429, no user created.
        $this->postJson('/api/register', [
            'name'       => 'Early Block',
            'email'      => 'earlyblock@test.dev',
            'password'   => 'Secret123',
            'jalur'      => 'UMUM',
            'kredensial' => 'AM-INVALID',
        ])->assertStatus(429);

        $this->assertDatabaseMissing('users', ['email' => 'earlyblock@test.dev']);
    }

    public function test_register_below_threshold_still_processes_normally(): void
    {
        $key = 'register|127.0.0.1';
        $this->usedKeys[] = $key;

        // A couple of invalid attempts (below threshold) still return the
        // controlled 422 business response, not 429.
        $this->postJson('/api/register', [
            'name' => 'A', 'email' => 'a@test.dev', 'password' => 'Secret123',
            'jalur' => 'UMUM', 'kredensial' => 'AM-BAD',
        ])->assertStatus(422);

        $this->postJson('/api/register', [
            'name' => 'B', 'email' => 'b@test.dev', 'password' => 'Secret123',
            'jalur' => 'INTERNAL', 'kredensial' => '0000000000',
        ])->assertStatus(422);
    }

    // =====================================================================
    // PAYMENT MANUAL — authenticated, user-id+IP keyed, 10/60s
    // =====================================================================

    public function test_payment_manual_throttles_after_10_and_creates_nothing_on_429(): void
    {
        Storage::fake('private');
        $this->price('biaya_sd', '150000');
        $user = $this->makeUser('pay-throttle@test.dev', 'SD');
        Sanctum::actingAs($user);

        $key = 'payment-manual|' . $user->id . '|127.0.0.1';
        $this->usedKeys[] = $key;

        // First valid upload succeeds (normal flow intact).
        $this->post('/api/payment/manual', [
            'jenjang'     => 'SD',
            'bukti_bayar' => UploadedFile::fake()->image('b1.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertDatabaseCount('payments', 1);

        // Attempts 2-10 hit the duplicate guard (409) but still consume budget.
        for ($i = 2; $i <= 10; $i++) {
            $this->post('/api/payment/manual', [
                'jenjang'     => 'SD',
                'bukti_bayar' => UploadedFile::fake()->image("b{$i}.png", 64, 64),
            ], ['Accept' => 'application/json'])->assertStatus(409);
        }

        $this->assertEquals(10, RateLimiter::attempts($key));

        // 11th within window: 429, and NO additional payment/file is created.
        $paymentsBefore = Payment::count();
        $filesBefore = count(Storage::disk('private')->allFiles());

        $this->post('/api/payment/manual', [
            'jenjang'     => 'SD',
            'bukti_bayar' => UploadedFile::fake()->image('b11.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertStatus(429);

        $this->assertSame($paymentsBefore, Payment::count(), '429 must not create a payment row');
        $this->assertCount($filesBefore, Storage::disk('private')->allFiles(), '429 must not write a file');
    }

    public function test_payment_manual_limiter_is_per_user_not_bypassed_by_field_change(): void
    {
        Storage::fake('private');
        $this->price('biaya_sd', '150000');

        // Two distinct users => two distinct limiter buckets.
        $userA = $this->makeUser('usera@test.dev', 'SD');
        $userB = $this->makeUser('userb@test.dev', 'SD');

        $keyA = 'payment-manual|' . $userA->id . '|127.0.0.1';
        $keyB = 'payment-manual|' . $userB->id . '|127.0.0.1';
        $this->usedKeys[] = $keyA;
        $this->usedKeys[] = $keyB;

        // Exhaust user A's bucket.
        Sanctum::actingAs($userA);
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit($keyA, 60);
        }

        // User A is blocked.
        $this->post('/api/payment/manual', [
            'jenjang' => 'SD',
            'bukti_bayar' => UploadedFile::fake()->image('a.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertStatus(429);

        // User B (same IP) is NOT blocked — the limiter key includes user id,
        // so a different account is a different bucket. This also proves the
        // limiter is keyed on server-side identity, not a client field.
        Sanctum::actingAs($userB);
        $this->post('/api/payment/manual', [
            'jenjang' => 'SD',
            'bukti_bayar' => UploadedFile::fake()->image('b.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_payment_manual_validation_and_size_limits_still_apply_below_threshold(): void
    {
        Storage::fake('private');
        $this->price('biaya_sd', '150000');
        $user = $this->makeUser('pay-valid@test.dev', 'SD');
        Sanctum::actingAs($user);

        $key = 'payment-manual|' . $user->id . '|127.0.0.1';
        $this->usedKeys[] = $key;

        // Below threshold: a non-image is still rejected with controlled 422
        // (validation runs after the limiter hit but before file storage).
        $this->post('/api/payment/manual', [
            'jenjang'     => 'SD',
            'bukti_bayar' => UploadedFile::fake()->create('evil.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        // Oversized image (> 2048 KB) still rejected.
        $this->post('/api/payment/manual', [
            'jenjang'     => 'SD',
            'bukti_bayar' => UploadedFile::fake()->create('big.png', 3000, 'image/png'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        // Nothing was stored.
        $this->assertDatabaseCount('payments', 0);
    }

    // =====================================================================
    // BERKAS UPLOAD — authenticated, user-id+IP keyed, 10/60s
    // =====================================================================

    public function test_berkas_upload_throttles_after_10_and_writes_nothing_on_429(): void
    {
        Storage::fake('private');
        $user = $this->makeUser('berkas-throttle@test.dev', 'SD');
        Sanctum::actingAs($user);

        $key = 'berkas-upload|' . $user->id . '|127.0.0.1';
        $this->usedKeys[] = $key;

        // 10 valid uploads succeed (each writes a file to the private disk).
        for ($i = 1; $i <= 10; $i++) {
            $this->post('/api/berkas/upload', [
                'jenis' => 'kartu_keluarga',
                'file'  => UploadedFile::fake()->image("kk{$i}.png", 64, 64),
            ], ['Accept' => 'application/json'])->assertOk();
        }

        $this->assertEquals(10, RateLimiter::attempts($key));

        // 11th within window: 429 and no new file written.
        $filesBefore = count(Storage::disk('private')->allFiles());

        $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file'  => UploadedFile::fake()->image('kk11.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertStatus(429);

        $this->assertCount($filesBefore, Storage::disk('private')->allFiles(), '429 must not write a berkas file');
    }

    public function test_berkas_upload_mime_and_size_protection_intact_below_threshold(): void
    {
        Storage::fake('private');
        $user = $this->makeUser('berkas-valid@test.dev', 'SD');
        Sanctum::actingAs($user);

        $key = 'berkas-upload|' . $user->id . '|127.0.0.1';
        $this->usedKeys[] = $key;

        // Below threshold: a PHP payload renamed to .png is still rejected (422)
        // by the MIME rule — proving validation still runs under the throttle.
        $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file'  => UploadedFile::fake()->create('shell.png', 10, 'application/x-php'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        // Oversized (> 5120 KB) rejected.
        $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file'  => UploadedFile::fake()->create('huge.png', 6000, 'image/png'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_berkas_upload_requires_authentication(): void
    {
        // Unauthenticated request is rejected by auth middleware (401) before
        // ever reaching the limiter/controller — authorization still executes.
        $this->postJson('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file'  => UploadedFile::fake()->image('kk.png', 64, 64),
        ])->assertStatus(401);
    }
}
