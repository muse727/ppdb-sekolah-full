<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppdb_seat_allocations', function (Blueprint $table) {
            $table->index(['jenjang', 'status'], 'ppdb_alloc_jenjang_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('ppdb_seat_allocations', function (Blueprint $table) {
            $table->dropIndex('ppdb_alloc_jenjang_status_index');
        });
    }
};
