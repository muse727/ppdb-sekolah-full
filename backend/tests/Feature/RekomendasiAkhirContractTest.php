<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E.12C — canonical `rekomendasi_akhir` contract regression coverage.
 *
 * THE BUG UNDER TEST
 * ------------------
 * The DB enum was ('Diterima','Dipertimbangkan','Ditolak') while the guru
 * rekapan editor posts 'LULUS' / 'LULUS BERSYARAT' / 'TIDAK LULUS'. Under the
 * live sql_mode (STRICT_TRANS_TABLES on) every such write failed with
 * SQLSTATE[01000] 1265 Data truncated — HTTP 500, nothing stored — so the
 * rekapan editor's save was completely broken. (Verified empirically against
 * ppdb_sekolah_test before the fix.)
 *
 * THE CONTRACT (product decision)
 * -------------------------------
 *   LULUS           — accepted
 *   LULUS BERSYARAT — accepted with special conditions/agreement (a DECIDED
 *                     acceptance, e.g. psychologist-report condition — NOT
 *                     "undecided")
 *   TIDAK LULUS     — rejected
 * Enforced by migration 2026_10_12_000000_align_rekomendasi_akhir_canonical
 * (DB enum) and ObservasiController::REKOMENDASI_AKHIR_VALUES (validation).
 *
 * FRONTEND BEHAVIOUR NOT COVERED HERE
 * -----------------------------------
 * The SIAP_CETAK localStorage flag lives client-side only. It is set inside
 * the try-block AFTER the awaited POST succeeds (guru/rekapan/page.tsx), so a
 * failed save can never flip it — that property is guaranteed by code review
 * here, not by this backend suite.
 */
class RekomendasiAkhirContractTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name = 'Parent', string $role = 'orang_tua'): User
    {
        $user = User::factory()->create(['name' => $name, 'jenjang' => 'SD']);
        $user->forceFill(['role' => $role])->save();

        return $user->refresh();
    }

    private function guru(): User
    {
        $guru = $this->user('Guru', 'guru');
        Sanctum::actingAs($guru);

        return $guru;
    }

    /**
     * E.12G Phase 3: the submit endpoint now requires an AKTIF assignment
     * for the acting guru. Test helper — creates (or reuses) the assignment
     * so the enum-contract tests below keep exercising the SAME payload
     * semantics they always did, under the new authorization contract.
     */
    private function assignGuru(User $guru, User $parent): void
    {
        \App\Models\ObservasiAssignment::updateOrCreate(
            ['user_id' => $parent->id, 'status' => 'AKTIF'],
            ['guru_id' => $guru->id, 'started_at' => now()],
        );
    }

    /** Column definition straight from MySQL — the enum IS the contract. */
    private function enumDefinition(): string
    {
        $column = DB::select(
            "SHOW COLUMNS FROM hasil_observasis LIKE 'rekomendasi_akhir'"
        )[0];

        return $column->Type;
    }

    private function submit(User $parent, ?string $rekomendasi = 'LULUS')
    {
        $guru = auth()->user();
        // E.12G Phase 3: ensure the acting guru is the assigned one.
        if ($guru && $guru->role === 'guru') {
            $this->assignGuru($guru, $parent);
        }

        $payload = [
            'user_id' => $parent->id,
            'jawaban_json' => ['K01' => ['jawaban' => 'YA']],
        ];

        // E.12G Phase 3: optimistic concurrency — send the current version
        // when a row already exists (null for the first write).
        $existing = \App\Models\HasilObservasi::where('user_id', $parent->id)->first();
        if ($existing) {
            $payload['expected_version'] = $existing->version;
        }

        if ($rekomendasi !== null) {
            $payload['rekomendasi_akhir'] = $rekomendasi;
        }

        return $this->postJson('/api/guru/observasi/submit', $payload);
    }

    private function storedRekomendasi(User $parent): ?string
    {
        $value = DB::table('hasil_observasis')->where('user_id', $parent->id)->value('rekomendasi_akhir');

        return $value === null ? null : (string) $value;
    }

    /** Requirement: the migrated enum is exactly the three canonical values. */
    public function test_enum_matches_the_canonical_contract(): void
    {
        // RefreshDatabase migrated the schema fresh; the new migration must
        // have reshaped the enum created by the old one.
        $this->assertSame(
            "enum('LULUS','LULUS BERSYARAT','TIDAK LULUS')",
            $this->enumDefinition()
        );
    }

    /** Requirement: all three canonical values persist (the bug used to 500). */
    public function test_all_three_canonical_values_persist(): void
    {
        $guru = $this->guru();

        foreach (['LULUS', 'LULUS BERSYARAT', 'TIDAK LULUS'] as $value) {
            $parent = $this->user('Parent '.md5($value));

            $this->submit($parent, $value)
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertSame(
                $value,
                $this->storedRekomendasi($parent),
                "value {$value} must persist (used to fail with SQLSTATE 1265)"
            );
        }
    }

    /**
     * Requirement: a guru saving WITHOUT a recommendation (the assessment
     * arena flow) must still work and leave the column NULL — not coerce it.
     */
    public function test_omitting_the_field_stores_null(): void
    {
        $guru = $this->guru();
        $parent = $this->user();

        $this->submit($parent, null)->assertOk();

        $this->assertNull($this->storedRekomendasi($parent));
    }

    /**
     * Requirement: invalid values must be rejected with 422 BEFORE any query
     * writes to the database. The old `nullable|string` rule let any string
     * reach the enum column and blow up as a 500.
     */
    public function test_invalid_values_are_rejected_before_touching_the_database(): void
    {
        $guru = $this->guru();
        $parent = $this->user();

        // Genuinely invalid values: 422, and NO row may be created — the
        // validation failure happened before the insert query ran.
        foreach ([
            'Diterima', 'Dipertimbangkan', 'Ditolak', // legacy vocabulary
            'lulus', 'lulus bersyarat', // case variants — the enum is exact
            'LULUS_BERSYARAT', 'TIDAK_LULUS', // separator variants
            'x', str_repeat('A', 1001), // junk, oversize
        ] as $value) {
            $this->submit($parent, $value)
                ->assertStatus(422, "value [{$value}] must be rejected with 422");

            $this->assertSame(
                0,
                DB::table('hasil_observasis')->where('user_id', $parent->id)->count(),
                "value [{$value}] must not create any row"
            );
        }

        // An empty string is NOT a validation error: ConvertEmptyStringsToNull
        // normalises it to null, `nullable` accepts it, and `filled()` is false
        // so no recommendation is written — identical to omitting the field.
        // The row IS created (arena-style no-recommendation save).
        $this->submit($parent, '')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull($this->storedRekomendasi($parent));
    }

    /** A prior valid save must survive a later rejected one untouched. */
    public function test_rejected_save_leaves_previous_data_intact(): void
    {
        $guru = $this->guru();
        $parent = $this->user();

        $this->submit($parent, 'LULUS BERSYARAT')->assertOk();
        $before = DB::table('hasil_observasis')->where('user_id', $parent->id)->first();

        $this->submit($parent, 'Diterima')->assertStatus(422);

        $after = DB::table('hasil_observasis')->where('user_id', $parent->id)->first();

        $this->assertSame('LULUS BERSYARAT', $after->rekomendasi_akhir);
        $this->assertSame($before->id, $after->id);
        $this->assertSame($before->jawaban_json, $after->jawaban_json);
    }

    /**
     * Requirement: the value round-trips through the rekapan reader, so the
     * editor and the printed document (which renders the
     * LULUS-BERSYARAT agreement note off this value) receive the truth.
     */
    public function test_rekomendasi_round_trips_through_the_rekapan_reader(): void
    {
        $guru = $this->guru();
        $parent = $this->user('Roundtrip');

        $this->submit($parent, 'LULUS BERSYARAT')->assertOk();

        $rows = $this->getJson('/api/guru/rekapan-observasi')->assertOk()->json();
        $mine = collect($rows)->firstWhere('user_id', $parent->id);

        $this->assertNotNull($mine);
        $this->assertSame('LULUS BERSYARAT', $mine['rekomendasi_akhir']);
    }

    /**
     * The status_kelulusan / status_siswa domains are UNRELATED and must be
     * untouched by this contract (explicit product constraint).
     */
    public function test_recommendation_write_never_touches_status_kelulusan_or_siswa(): void
    {
        $guru = $this->guru();
        $parent = $this->user();

        $this->submit($parent, 'TIDAK LULUS')->assertOk();

        $fresh = $parent->fresh();

        $this->assertSame('BELUM', $fresh->status_kelulusan);
        $this->assertNull($fresh->status_siswa);
    }

    /**
     * Requirement: guru-only endpoint behaviour must not regress. The arena
     * (same endpoint) and the rekapan editor both write through here.
     */
    public function test_endpoint_stays_guru_only(): void
    {
        $parent = $this->user();

        Sanctum::actingAs($this->user('Admin', 'admin'));
        $this->submit($parent, 'LULUS')->assertForbidden();

        Sanctum::actingAs($this->user('Parent', 'orang_tua'));
        $this->submit($parent, 'LULUS')->assertForbidden();

        $this->assertSame(0, DB::table('hasil_observasis')->count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // MIGRATION up()/down() BEHAVIOUR — exercised on ppdb_sekolah_test only
    // (RefreshDatabase pins it via tests/bootstrap.php).
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Requirement: up() maps legacy rows safely and down() round-trips the
     * schema. Legacy rows are INSERTed directly via SQL (they cannot be
     * written through the API anymore).
     */
    public function test_migration_up_and_down_with_legacy_data(): void
    {
        $parent = $this->user('Legacy');
        $guru = $this->user('Legacy Guru', 'guru');

        // Seed canonical rows first (schema is canonical after RefreshDatabase);
        // the down() below must map them back to legacy values.
        DB::table('hasil_observasis')->insert([
            ['user_id' => $parent->id, 'penilai_id' => $guru->id, 'jawaban_json' => json_encode(['K01' => 'YA']), 'rekomendasi_akhir' => 'LULUS', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $guru->id, 'penilai_id' => $guru->id, 'jawaban_json' => json_encode(['K01' => 'TIDAK']), 'rekomendasi_akhir' => 'TIDAK LULUS', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── down(): canonical -> legacy (documented lossy for BERSYARAT) ──
        // E.12G note: newer migrations (2026_10_20_*) now exist, so the
        // E.12C migration is no longer "the newest" — target it explicitly
        // by path instead of assuming --step=1 reaches it.
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_10_12_000000_align_rekomendasi_akhir_canonical.php',
        ])->assertExitCode(0);

        $this->assertSame(
            "enum('Diterima','Dipertimbangkan','Ditolak')",
            $this->enumDefinition(),
            'down() must restore the legacy enum'
        );

        // down() mapped LULUS -> Diterima and TIDAK LULUS -> Ditolak.
        $this->assertSame('Diterima', $this->storedRekomendasi($parent));
        $this->assertSame('Ditolak', $this->storedRekomendasi($guru));

        // ── up(): legacy -> canonical ────────────────────────────────────
        $this->artisan('migrate')->run();

        $this->assertSame(
            "enum('LULUS','LULUS BERSYARAT','TIDAK LULUS')",
            $this->enumDefinition(),
            'up() must restore the canonical enum'
        );

        // Diterima -> LULUS, Ditolak -> TIDAK LULUS: data survived the full
        // down+up round-trip.
        $this->assertSame('LULUS', $this->storedRekomendasi($parent));
        $this->assertSame('TIDAK LULUS', $this->storedRekomendasi($guru));
    }

    /**
     * Requirement: finding `Dipertimbangkan` must HALT the migration with a
     * clear message, leaving schema and data untouched.
     */
    public function test_migration_halts_on_ambiguous_legacy_value(): void
    {
        $guru = $this->user('Ambiguous Guru', 'guru');

        // Roll the schema back to legacy FIRST (schema is canonical after
        // RefreshDatabase; `Dipertimbangkan` cannot be inserted into the
        // canonical enum). Target the E.12C migration explicitly by path
        // (E.12G added newer migrations after it).
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_10_12_000000_align_rekomendasi_akhir_canonical.php',
        ])->assertExitCode(0);
        $this->assertSame(
            "enum('Diterima','Dipertimbangkan','Ditolak')",
            $this->enumDefinition()
        );

        // With the legacy enum in place, insert the ambiguous row.
        $ambiguousParent = $this->user('Ambiguous Two');
        DB::table('hasil_observasis')->insert([
            'user_id' => $ambiguousParent->id,
            'penilai_id' => $guru->id,
            'jawaban_json' => json_encode(['K01' => 'YA']),
            'rekomendasi_akhir' => 'Dipertimbangkan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ── up() must HALT ──────────────────────────────────────────────
        try {
            $this->artisan('migrate')->run();
            $this->fail('up() should have thrown on Dipertimbangkan data');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('E.12C migration halted', $e->getMessage());
            $this->assertStringContainsString('Dipertimbangkan', $e->getMessage());
        }

        // Schema and data must be untouched by the halted attempt.
        $this->assertSame(
            "enum('Diterima','Dipertimbangkan','Ditolak')",
            $this->enumDefinition(),
            'schema must remain legacy after a halted up()'
        );

        $rows = DB::table('hasil_observasis')->where('user_id', $ambiguousParent->id)->get();
        $this->assertTrue($rows->contains(fn ($r) => $r->rekomendasi_akhir === 'Dipertimbangkan'));
    }
}
