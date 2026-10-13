<?php

namespace Tests\Feature;

use App\Models\CbtAttempt;
use App\Models\CbtQuestion;
use App\Models\CbtResult;
use App\Models\CbtSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CbtSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, string $role = 'orang_tua'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->forceFill(['role' => $role])->save();
        return $user->refresh();
    }

    private function cbtSession(string $token = 'ABC123'): CbtSession
    {
        return CbtSession::create([
            'nama_ujian' => 'Security Test',
            'tanggal_ujian' => now()->toDateString(),
            'jam_mulai' => now()->format('H:i:s'),
            'jam_selesai' => now()->addHour()->format('H:i:s'),
            'ruangan' => 'LAB',
            'token' => $token,
            'is_active' => true,
        ]);
    }

    private function question(CbtSession $session, string $answer = 'A'): CbtQuestion
    {
        return CbtQuestion::create([
            'cbt_session_id' => $session->id,
            'jenis_soal' => 'PG',
            'pertanyaan' => 'Two plus two?',
            'opsi_a' => 'Four', 'opsi_b' => 'Five', 'opsi_c' => 'Six', 'opsi_d' => 'Seven',
            'kunci_jawaban' => $answer,
        ]);
    }

    private function attempt(User $user, CbtSession $session, string $status = 'IN_PROGRESS', ?string $submittedAt = null): CbtAttempt
    {
        return CbtAttempt::create([
            'cbt_session_id' => $session->id,
            'user_id' => $user->id,
            'attempt_token' => hash('sha256', $user->id . '-' . $session->id . '-' . $status . '-' . microtime(true)),
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
            'submitted_at' => $submittedAt,
            'status' => $status,
        ]);
    }

    private function submit(User $user, CbtAttempt $attempt, array $extra = [])
    {
        Sanctum::actingAs($user);
        return $this->postJson('/api/cbt/submit', array_merge([
            'attempt_token' => $attempt->attempt_token,
            'jawaban' => ['fixture' => 'A'],
            'pelanggaran' => 0,
        ], $extra));
    }

    public function test_session_window_and_availability_are_server_enforced(): void
    {
        $session = $this->cbtSession();
        $user = $this->user('Window');
        Sanctum::actingAs($user);
        $this->travelTo(now()->subDay());
        $this->postJson('/api/cbt/login', ['token' => $session->token])->assertForbidden();
        $this->travelBack();
        $this->travelTo(now()->addDay());
        $this->postJson('/api/cbt/login', ['token' => $session->token])->assertForbidden();
        $this->travelBack();
        $this->assertDatabaseCount('cbt_attempts', 0);
        $session->update(['is_active' => false]);
        $this->postJson('/api/cbt/login', ['token' => $session->token])->assertStatus(422);
        $session->update(['is_active' => true]);
        $this->postJson('/api/cbt/login', ['token' => $session->token])->assertOk();
        $session->update(['is_active' => false]);
        $this->postJson('/api/cbt/submit', ['attempt_token' => CbtAttempt::firstOrFail()->attempt_token, 'jawaban' => ['fixture' => 'A']])->assertForbidden();
    }

    public function test_overnight_session_does_not_reopen_after_its_end(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-02-01 23:30:00'));
        $session = $this->cbtSession();
        $session->update(['jam_mulai' => '23:00:00', 'jam_selesai' => '01:00:00']);
        $user = $this->user('Night');
        Sanctum::actingAs($user);
        $this->postJson('/api/cbt/login', ['token' => $session->token])->assertOk();
        $this->assertSame('2026-02-02 01:00:00', CbtAttempt::firstOrFail()->expires_at->format('Y-m-d H:i:s'));
        $this->travelTo(\Carbon\Carbon::parse('2026-02-02 01:01:00'));
        Sanctum::actingAs($this->user('Late'));
        $this->postJson('/api/cbt/login', ['token' => $session->token])->assertForbidden();
        $this->travelBack();
    }

    public function test_anonymous_cbt_endpoints_are_rejected(): void
    {
        $this->postJson('/api/cbt/login', ['token' => 'ABC123'])->assertUnauthorized();
        $this->postJson('/api/cbt/submit', ['attempt_token' => str_repeat('a', 64), 'jawaban' => ['fixture' => 'A']])->assertUnauthorized();
    }

    public function test_wrong_role_is_rejected_and_parent_reaches_controller_validation(): void
    {
        $staff = $this->user('Staff', 'guru');
        Sanctum::actingAs($staff);
        $this->postJson('/api/cbt/login', ['token' => 'ABC123'])->assertForbidden();

        $parent = $this->user('Parent A');
        Sanctum::actingAs($parent);
        $this->postJson('/api/cbt/login', ['token' => 'bad'])->assertStatus(422);
    }

    public function test_session_token_requires_exactly_six_characters(): void
    {
        $this->cbtSession('ABC123');
        $user = $this->user('Parent A');
        Sanctum::actingAs($user);

        $this->postJson('/api/cbt/login', ['token' => 'ABC12'])->assertStatus(422);
        $this->postJson('/api/cbt/login', ['token' => 'ABC1234'])->assertStatus(422);
        $this->postJson('/api/cbt/login', ['token' => ['ABC123']])->assertStatus(422);
        $this->postJson('/api/cbt/login', ['token' => 'ABC123'])->assertOk();
    }

    public function test_login_creates_attempt_for_authenticated_parent_only(): void
    {
        $session = $this->cbtSession();
        $this->question($session);
        $user = $this->user('Parent A');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/cbt/login', ['token' => $session->token])->assertOk();
        $this->assertSame($user->name, $response->json('student_name'));
        $attempt = CbtAttempt::firstOrFail();
        $this->assertSame($user->id, $attempt->user_id);
        $this->assertSame($session->id, $attempt->cbt_session_id);
        $this->assertSame('IN_PROGRESS', $attempt->status);
    }

    public function test_repeated_login_for_existing_attempt_is_rejected_without_replacing_attempt(): void
    {
        $session = $this->cbtSession();
        $this->question($session);
        $user = $this->user('Parent A');
        $attempt = $this->attempt($user, $session);
        $originalToken = $attempt->attempt_token;

        Sanctum::actingAs($user);
        $this->postJson('/api/cbt/login', ['token' => $session->token])->assertForbidden();

        $this->assertSame(1, CbtAttempt::where('cbt_session_id', $session->id)->where('user_id', $user->id)->count());
        $this->assertSame($originalToken, $attempt->fresh()->attempt_token);
        $this->assertSame('IN_PROGRESS', $attempt->fresh()->status);
        $this->assertTrue($attempt->fresh()->expires_at->isFuture());
    }

    public function test_unrelated_database_exceptions_are_not_classified_as_attempt_conflicts(): void
    {
        $controller = app(\App\Http\Controllers\CbtController::class);
        $method = new \ReflectionMethod($controller, 'isAttemptUserSessionUniqueConflict');
        $method->setAccessible(true);

        $connectionLost = new QueryException('mysql', 'insert into cbt_attempts', [], new \PDOException('connection lost', 2002));
        $differentDuplicate = new QueryException('mysql', 'insert into cbt_attempts', [], new \PDOException('duplicate attempt token', 1062));

        $this->assertFalse($method->invoke($controller, $connectionLost));
        $this->assertFalse($method->invoke($controller, $differentDuplicate));
    }

    public function test_identity_override_fields_cannot_change_result_identity(): void
    {
        $owner = $this->user('Official Owner');
        $other = $this->user('Attacker Name');
        $session = $this->cbtSession();
        $question = $this->question($session);
        $attempt = $this->attempt($owner, $session);

        $response = $this->submit($owner, $attempt, [
            'jawaban' => [$question->id => 'A'],
            'user_id' => $other->id,
            'student_id' => $other->id,
            'nama_siswa' => $other->name,
        ])->assertOk();

        $result = CbtResult::firstOrFail();
        $this->assertSame($owner->id, $result->user_id);
        $this->assertSame($attempt->id, $result->cbt_attempt_id);
        $this->assertSame($owner->name, $result->nama_siswa);
        $this->assertSame(100.0, (float) $response->json('skor'));
    }

    public function test_non_owner_cannot_submit_another_users_attempt(): void
    {
        $owner = $this->user('Owner');
        $other = $this->user('Other');
        $attempt = $this->attempt($owner, $this->cbtSession());

        $this->submit($other, $attempt)->assertForbidden();
        $this->assertDatabaseCount('cbt_results', 0);
        $this->assertSame('IN_PROGRESS', $attempt->fresh()->status);
    }

    public function test_attempt_uses_its_stored_session_and_ignores_client_session_fields(): void
    {
        $owner = $this->user('Owner');
        $sessionA = $this->cbtSession('ABC123');
        $sessionB = $this->cbtSession('XYZ789');
        $question = $this->question($sessionA);
        $attempt = $this->attempt($owner, $sessionA);

        $this->submit($owner, $attempt, [
            'session_id' => $sessionB->id,
            'cbt_session_id' => $sessionB->id,
            'jawaban' => [$question->id => 'A'],
        ])->assertOk();

        $result = CbtResult::firstOrFail();
        $this->assertSame($sessionA->id, $result->cbt_session_id);
        $this->assertSame($attempt->id, $result->cbt_attempt_id);
    }

    public function test_only_in_progress_unsent_attempt_can_submit(): void
    {
        $user = $this->user('Owner');
        $session = $this->cbtSession();
        $this->question($session);

        $submitted = $this->attempt($user, $session, 'SUBMITTED', now()->subMinute());
        $this->submit($user, $submitted)->assertForbidden();

        $invalid = $this->attempt($this->user('Second'), $session, 'CANCELLED');
        $this->submit($invalid->user, $invalid)->assertForbidden();

        $withTimestamp = $this->attempt($this->user('Third'), $session, 'IN_PROGRESS', now()->subMinute());
        $this->submit($withTimestamp->user, $withTimestamp)->assertForbidden();
    }

    public function test_server_expiry_is_authoritative(): void
    {
        $user = $this->user('Owner');
        $session = $this->cbtSession();
        $this->question($session);
        $attempt = $this->attempt($user, $session);

        $this->travelTo(now()->addMinutes(30));
        $this->submit($user, $attempt, ['expires_at' => now()->addDay()->toISOString()])->assertOk();
        $this->travelBack();

        $expired = $this->attempt($this->user('Expired'), $session);
        $expired->update(['expires_at' => now()->subSecond()]);
        $this->submit($expired->user, $expired, ['expires_at' => now()->addDay()->toISOString()])->assertForbidden();
    }

    public function test_duplicate_submission_creates_exactly_one_result(): void
    {
        $user = $this->user('Owner');
        $session = $this->cbtSession();
        $question = $this->question($session);
        $attempt = $this->attempt($user, $session);
        $payload = ['jawaban' => [$question->id => 'A'], 'pelanggaran' => 4];

        $this->submit($user, $attempt, $payload)->assertOk();
        $submittedAt = $attempt->fresh()->submitted_at;
        $this->submit($user, $attempt, $payload)->assertForbidden();

        $this->assertDatabaseCount('cbt_results', 1);
        $this->assertSame('SUBMITTED', $attempt->fresh()->status);
        $this->assertNotNull($submittedAt);
        $this->assertEquals($submittedAt->toDateTimeString(), $attempt->fresh()->submitted_at->toDateTimeString());
    }

    public function test_client_telemetry_is_only_persisted_and_does_not_authorize_or_change_score(): void
    {
        $user = $this->user('Owner');
        $session = $this->cbtSession();
        $question = $this->question($session);
        $attempt = $this->attempt($user, $session);

        $this->submit($user, $attempt, ['jawaban' => [$question->id => 'A'], 'pelanggaran' => 999])->assertOk();
        $result = CbtResult::firstOrFail();
        $this->assertSame(999, $result->jumlah_pelanggaran);
        $this->assertSame(100.0, (float) $result->skor);
        $this->assertSame($user->id, $result->user_id);
    }

    public function test_legacy_result_identity_fields_remain_null_and_are_not_name_matched(): void
    {
        $user = $this->user('Legacy Name');
        $session = $this->cbtSession();
        $legacy = CbtResult::create([
            'cbt_session_id' => $session->id,
            'nama_siswa' => $user->name,
            'jawaban_json' => json_encode([]),
            'benar' => 0,
            'salah' => 1,
            'skor' => 0,
        ]);

        $this->assertNull($legacy->fresh()->user_id);
        $this->assertNull($legacy->fresh()->cbt_attempt_id);
    }

    public function test_cbt_login_and_submit_rate_limits_match_controller_limits(): void
    {
        RateLimiter::clear('cbt-login:127.0.0.1:1');
        $user = $this->user('Owner');
        Sanctum::actingAs($user);
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/cbt/login', ['token' => 'BAD999'])->assertStatus(422);
        }
        $this->postJson('/api/cbt/login', ['token' => 'BAD999'])->assertStatus(429);

        RateLimiter::clear('cbt-submit:127.0.0.1:' . $user->id);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/cbt/submit', ['attempt_token' => str_repeat(chr(97 + $i), 64), 'jawaban' => ['fixture' => 'A']])->assertForbidden();
        }
        $this->postJson('/api/cbt/submit', ['attempt_token' => str_repeat('z', 64), 'jawaban' => ['fixture' => 'A']])->assertStatus(429);
    }
}
