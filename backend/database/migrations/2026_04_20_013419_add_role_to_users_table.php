<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Kita kasih default 'orang_tua', jadi tiap ada yang register dari halaman web, otomatis jadi ortu
            $table->enum('role', ['super_admin', 'admin_sd', 'admin_smp', 'orang_tua'])->default('orang_tua');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};