<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('cbt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cbt_session_id')->constrained('cbt_sessions')->onDelete('cascade');
            $table->enum('jenis_soal', ['PG', 'ESAI']); // Pilihan Ganda atau Esai
            $table->text('pertanyaan');
            // Opsi & Kunci buat PG (Boleh kosong kalau jenisnya ESAI)
            $table->string('opsi_a')->nullable();
            $table->string('opsi_b')->nullable();
            $table->string('opsi_c')->nullable();
            $table->string('opsi_d')->nullable();
            $table->string('kunci_jawaban', 1)->nullable(); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cbt_questions');
    }
};
