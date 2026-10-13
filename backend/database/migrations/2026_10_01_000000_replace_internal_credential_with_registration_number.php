<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4-D.2 — INTERNAL credential is a single 10-digit CSPRNG registration_number.
 *
 * The legacy design used NISN (public identifier) + access_code (secret). That
 * two-factor shape is removed entirely for jalur INTERNAL:
 *
 *   - access_code  (the old secret)      -> renamed to registration_number (VARCHAR 10)
 *   - nisn         (the old identifier)  -> DROPPED from the whitelist
 *
 * registration_number is a BEARER credential: knowing the 10 digits alone is
 * sufficient and necessary to register the bound candidate. It is CSPRNG-drawn,
 * unique, single-use, and never derived from nisn/nik/nama/tanggal_lahir/id/
 * timestamp/counter.
 *
 * NISN/NIK are NOT stored here. They belong to the post-registration biodata
 * stage, never to the INTERNAL credential.
 *
 * Column-type note: legacy access_code was VARCHAR(12); registration_number is
 * exactly 10 chars. `->change()` requires doctrine/dbal (not installed), so the
 * resize uses a raw ALTER — safe on MySQL, the only supported driver here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whitelists', function (Blueprint $table) {
            // Drop the legacy unique index BEFORE the rename so it does not carry
            // an obsolete name/column mapping across the rename.
            $table->dropUnique('whitelists_access_code_unique');
        });

        Schema::table('whitelists', function (Blueprint $table) {
            $table->renameColumn('access_code', 'registration_number');
        });

        // Tighten the bearer credential to exactly 10 characters (raw ALTER; no dbal).
        DB::statement('ALTER TABLE whitelists MODIFY registration_number VARCHAR(10) NULL');

        Schema::table('whitelists', function (Blueprint $table) {
            $table->unique('registration_number', 'whitelists_registration_number_unique');
        });

        Schema::table('whitelists', function (Blueprint $table) {
            // NISN is no longer part of the INTERNAL whitelist credential nor
            // required candidate data. Drop its unique index, then the column.
            $table->dropUnique(['nisn']);
            $table->dropColumn('nisn');
        });
    }

    public function down(): void
    {
        Schema::table('whitelists', function (Blueprint $table) {
            $table->string('nisn', 20)->nullable();
            $table->unique('nisn');
        });

        Schema::table('whitelists', function (Blueprint $table) {
            $table->dropUnique('whitelists_registration_number_unique');
            $table->renameColumn('registration_number', 'access_code');
        });

        DB::statement('ALTER TABLE whitelists MODIFY access_code VARCHAR(12) NULL');

        Schema::table('whitelists', function (Blueprint $table) {
            $table->unique('access_code', 'whitelists_access_code_unique');
        });
    }
};
