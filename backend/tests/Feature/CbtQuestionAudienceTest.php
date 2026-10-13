<?php

namespace Tests\Feature;

use App\Http\Resources\CbtQuestionGuruResource;
use App\Http\Resources\CbtQuestionStudentResource;
use App\Models\CbtQuestion;
use App\Models\CbtSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P4-B — locks the audience boundary around `kunci_jawaban`.
 *
 * The invariant under test is: the answer key reaches a guru and NEVER reaches a
 * student. Selection between the two shapes is made by route-level authorization
 * only, so these tests also assert that no client-supplied input can select the
 * guru shape on a student route.
 */
class CbtQuestionAudienceTest extends TestCase
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
            'nama_ujian' => 'Audience Test',
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

    private function studentLogin(User $student, CbtSession $session, array $extra = [])
    {
        RateLimiter::clear('cbt-login:127.0.0.1:' . $student->id);
        Sanctum::actingAs($student);
        return $this->postJson('/api/cbt/login', array_merge(['token' => $session->token], $extra));
    }

    private function guruQuestions(User $guru, CbtSession $session)
    {
        Sanctum::actingAs($guru);
        return $this->getJson('/api/guru/cbt-sessions/' . $session->id . '/questions');
    }

    // -----------------------------------------------------------------
    // The guru shape DOES carry the key
    // -----------------------------------------------------------------

    public function test_guru_question_endpoint_includes_the_answer_key(): void
    {
        $session = $this->cbtSession();
        $this->question($session, 'C');

        $response = $this->guruQuestions($this->user('Guru A', 'guru'), $session);

        $response->assertOk();
        $payload = $response->json();
        $this->assertIsArray($payload);
        $this->assertCount(1, $payload);
        $this->assertSame('C', $payload[0]['kunci_jawaban']);
    }

    public function test_guru_question_endpoint_preserves_the_bare_array_contract(): void
    {
        $session = $this->cbtSession();
        $this->question($session);

        $body = $this->guruQuestions($this->user('Guru B', 'guru'), $session)->getContent();

        // Serializing the collection instead of resolving it would wrap this in
        // {"data": [...]}, which both guru pages reject — they type the response
        // as CbtQuestion[].
        $this->assertStringStartsWith('[', $body);
        $this->assertStringNotContainsString('"data"', $body);
    }

    public function test_guru_question_endpoint_exposes_every_field_the_guru_ui_reads(): void
    {
        $session = $this->cbtSession();
        $this->question($session, 'B');

        $payload = $this->guruQuestions($this->user('Guru C', 'guru'), $session)->json();

        // guru/cbt/page.tsx reads all of these for the card, the Edit prefill and
        // the correct-option highlight; a dropped field silently breaks the UI.
        foreach ([
            'id', 'cbt_session_id', 'jenis_soal', 'pertanyaan', 'gambar',
            'opsi_a', 'opsi_b', 'opsi_c', 'opsi_d', 'kunci_jawaban',
        ] as $field) {
            $this->assertArrayHasKey($field, $payload[0], "guru payload missing {$field}");
        }
    }

    public function test_super_admin_may_also_read_questions_with_the_key(): void
    {
        $session = $this->cbtSession();
        $this->question($session, 'D');

        // CheckRole grants super_admin access to every gated zone.
        $response = $this->guruQuestions($this->user('Root', 'super_admin'), $session);

        $response->assertOk();
        $this->assertSame('D', $response->json()[0]['kunci_jawaban']);
    }

    public function test_non_guru_role_cannot_reach_the_question_endpoint(): void
    {
        $session = $this->cbtSession();
        $this->question($session);

        // Anonymous first: Sanctum::actingAs() persists for the remainder of the
        // test method, so any authenticated assertion below would otherwise make
        // this one pass for the wrong reason (403 instead of 401).
        $this->getJson('/api/guru/cbt-sessions/' . $session->id . '/questions')->assertUnauthorized();
        $this->guruQuestions($this->user('Parent', 'orang_tua'), $session)->assertForbidden();
    }

    // -----------------------------------------------------------------
    // The student shape NEVER carries the key
    // -----------------------------------------------------------------

    public function test_student_login_response_contains_no_answer_key_anywhere(): void
    {
        $session = $this->cbtSession();
        $this->question($session, 'C');

        $response = $this->studentLogin($this->user('Siswa'), $session);

        $response->assertOk();
        // Asserted on the raw body, not just the modelled fields: any accidental
        // inclusion — nested, renamed, or added by a future column — fails here.
        $this->assertStringNotContainsString('kunci_jawaban', $response->getContent());

        $questions = $response->json('questions');
        $this->assertCount(1, $questions);
        $this->assertSame([
            'id', 'jenis_soal', 'pertanyaan', 'gambar',
            'opsi_a', 'opsi_b', 'opsi_c', 'opsi_d',
        ], array_keys($questions[0]));
    }

    public function test_client_supplied_flags_cannot_select_the_guru_shape(): void
    {
        $session = $this->cbtSession();
        $this->question($session, 'C');

        // Every one of these is a plausible way to try to talk a permissive
        // implementation into including the key. None may have any effect: the
        // controller picks the resource from the authenticated route, not input.
        $response = $this->studentLogin($this->user('Siswa Licik'), $session, [
            'include_answer' => true,
            'include_kunci' => true,
            'show_key' => 1,
            'with_key' => 'true',
            'role' => 'guru',
            'as' => 'guru',
            'resource' => 'CbtQuestionGuruResource',
            'fields' => 'all',
            'debug' => true,
        ]);

        $response->assertOk();
        $this->assertStringNotContainsString('kunci_jawaban', $response->getContent());
    }

    public function test_submit_response_reveals_only_aggregate_scoring(): void
    {
        $session = $this->cbtSession();
        $question = $this->question($session, 'A');
        $student = $this->user('Siswa Submit');

        RateLimiter::clear('cbt-login:127.0.0.1:' . $student->id);
        Sanctum::actingAs($student);
        $attemptToken = $this->postJson('/api/cbt/login', ['token' => $session->token])->json('attempt_token');
        $this->assertNotNull($attemptToken);

        RateLimiter::clear('cbt-submit:127.0.0.1:' . $student->id);
        $response = $this->postJson('/api/cbt/submit', [
            'attempt_token' => $attemptToken,
            'jawaban' => [$question->id => 'B'], // deliberately wrong
            'pelanggaran' => 0,
        ]);

        $response->assertOk();
        // A per-question review payload would have to name the key to be useful,
        // so the response stays aggregate-only.
        $this->assertStringNotContainsString('kunci_jawaban', $response->getContent());
        $this->assertStringNotContainsString('pembahasan', $response->getContent());
        $this->assertSame(0.0, (float) $response->json('skor'));
        $this->assertSame(0, $response->json('benar'));
        $this->assertSame(1, $response->json('salah'));
    }

    public function test_student_and_guru_shapes_differ_only_by_the_answer_key(): void
    {
        $session = $this->cbtSession();
        $question = $this->question($session, 'C');
        $student = $this->user('Siswa Diff');

        $studentPayload = $this->studentLogin($student, $session)->json('questions')[0];
        $guruPayload = $this->guruQuestions($this->user('Guru Diff', 'guru'), $session)->json()[0];

        // Same question, so the student must not be seeing a redacted stub of
        // different content — only the key (and admin-only bookkeeping) differs.
        foreach (['id', 'jenis_soal', 'pertanyaan', 'opsi_a', 'opsi_b', 'opsi_c', 'opsi_d'] as $field) {
            $this->assertSame($guruPayload[$field], $studentPayload[$field]);
        }
        $this->assertSame($question->id, $studentPayload['id']);
        $this->assertArrayNotHasKey('kunci_jawaban', $studentPayload);
        $this->assertSame('C', $guruPayload['kunci_jawaban']);
    }

    // -----------------------------------------------------------------
    // The resource allowlist is the boundary, not the model's $hidden
    // -----------------------------------------------------------------

    public function test_student_resource_omits_the_key_even_when_model_hidden_is_cleared(): void
    {
        $session = $this->cbtSession();
        $question = $this->question($session, 'C');

        $question->setHidden([]); // simulate $hidden being removed or bypassed
        $this->assertSame('C', $question->kunci_jawaban);

        $student = CbtQuestionStudentResource::make($question)->toArray(request());
        $this->assertArrayNotHasKey('kunci_jawaban', $student);

        // The guru resource is unaffected by $hidden either way, which is why the
        // two classes — not a model-global rule — are what express the boundary.
        $guru = CbtQuestionGuruResource::make($question)->toArray(request());
        $this->assertSame('C', $guru['kunci_jawaban']);
    }

    public function test_default_model_serialization_still_hides_the_key_as_defence_in_depth(): void
    {
        $session = $this->cbtSession();
        $question = $this->question($session, 'C');

        $this->assertArrayNotHasKey('kunci_jawaban', $question->toArray());
        $this->assertStringNotContainsString('kunci_jawaban', $question->toJson());
        // But it remains readable server-side for scoring.
        $this->assertSame('C', $question->kunci_jawaban);
    }

    public function test_student_resource_never_leaks_a_newly_added_column(): void
    {
        $session = $this->cbtSession();
        $question = $this->question($session, 'C');

        // A future column must not reach students by default: the resource is an
        // allowlist, not a "hide the key" denylist.
        $question->forceFill(['catatan_guru' => 'rahasia', 'kunci_jawaban' => 'C']);

        $student = CbtQuestionStudentResource::make($question)->toArray(request());
        $this->assertArrayNotHasKey('catatan_guru', $student);
        $this->assertArrayNotHasKey('kunci_jawaban', $student);
    }

    // -----------------------------------------------------------------
    // Ordering / pagination stability
    // -----------------------------------------------------------------

    public function test_both_endpoints_return_questions_in_the_same_stable_order(): void
    {
        $session = $this->cbtSession();
        $ids = [];
        foreach (['A', 'B', 'C', 'D'] as $key) {
            $ids[] = $this->question($session, $key)->id;
        }

        $guru = $this->guruQuestions($this->user('Guru Order', 'guru'), $session)->json();
        $this->assertSame($ids, array_column($guru, 'id'));

        $student = $this->studentLogin($this->user('Siswa Order'), $session)->json('questions');
        $this->assertSame($ids, array_column($student, 'id'));
    }

    public function test_question_collections_are_not_paginated_or_wrapped(): void
    {
        $session = $this->cbtSession();
        $this->question($session);

        $guruBody = $this->guruQuestions($this->user('Guru Wrap', 'guru'), $session)->getContent();
        $studentBody = $this->studentLogin($this->user('Siswa Wrap'), $session)->getContent();

        // No paginator envelope on either side — the clients index the arrays
        // directly and use the array length for progress navigation.
        foreach (['"data"', '"links"', '"meta"', '"current_page"'] as $envelope) {
            $this->assertStringNotContainsString($envelope, $guruBody);
        }
        $this->assertStringNotContainsString('"links"', $studentBody);
        $this->assertStringNotContainsString('"meta"', $studentBody);
    }

    public function test_empty_session_yields_empty_arrays_on_both_sides(): void
    {
        $session = $this->cbtSession('EMPTY1');

        $this->assertSame([], $this->guruQuestions($this->user('Guru Empty', 'guru'), $session)->json());
        $this->assertSame([], $this->studentLogin($this->user('Siswa Empty'), $session)->json('questions'));
    }

    public function test_esai_question_without_key_is_safe_for_both_audiences(): void
    {
        $session = $this->cbtSession();
        CbtQuestion::create([
            'cbt_session_id' => $session->id,
            'jenis_soal' => 'ESAI',
            'pertanyaan' => 'Explain photosynthesis.',
            'kunci_jawaban' => null, // nullable by schema
        ]);

        $guru = $this->guruQuestions($this->user('Guru Esai', 'guru'), $session)->json();
        $this->assertNull($guru[0]['kunci_jawaban']);
        $this->assertSame('ESAI', $guru[0]['jenis_soal']);

        $student = $this->studentLogin($this->user('Siswa Esai'), $session)->json('questions');
        $this->assertSame('ESAI', $student[0]['jenis_soal']);
        $this->assertArrayNotHasKey('kunci_jawaban', $student[0]);
    }
}
