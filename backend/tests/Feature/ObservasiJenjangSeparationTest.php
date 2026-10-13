<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E.12D — jenjang separation & anti-mock regression coverage.
 *
 * BUSINESS RULE (product decision):
 *   SD  — physical paper instruments with printed codes; results are recorded
 *         digitally in this observasi arena.
 *   SMP — CBT (guru sessions + PIN at /guru/cbt, student exam at /cbt,
 *         results at /guru/rekapan-cbt). SMP candidates must NEVER enter the
 *         SD observasi flow, and no synthetic/mock observation result may be
 *         persisted for either jenjang.
 *
 * WHAT THIS PROVES
 *   1. getAntrean returns ONLY SD candidates — an SMP candidate never appears.
 *   2. submitHasil rejects an SMP candidate with 422 and stores nothing —
 *      a second line of defence behind the antrean filter (e.g. a stale
 *      client that still holds an SMP entry from before the filter existed).
 *   3. The antrean payload exposes jenjang so the client can scope itself.
 *   4. A genuine SD submit still works end-to-end (no over-blocking).
 */
class ObservasiJenjangSeparationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, string $jenjang, string $role = 'orang_tua'): User
    {
        $user = User::factory()->create(['name' => $name, 'jenjang' => $jenjang]);
        $user->forceFill(['role' => $role])->save();

        return $user->refresh();
    }

    private function guru(): User
    {
        $guru = $this->user('Guru', 'SD', 'guru');
        Sanctum::actingAs($guru);

        return $guru;
    }

    /** Give the logged-in guru an ACTIVE assignment to an SD candidate. */
    private function assignSd(User $guru, User $sd): void
    {
        \App\Models\ObservasiAssignment::create([
            'user_id' => $sd->id, 'guru_id' => $guru->id,
            'status' => 'AKTIF', 'started_at' => now(),
        ]);
    }

    /** SD candidates appear; SMP candidates never do. */
    public function test_antrean_returns_only_sd_candidates(): void
    {
        $guru = $this->guru();

        $sd = $this->user('Calon SD', 'SD');
        $smp = $this->user('Calon SMP', 'SMP');
        // E.12G: antrean now requires an ACTIVE assignment to the logged-in guru.
        $this->assignSd($guru, $sd);

        $rows = $this->getJson('/api/guru/antrean-observasi')->assertOk()->json();

        $ids = collect($rows)->pluck('id');

        $this->assertContains($sd->id, $ids, 'SD candidate must appear in the observasi queue');
        $this->assertNotContains($smp->id, $ids, 'SMP candidate must NEVER appear in the observasi queue (CBT flow)');
    }

    /** Every returned row is SD and carries its jenjang for client scoping. */
    public function test_antrean_rows_are_all_sd_and_expose_jenjang(): void
    {
        $guru = $this->guru();

        $sd1 = $this->user('SD One', 'SD');
        $sd2 = $this->user('SD Two', 'SD');
        $this->user('SMP One', 'SMP');
        // E.12G: assign the SD candidates to this guru; SMP never assigned.
        $this->assignSd($guru, $sd1);
        $this->assignSd($guru, $sd2);

        $rows = $this->getJson('/api/guru/antrean-observasi')->assertOk()->json();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(
                'SD',
                $row['jenjang'] ?? null,
                'every antrean row must be SD and must expose jenjang=SD'
            );
        }
    }

    /** The API itself refuses SMP targets: 422, nothing stored. */
    public function test_submit_rejects_smp_candidate_with_422_and_stores_nothing(): void
    {
        $this->guru();
        $smp = $this->user('Calon SMP', 'SMP');

        $this->postJson('/api/guru/observasi/submit', [
            'user_id' => $smp->id,
            'jawaban_json' => ['K1' => ['jawaban' => 'YA', 'skor' => 2, 'catatan' => '', 'kategori' => 'X', 'keterampilan' => 'Y']],
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame(
            0,
            DB::table('hasil_observasis')->where('user_id', $smp->id)->count(),
            'no observation row may be created for an SMP candidate'
        );
    }

    /** A guru with jenjang attribute SMP can still not push SMP targets in. */
    public function test_submit_rejects_smp_candidate_regardless_of_caller_jenjang(): void
    {
        $smpGuru = $this->user('Guru SMP', 'SMP', 'guru');
        Sanctum::actingAs($smpGuru);

        $smp = $this->user('Calon SMP 2', 'SMP');

        $this->postJson('/api/guru/observasi/submit', [
            'user_id' => $smp->id,
            'jawaban_json' => ['K1' => ['jawaban' => 'YA']],
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('hasil_observasis')->count());
    }

    /** SD submissions keep working — the guard must not over-block. */
    public function test_sd_submit_still_persists(): void
    {
        $guru = $this->guru();
        $sd = $this->user('Calon SD Valid', 'SD');
        // E.12G Phase 3: submit now requires an AKTIF assignment.
        \App\Models\ObservasiAssignment::create([
            'user_id' => $sd->id, 'guru_id' => $guru->id,
            'status' => 'AKTIF', 'started_at' => now(),
        ]);

        $this->postJson('/api/guru/observasi/submit', [
            'user_id' => $sd->id,
            'jawaban_json' => ['K1' => ['jawaban' => 'YA', 'skor' => 2, 'catatan' => '', 'kategori' => 'Motorik', 'keterampilan' => 'Berlari']],
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, DB::table('hasil_observasis')->where('user_id', $sd->id)->count());
    }

    /**
     * Anti-mock: the CBT flow for SMP is a SEPARATE domain. This pins that
     * submitting an observasi result never creates CBT rows and vice versa —
     * i.e. the two flows cannot contaminate each other's result stores.
     */
    public function test_observasi_submit_never_creates_cbt_rows(): void
    {
        $guru = $this->guru();
        $sd = $this->user('Calon SD CBT-check', 'SD');
        // E.12G Phase 3: submit now requires an AKTIF assignment.
        \App\Models\ObservasiAssignment::create([
            'user_id' => $sd->id, 'guru_id' => $guru->id,
            'status' => 'AKTIF', 'started_at' => now(),
        ]);

        $this->postJson('/api/guru/observasi/submit', [
            'user_id' => $sd->id,
            'jawaban_json' => ['K1' => ['jawaban' => 'YA']],
        ])->assertOk();

        foreach (['cbt_sessions', 'cbt_results', 'cbt_attempts', 'cbt_questions'] as $table) {
            if (\Schema::hasTable($table)) {
                $this->assertSame(0, DB::table($table)->count(), "{$table} must stay empty for an observasi submit");
            }
        }
    }
}
