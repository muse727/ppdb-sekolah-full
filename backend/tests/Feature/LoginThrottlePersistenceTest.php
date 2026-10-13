<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Regression coverage for the cache-backed login RateLimiter being PERSISTENT
 * across separate HTTP requests.
 *
 * Root cause: CACHE_STORE=array makes the cache (and therefore the
 * RateLimiter state) live only for the lifetime of a single PHP process /
 * request. With `php artisan serve` each HTTP request is a fresh process, so
 * the attempt counter was reset on every request and the limiter NEVER
 * engaged (attempt 6+ still returned 401 instead of 429).
 *
 * Fix: CACHE_STORE=file (a persistent, shared store for this single-server
 * deployment).
 *
 * These tests run the limiter against a REAL, shared FileStore (not the
 * ephemeral `array` store and NOT a mocked middleware) and hit the REAL
 * /api/login endpoint across multiple separate `$this->post()` requests so
 * the persistence guarantee is exercised end-to-end. A dedicated cache path
 * (storage/framework/cache/data-testing) is used so the suite does not touch
 * the developer's real cache, and each test key is cleared afterwards so no
 * state leaks between tests.
 */
class LoginThrottlePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = ['Origin' => 'http://localhost:3000', 'Accept' => 'application/json'];

    /**
     * The dedicated file-cache path used only while this test runs.
     *
     * @var string|null
     */
    private ?string $testCachePath = null;

    /**
     * Throttle keys (email|ip) touched by each test, cleared in tearDown so
     * no rate-limit state leaks between tests.
     *
     * @var string[]
     */
    private array $usedKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Point the `file` cache store at a dedicated, disposable path and
        // force the limiter to use a REAL shared FileStore. RefreshDatabase
        // only rolls back DB state, not the file cache, so isolation is
        // handled explicitly via per-test keys + tearDown cleanup.
        $this->testCachePath = storage_path('framework/cache/data-testing');
        config(['cache.stores.file.path' => $this->testCachePath]);
        config(['cache.default' => 'file']);

        // Rebind the default cache store + the RateLimiter so both resolve a
        // fresh FileStore against the test path (the container may already
        // have resolved the store for this app instance).
        app()->forgetInstance('cache');
        app()->forgetInstance('cache.store');
        app()->forgetInstance(\Illuminate\Cache\RateLimiter::class);

        // Start every test from a clean slate.
        $this->flushTestCachePath();
    }

    protected function tearDown(): void
    {
        // Clear every throttle key this test used so no state leaks.
        foreach ($this->usedKeys as $key) {
            RateLimiter::clear($key);
        }
        $this->usedKeys = [];

        $this->flushTestCachePath();

        // Restore the container's default cache bindings.
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

    private function makeUser(string $email): User
    {
        $user = User::factory()->create([
            'email'    => $email,
            'password' => Hash::make('CorrectPass123'),
            'jenjang'  => 'SD',
        ]);
        $user->forceFill(['role' => 'orang_tua'])->save();

        return $user->refresh();
    }

    /** The throttle key the controller computes for the given email + test ip. */
    private function throttleKey(string $email): string
    {
        return strtolower($email).'|127.0.0.1';
    }

    /** Track a key for tearDown cleanup and return it. */
    private function track(string $email): string
    {
        $key = $this->throttleKey($email);
        $this->usedKeys[] = $key;

        return $key;
    }

    public function test_limiter_persists_across_separate_requests(): void
    {
        // Prove the limiter is actually backed by a persistent FileStore for
        // this test (i.e. NOT the ephemeral ArrayStore). Cache::store() with
        // no argument resolves the default store we set in setUp().
        $this->assertInstanceOf(
            \Illuminate\Cache\FileStore::class,
            \Illuminate\Support\Facades\Cache::store()->getStore()
        );

        $email = 'persist@test.dev';
        $this->makeUser($email);
        $this->track($email);

        // Requests 1-5: each is a SEPARATE HTTP request handled by the real
        // endpoint. Each wrong password returns a controlled generic 401 and
        // consumes one attempt. If the cache were still `array`, the counter
        // would reset per request and none of this state would accumulate.
        for ($i = 1; $i <= 5; $i++) {
            $this->withHeaders(self::ORIGIN)
                ->post('/api/login', ['email' => $email, 'password' => 'WrongPass'])
                ->assertStatus(401)
                ->assertJson(['message' => 'Email atau password salah.']);
        }

        // After 5 attempts within the window the limiter is engaged.
        $this->assertEquals(5, RateLimiter::attempts($this->throttleKey($email)));

        // Request 6 within the window: blocked with HTTP 429.
        $blocked = $this->withHeaders(self::ORIGIN)
            ->post('/api/login', ['email' => $email, 'password' => 'WrongPass']);
        $blocked->assertStatus(429);

        // Retry-after information is present and valid (1..60 seconds).
        $retryAfter = (int) RateLimiter::availableIn($this->throttleKey($email));
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
        $this->assertStringContainsString((string) $retryAfter, $blocked->json('message'));

        // A further attempt within the window is still blocked (429), proving
        // the block state persists across yet another separate request.
        $this->withHeaders(self::ORIGIN)
            ->post('/api/login', ['email' => $email, 'password' => 'WrongPass'])
            ->assertStatus(429);
    }

    public function test_limiter_unblocks_after_window_expires(): void
    {
        $email = 'window-expiry@test.dev';
        $this->makeUser($email);
        $this->track($email);

        // Exhaust the 5 attempts.
        for ($i = 1; $i <= 5; $i++) {
            $this->withHeaders(self::ORIGIN)
                ->post('/api/login', ['email' => $email, 'password' => 'WrongPass'])
                ->assertStatus(401);
        }

        // 6th is blocked.
        $this->withHeaders(self::ORIGIN)
            ->post('/api/login', ['email' => $email, 'password' => 'WrongPass'])
            ->assertStatus(429);

        // Travel past the 60-second decay window. Carbon::setTestNow drives
        // the FileStore's expiry check, so the persisted entries now count as
        // expired even though they live on disk (real time-travel of the
        // persistent store, not a cleared mock).
        $this->travel(61)->seconds();

        // The next attempt is no longer blocked — it returns the controlled
        // 401 (wrong password) rather than 429.
        $this->withHeaders(self::ORIGIN)
            ->post('/api/login', ['email' => $email, 'password' => 'WrongPass'])
            ->assertStatus(401)
            ->assertJson(['message' => 'Email atau password salah.']);
    }

    public function test_successful_login_clears_throttle_state(): void
    {
        $email = 'clear-on-success@test.dev';
        $this->makeUser($email);
        $this->track($email);

        // Consume 4 failed attempts.
        for ($i = 1; $i <= 4; $i++) {
            $this->withHeaders(self::ORIGIN)
                ->post('/api/login', ['email' => $email, 'password' => 'WrongPass'])
                ->assertStatus(401);
        }
        $this->assertEquals(4, RateLimiter::attempts($this->throttleKey($email)));

        // A correct login succeeds (not blocked) and clears the counter.
        $this->withHeaders(self::ORIGIN)
            ->post('/api/login', ['email' => $email, 'password' => 'CorrectPass123'])
            ->assertOk()
            ->assertJson(['message' => 'Login berhasil']);

        $this->assertEquals(0, RateLimiter::attempts($this->throttleKey($email)));
    }
}
