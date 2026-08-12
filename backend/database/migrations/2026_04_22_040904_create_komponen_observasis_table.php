<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up(): void
{
    Schema::create('komponen_observasis', function (Blueprint $table) {
        $table->id();
        $table->foreignId('kategori_id')->constrained('kategori_observasis')->onDelete('cascade');
        $table->string('kode', 10); // K1, K2, L1, dst
        $table->text('deskripsi');
        $table->text('saran_jika_kurang')->nullable(); // Auto-Saran buat di PDF Laporan
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('komponen_observasis');
    }
};
