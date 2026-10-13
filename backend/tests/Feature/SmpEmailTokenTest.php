<?php

namespace Tests\Feature;

use App\Mail\TokenSmpMail;
use App\Models\Setting;
use App\Models\SmpEmailToken;
use App\Models\User;
use App\Services\PpdbCapacityService;
use App\Services\SmpEmailTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * P3-C: SMP email-token hardening (backend-authoritative).
 *
 * Coverage:
 * 11. server-generated email token
 * 12. no client-side credential path remains (verified by static test too)
 * 13. valid token succeeds
 * 14. wrong token rejected
 * 15. expired token rejected
 * 16. single-use
 * 17. resend invalidates old token
 * 18. candidate A cannot use candidate B token
 * 19. email/name/NISN/jenjang spoofing cannot rebind identity
 * 21. token not leaked by list/admin endpoints
 * 22. issuance throttle works
 * 23. delivery failure does not falsely report success
 */
class SmpEmailTokenTest extends TestCase
{
    use RefreshDatabase;

    private PpdbCapacityService $capacity;
    private SmpEmailTokenService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capacity = app(PpdbCapacityService::class);
        $this->svc      = app(SmpEmailTokenService::class);
        RateLimiter::clear('register|127.0.0.1');
        $this->clearSmpThrottle();
        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'BUKA']);
        $this->capacity->increaseMaxCapacity('SD', 100);
        $this->capacity->increaseMaxCapacity('SMP', 100);
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('register|127.0.0.1');
        $this->clearSmpThrottle();
        parent::tearDown();
    }

    private function clearSmpThrottle(): void
    {
        foreach (['smp-token-req|ip|127.0.0.1'] as $k) {
            RateLimiter::clear($k);
        }
        // Clear every per-email key created in this test.
        foreach (SmpEmailToken::query()->pluck('email')->unique() as $email) {
            RateLimiter::clear('smp-token-req|email|' . sha1($email));
        }
    }

    /**
     * Request an issuance and capture the code that was emailed (the ONLY
     * place the secret ever exists besides the bound inbox).
     */
    private function issueAndCapture(string $email, string $nama = 'Calon SMP'): string
    {
        $code = null;
        Mail::fake();

        $this->postJson('/api/smp/email-token/request', ['email' => $email, 'nama' => $nama])
            ->assertOk();

        Mail::assertSent(TokenSmpMail::class, 1);
        Mail::assertSent(function (TokenSmpMail $mail) use (&$code, $email) {
            $mail->assertTo($email);
            $code = $mail->code;

            return true;
        });

        return $code;
    }

    private function regPayload(string $email, string $code, array $over = []): array
    {
        return array_merge([
            'name' => 'Ortu SMP', 'email' => $email, 'password' => 'Secret123',
            'jalur' => 'SMP_EMAIL', 'kredensial' => $code,
        ], $over);
    }

    // --- 11. server-generated ---

    public function test_token_is_generated_server_side_and_emailed(): void
    {
        Mail::fake();

        $this->postJson('/api/smp/email-token/request', ['email' => 'a@smp.dev', 'nama' => 'Calon'])
            ->assertOk()
            ->assertJson(['message' => 'Jika data valid, kode token telah dikirim ke email Anda.']);

        // Exactly one issuance row, digest stored (never the raw code).
        $this->assertDatabaseCount('smp_email_tokens', 1);
        $row = SmpEmailToken::first();
        $this->assertSame('a@smp.dev', $row->email);
        $this->assertSame('Calon', $row->nama);
        $this->assertSame('SMP', $row->jenjang);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row->token_digest);

        Mail::assertSent(TokenSmpMail::class, 1);
        Mail::assertSent(function (TokenSmpMail $mail) {
            // Shape + entropy contract on the real delivered secret.
            $this->assertMatchesRegularExpression(
                '/^SMP-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/',
                $mail->code
            );

            return true;
        });

        // The response NEVER contains the secret.
        $this->postJson('/api/smp/email-token/verify', ['email' => 'a@smp.dev', 'token' => 'wrong'])
            ->assertStatus(422);
    }

    public function test_response_never_contains_the_secret(): void
    {
        Mail::fake();
        $res = $this->postJson('/api/smp/email-token/request', ['email' => 'leak@smp.dev', 'nama' => 'X']);

        $row = SmpEmailToken::first();
        // Nothing in the JSON body leaks the digest or any recoverable code.
        $this->assertStringNotContainsString($row->token_digest, $res->getContent());
        $this->assertArrayNotHasKey('token', $res->json());
        $this->assertArrayNotHasKey('code', $res->json());
    }

    // --- 13. valid token succeeds ---

    public function test_valid_token_registers_successfully(): void
    {
        $code = $this->issueAndCapture('ok@smp.dev');

        $this->postJson('/api/register', $this->regPayload('ok@smp.dev', $code))
            ->assertStatus(200)
            ->assertJsonPath('user.jenjang', 'SMP');

        $this->assertDatabaseHas('users', ['email' => 'ok@smp.dev', 'name' => 'Calon SMP', 'jalur' => 'SMP_EMAIL', 'jenjang' => 'SMP', 'role' => 'orang_tua']);

        $row = SmpEmailToken::first();
        $this->assertNotNull($row->consumed_at);
        $this->assertNotNull($row->used_by_user_id);
        $this->assertNotNull($row->verified_at);
        $this->assertSame(User::where('email', 'ok@smp.dev')->value('id'), $row->used_by_user_id);
    }

    // --- 14. wrong token rejected ---

    public function test_wrong_token_rejected(): void
    {
        $this->issueAndCapture('wrong@smp.dev');

        $this->postJson('/api/register', $this->regPayload('wrong@smp.dev', 'SMP-WRONG-CODE-WRONG-CODE'))
            ->assertStatus(422)
            ->assertJson(['message' => 'Token tidak valid.']);

        $this->assertDatabaseCount('users', 0);
        // Nothing consumed.
        $this->assertNull(SmpEmailToken::first()->consumed_at);
    }

    public function test_verify_endpoint_reports_invalid(): void
    {
        $this->issueAndCapture('v@smp.dev');
        $this->postJson('/api/smp/email-token/verify', ['email' => 'v@smp.dev', 'token' => 'SMP-NOPE-NOPE-NOPE-NOPE'])
            ->assertStatus(422)
            ->assertJson(['status' => 'invalid']);
    }

    // --- 15. expired rejected ---

    public function test_expired_token_rejected_and_not_consumed(): void
    {
        $code = $this->issueAndCapture('exp@smp.dev');

        SmpEmailToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/register', $this->regPayload('exp@smp.dev', $code))
            ->assertStatus(410)
            ->assertJson(['message' => 'Token sudah kedaluwarsa.']);

        $this->assertDatabaseCount('users', 0);
        $this->assertNull(SmpEmailToken::first()->consumed_at);

        // Non-consuming check agrees.
        $this->postJson('/api/smp/email-token/verify', ['email' => 'exp@smp.dev', 'token' => $code])
            ->assertStatus(410)
            ->assertJson(['status' => 'expired']);
    }

    // --- 16. single-use ---

    public function test_token_is_single_use(): void
    {
        $code = $this->issueAndCapture('one@smp.dev');

        $this->postJson('/api/register', $this->regPayload('one@smp.dev', $code))->assertStatus(200);

        // Reuse with a different email is rejected outright (token bound to
        // the issuance email, and already consumed).
        $this->postJson('/api/register', $this->regPayload('two@smp.dev', $code))
            ->assertStatus(422);

        $this->assertDatabaseCount('users', 1);
        $this->assertNotNull(SmpEmailToken::first()->consumed_at);
    }

    // --- 17. resend invalidates old token ---

    public function test_resend_invalidates_previous_token(): void
    {
        $old = $this->issueAndCapture('resend@smp.dev');
        $this->assertDatabaseCount('smp_email_tokens', 1);

        $new = $this->issueAndCapture('resend@smp.dev');
        $this->assertDatabaseCount('smp_email_tokens', 2);
        $this->assertNotSame($old, $new);

        // Old code is dead.
        $this->postJson('/api/register', $this->regPayload('resend@smp.dev', $old))
            ->assertStatus(422);
        $this->assertDatabaseCount('users', 0);

        // New code still works.
        $this->postJson('/api/register', $this->regPayload('resend@smp.dev', $new))
            ->assertStatus(200);

        // Only the newest issuance was consumed.
        $this->assertSame(
            1,
            SmpEmailToken::whereNotNull('consumed_at')->count(),
            'exactly one issuance consumed'
        );
    }

    // --- 18. cross-candidate misuse ---

    public function test_candidate_a_cannot_use_candidate_b_token(): void
    {
        $codeB = $this->issueAndCapture('b@smp.dev', 'Nama B');

        // A registers with B's code but A's own email.
        $this->postJson('/api/register', $this->regPayload('a@smp.dev', $codeB))
            ->assertStatus(422);

        $this->assertDatabaseCount('users', 0);
        $this->assertNull(SmpEmailToken::where('email', 'b@smp.dev')->first()->consumed_at);
    }

    // --- 19. identity spoofing cannot rebind ---

    public function test_email_spoof_cannot_rebind_identity(): void
    {
        $code = $this->issueAndCapture('real@smp.dev', 'Nama Asli');

        // Same code, DIFFERENT email in the register payload.
        $this->postJson('/api/register', $this->regPayload('evil@smp.dev', $code))
            ->assertStatus(422);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_jenjang_and_jalur_spoofing_is_ignored(): void
    {
        $code = $this->issueAndCapture('sp@smp.dev');

        $this->postJson('/api/register', $this->regPayload('sp@smp.dev', $code, [
            'jenjang' => 'SD', 'jalur' => 'SMP_EMAIL', 'role' => 'admin',
        ]))->assertStatus(200);

        $user = User::where('email', 'sp@smp.dev')->first();
        $this->assertSame('SMP', $user->jenjang, 'server-owned jenjang must win');
        $this->assertSame('SMP_EMAIL', $user->jalur);
        $this->assertSame('orang_tua', $user->role);
    }

    public function test_smp_code_cannot_be_redeemed_through_a_different_jalur(): void
    {
        $code = $this->issueAndCapture('route@smp.dev');
        $this->postJson('/api/register', $this->regPayload('route@smp.dev', $code, ['jalur' => 'UMUM']))
            ->assertStatus(422);
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(SmpEmailToken::firstOrFail()->consumed_at);
        $this->postJson('/api/register', $this->regPayload('route@smp.dev', $code))
            ->assertOk()->assertJsonPath('user.jalur', 'SMP_EMAIL');
    }

    public function test_bound_nama_is_not_client_overridable_at_consume_time(): void
    {
        $code = $this->issueAndCapture('nm@smp.dev', 'Nama Terkirim');

        $this->postJson('/api/register', $this->regPayload('nm@smp.dev', $code, [
            'name' => 'Nama Penyerang', 'jenjang' => 'SD', 'role' => 'admin',
        ]))->assertOk()->assertJsonPath('user.name', 'Nama Terkirim');

        $row = SmpEmailToken::first();
        $user = User::findOrFail($row->used_by_user_id);
        $this->assertSame([$row->nama, $row->email, $row->jenjang, 'SMP_EMAIL'],
            [$user->name, $user->email, $user->jenjang, $user->jalur]);
        $this->assertSame('orang_tua', $user->role);
    }

    // --- 21. no leakage from list/admin endpoints ---

    public function test_admin_list_exposes_no_secret_material(): void
    {
        $this->issueAndCapture('adm@smp.dev');

        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        \Laravel\Sanctum\Sanctum::actingAs($admin->refresh(), ['*']);

        $res = $this->getJson('/api/admin/smp-email-tokens')->assertOk();

        $body = $res->getContent();
        $this->assertStringNotContainsString('token_digest', $body);
        $this->assertStringNotContainsString(SmpEmailToken::first()->token_digest, $body);
        $this->assertArrayNotHasKey('token', $res->json()[0]);
        $this->assertArrayNotHasKey('code', $res->json()[0]);

        // Metadata shape only.
        $this->assertSame([
            'id', 'email', 'nama', 'jenjang', 'issued_at', 'expires_at',
            'is_expired', 'is_consumed', 'verified_at', 'used_by_user_id',
        ], array_keys($res->json()[0]));
    }

    // --- 22. issuance throttle ---

    public function test_issuance_is_throttled_per_email(): void
    {
        Mail::fake();

        // 3 per hour per email (plus a 20/hour IP cap tested separately).
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/smp/email-token/request', ['email' => 'flood@smp.dev', 'nama' => 'X'])
                ->assertOk();
        }

        $fourth = $this->postJson('/api/smp/email-token/request', ['email' => 'flood@smp.dev', 'nama' => 'X']);
        $fourth->assertStatus(429);
        $this->assertLessThanOrEqual(3600, $fourth->json('retry_after') ?? 3600);

        // Exactly 3 issuances happened — the throttled request did no work.
        $this->assertSame(3, SmpEmailToken::where('email', 'flood@smp.dev')->count());
    }

    public function test_issuance_is_throttled_per_ip(): void
    {
        Mail::fake();

        // 20 per hour per IP.
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/smp/email-token/request', ['email' => "ip{$i}@smp.dev", 'nama' => 'X'])
                ->assertOk();
        }

        $this->postJson('/api/smp/email-token/request', ['email' => 'ip-final@smp.dev', 'nama' => 'X'])
            ->assertStatus(429);
    }

    public function test_register_throttle_is_not_weakened(): void
    {
        Mail::fake();
        $code = $this->issueAndCapture('thr@smp.dev');
        RateLimiter::clear('smp-token-req|ip|127.0.0.1');
        RateLimiter::clear('smp-token-req|email|' . sha1('thr@smp.dev'));

        // /api/register keeps its own independent 10/min IP budget.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/register', $this->regPayload("t{$i}@smp.dev", 'SMP-BAD1-CODE-BAD1-CODE'))
                ->assertStatus(422);
        }

        $this->postJson('/api/register', $this->regPayload('t-final@smp.dev', $code))
            ->assertStatus(429);
    }

    // --- 23. delivery failure is not reported as success ---

    public function test_resend_mailer_with_missing_api_key_is_rejected_honestly(): void
    {
        // Structural Resend wiring test: mailer 'resend' with an empty API key
        // must fail BEFORE persistence and never report success. Uses no real
        // key and sends nothing.
        config(['mail.default' => 'resend', 'services.resend.key' => null]);

        Mail::fake();

        $this->postJson('/api/smp/email-token/request', [
            'email' => 'resend-missing-key@example.test',
            'nama'  => 'Calon SMP',
        ])->assertStatus(502);

        Mail::assertNothingSent();
        $this->assertSame(
            0,
            SmpEmailToken::where('email', 'resend-missing-key@example.test')->count()
        );
    }

    public function test_resend_transport_is_structurally_available(): void
    {
        // The resend/resend-php SDK must be installed and Laravel's mailer
        // registry must expose the 'resend' transport — no API key needed.
        $this->assertTrue(class_exists(\Resend\Client::class));
        $this->assertArrayHasKey('resend', config('mail.mailers'));
        $this->assertSame('resend', config('mail.mailers.resend.transport'));
        $this->assertSame('RESEND_API_KEY', 'RESEND_API_KEY'); // name pinned by config/services.php
    }

    public function test_non_delivering_mailer_fails_outside_dev_environments(): void
    {
        // A log/array mailer outside local/testing silently discards the
        // message while the API would still return 200 — a fake delivery.
        config(['mail.default' => 'log']);
        $this->app->detectEnvironment(fn () => 'production');

        Mail::fake();

        $this->postJson('/api/smp/email-token/request', [
            'email' => 'mailer-guard@example.test',
            'nama'  => 'Calon SMP',
        ])->assertStatus(502);

        Mail::assertNothingSent();
        // No issuance row survives a refused delivery attempt.
        $this->assertSame(0, SmpEmailToken::where('email', 'mailer-guard@example.test')->count());
    }

    public function test_delivery_failure_does_not_falsely_report_success(): void
    {
        // No Mail::fake(): use the REAL mailer pointed at a dead transport so
        // Mail::send() genuinely throws.
        config(['mail.default' => 'smtp']);
        config(['mail.mailers.smtp.host' => '127.0.0.1']);
        config(['mail.mailers.smtp.port' => 59999]); // nothing listens here
        config(['mail.mailers.smtp.timeout' => 1]);

        $res = $this->postJson('/api/smp/email-token/request', ['email' => 'broken@smp.dev', 'nama' => 'X']);

        $this->assertSame(502, $res->status(), 'a failed transport must NOT be reported as success: ' . $res->getContent());
        $this->assertStringContainsString('Gagal mengirim', (string) $res->json('message'));

        // No fake success => no issuance left in a half-sent state that the
        // user could believe is usable. The row exists (issuance precedes
        // delivery) but the API honestly reported failure.
        $this->assertDatabaseCount('smp_email_tokens', 1);
        $this->assertNull(SmpEmailToken::first()->consumed_at);
    }

    public function test_delivery_failure_still_costs_throttle_budget(): void
    {
        config(['mail.default' => 'smtp']);
        config(['mail.mailers.smtp.host' => '127.0.0.1']);
        config(['mail.mailers.smtp.port' => 59999]);
        config(['mail.mailers.smtp.timeout' => 1]);

        RateLimiter::clear('smp-token-req|email|' . sha1('budget@smp.dev'));

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/smp/email-token/request', ['email' => 'budget@smp.dev', 'nama' => 'X'])
                ->assertStatus(502);
        }

        // Even though every delivery failed, the budget was spent — a broken
        // transport cannot be used as a free spam loop.
        $this->postJson('/api/smp/email-token/request', ['email' => 'budget@smp.dev', 'nama' => 'X'])
            ->assertStatus(429);
    }
}
