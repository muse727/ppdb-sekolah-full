<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Internal candidate access-code + identity binding.
     *
     * - jenjang: EXPLICIT SD/SMP, replaces the fragile asal_sekolah->match() derivation.
     * - access_code: server-generated CSPRNG secret credential (>=48 bits), UNIQUE.
     * - user_id: nullable FK to users.id — audit link candidate -> account.
     *
     * Existing rows: jenjang is backfilled from the legacy asal_sekolah mapping;
     * rows whose jenjang cannot be determined are left NULL and are REJECTED by the
     * registration path (no INTERNAL user may be created with jenjang=NULL).
     * access_code is left NULL (no code) until an admin generates one.
     */
    public function up(): void
    {
        Schema::table('whitelists', function (Blueprint $table) {
            $table->string('jenjang', 3)->nullable()->after('asal_sekolah');
            $table->string('access_code', 12)->nullable()->after('jenjang');
            $table->foreignId('user_id')->nullable()->after('access_code')
                  ->constrained('users')->nullOnDelete();
            $table->unique('access_code', 'whitelists_access_code_unique');
        });

        // Backfill jenjang from the legacy asal_sekolah mapping (same mapping the
        // register controller used). Unmapped rows stay NULL (rejected downstream).
        DB::table('whitelists')->where('asal_sekolah', 'SD IT Al-Mubarak')->update(['jenjang' => 'SMP']);
        DB::table('whitelists')->where('asal_sekolah', 'TK IT Al-Mubarak')->update(['jenjang' => 'SD']);
    }

    public function down(): void
    {
        Schema::table('whitelists', function (Blueprint $table) {
            $table->dropUnique('whitelists_access_code_unique');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['jenjang', 'access_code']);
        });
    }
};
