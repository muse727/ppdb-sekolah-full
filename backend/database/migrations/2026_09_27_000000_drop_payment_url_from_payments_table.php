<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The online payment-gateway integration was removed — payments are now
     * manual-transfer only. Clean the gateway-only remnants from the schema:
     *
     * 1. Drop payments.payment_url — written solely by the removed gateway
     *    invoice flow and never read anywhere in the backend or frontend.
     * 2. Repoint payments.payment_method's column default to 'MANUAL'.
     *
     * Existing payment rows are preserved (nothing is deleted). Legacy rows
     * keep whatever payment_method value they already hold; only the schema
     * default and the dead column change.
     */
    public function up(): void
    {
        if (Schema::hasColumn('payments', 'payment_url')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('payment_url');
            });
        }

        if (Schema::hasColumn('payments', 'payment_method')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('payment_method')->default('MANUAL')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore the dropped column so the migration stays reversible.
        // The gateway is permanently retired, so payment_method keeps its
        // 'MANUAL' default (matching the historical add_manual_fields migration).
        if (!Schema::hasColumn('payments', 'payment_url')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('payment_url')->nullable()->after('amount');
            });
        }
    }
};
