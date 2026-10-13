<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B.7.2 — Canonical official-student lifecycle state on users.id.
 *
 * users.id remains the canonical student identity; no students/applicants/
 * children table is introduced. status_siswa is the single canonical
 * official-student field:
 *
 *   NULL                no continuation decision has been committed yet
 *                       (legacy users STAY NULL — they are never auto-promoted
 *                       by LULUS / payment SUCCESS / agreement alone)
 *   TERDAFTAR           explicit MELANJUTKAN committed by
 *                       DaftarUlangCompletionService after ALL server-side
 *                       prerequisites pass
 *   MENGUNDURKAN_DIRI   withdrawn (B.7.1; withdrawal remains terminal)
 *
 * continued_at records the committed MELANJUTKAN timestamp. It is an explicit
 * field (rather than overloading updated_at, which any unrelated user edit
 * would rewrite) so the transition time is reconstructable exactly once and
 * is never overwritten on idempotent replay.
 *
 * Both transitions are terminal for this milestone: NULL -> TERDAFTAR or
 * NULL -> MENGUNDURKAN_DIRI only. The completion service enforces this under
 * a users row lock; the CHECK constraints are a DB-level last line of defence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status_siswa', 20)->nullable()->after('status_kelulusan');
            $table->timestamp('continued_at')->nullable()->after('status_siswa');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_siswa_valid_check
            CHECK (status_siswa IS NULL OR status_siswa IN ('TERDAFTAR', 'MENGUNDURKAN_DIRI'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_continued_at_consistency_check
            CHECK ((status_siswa = 'TERDAFTAR' AND continued_at IS NOT NULL)
                OR (status_siswa IS NULL AND continued_at IS NULL)
                OR (status_siswa = 'MENGUNDURKAN_DIRI' AND continued_at IS NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_status_siswa_valid_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_continued_at_consistency_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['status_siswa', 'continued_at']);
        });
    }
};
