<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            // Data Pribadi
            $table->string('student_name');
            $table->string('whatsapp_number');
            
            // Logic Penjurusan & VIP
            $table->enum('unit_level', ['SD', 'SMP']); // Pemisah admin
            $table->boolean('is_vip')->default(false); // Flag token dari TK
            
            // Status Pipeline
            $table->enum('status', [
                'pending', 
                'observation', 
                'accepted', 
                'waiting_list', 
                'registered', 
                'expired'
            ])->default('pending');
            
            // Nullable karena bisa jadi masuk waiting list (progressive profiling)
            $table->text('address')->nullable();
            $table->string('nik')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};