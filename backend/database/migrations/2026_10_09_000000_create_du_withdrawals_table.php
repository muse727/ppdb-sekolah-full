<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('du_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // One canonical withdrawal record per Daftar Ulang invoice, ever.
            $table->foreignId('invoice_id')->unique('du_withdrawals_invoice_unique')
                ->constrained('du_invoices')->cascadeOnDelete();
            $table->string('reason', 30); // controlled enum: MENGUNDURKAN_DIRI
            $table->text('note')->nullable();
            $table->string('initiated_by', 10); // PARENT | ADMIN
            $table->timestamps(); // immutable after creation; only created_at is meaningful

            $table->index('user_id', 'du_withdrawals_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('du_withdrawals');
    }
};
