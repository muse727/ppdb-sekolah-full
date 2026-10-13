<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── users ──────────────────────────────────────────────
        // Filter WHERE role = 'orang_tua' dipakai di hampir semua query admin
        Schema::table('users', function (Blueprint $table) {
            if (!$this->indexExists('users', 'users_role_index')) {
                $table->index('role', 'users_role_index');
            }
        });

        // ── biodatas ───────────────────────────────────────────
        // Lookup per user di setiap halaman pendaftar
        Schema::table('biodatas', function (Blueprint $table) {
            if (!$this->indexExists('biodatas', 'biodatas_user_id_index')) {
                $table->index('user_id', 'biodatas_user_id_index');
            }
        });

        // ── payments ───────────────────────────────────────────
        // Filter status + join ke users sangat sering dipakai
        Schema::table('payments', function (Blueprint $table) {
            if (!$this->indexExists('payments', 'payments_user_id_index')) {
                $table->index('user_id', 'payments_user_id_index');
            }
            if (!$this->indexExists('payments', 'payments_status_index')) {
                $table->index('status', 'payments_status_index');
            }
            if (!$this->indexExists('payments', 'payments_invoice_number_index')) {
                $table->index('invoice_number', 'payments_invoice_number_index');
            }
        });

        // ── berkas ─────────────────────────────────────────────
        Schema::table('berkas', function (Blueprint $table) {
            if (!$this->indexExists('berkas', 'berkas_user_id_index')) {
                $table->index('user_id', 'berkas_user_id_index');
            }
        });

        // ── hasil_observasis ───────────────────────────────────
        // Join + filter di ObservasiController
        Schema::table('hasil_observasis', function (Blueprint $table) {
            if (!$this->indexExists('hasil_observasis', 'hasil_observasis_user_id_index')) {
                $table->index('user_id', 'hasil_observasis_user_id_index');
            }
            if (!$this->indexExists('hasil_observasis', 'hasil_observasis_penilai_id_index')) {
                $table->index('penilai_id', 'hasil_observasis_penilai_id_index');
            }
        });

        // ── cbt_results ────────────────────────────────────────
        // getResults() join ke sesi
        Schema::table('cbt_results', function (Blueprint $table) {
            if (!$this->indexExists('cbt_results', 'cbt_results_session_id_index')) {
                $table->index('cbt_session_id', 'cbt_results_session_id_index');
            }
        });

        // ── cbt_questions ──────────────────────────────────────
        // Setiap login CBT query semua soal per sesi
        Schema::table('cbt_questions', function (Blueprint $table) {
            if (!$this->indexExists('cbt_questions', 'cbt_questions_session_id_index')) {
                $table->index('cbt_session_id', 'cbt_questions_session_id_index');
            }
        });

        // ── tokens ─────────────────────────────────────────────
        // loginCbt brute-force lookup by code + filter is_used
        Schema::table('tokens', function (Blueprint $table) {
            if (!$this->indexExists('tokens', 'tokens_is_used_index')) {
                $table->index('is_used', 'tokens_is_used_index');
            }
            // 'code' sudah unique() di migration awal, unique index sudah ada
        });

        // ── whitelists ─────────────────────────────────────────
        // Register internal query WHERE nisn + is_used tiap pendaftaran
        Schema::table('whitelists', function (Blueprint $table) {
            if (!$this->indexExists('whitelists', 'whitelists_is_used_index')) {
                $table->index('is_used', 'whitelists_is_used_index');
            }
            // 'nisn' sudah ada index dari unique() di migration awal
        });

        // ── settings ───────────────────────────────────────────
        // WHERE key = 'biaya_sd' dipanggil tiap checkout & tiap load halaman
        Schema::table('settings', function (Blueprint $table) {
            if (!$this->indexExists('settings', 'settings_key_index')) {
                $table->index('key', 'settings_key_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users',            fn (Blueprint $t) => $t->dropIndexIfExists('users_role_index'));
        Schema::table('biodatas',         fn (Blueprint $t) => $t->dropIndexIfExists('biodatas_user_id_index'));
        Schema::table('payments',         function (Blueprint $t) {
            $t->dropIndexIfExists('payments_user_id_index');
            $t->dropIndexIfExists('payments_status_index');
            $t->dropIndexIfExists('payments_invoice_number_index');
        });
        Schema::table('berkas',           fn (Blueprint $t) => $t->dropIndexIfExists('berkas_user_id_index'));
        Schema::table('hasil_observasis', function (Blueprint $t) {
            $t->dropIndexIfExists('hasil_observasis_user_id_index');
            $t->dropIndexIfExists('hasil_observasis_penilai_id_index');
        });
        Schema::table('cbt_results',      fn (Blueprint $t) => $t->dropIndexIfExists('cbt_results_session_id_index'));
        Schema::table('cbt_questions',    fn (Blueprint $t) => $t->dropIndexIfExists('cbt_questions_session_id_index'));
        Schema::table('tokens',           fn (Blueprint $t) => $t->dropIndexIfExists('tokens_is_used_index'));
        Schema::table('whitelists',       fn (Blueprint $t) => $t->dropIndexIfExists('whitelists_is_used_index'));
        Schema::table('settings',         fn (Blueprint $t) => $t->dropIndexIfExists('settings_key_index'));
    }

    // Helper: cek apakah index sudah ada — cegah error duplicate index
    private function indexExists(string $table, string $indexName): bool
    {
        $conn    = Schema::getConnection();
        $dbName  = $conn->getDatabaseName();
        $indexes = $conn->select(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [$dbName, $table, $indexName]
        );
        return count($indexes) > 0;
    }
};
