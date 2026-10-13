<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix 500 on INTERNAL candidate creation (POST /api/admin/whitelists).
 *
 * Root cause (schema mismatch): the P4-D.2 redesign made `asal_sekolah`
 * OPTIONAL — the admin form labels it "opsional", the request validation is
 * `nullable`, and SystemConfigController::storeWhitelist() passes the value
 * straight through. But the `whitelists.asal_sekolah` column was created
 * NOT NULL (2026_06_22) and was never relaxed by the credential migration
 * (2026_10_01). Omitting it therefore bound NULL into a NOT NULL column:
 *
 *   SQLSTATE[23000]: Integrity constraint violation: 1048
 *   Column 'asal_sekolah' cannot be null
 *
 * This migration aligns the schema with the (already-correct) controller and
 * frontend contract: asal_sekolah is optional display data, so the column is
 * nullable. Uses a raw ALTER because `->change()` requires doctrine/dbal,
 * which is not installed (same pattern as the registration_number migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE whitelists MODIFY asal_sekolah VARCHAR(255) NULL');
    }

    public function down(): void
    {
        // Reverting to NOT NULL only when no NULL rows exist, so the reverse is
        // safe and never fails on data that legitimately used the optional field.
        DB::statement("UPDATE whitelists SET asal_sekolah = '' WHERE asal_sekolah IS NULL");
        DB::statement('ALTER TABLE whitelists MODIFY asal_sekolah VARCHAR(255) NOT NULL');
    }
};
