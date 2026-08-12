<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{public function up()
    {
        Schema::create('tokens', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // Kode token unik, misal: AM-X7R92
            $table->boolean('is_used')->default(false); // True kalau sudah dipakai daftar
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null'); // Akun ortu yang pakai
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tokens');
    }
};
