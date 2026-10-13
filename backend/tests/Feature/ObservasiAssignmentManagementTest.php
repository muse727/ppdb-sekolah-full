<?php

namespace Tests\Feature;

use App\Models\ObservasiAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E.12G Phase 2 — assignment management endpoints.
 * Runs ONLY against ppdb_sekolah_test (RefreshDatabase; P4-D.1 bootstrap
 * pins the suite to the isolated test database).
 */
class ObservasiAssignmentManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $kandidat;
    private User $guruA;
    private User $guruB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('Admin', 'admin', 'SD');

        $this->kandidat = $this->makeUser('Calon SD', 'orang_tua', 'SD');
        $this->guruA = $this->makeUser('Guru A', 'guru', 'SD');
        $this->guruB = $this->makeUser('Guru B', 'guru', 'SD');

        Sanctum::actingAs($this->admin);
    }

    private function makeUser(string $name, string $role, string $jenjang): User
    {
        $u = User::factory()->create(['name' => $name, 'jenjang' => $jenjang]);
        $u->forceFill(['role' => $role])->save();

        return $u->refresh();
    }

    private function storePayload(array $over = []): array
    {
        return array_merge([
            'user_id' => $this->kandidat->id,
            'guru_id' => $this->guruA->id,
        ], $over);
    }

    // ── Authorization ──────────────────────────────────────────────────────

    public function test_admin_can_create_first_assignment(): void
    {
        $this->postJson('/api/admin/observasi-assignments', $this->storePayload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.guru_id', $this->guruA->id)
            ->assertJsonPath('data.status', 'AKTIF');

        $this->assertSame(1, ObservasiAssignment::where('user_id', $this->kandidat->id)->count());
    }

    public function test_guru_role_is_rejected(): void
    {
        Sanctum::actingAs($this->guruA);
        $this->postJson('/api/admin/observasi-assignments', $this->storePayload())
            ->assertStatus(403);
    }

    public function test_orang_tua_role_is_rejected(): void
    {
        Sanctum::actingAs($this->kandidat);
        $this->postJson('/api/admin/observasi-assignments', $this->storePayload())
            ->assertStatus(403);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        auth()->forgetGuards();
        $this->postJson('/api/admin/observasi-assignments', $this->storePayload())
            ->assertStatus(401);
    }

    // ── Candidate / guru validation ────────────────────────────────────────

    public function test_non_sd_candidate_is_rejected(): void
    {
        $smp = $this->makeUser('Calon SMP', 'orang_tua', 'SMP');
        $this->postJson('/api/admin/observasi-assignments', $this->storePayload(['user_id' => $smp->id]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_candidate');
    }

    public function test_internal_account_is_rejected_as_candidate(): void
    {
        // role guru → not an orang_tua candidate.
        $this->postJson('/api/admin/observasi-assignments', $this->storePayload(['user_id' => $this->guruB->id]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_candidate');
    }

    public function test_non_guru_assignee_is_rejected(): void
    {
        $this->postJson('/api/admin/observasi-assignments', $this->storePayload(['guru_id' => $this->kandidat->id]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_guru');
    }

    // ── Replace / revoke with history ──────────────────────────────────────

    public function test_replace_creates_history_and_single_active(): void
    {
        $a = $this->postJson('/api/admin/observasi-assignments', $this->storePayload())
            ->assertCreated()->json('data');

        $response = $this->postJson(
            "/api/admin/observasi-assignments/{$a['id']}/ganti",
            ['guru_id' => $this->guruB->id]
        );
        $response->assertOk()->assertJsonPath('data.guru_id', $this->guruB->id);

        $rows = ObservasiAssignment::where('user_id', $this->kandidat->id)->get();
        $this->assertSame(2, $rows->count(), 'history row must be preserved');
        $this->assertSame(1, $rows->where('status', 'AKTIF')->count());
        $this->assertSame(1, $rows->where('status', 'DIGANTI')->count());
        $old = $rows->firstWhere('status', 'DIGANTI');
        $this->assertNotNull($old->ended_at, 'replaced row must record ended_at');
    }

    public function test_revoke_marks_dicabut_and_keeps_history(): void
    {
        $a = $this->postJson('/api/admin/observasi-assignments', $this->storePayload())
            ->assertCreated()->json('data');

        $this->postJson("/api/admin/observasi-assignments/{$a['id']}/cabut", ['reason' => 'Guru pindah tugas'])
            ->assertOk()->assertJsonPath('data.status', 'DICABUT');

        $row = ObservasiAssignment::whereKey($a['id'])->first();
        $this->assertSame('DICABUT', $row->status);
        $this->assertSame('Guru pindah tugas', $row->revoked_reason);
        $this->assertNotNull($row->ended_at);
        $this->assertSame(1, ObservasiAssignment::where('user_id', $this->kandidat->id)->count(), 'row must NOT be deleted');
    }

    // ── Stale / ABA (expected_assignment_id) ───────────────────────────────

    public function test_stale_assignment_id_returns_409(): void
    {
        $a = $this->postJson('/api/admin/observasi-assignments', $this->storePayload())
            ->assertCreated()->json('data');

        // Someone replaces it first.
        $this->postJson("/api/admin/observasi-assignments/{$a['id']}/ganti", ['guru_id' => $this->guruB->id])
            ->assertOk();

        // Stale retry of the original replace → 409, no extra history.
        $this->postJson("/api/admin/observasi-assignments/{$a['id']}/ganti", ['guru_id' => $this->guruA->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'assignment_conflict');

        $this->assertSame(2, ObservasiAssignment::where('user_id', $this->kandidat->id)->count(),
            'stale retry must not create fake history');
    }

    public function test_revoke_on_stale_id_returns_409(): void
    {
        $a = $this->postJson('/api/admin/observasi-assignments', $this->storePayload())
            ->assertCreated()->json('data');

        $this->postJson("/api/admin/observasi-assignments/{$a['id']}/ganti", ['guru_id' => $this->guruB->id])
            ->assertOk();

        $this->postJson("/api/admin/observasi-assignments/{$a['id']}/cabut", ['reason' => 'x'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'assignment_conflict');

        $this->assertSame(1, ObservasiAssignment::where('user_id', $this->kandidat->id)
            ->where('status', 'AKTIF')->count(), 'the real active row must remain untouched');
    }

    public function test_aba_is_blocked_by_assignment_id(): void
    {
        // A -> B -> A. A stale "replace old-A-row" request must NOT win because
        // the current AKTIF row has a different id even though the guru matches.
        $a1 = $this->postJson('/api/admin/observasi-assignments', $this->storePayload())
            ->assertCreated()->json('data');

        $a2 = $this->postJson("/api/admin/observasi-assignments/{$a1['id']}/ganti", ['guru_id' => $this->guruB->id])
            ->assertOk()->json('data');

        // Stale admin tab still shows a1; trying to replace it to guru A again.
        $this->postJson("/api/admin/observasi-assignments/{$a1['id']}/ganti", ['guru_id' => $this->guruA->id])
            ->assertStatus(409);

        $aktif = ObservasiAssignment::aktif($this->kandidat->id)->first();
        $this->assertSame($a2['id'], $aktif->id, 'the newest admin decision (guru B) must stand');
    }

    public function test_replacing_to_same_guru_is_422_not_silent_noop(): void
    {
        $a = $this->postJson('/api/admin/observasi-assignments', $this->storePayload())
            ->assertCreated()->json('data');

        $this->postJson("/api/admin/observasi-assignments/{$a['id']}/ganti", ['guru_id' => $this->guruA->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'same_guru');

        $this->assertSame(1, ObservasiAssignment::where('user_id', $this->kandidat->id)->count());
    }

    // ── Duplicate active ──────────────────────────────────────────────────

    public function test_second_first_assignment_returns_409_not_second_row(): void
    {
        $this->postJson('/api/admin/observasi-assignments', $this->storePayload())
            ->assertCreated();

        $this->postJson('/api/admin/observasi-assignments', $this->storePayload(['guru_id' => $this->guruB->id]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'assignment_conflict');

        $this->assertSame(1, ObservasiAssignment::where('user_id', $this->kandidat->id)->count());
    }

    public function test_different_candidates_each_hold_active_assignments(): void
    {
        $other = $this->makeUser('Calon SD 2', 'orang_tua', 'SD');

        $this->postJson('/api/admin/observasi-assignments', $this->storePayload())->assertCreated();
        $this->postJson('/api/admin/observasi-assignments', $this->storePayload(['user_id' => $other->id]))
            ->assertCreated();

        $this->assertSame(2, ObservasiAssignment::where('status', 'AKTIF')->count());
    }

    // ── Reads ─────────────────────────────────────────────────────────────

    public function test_index_lists_sd_candidates_with_active_assignment(): void
    {
        $smp = $this->makeUser('Calon SMP', 'orang_tua', 'SMP');

        $this->postJson('/api/admin/observasi-assignments', $this->storePayload())->assertCreated();

        $rows = $this->getJson('/api/admin/observasi-assignments')->assertOk()->json();
        $ids = collect($rows)->pluck('id');

        $this->assertContains($this->kandidat->id, $ids);
        $this->assertNotContains($smp->id, $ids, 'SMP candidates must never appear');
        $this->assertNotContains($this->guruA->id, $ids, 'internal guru accounts must never appear');
        $row = collect($rows)->firstWhere('id', $this->kandidat->id);
        $this->assertSame('Guru A', $row['guru_name']);
        $this->assertNotNull($row['assignment_id']);
    }

    public function test_history_endpoint_returns_full_chronology(): void
    {
        $a = $this->postJson('/api/admin/observasi-assignments', $this->storePayload())
            ->assertCreated()->json('data');
        $this->postJson("/api/admin/observasi-assignments/{$a['id']}/ganti", ['guru_id' => $this->guruB->id])->assertOk();

        $res = $this->getJson("/api/admin/observasi-assignments/history/{$this->kandidat->id}")
            ->assertOk()
            ->assertJsonPath('kandidat.name', 'Calon SD');

        $history = $res->json('history');
        $this->assertCount(2, $history);
        $this->assertSame('AKTIF', $history[0]['status'], 'newest first');
        $this->assertSame('DIGANTI', $history[1]['status']);
        $this->assertSame('Admin', $history[1]['assigned_by_name']);
    }

    public function test_history_for_non_sd_candidate_404s(): void
    {
        $smp = $this->makeUser('Calon SMP', 'orang_tua', 'SMP');
        $this->getJson("/api/admin/observasi-assignments/history/{$smp->id}")->assertNotFound();
    }

    public function test_options_lists_only_guru_role(): void
    {
        $res = $this->getJson('/api/admin/observasi-assignments/options')->assertOk()->json('gurus');
        $ids = collect($res)->pluck('id');

        $this->assertContains($this->guruA->id, $ids);
        $this->assertContains($this->guruB->id, $ids);
        $this->assertNotContains($this->admin->id, $ids);
        $this->assertNotContains($this->kandidat->id, $ids);
    }

    // ── Error hygiene ─────────────────────────────────────────────────────

    public function test_error_responses_do_not_leak_sql_or_internals(): void
    {
        // Duplicate-create conflict — body must be the controlled envelope.
        $this->postJson('/api/admin/observasi-assignments', $this->storePayload())->assertCreated();
        $res = $this->postJson('/api/admin/observasi-assignments', $this->storePayload(['guru_id' => $this->guruB->id]));

        $this->assertSame(409, $res->status());
        $body = $res->getContent();
        $this->assertStringNotContainsString('SQL', $body);
        $this->assertStringNotContainsString('Duplicate entry', $body);
        $this->assertStringNotContainsString('uq_active', $body);
        $this->assertStringNotContainsString('exception', strtolower($body));
    }

    public function test_transaction_failure_leaves_no_partial_history(): void
    {
        $a = $this->postJson('/api/admin/observasi-assignments', $this->storePayload())
            ->assertCreated()->json('data');

        // The listener below is registered AFTER the `store` request, so the
        // new-row INSERT inside ganti is the FIRST insert it will ever see.
        $count = 0;
        DB::listen(function ($query) use (&$count) {
            if (str_contains($query->sql, 'insert into `observasi_assignments`')) {
                $count++;
                if ($count === 1) { // ganti's new AKTIF row — after the DIGANTI update
                    throw new \RuntimeException('simulated mid-transaction failure');
                }
            }
        });

        $this->postJson("/api/admin/observasi-assignments/{$a['id']}/ganti", ['guru_id' => $this->guruB->id])
            ->assertStatus(500);

        // Rollback proof: no DIGANTI, no new AKTIF, original row intact.
        $rows = ObservasiAssignment::where('user_id', $this->kandidat->id)->get();
        $this->assertSame(1, $rows->count(), 'transaction must fully roll back');
        $this->assertSame('AKTIF', $rows->first()->status);
        $this->assertNull($rows->first()->ended_at);
    }
}
