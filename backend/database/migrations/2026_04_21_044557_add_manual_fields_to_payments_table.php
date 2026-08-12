<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
{
    Schema::table('payments', function (Blueprint $table) {
        $table->string('payment_method')->default('DOKU')->after('amount'); // DOKU atau MANUAL
        $table->string('proof_of_payment')->nullable()->after('payment_method'); // Path gambar
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            //
        });
    }
};
