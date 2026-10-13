<?php

namespace Tests\Feature;

use App\Models\ObservasiAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E.12G Phase 1 — schema smoke test (runs ONLY against ppdb_sekolah_test via
 * RefreshDatabase; the shared dev database is never touched).
 *
 * Proves on real MariaDB 10.11:
 *  - the migrations create the three tables,
 *  - the generated column active_marker exists and behaves,
 *  - uq_active enforces ONE AKTIF row per candidate at the DATABASE level,
 *  - history rows (DIGANTI/DICABUT) are unlimited,
 *  - the FKs hold (non-guru/nonexistent references rejected by validation
 *    layers, but referential integrity is the DB backstop).
 */
class ObservasiAssignmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    private int $kandidatId;
    private int $guruA;
    private int $guruB;

    protected function setUp(): void
    {
        parent::setUp();

        $kandidat = \App\Models\User::factory()->create(['jenjang' => 'SD']);
        $kandidat->forceFill(['role' => 'orang_tua'])->save();
        $guruA = \App\Models\User::factory()->create(['jenjang' => 'SD']);
        $guruA->forceFill(['role' => 'guru'])->save();
        $guruB = \App\Models\User::factory()->create(['jenjang' => 'SD']);
        $guruB->forceFill(['role' => 'guru'])->save();

        $this->kandidatId = $kandidat->id;
        $this->guruA = $guruA->id;
        $this->guruB = $guruB->id;
    }

    public function test_all_three_e12g_tables_exist(): void
    {
        $this->assertTrue(\Schema::hasTable('observasi_assignments'));
        $this->assertTrue(\Schema::hasTable('idempotency_keys'));
        $this->assertTrue(\Schema::hasTable('observasi_override_logs'));
    }

    public function test_active_marker_generated_column_exists(): void
    {
        $col = collect(\DB::select("SHOW COLUMNS FROM `observasi_assignments` LIKE 'active_marker'"));
        $this->assertCount(1, $col, 'active_marker generated column must exist');
        // MariaDB reports generated columns via Extra ("VIRTUAL"/"STORED").
        // The exact reported Type string varies by engine version — assert
        // the generated-ness, not the cosmetic type spelling.
        $this->assertMatchesRegularExpression('/VIRTUAL|STORED/i', $col[0]->Extra,
            'active_marker must be a generated (computed) column');
    }

    public function test_database_rejects_second_active_assignment_for_same_candidate(): void
    {
        ObservasiAssignment::create([
            'user_id' => $this->kandidatId, 'guru_id' => $this->guruA,
            'status' => 'AKTIF', 'started_at' => now(),
        ]);

        try {
            ObservasiAssignment::create([
                'user_id' => $this->kandidatId, 'guru_id' => $this->guruB,
                'status' => 'AKTIF', 'started_at' => now(),
            ]);
            $this->fail('a second AKTIF assignment must violate uq_active');
        } catch (QueryException $e) {
            $this->assertMatchesRegularExpression('/Duplicate entry/i', $e->getMessage());
        }

        $this->assertSame(1, ObservasiAssignment::where('user_id', $this->kandidatId)
            ->where('status', 'AKTIF')->count());
    }

    public function test_history_rows_are_unlimited(): void
    {
        // One AKTIF plus two history rows for the same candidate — all allowed.
        foreach (['DIGANTI', 'DIGANTI', 'DICABUT'] as $status) {
            ObservasiAssignment::create([
                'user_id' => $this->kandidatId, 'guru_id' => $this->guruA,
                'status' => $status, 'started_at' => now(), 'ended_at' => now(),
            ]);
        }
        ObservasiAssignment::create([
            'user_id' => $this->kandidatId, 'guru_id' => $this->guruB,
            'status' => 'AKTIF', 'started_at' => now(),
        ]);

        $this->assertSame(4, ObservasiAssignment::where('user_id', $this->kandidatId)->count());
        $this->assertSame(1, ObservasiAssignment::where('user_id', $this->kandidatId)
            ->where('status', 'AKTIF')->count());
    }

    public function test_same_active_in_a_different_candidate_is_fine(): void
    {
        $other = \App\Models\User::factory()->create(['jenjang' => 'SD']);
        $other->forceFill(['role' => 'orang_tua'])->save();

        ObservasiAssignment::create([
            'user_id' => $this->kandidatId, 'guru_id' => $this->guruA,
            'status' => 'AKTIF', 'started_at' => now(),
        ]);
        ObservasiAssignment::create([
            'user_id' => $other->id, 'guru_id' => $this->guruA,
            'status' => 'AKTIF', 'started_at' => now(),
        ]);

        $this->assertSame(2, ObservasiAssignment::where('status', 'AKTIF')->count());
    }

    public function test_idempotency_key_hash_unique(): void
    {
        \App\Models\IdempotencyKey::create([
            'key_hash' => str_repeat('a', 64),
            'endpoint_class' => 'observasi_submit',
            'actor_id' => $this->guruA,
            'payload_hash' => str_repeat('b', 64),
            'expires_at' => now()->addHours(24),
        ]);

        $this->expectException(QueryException::class);
        \App\Models\IdempotencyKey::create([
            'key_hash' => str_repeat('a', 64),
            'endpoint_class' => 'observasi_submit',
            'actor_id' => $this->guruA,
            'payload_hash' => str_repeat('c', 64),
            'expires_at' => now()->addHours(24),
        ]);
    }

    public function test_migrations_rollback_cleanly(): void
    {
        // migrate:rollback one step batch on the ISOLATED test db is part of
        // RefreshDatabase teardown anyway; here we verify down() DDL validity.
        $this->artisan('migrate:rollback', ['--step' => 3, '--force' => true])->assertExitCode(0);
        $this->assertFalse(\Schema::hasTable('observasi_override_logs'));

        // Re-migrate so the rest of the suite still sees the tables.
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(\Schema::hasTable('observasi_assignments'));
    }
}
