<?php

namespace Tests\Feature;

use App\Models\HasilObservasi;
use App\Models\ObservasiAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E.12G Phase 3 — authorization + versioning on POST /api/guru/observasi/submit.
 * Runs ONLY against ppdb_sekolah_test (RefreshDatabase; P4-D.1 bootstrap).
 *
 * Test classification (explicit):
 *  - SEQUENTIAL: HTTP calls one after another, asserting state transitions.
 *  - ATOMICITY:  injected mid-transaction failure; asserts full rollback.
 *  - VERSION:    stale expected_version refuses writes (409, no overwrite).
 *  - CONCURRENCY (multi-connection, truly overlapping transactions): NOT
 *    performed here. Guaranteed only by design (parent-row lock protocol +
 *    lock ordering shared with Fase 2 assignment writes). Stated honestly.
 */
class ObservasiSubmitAuthorizationVersioningTest extends TestCase
{
    use RefreshDatabase;

    private User $guruA;
    private User $guruB;
    private User $kandidat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guruA = $this->makeUser('Guru A', 'guru');
        $this->guruB = $this->makeUser('Guru B', 'guru');
        $this->kandidat = $this->makeUser('Calon SD', 'orang_tua', 'SD');
    }

    private function makeUser(string $name, string $role, string $jenjang = 'SD'): User
    {
        $u = User::factory()->create(['name' => $name, 'jenjang' => $jenjang]);
        $u->forceFill(['role' => $role])->save();

        return $u->refresh();
    }

    private function assign(User $guru): ObservasiAssignment
    {
        return ObservasiAssignment::create([
            'user_id'    => $this->kandidat->id,
            'guru_id'    => $guru->id,
            'status'     => 'AKTIF',
            'started_at' => now(),
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'user_id'      => $this->kandidat->id,
            'jawaban_json' => ['K01' => ['jawaban' => 'YA', 'skor' => 1]],
        ], $over);
    }

    // ── Authorization (sequential) ────────────────────────────────────────

    public function test_assigned_active_guru_can_create_first_hasil(): void
    {
        $this->assign($this->guruA);
        Sanctum::actingAs($this->guruA);

        $res = $this->postJson('/api/guru/observasi/submit', $this->payload());

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.penilai_id', $this->guruA->id);

        $this->assertSame(1, HasilObservasi::where('user_id', $this->kandidat->id)->count());
    }

    public function test_unassigned_guru_is_rejected_403(): void
    {
        // No assignment at all.
        Sanctum::actingAs($this->guruA);
        $this->postJson('/api/guru/observasi/submit', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_assigned');

        // Assignment exists but belongs to someone else.
        $this->assign($this->guruB);
        $this->postJson('/api/guru/observasi/submit', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_assigned');

        $this->assertSame(0, HasilObservasi::count(), 'no row may be written by an unassigned guru');
    }

    public function test_old_guru_rejected_after_reassignment(): void
    {
        $a = $this->assign($this->guruA);
        Sanctum::actingAs($this->guruA);
        $this->postJson('/api/guru/observasi/submit', $this->payload())->assertOk();

        // Admin replaces to Guru B.
        $a->update(['status' => 'DIGANTI', 'ended_at' => now()]);
        ObservasiAssignment::create([
            'user_id' => $this->kandidat->id, 'guru_id' => $this->guruB->id,
            'status' => 'AKTIF', 'started_at' => now(),
        ]);

        // Old guru tries to save again — 403, data intact.
        $this->postJson('/api/guru/observasi/submit', $this->payload(['expected_version' => 1]))
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_assigned');

        $row = HasilObservasi::where('user_id', $this->kandidat->id)->first();
        $this->assertSame(1, $row->version, 'old guru must not bump the version');
    }

    public function test_old_guru_rejected_after_revocation(): void
    {
        $a = $this->assign($this->guruA);
        Sanctum::actingAs($this->guruA);
        $this->postJson('/api/guru/observasi/submit', $this->payload())->assertOk();

        $a->update(['status' => 'DICABUT', 'ended_at' => now()]);

        $this->postJson('/api/guru/observasi/submit', $this->payload(['expected_version' => 1]))
            ->assertStatus(403);

        $this->assertSame(1, HasilObservasi::where('user_id', $this->kandidat->id)->first()->version);
    }

    public function test_new_active_guru_can_edit_hasil_created_by_previous_guru(): void
    {
        $a = $this->assign($this->guruA);
        Sanctum::actingAs($this->guruA);
        $this->postJson('/api/guru/observasi/submit', $this->payload())->assertOk();

        // Reassign to Guru B.
        $a->update(['status' => 'DIGANTI', 'ended_at' => now()]);
        $this->assign($this->guruB);
        Sanctum::actingAs($this->guruB);

        $this->postJson('/api/guru/observasi/submit', $this->payload([
            'expected_version'   => 1,
            'rekomendasi_akhir'  => 'LULUS',
            'jawaban_json'       => ['K01' => ['jawaban' => 'TIDAK', 'skor' => 0]],
        ]))
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.penilai_id', $this->guruB->id, 'penilai_id records the saver');

        $row = HasilObservasi::where('user_id', $this->kandidat->id)->first();
        $this->assertSame($this->guruB->id, $row->penilai_id);
        $this->assertSame('LULUS', $row->rekomendasi_akhir);
    }

    public function test_admin_role_cannot_edit_via_submit_endpoint(): void
    {
        $this->assign($this->guruA);
        Sanctum::actingAs($this->makeUser('Admin', 'admin'));

        $this->postJson('/api/guru/observasi/submit', $this->payload())
            ->assertStatus(403); // route-level CheckRole:guru (pre-existing)

        // Even through a guru-role account, admin is not the assigned guru:
        Sanctum::actingAs($this->guruB);
        $this->postJson('/api/guru/observasi/submit', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_assigned');
    }

    // ── Versioning (version conflict tests) ──────────────────────────────

    public function test_version_increments_exactly_once_per_successful_write(): void
    {
        $this->assign($this->guruA);
        Sanctum::actingAs($this->guruA);

        $this->postJson('/api/guru/observasi/submit', $this->payload())->assertOk()
            ->assertJsonPath('data.version', 1);
        $this->postJson('/api/guru/observasi/submit', $this->payload(['expected_version' => 1]))->assertOk()
            ->assertJsonPath('data.version', 2);
        $this->postJson('/api/guru/observasi/submit', $this->payload(['expected_version' => 2]))->assertOk()
            ->assertJsonPath('data.version', 3);

        $this->assertSame(3, HasilObservasi::where('user_id', $this->kandidat->id)->first()->version);
        $this->assertSame(1, HasilObservasi::count());
    }

    public function test_stale_expected_version_returns_409_and_preserves_newest(): void
    {
        $this->assign($this->guruA);
        Sanctum::actingAs($this->guruA);

        $this->postJson('/api/guru/observasi/submit', $this->payload())->assertOk();
        $this->postJson('/api/guru/observasi/submit', $this->payload(['expected_version' => 1]))->assertOk();

        // Stale: sends expected_version=1 while actual is 2.
        $res = $this->postJson('/api/guru/observasi/submit', $this->payload([
            'expected_version' => 1,
            'rekomendasi_akhir' => 'TIDAK LULUS', // must NOT land
        ]));

        $res->assertStatus(409)
            ->assertJsonPath('code', 'stale_version')
            ->assertJsonPath('current.version', 2);

        $row = HasilObservasi::where('user_id', $this->kandidat->id)->first();
        $this->assertSame(2, $row->version);
        $this->assertNull($row->rekomendasi_akhir, 'stale payload must not overwrite newer data');
    }

    public function test_update_without_expected_version_is_rejected(): void
    {
        $this->assign($this->guruA);
        Sanctum::actingAs($this->guruA);

        $this->postJson('/api/guru/observasi/submit', $this->payload())->assertOk();

        $this->postJson('/api/guru/observasi/submit', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_version');
    }

    public function test_first_write_with_expected_version_is_rejected(): void
    {
        $this->assign($this->guruA);
        Sanctum::actingAs($this->guruA);

        $this->postJson('/api/guru/observasi/submit', $this->payload(['expected_version' => 1]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_version');

        $this->assertSame(0, HasilObservasi::count());
    }

    public function test_both_writers_share_identical_rules(): void
    {
        // The arena (no rekomendasi) and the rekapan editor (with rekomendasi)
        // hit the SAME endpoint; both must obey the same version contract.
        $this->assign($this->guruA);
        Sanctum::actingAs($this->guruA);

        // Arena-style first write.
        $this->postJson('/api/guru/observasi/submit', $this->payload())->assertOk();

        // Editor-style update WITH expected_version — accepted.
        $this->postJson('/api/guru/observasi/submit', $this->payload([
            'expected_version' => 1, 'rekomendasi_akhir' => 'LULUS BERSYARAT',
        ]))->assertOk()->assertJsonPath('data.version', 2);

        // Editor-style update with STALE version — rejected like the arena's.
        $this->postJson('/api/guru/observasi/submit', $this->payload([
            'expected_version' => 1, 'rekomendasi_akhir' => 'LULUS',
        ]))->assertStatus(409);
    }

    // ── Pre-existing rows / duplicates ────────────────────────────────────

    public function test_legacy_row_backfilled_to_version_1_is_updatable(): void
    {
        $this->assign($this->guruA);
        Sanctum::actingAs($this->guruA);

        // A pre-versioning row (as written by the old updateOrCreate path).
        // NOTE: refresh() — the DB default (version=1) is only visible after
        // re-reading; the in-memory model would otherwise hold null and a
        // null expected_version means "first write" (409 by contract).
        $legacy = HasilObservasi::create([
            'user_id' => $this->kandidat->id, 'penilai_id' => $this->guruB->id,
            'jawaban_json' => ['K01' => 'YA'], 'catatan_tambahan' => 'legacy',
        ])->refresh();

        $this->postJson('/api/guru/observasi/submit', $this->payload(['expected_version' => $legacy->version]))
            ->assertOk()
            ->assertJsonPath('data.version', $legacy->version + 1);
    }

    public function test_duplicate_rows_are_never_silently_overwritten(): void
    {
        $this->assign($this->guruA);
        Sanctum::actingAs($this->guruA);

        HasilObservasi::create([
            'user_id' => $this->kandidat->id, 'penilai_id' => $this->guruA->id,
            'jawaban_json' => ['K01' => 'YA'],
        ]);
        HasilObservasi::create([
            'user_id' => $this->kandidat->id, 'penilai_id' => $this->guruA->id,
            'jawaban_json' => ['K01' => 'TIDAK'],
        ]);

        $res = $this->postJson('/api/guru/observasi/submit', $this->payload(['expected_version' => 1]));

        $res->assertStatus(409)->assertJsonPath('code', 'duplicate_hasil_rows');
        $this->assertSame(2, HasilObservasi::where('user_id', $this->kandidat->id)->count(),
            'duplicates must be left untouched');

        // getHasil still returns both rows for viewing/printing (read rights intact).
        $this->getJson('/api/guru/rekapan-observasi')
            ->assertOk()
            ->assertJsonCount(2);
    }

    // ── Atomicity ─────────────────────────────────────────────────────────

    public function test_transaction_failure_leaves_no_partial_write(): void
    {
        $this->assign($this->guruA);
        Sanctum::actingAs($this->guruA);

        $this->postJson('/api/guru/observasi/submit', $this->payload())->assertOk();

        // Fail the hasil UPDATE inside the transaction.
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'update `hasil_observasis`')) {
                throw new \RuntimeException('simulated mid-transaction failure');
            }
        });

        $this->postJson('/api/guru/observasi/submit', $this->payload(['expected_version' => 1]))
            ->assertStatus(500);

        $row = HasilObservasi::where('user_id', $this->kandidat->id)->first();
        $this->assertSame(1, $row->version, 'failed update must not bump the version');
        $this->assertSame(1, HasilObservasi::count());
    }

    // ── Read/print rights unchanged + SMP CBT untouched ──────────────────

    public function test_getHasil_still_readable_by_any_guru_and_exposes_version(): void
    {
        $this->assign($this->guruA);
        Sanctum::actingAs($this->guruA);
        $this->postJson('/api/guru/observasi/submit', $this->payload())->assertOk();

        // A DIFFERENT guru (never assigned) can still read the rekapan.
        Sanctum::actingAs($this->guruB);
        $this->getJson('/api/guru/rekapan-observasi')
            ->assertOk()
            ->assertJsonFragment(['user_id' => $this->kandidat->id, 'version' => 1]);
    }

    public function test_smp_candidate_still_rejected_before_any_assignment_logic(): void
    {
        Sanctum::actingAs($this->guruA);
        $smp = $this->makeUser('Calon SMP', 'orang_tua', 'SMP');

        $this->postJson('/api/guru/observasi/submit', $this->payload(['user_id' => $smp->id]))
            ->assertStatus(422); // E.12D jenjang guard — unchanged, CBT untouched

        $this->assertSame(0, HasilObservasi::count());
    }

    public function test_antrean_still_lists_sd_candidates_unchanged(): void
    {
        // E.12G: antrean now requires an ACTIVE assignment to the logged-in
        // guru. Give guruA the assignment first, then the SD candidate appears.
        $this->assign($this->guruA);
        Sanctum::actingAs($this->guruA);
        $this->getJson('/api/guru/antrean-observasi')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->kandidat->id]);
    }

    // ── Antrean per-guru isolation (E.12G filter fix) ─────────────────────

    /** Guru A sees only candidates ACTIVELY assigned to them; Guru B does not. */
    public function test_antrean_shows_only_candidates_assigned_to_logged_in_guru(): void
    {
        $this->assign($this->guruA); // kandidat assigned to guruA

        Sanctum::actingAs($this->guruA);
        $rowsA = $this->getJson('/api/guru/antrean-observasi')->assertOk()->json();
        $this->assertContains($this->kandidat->id, collect($rowsA)->pluck('id'));

        // Guru B (no assignment) must NOT see guruA's candidate.
        Sanctum::actingAs($this->guruB);
        $rowsB = $this->getJson('/api/guru/antrean-observasi')->assertOk()->json();
        $this->assertNotContains($this->kandidat->id, collect($rowsB)->pluck('id'),
            'guruB must not see a candidate assigned to guruA');
    }

    /** After replacement, the old guru's queue drops the candidate; new guru sees it. */
    public function test_antrean_drops_candidate_from_old_guru_after_reassignment(): void
    {
        $a = $this->assign($this->guruA);

        Sanctum::actingAs($this->guruA);
        $this->getJson('/api/guru/antrean-observasi')->assertOk()
            ->assertJsonFragment(['id' => $this->kandidat->id]);

        // Admin replaces guruA -> guruB.
        $a->update(['status' => 'DIGANTI', 'ended_at' => now()]);
        $this->assign($this->guruB);

        // Old guru: candidate disappears.
        $this->getJson('/api/guru/antrean-observasi')->assertOk()
            ->assertJsonMissing(['id' => $this->kandidat->id]);

        // New guru: candidate appears.
        Sanctum::actingAs($this->guruB);
        $this->getJson('/api/guru/antrean-observasi')->assertOk()
            ->assertJsonFragment(['id' => $this->kandidat->id]);
    }

    /** After revocation, the revoked guru's queue drops the candidate. */
    public function test_antrean_drops_candidate_after_revocation(): void
    {
        $a = $this->assign($this->guruA);

        Sanctum::actingAs($this->guruA);
        $this->getJson('/api/guru/antrean-observasi')->assertOk()
            ->assertJsonFragment(['id' => $this->kandidat->id]);

        $a->update(['status' => 'DICABUT', 'ended_at' => now()]);

        $this->getJson('/api/guru/antrean-observasi')->assertOk()
            ->assertJsonMissing(['id' => $this->kandidat->id]);
    }

    /** An SD candidate with NO assignment at all never appears in any guru's queue. */
    public function test_unassigned_sd_candidate_never_appears_in_antrean(): void
    {
        Sanctum::actingAs($this->guruA);
        $this->getJson('/api/guru/antrean-observasi')->assertOk()
            ->assertJsonMissing(['id' => $this->kandidat->id]);
    }
}
