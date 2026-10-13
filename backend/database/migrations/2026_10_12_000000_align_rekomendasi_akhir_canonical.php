<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E.12C — align the `rekomendasi_akhir` enum with the canonical contract.
 *
 * CANONICAL CONTRACT (product decision, confirmed 2026):
 *   LULUS            — accepted.
 *   LULUS BERSYARAT  — accepted WITH special conditions/agreement (e.g. the
 *                      candidate must undergo a psychological assessment and
 *                      provide the psychologist's report as part of the
 *                      conditions). It is a DECIDED acceptance-with-conditions
 *                      outcome, NOT an "undecided" status.
 *   TIDAK LULUS      — rejected.
 *
 * WHY A NEW MIGRATION
 * -------------------
 * The original enum ('Diterima','Dipertimbangkan','Ditolak') was defined in
 * 2026_04_22_040904_create_hasil_observasis_table.php, which is intentionally
 * left untouched. The guru rekapan editor, print document and this contract
 * all use the three canonical values above; under STRICT_TRANS_TABLES every
 * write of a canonical value used to fail with
 * SQLSTATE[01000] 1265 Data truncated (verified empirically against
 * ppdb_sekolah_test before this migration was written).
 *
 * DATA-SAFETY CONTRACT
 * --------------------
 *   Diterima     -> LULUS         (safe: accepted == accepted)
 *   Ditolak      -> TIDAK LULUS   (safe: rejected == rejected)
 *   Dipertimbangkan              (AMBIGUOUS — HALTS the migration)
 *
 * `Dipertimbangkan` ("under consideration") historically means "not yet
 * decided", which is semantically DIFFERENT from `LULUS BERSYARAT`
 * ("accepted with conditions" — a decision). Mapping it automatically would
 * fabricate decisions that were never made, so its presence aborts the
 * migration with a clear message for separate manual handling. Product has
 * confirmed no current data uses it; the guard exists precisely because not
 * every environment can be assumed empty.
 *
 * WHY A THREE-STAGE ALTER (expand -> map -> final)
 * ------------------------------------------------
 * Under STRICT_TRANS_TABLES MySQL validates enum values per row, so data
 * cannot be mapped onto values the current enum does not contain yet. The
 * first ALTER therefore widens the enum to a UNION of legacy and canonical
 * values, the mapping stage runs inside that superset, and the final ALTER
 * narrows the domain back. (A naive map-then-alter was caught failing by the
 * migration tests with SQLSTATE 1265 and rewritten as this three-stage form.)
 *
 * ROLLBACK (`down()`) IS SEMANTICALLY LOSSY — DOCUMENTED
 * ------------------------------------------------------
 * `down()` maps `LULUS` -> `Diterima`, `TIDAK LULUS` -> `Ditolak` and
 * `LULUS BERSYARAT` -> `Dipertimbangkan`, then restores the old enum. The
 * first two are meaning-preserving; the third is NOT: an accepted-with-
 * conditions decision loses its "accepted" half and degrades to "under
 * consideration". Rollback therefore restores the SCHEMA and every ROW, but
 * the semantics of any `LULUS BERSYARAT` row are degraded. This trade-off is
 * documented here deliberately rather than silently dropping or NULLing data.
 *
 * SAFE EXECUTION ENVIRONMENTS
 * ---------------------------
 * Designed to run only against the verified test database
 * `ppdb_sekolah_test` (pinned by tests/bootstrap.php). Never run against
 * development or production in this task.
 */
return new class extends Migration
{
    /** Canonical values — single source of truth for this migration. */
    private const CANONICAL = ['LULUS', 'LULUS BERSYARAT', 'TIDAK LULUS'];

    /** Legacy values that map cleanly onto the canonical contract. */
    private const LEGACY_ACCEPTED = 'Diterima';

    private const LEGACY_REJECTED = 'Ditolak';

    /** Legacy value with no safe automatic mapping — halts the migration. */
    private const LEGACY_AMBIGUOUS = 'Dipertimbangkan';

    public function up(): void
    {
        if (! Schema::hasTable('hasil_observasis')) {
            return;
        }

        // ── 1. Refuse to proceed while ambiguous data exists ──────────────
        // Counted BEFORE any DDL so the migration fails fast and leaves the
        // schema untouched. Emptiness of both local databases was verified
        // immediately before this migration was authored, but the guard
        // exists because other environments cannot be assumed empty.
        $ambiguous = DB::table('hasil_observasis')
            ->where('rekomendasi_akhir', self::LEGACY_AMBIGUOUS)
            ->count();

        if ($ambiguous > 0) {
            // Deliberately abort with an explicit, actionable message. No
            // DDL or data write has happened yet, so nothing needs rolling
            // back — the schema is left exactly as it was.
            throw new RuntimeException(sprintf(
                'E.12C migration halted: found %d row(s) in hasil_observasis with the '
                    .'legacy value `%s`. This value means "under consideration" (no decision '
                    .'made), which is NOT equivalent to `%s` ("accepted with conditions", a '
                    .'decision). Automatic mapping would fabricate decisions that were never '
                    .'made. Resolve these rows manually (re-assess and set a canonical '
                    .'value), then re-run the migration. No schema changes were applied.',
                $ambiguous,
                self::LEGACY_AMBIGUOUS,
                'LULUS BERSYARAT'
            ));
        }

        // ── 2. Widen the enum to a UNION of legacy+canonical values ─────
        // Under STRICT_TRANS_TABLES MySQL validates values per row, so the
        // mapping below cannot write canonical literals while the legacy
        // enum is still in place (SQLSTATE 1265 — caught by the migration
        // tests, which is why this is a three-stage alter). After this stage
        // every legacy AND canonical value is valid, so the mapping reads
        // and writes are both safe.
        DB::statement(
            "ALTER TABLE `hasil_observasis` "
            ."MODIFY `rekomendasi_akhir` ENUM('Diterima','Dipertimbangkan','Ditolak','LULUS','LULUS BERSYARAT','TIDAK LULUS') NULL DEFAULT NULL"
        );

        // ── 3. Map legacy data to canonical values ─────────────────────
        DB::table('hasil_observasis')
            ->where('rekomendasi_akhir', self::LEGACY_ACCEPTED)
            ->update(['rekomendasi_akhir' => 'LULUS']);

        DB::table('hasil_observasis')
            ->where('rekomendasi_akhir', self::LEGACY_REJECTED)
            ->update(['rekomendasi_akhir' => 'TIDAK LULUS']);

        // ── 4. Redefine the enum to the canonical domain ───────────────
        // No row holds a legacy value any more (ambiguous rows halted at
        // stage 1), so narrowing the domain back to the three canonical
        // values cannot lose data. Raw DDL rather than ->change(): we need
        // the exact nullable enum definition. The column stays NULLable with
        // default NULL, exactly as before.
        DB::statement(
            "ALTER TABLE `hasil_observasis` "
            ."MODIFY `rekomendasi_akhir` ENUM('LULUS','LULUS BERSYARAT','TIDAK LULUS') NULL DEFAULT NULL"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('hasil_observasis')) {
            return;
        }

        // ── 1. Warn loudly before the lossy degradation ──────────────────
        // `LULUS BERSYARAT` has NO meaning-preserving legacy target. Per the
        // documented lossy-rollback decision, it degrades to `Dipertimbangkan`
        // rather than being dropped — but that degradation is loud, not
        // silent: the operator is told how many rows are affected before any
        // change is made, and may abort here for a manual export instead.
        $bersyarat = DB::table('hasil_observasis')
            ->where('rekomendasi_akhir', 'LULUS BERSYARAT')
            ->count();

        if ($bersyarat > 0) {
            fwrite(STDERR, sprintf(
                "[E.12C] ROLLBACK WARNING: %d row(s) carry `LULUS BERSYARAT`, which has no "
                    .'meaning-preserving legacy value. They will degrade to `%s` '
                    .'("under consideration") — the "accepted" half of their meaning is '
                    ."lost. This is the documented lossy-rollback trade-off; no rows are "
                    ."deleted or NULLed. Export these rows first if you need the decision "
                    ."semantics preserved.\n",
                $bersyarat,
                self::LEGACY_AMBIGUOUS
            ));
        }

        // ── 2. Widen the enum to a UNION of legacy+canonical values ─────
        // Mirror of up(): canonical literals cannot be written to legacy
        // values while the canonical enum is in place (STRICT_TRANS_TABLES
        // per-row validation), so widen before mapping.
        DB::statement(
            "ALTER TABLE `hasil_observasis` "
            ."MODIFY `rekomendasi_akhir` ENUM('Diterima','Dipertimbangkan','Ditolak','LULUS','LULUS BERSYARAT','TIDAK LULUS') NULL DEFAULT NULL"
        );

        // ── 3. Map canonical data back to legacy values ────────────────
        // LULUS -> Diterima and TIDAK LULUS -> Ditolak are meaning-preserving.
        // LULUS BERSYARAT -> Dipertimbangkan is the documented lossy mapping.
        DB::table('hasil_observasis')
            ->where('rekomendasi_akhir', 'LULUS')
            ->update(['rekomendasi_akhir' => self::LEGACY_ACCEPTED]);

        DB::table('hasil_observasis')
            ->where('rekomendasi_akhir', 'TIDAK LULUS')
            ->update(['rekomendasi_akhir' => self::LEGACY_REJECTED]);

        DB::table('hasil_observasis')
            ->where('rekomendasi_akhir', 'LULUS BERSYARAT')
            ->update(['rekomendasi_akhir' => self::LEGACY_AMBIGUOUS]);

        // ── 4. Restore the legacy enum definition ──────────────────────
        DB::statement(
            "ALTER TABLE `hasil_observasis` "
            ."MODIFY `rekomendasi_akhir` ENUM('Diterima','Dipertimbangkan','Ditolak') NULL DEFAULT NULL"
        );
    }
};
