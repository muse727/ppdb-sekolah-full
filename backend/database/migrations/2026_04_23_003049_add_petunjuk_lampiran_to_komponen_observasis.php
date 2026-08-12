<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up() {
    Schema::table('komponen_observasis', function (Blueprint $table) {
        $table->text('petunjuk')->nullable()->after('deskripsi');
        $table->string('lampiran')->nullable()->after('petunjuk');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('komponen_observasis', function (Blueprint $table) {
            //
        });
    }
};
