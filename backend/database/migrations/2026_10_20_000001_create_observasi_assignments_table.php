<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observasi_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('guru_id');
            $table->enum('status', ['AKTIF', 'DIGANTI', 'DICABUT']);
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('guru_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('assigned_by')->references('id')->on('users')->onDelete('set null');

            $table->index(['guru_id', 'status'], 'idx_guru_status');
            $table->index('user_id', 'idx_user');
        });

        // Di MySQL 8.0 sintaks kolom komputasi menggunakan STORED (bukan PERSISTENT ala MariaDB)
        DB::statement(
            'ALTER TABLE `observasi_assignments` '
            ."ADD COLUMN `active_marker` TINYINT AS (IF(`status` = 'AKTIF', 1, NULL)) STORED, "
            .'ADD UNIQUE KEY `uq_active` (`user_id`, `active_marker`)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('observasi_assignments');
    }
};
