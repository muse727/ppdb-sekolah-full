<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('whitelists', function (Blueprint $table) {
            $table->id();
            $table->string('nisn')->unique();
            $table->string('nama_siswa');
            $table->string('asal_sekolah'); // Misal: TK Al-Mubarak
            $table->boolean('is_used')->default(false); // Kalau ortu udah register pake NISN ini, jadi true
            $table->timestamps();
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whitelists');
    }
};
