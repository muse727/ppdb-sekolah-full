<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::create('kategori_observasis', function (Blueprint $table) {
        $table->id();
        $table->string('nama_kategori');
        $table->enum('jenjang', ['SD', 'SMP']); // Biar soal SD dan SMP kepisah
        $table->integer('urutan')->default(1);
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kategori_observasis');
    }
};
