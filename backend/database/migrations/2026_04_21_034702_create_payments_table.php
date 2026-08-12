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
    Schema::create('payments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->onDelete('cascade');
        $table->string('invoice_number')->unique();
        $table->string('jenjang'); // SD / SMP
        $table->integer('amount');
        $table->string('payment_url')->nullable(); // Link dari DOKU
        $table->string('status')->default('PENDING'); // PENDING, SUCCESS, FAILED
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
