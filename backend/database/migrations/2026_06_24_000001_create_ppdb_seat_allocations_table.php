<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seat allocations — one row per consumed seat.
     *
     * CRITICAL DOMAIN RULE: a seat allocation is SEPARATE from a payment.
     *   PAYMENT: PENDING_MANUAL -> SUCCESS   (never becomes a fake "RELEASED")
     *   SEAT:    ALLOCATED     -> RELEASED   (auditable, not counted as active)
     *
     * Invariants enforced by indexes (backstopped by the service):
     *   - one payment => at most one allocation (UNIQUE payment_id)
     *   - one user    => at most one ACTIVE seat  (partial UNIQUE on active)
     *   - jenjang is immutable once written (never updated in code)
     *   - a RELEASED allocation is kept for audit and never counted as active
     */
    public function up(): void
    {
        Schema::create('ppdb_seat_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('jenjang', 3); // 'SD' | 'SMP' — server-derived from the payment
            $table->string('status', 10)->default('ALLOCATED'); // ALLOCATED | RELEASED
            $table->timestamp('allocated_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 255)->nullable();
            $table->timestamps();

            // One payment -> exactly one allocation ever.
            $table->unique('payment_id', 'ppdb_alloc_payment_unique');
            $table->index('jenjang', 'ppdb_alloc_jenjang_index');
            $table->index('status', 'ppdb_alloc_status_index');
        });

        // One user -> at most ONE ACTIVE seat. Partial unique index (generated
        // column trick, supported by MariaDB 10.2+ / MySQL 5.7+): rows that are
        // RELEASED get active_user = NULL and are ignored by the unique index,
        // so a user can have many RELEASED rows but only one ALLOCATED row.
        DB::statement(
            "ALTER TABLE ppdb_seat_allocations
             ADD active_user BIGINT UNSIGNED GENERATED ALWAYS AS
                 (IF(status = 'ALLOCATED', user_id, NULL)) VIRTUAL"
        );
        DB::statement(
            "CREATE UNIQUE INDEX ppdb_alloc_active_user_unique
             ON ppdb_seat_allocations (active_user)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ppdb_seat_allocations');
    }
};
