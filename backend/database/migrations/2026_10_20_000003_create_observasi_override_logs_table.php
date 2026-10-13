<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E.12G — observasi_override_logs: append-only audit of super_admin result
 * overrides. Written in the same transaction as the hasil change; a failure
 * to write cancels the override entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observasi_override_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id');            // super_admin performing override
            $table->string('reason');                          // mandatory, non-empty
            $table->unsignedBigInteger('user_id');             // kandidat (hasil identity)
            $table->unsignedBigInteger('hasil_id');
            $table->unsignedInteger('version_before')->nullable(); // null = row created by override
            $table->unsignedInteger('version_after');
            $table->char('idempotency_key_hash', 64)->nullable();  // ties audit to the replay record
            $table->timestamps();

            $table->foreign('actor_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('hasil_id')->references('id')->on('hasil_observasis')->onDelete('restrict');
            $table->index('user_id', 'idx_user');
            $table->index('hasil_id', 'idx_hasil');
        });
    }

    public function down(): void
    {
        // Dropping loses override audit history — same caution as assignment
        // history; requires explicit owner approval. Hasil data untouched.
        Schema::dropIfExists('observasi_override_logs');
    }
};
