<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up(): void
{
    Schema::create('biodatas', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->onDelete('cascade');
        
        // --- Identitas Siswa ---
        $table->string('asal_sekolah');
        $table->string('nama_lengkap');
        $table->string('nama_panggilan')->nullable();
        $table->enum('jenis_kelamin', ['L', 'P']);
        $table->string('nisn')->nullable();
        $table->string('nik_siswa');
        $table->string('no_kk');
        $table->string('tempat_lahir');
        $table->date('tanggal_lahir');
        $table->integer('anak_ke');
        $table->string('status_peserta_didik'); // Yatim/Piatu/Lengkap
        
        // --- Data Ibu ---
        $table->string('nama_ibu');
        $table->string('nik_ibu');
        $table->string('pendidikan_ibu');
        $table->string('pekerjaan_ibu');
        $table->string('penghasilan_ibu');
        
        // --- Data Ayah ---
        $table->string('nama_ayah');
        $table->string('nik_ayah');
        $table->string('pendidikan_ayah');
        $table->string('pekerjaan_ayah');
        $table->string('penghasilan_ayah');
        
        // --- Alamat & Kontak ---
        $table->text('alamat_lengkap');
        $table->string('kota');
        $table->string('kecamatan');
        $table->string('kelurahan');
        $table->string('rt', 5);
        $table->string('rw', 5);
        $table->string('no_hp_ortu');
        $table->string('email_siswa')->nullable();
        
        // --- Data Wali (Opsional) ---
        $table->string('nama_wali')->nullable();
        $table->string('no_hp_wali')->nullable();

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biodatas');
    }
};
