<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E.12G Phase 3 — explicit version column for optimistic concurrency on
 * hasil_observasis.
 *
 * CONTRACT:
 *  - First write of a candidate's hasil creates the row with version = 1.
 *  - Every successful update increments version by exactly 1.
 *  - Clients must send expected_version on updates; mismatch = HTTP 409
 *    stale_version (no auto-merge, newest data wins by refusing stale writes).
 *  - Pre-existing rows (written before versioning existed) are backfilled to
 *    version = 1 — the first "versioned" write they will see is an update
 *    from 1 -> 2, and the client obtains that 1 from getHasil.
 *
 * NOTE (Phase 3 boundary): this migration is SOURCE ONLY for shared/dev/prod.
 * It runs exclusively on the isolated test database via RefreshDatabase.
 * The UNIQUE constraint on user_id is deliberately NOT added here — that is
 * a separate E.12F step gated on per-environment duplicate audits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hasil_observasis', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('rekomendasi_akhir');
        });

        // Defensive backfill: rows created in the same request-batch as the
        // column addition would carry the DEFAULT anyway; make existing rows
        // explicit. Harmless no-op when the table is empty.
        DB::table('hasil_observasis')->whereNull('version')->update(['version' => 1]);
    }

    public function down(): void
    {
        Schema::table('hasil_observasis', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
