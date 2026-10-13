<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * E.10 — password reset via email (Laravel Password Broker + Resend mailer
 * under the existing mail configuration; Mail::fake() in tests — no real
 * email is ever sent).
 *
 * Security properties under test:
 *  - generic response whether or not the email exists (no enumeration)
 *  - token works once; reuse is refused
 *  - wrong/expired token is refused
 *  - a new reset request invalidates the previous token
 *  - password must pass validation (confirmed, min 8) and is bcrypt-hashed
 *  - reset only touches the target account
 *  - IP throttling on the request endpoint
 *  - sessions of the user are revoked after a successful reset
 *  - no reset token is written to the log
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email = 'ortu@test.dev', string $role = 'orang_tua'): User
    {
        $u = User::create([
            'name'     => 'Ortu Uji',
            'email'    => $email,
            'password' => Hash::make('Secret123'),
            'jalur'    => 'UMUM',
            'jenjang'  => 'SD',
        ]);
        $u->forceFill(['role' => $role])->save();

        return $u->refresh();
    }

    private function requestReset(string $email): void
    {
        $this->postJson('/api/forgot-password', ['email' => $email])->assertOk();
    }

    /** Extract the plaintext token from the LAST faked mailable (never stored raw). */
    private function tokenFromMail(): string
    {
        $sent = Mail::sent(ResetPasswordMail::class);
        $this->assertGreaterThan(0, $sent->count(), 'ResetPasswordMail was not sent.');
        $url = $sent->last()->url;
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
        $token = $q['token'] ?? '';
        $this->assertNotSame('', $token);
        $this->assertStringContainsString('/reset-password', $url);

        return $token;
    }

    // --- Request endpoint ----------------------------------------------------

    public function test_request_returns_generic_response_for_unknown_email(): void
    {
        Mail::fake();
        $this->postJson('/api/forgot-password', ['email' => 'ghost@nowhere.dev'])
            ->assertOk()
            ->assertJson(['message' => 'Jika email terdaftar, tautan reset password telah dikirim. Silakan periksa kotak masuk Anda.']);
        Mail::assertNothingSent();
    }

    public function test_request_for_known_email_sends_reset_mail_with_hashed_storage(): void
    {
        Mail::fake();
        $user = $this->user();
        $this->requestReset($user->email);

        $token = $this->tokenFromMail();
        $this->assertNotSame('', $token);

        // DB stores the broker HASH (bcrypt, 60 chars), never the plaintext token.
        $row = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        $this->assertNotNull($row);
        $this->assertNotSame($token, $row->token);
        $this->assertSame(60, strlen($row->token)); // bcrypt hash
        $this->assertTrue(Hash::check($token, $row->token));
    }

    public function test_new_request_invalidates_previous_token(): void
    {
        Mail::fake();
        $user = $this->user();

        $this->requestReset($user->email);
        $first = $this->tokenFromMail();

        // Broker throttle (60s) is based on the row's created_at column, not
        // the cache — backdate it to simulate a legitimate later re-request.
        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(2)]);

        $this->requestReset($user->email);
        $second = $this->tokenFromMail();

        $this->assertNotSame($first, $second);

        // First token must no longer reset the password.
        $this->postJson('/api/reset-password', [
            'email'    => $user->email,
            'token'    => $first,
            'password' => 'NewSecret123',
            'password_confirmation' => 'NewSecret123',
        ])->assertStatus(422);
        $this->assertTrue(Hash::check('Secret123', $user->fresh()->password));
    }

    public function test_request_is_ip_throttled(): void
    {
        Mail::fake();
        $user = $this->user();

        // 5 allowed, 6th must be 429.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
        }
        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertStatus(429);
    }

    // --- Reset endpoint -------------------------------------------------------

    public function test_valid_token_resets_password_once_and_hashes_it(): void
    {
        Mail::fake();
        $user = $this->user();
        $this->requestReset($user->email);
        $token = $this->tokenFromMail();

        $this->postJson('/api/reset-password', [
            'email'    => $user->email,
            'token'    => $token,
            'password' => 'NewSecret123',
            'password_confirmation' => 'NewSecret123',
        ])->assertOk()->assertJson(['message' => 'Password berhasil diubah. Silakan login dengan password baru Anda.']);

        // Bcrypt-hashed new password, old one gone.
        $this->assertTrue(Hash::check('NewSecret123', $user->fresh()->password));
        $this->assertFalse(Hash::check('Secret123', $user->fresh()->password));

        // Token consumed — single use.
        $this->postJson('/api/reset-password', [
            'email'    => $user->email,
            'token'    => $token,
            'password' => 'Another12345',
            'password_confirmation' => 'Another12345',
        ])->assertStatus(422);
        $this->assertTrue(Hash::check('NewSecret123', $user->fresh()->password));
    }

    public function test_wrong_token_is_refused(): void
    {
        Mail::fake();
        $user = $this->user();
        $this->requestReset($user->email);

        $this->postJson('/api/reset-password', [
            'email'    => $user->email,
            'token'    => str_repeat('x', 64),
            'password' => 'NewSecret123',
            'password_confirmation' => 'NewSecret123',
        ])->assertStatus(422);
        $this->assertTrue(Hash::check('Secret123', $user->fresh()->password));
    }

    public function test_expired_token_is_refused(): void
    {
        Mail::fake();
        $user = $this->user();

        // Create an already-expired broker token directly (hash stored raw).
        $expiredToken = 'expiredtokentokenexpiredtokentokenexpiredtoke';
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => hash('sha256', $expiredToken), 'created_at' => now()->subHours(2)],
        );

        $this->postJson('/api/reset-password', [
            'email'    => $user->email,
            'token'    => $expiredToken,
            'password' => 'NewSecret123',
            'password_confirmation' => 'NewSecret123',
        ])->assertStatus(422);
        $this->assertTrue(Hash::check('Secret123', $user->fresh()->password));
    }

    public function test_password_mismatch_and_short_password_are_refused(): void
    {
        Mail::fake();
        $user = $this->user();
        $this->requestReset($user->email);
        $token = $this->tokenFromMail();

        $this->postJson('/api/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'NewSecret123', 'password_confirmation' => 'Different123',
        ])->assertStatus(422);
        $this->assertTrue(Hash::check('Secret123', $user->fresh()->password));

        cache()->flush();
        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(2)]);
        $this->requestReset($user->email);
        $token = $this->tokenFromMail();

        $this->postJson('/api/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertStatus(422);
        $this->assertTrue(Hash::check('Secret123', $user->fresh()->password));
    }

    public function test_reset_does_not_touch_other_accounts_or_roles(): void
    {
        Mail::fake();
        $ortu = $this->user('ortu@test.dev', 'orang_tua');
        $admin = $this->user('admin@test.dev', 'super_admin');

        $this->requestReset($ortu->email);
        $token = $this->tokenFromMail();

        // Resetting ortu's password must not change the admin's password.
        $this->postJson('/api/reset-password', [
            'email'    => $ortu->email,
            'token'    => $token,
            'password' => 'NewSecret123',
            'password_confirmation' => 'NewSecret123',
        ])->assertOk();

        $this->assertTrue(Hash::check('Secret123', $admin->fresh()->password));
        $this->assertSame('super_admin', $admin->fresh()->role);
        $this->assertSame('orang_tua', $ortu->fresh()->role);
    }

    public function test_successful_reset_revokes_existing_sessions(): void
    {
        Mail::fake();
        $user = $this->user();
        $this->requestReset($user->email);
        $token = $this->tokenFromMail();

        // Simulate an active session for the user.
        DB::table('sessions')->insert([
            'id'            => str_repeat('a', 40),
            'user_id'       => $user->id,
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'test',
            'payload'       => 'x',
            'last_activity' => time(),
        ]);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());

        $this->postJson('/api/reset-password', [
            'email'    => $user->email,
            'token'    => $token,
            'password' => 'NewSecret123',
            'password_confirmation' => 'NewSecret123',
        ])->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_reset_token_never_written_to_logs(): void
    {
        Mail::fake();
        $user = $this->user();
        $this->requestReset($user->email);
        $token = $this->tokenFromMail();

        $logFiles = glob(storage_path('logs/*.log')) ?: [];
        foreach ($logFiles as $f) {
            $content = file_get_contents($f) ?: '';
            $this->assertFalse(
                str_contains($content, $token),
                "Reset token leaked to log: {$f}"
            );
        }
    }
}
