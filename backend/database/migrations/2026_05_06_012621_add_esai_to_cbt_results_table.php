<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('cbt_results', function (Blueprint $table) {
            $table->float('skor_esai')->default(0)->after('skor');
            $table->boolean('is_koreksi_esai')->default(false)->after('skor_esai');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cbt_results', function (Blueprint $table) {
            //
        });
    }
};
