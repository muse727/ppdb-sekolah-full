<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Karena login pakai Google/Apple, password boleh kosong
            $table->string('password')->nullable()->change();
            
            // Simpan data dari Google/Apple
            $table->string('provider')->nullable(); // isinya 'google' atau 'apple'
            $table->string('provider_id')->nullable(); // ID unik dari Google/Apple
            $table->string('provider_token')->nullable(); // Buat nyimpen token (opsional)
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
            $table->dropColumn(['provider', 'provider_id', 'provider_token']);
        });
    }
};