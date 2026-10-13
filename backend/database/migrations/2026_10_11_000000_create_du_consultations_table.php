<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B.7.4-B — Daftar Ulang consultation (KONSULTASI KE SEKOLAH).
 *
 * The third decision path on the Daftar Ulang page, next to MELANJUTKAN (B.7.2)
 * and MENGUNDURKAN DIRI (B.7.1). A consultation exists because a parent may
 * still be unsure after seeing the cost breakdown and the agreement, so it may
 * legitimately happen while the DU payment is still PENDING.
 *
 * It is deliberately NOT a lifecycle event:
 *   - it never makes a student official (users.status_siswa stays NULL)
 *   - it never withdraws (users.status_kelulusan stays LULUS)
 *   - it never touches payments, ppdb_capacities or ppdb_seat_allocations
 *   - it never alters the DU pricing snapshot or the agreement acceptance
 *
 * FIELD OWNERSHIP (mirrored by the model allowlist and the service layer):
 *   parent -> preferred_date, preferred_time, note   (write-once at CREATE)
 *   admin  -> admin_note, scheduled_date, scheduled_time
 *   server -> user_id, invoice_id, status, scheduled_by, resolved_by,
 *             resolved_at, timestamps
 *
 * EXACTLY ONE ACTIVE CONSULTATION PER INVOICE
 * -------------------------------------------
 * ACTIVE = REQUESTED | SCHEDULED. This is enforced by the DATABASE, not by an
 * application-level count, using the same generated-column + unique-index
 * strategy already proven on ppdb_seat_allocations.active_user: terminal rows
 * collapse active_invoice to NULL and a UNIQUE index ignores NULLs, so an
 * invoice may accumulate many historical CANCELLED/COMPLETED rows while holding
 * at most one ACTIVE row. invoice_id is therefore deliberately NOT globally
 * unique (contrast du_withdrawals, which is one-per-invoice history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('du_consultations', function (Blueprint $table) {
            $table->id();

            // Canonical identity, always server-resolved from auth()->id() and
            // the parent's own DU invoice. A client never supplies either id.
            // cascadeOnDelete mirrors du_withdrawals, the closest sibling record.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('du_invoices')->cascadeOnDelete();

            // Parent-owned request window. preferred_date is a real DATE column
            // (never free text) and preferred_time is a real TIME column that the
            // boundary validates as H:i, so no operating-hours policy is invented.
            $table->date('preferred_date');
            $table->time('preferred_time');
            $table->text('note')->nullable();

            // Controlled state machine: REQUESTED | SCHEDULED | COMPLETED | CANCELLED
            $table->string('status', 20);

            // Admin-owned scheduling outcome.
            $table->text('admin_note')->nullable();
            $table->date('scheduled_date')->nullable();
            $table->time('scheduled_time')->nullable();

            // Server-owned actor / resolution audit. restrictOnDelete on the actor
            // refs mirrors du_pricing_audits.actor_id: who scheduled or resolved a
            // consultation must never be silently nulled by a user deletion.
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['invoice_id', 'status'], 'du_consult_invoice_status_index');
            $table->index(['user_id', 'status'], 'du_consult_user_status_index');
        });

        // Closed status vocabulary at the DB level (defence in depth behind the
        // model guard), following the users_status_siswa_valid_check precedent.
        DB::statement("ALTER TABLE du_consultations ADD CONSTRAINT du_consultations_status_valid_check
            CHECK (status IN ('REQUESTED', 'SCHEDULED', 'COMPLETED', 'CANCELLED'))");

        // Structural consistency, following the users_continued_at_consistency_check
        // precedent: a schedule is only ever written by an admin, a resolution only
        // ever accompanies a terminal state, and a pristine REQUESTED carries neither.
        DB::statement("ALTER TABLE du_consultations ADD CONSTRAINT du_consultations_schedule_consistency_check
            CHECK (status <> 'SCHEDULED'
                OR (scheduled_date IS NOT NULL AND scheduled_time IS NOT NULL AND scheduled_by IS NOT NULL))");
        DB::statement("ALTER TABLE du_consultations ADD CONSTRAINT du_consultations_resolution_consistency_check
            CHECK (status IN ('REQUESTED', 'SCHEDULED')
                OR (resolved_by IS NOT NULL AND resolved_at IS NOT NULL))");
        DB::statement("ALTER TABLE du_consultations ADD CONSTRAINT du_consultations_requested_is_pristine_check
            CHECK (status <> 'REQUESTED'
                OR (scheduled_date IS NULL AND scheduled_time IS NULL AND scheduled_by IS NULL
                    AND resolved_by IS NULL AND resolved_at IS NULL))");

        // Exactly one ACTIVE (REQUESTED | SCHEDULED) consultation per invoice.
        // Terminal rows collapse to NULL and are ignored by the unique index, so
        // historical CANCELLED/COMPLETED rows accumulate freely.
        DB::statement(
            "ALTER TABLE du_consultations
             ADD active_invoice BIGINT UNSIGNED GENERATED ALWAYS AS
                 (IF(status IN ('REQUESTED', 'SCHEDULED'), invoice_id, NULL)) VIRTUAL"
        );
        DB::statement(
            "CREATE UNIQUE INDEX du_consult_active_invoice_unique
             ON du_consultations (active_invoice)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('du_consultations');
    }
};
