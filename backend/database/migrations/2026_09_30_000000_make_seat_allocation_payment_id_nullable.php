<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the existing foreign key, unique index, and historical references.
        DB::statement('ALTER TABLE ppdb_seat_allocations MODIFY payment_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        // Refuse rollback rather than invent payments or rewrite allocations.
        if (DB::table('ppdb_seat_allocations')->whereNull('payment_id')->exists()) {
            throw new RuntimeException('Cannot restore NOT NULL while allocations without payment references exist.');
        }

        DB::statement('ALTER TABLE ppdb_seat_allocations MODIFY payment_id BIGINT UNSIGNED NOT NULL');
    }
};
