<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
{
    Schema::create('cbt_sessions', function (Blueprint $table) {
        $table->id();
        $table->string('nama_ujian'); // Contoh: Tes Akademik Gelombang 1
        $table->date('tanggal_ujian');
        $table->time('jam_mulai');
        $table->time('jam_selesai');
        $table->string('token', 6)->unique(); // Contoh: XJ789K
        $table->string('ruangan')->nullable();
        $table->boolean('is_active')->default(false); // Buat buka/tutup ujian secara manual
        $table->timestamps();
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cbt_sessions');
    }
};
