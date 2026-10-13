<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E.12G — observasi_assignments: one active guru per SD candidate, with
 * permanent history.
 *
 * DATABASE-LEVEL GUARANTEE (MariaDB 10.11):
 *   active_marker TINYINT AS (IF(status='AKTIF',1,NULL)) PERSISTENT
 *   UNIQUE KEY uq_active (user_id, active_marker)
 * MariaDB unique indexes allow multiple NULLs -> exactly one row per user_id
 * may be AKTIF; DIGANTI/DICABUT history rows are unlimited. The generated
 * column is added via raw DDL because Laravel's Blueprint cannot express it
 * (repository precedent for raw DDL in migrations: role-enum migration
 * 2026_04_22_042011 and 2026_10_12_000000).
 *
 * This migration is SAFE TO CREATE but NOT approved to run on shared/dev/prod
 * environments. Running it against the test database (via RefreshDatabase) is
 * the only sanctioned execution path for now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observasi_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');        // calon murid — identity matches hasil_observasis.user_id
            $table->unsignedBigInteger('guru_id');        // must be role='guru' (validated server-side)
            $table->enum('status', ['AKTIF', 'DIGANTI', 'DICABUT']);
            $table->unsignedBigInteger('assigned_by')->nullable(); // admin actor, audit
            $table->string('revoked_reason')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('guru_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('assigned_by')->references('id')->on('users')->onDelete('set null');

            $table->index(['guru_id', 'status'], 'idx_guru_status'); // guru queue listing
            $table->index('user_id', 'idx_user');                    // per-candidate history
        });

        // Generated column + composite unique — raw DDL (Blueprint can't express it).
        DB::statement(
            'ALTER TABLE `observasi_assignments` '
            ."ADD COLUMN `active_marker` TINYINT AS (IF(`status` = 'AKTIF', 1, NULL)) PERSISTENT, "
            .'ADD UNIQUE KEY `uq_active` (`user_id`, `active_marker`)'
        );
    }

    public function down(): void
    {
        // DANGER (documented in design): dropping this table DESTROYS permanent
        // assignment history. Only to be run with explicit owner approval after
        // a manual export. Rollback of the schema is safe for hasil/users data.
        Schema::dropIfExists('observasi_assignments');
    }
};
