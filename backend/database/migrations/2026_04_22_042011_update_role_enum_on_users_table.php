<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Timpa struktur ENUM lamanya jadi ada 'guru'
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin', 'admin', 'guru', 'orang_tua') NOT NULL DEFAULT 'orang_tua'");
    }

    public function down(): void
    {
        // Kalau di-rollback, balikin ke semula
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin', 'admin', 'orang_tua') NOT NULL DEFAULT 'orang_tua'");
    }
};