<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E.5 — automatic technical acceptance of uploaded Berkas.
 *
 * Domain change: berkas upload no longer waits for manual panitia
 * verification. Files that pass backend content validation (format,
 * size, structure) are accepted automatically. The new status
 * LOLOS_TEKNIS states exactly what happened: the document passed the
 * TECHNICAL check. It is NOT a statement of authenticity — keyword OCR
 * in the browser is advisory and never proves a document is genuine.
 *
 * - Column default moves from MENUNGGU to LOLOS_TEKNIS.
 * - Existing MENUNGGU rows are migrated forward: they already passed
 *   the same technical validation at upload time (E.3/E.4), and this is
 *   development data, so promoting them is honest, not a fabrication.
 * - TERVERIFIKASI/DITOLAK historical values are left untouched (kept in
 *   the vocabulary for backward compatibility); no row is downgraded.
 *
 * Consumer contract after this migration (see BerkasController, the
 * pendaftar gate, and the FE badge):
 *   LOLOS_TEKNIS  upload passed automated technical validation
 *   TERVERIFIKASI legacy/manual value, rendered as before
 *   DITOLAK       legacy/manual value, rendered as before
 *   MENUNGGU      no longer produced by the server
 */
return new class extends Migration
{
    public function up(): void
    {
        // The column is a plain string (no enum constraint in the original
        // migration), so widening the vocabulary needs no DDL change beyond
        // the default.
        Schema::table('berkas', function (Blueprint $table) {
            $table->string('status')->default('LOLOS_TEKNIS')->change();
        });

        // Forward-fill rows still parked in the removed MENUNGGU state.
        DB::table('berkas')
            ->where('status', 'MENUNGGU')
            ->update(['status' => 'LOLOS_TEKNIS']);
    }

    public function down(): void
    {
        Schema::table('berkas', function (Blueprint $table) {
            $table->string('status')->default('MENUNGGU')->change();
        });

        // Rows promoted by up() return to MENUNGGU; TERVERIFIKASI/DITOLAK
        // rows stay as-is (they were never touched).
        DB::table('berkas')
            ->where('status', 'LOLOS_TEKNIS')
            ->update(['status' => 'MENUNGGU']);
    }
};
