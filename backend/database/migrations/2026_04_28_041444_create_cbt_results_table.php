<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('cbt_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cbt_session_id')->constrained('cbt_sessions')->onDelete('cascade');
            $table->string('nama_siswa');
            $table->json('jawaban_json');
            $table->integer('benar')->default(0);
            $table->integer('salah')->default(0);
            $table->float('skor')->default(0);
            $table->integer('jumlah_pelanggaran')->default(0); // <--- INI BUAT NYATET CHEAT
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cbt_results');
    }
};
