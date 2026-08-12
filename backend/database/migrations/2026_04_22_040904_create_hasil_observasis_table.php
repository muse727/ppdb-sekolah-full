<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('hasil_observasis', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Anak yang dites
        $table->foreignId('penilai_id')->constrained('users')->onDelete('cascade'); // Guru yang ngetes
        $table->json('jawaban_json'); // Isinya bakal: {"K1": "BAIK", "K2": "KURANG"}
        $table->text('catatan_tambahan')->nullable();
        $table->enum('rekomendasi_akhir', ['Diterima', 'Dipertimbangkan', 'Ditolak'])->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hasil_observasis');
    }
};
