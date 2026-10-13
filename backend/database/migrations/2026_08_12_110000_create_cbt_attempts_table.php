<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbt_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cbt_session_id')->constrained('cbt_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('attempt_token', 64)->unique();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();
            $table->string('status', 20)->default('IN_PROGRESS');
            $table->timestamps();

            $table->unique(['cbt_session_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('cbt_results', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('cbt_session_id')->constrained('users')->nullOnDelete();
            $table->foreignId('cbt_attempt_id')->nullable()->after('user_id')->constrained('cbt_attempts')->nullOnDelete();
            $table->unique('cbt_attempt_id', 'cbt_results_attempt_id_unique');
            $table->index('user_id', 'cbt_results_user_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('cbt_results', function (Blueprint $table) {
            $table->dropUnique('cbt_results_attempt_id_unique');
            $table->dropForeign(['cbt_attempt_id']);
            $table->dropForeign(['user_id']);
            $table->dropIndex('cbt_results_user_id_index');
            $table->dropColumn(['cbt_attempt_id', 'user_id']);
        });

        Schema::dropIfExists('cbt_attempts');
    }
};
