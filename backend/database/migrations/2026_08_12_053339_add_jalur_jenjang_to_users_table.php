<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // jalur pendaftaran: REGULER, PRESTASI, AFIRMASI, dsb.
            $table->string('jalur')->nullable()->after('status_kelulusan');
            // jenjang sekolah tujuan: SMP, SMA, SMK, dsb.
            $table->string('jenjang')->nullable()->after('jalur');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['jalur', 'jenjang']);
        });
    }
};
