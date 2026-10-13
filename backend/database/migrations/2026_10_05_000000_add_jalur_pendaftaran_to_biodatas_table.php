<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biodatas', function (Blueprint $table): void {
            $table->enum('jalur_pendaftaran', [
                'PUTRA_PUTRI_GURU', 'YAYASAN', 'INTERNAL_TK', 'UMUM',
            ])->nullable()->after('status_peserta_didik')->index();
        });
    }

    public function down(): void
    {
        Schema::table('biodatas', function (Blueprint $table): void {
            $table->dropColumn('jalur_pendaftaran');
        });
    }
};
