<?php

namespace Tests\Feature;

use App\Models\Biodata;
use App\Models\DaftarUlangConsultation;
use App\Models\DaftarUlangInvoice;
use App\Models\Payment;
use App\Models\PpdbSeatAllocation;
use App\Models\Setting;
use App\Models\User;
use App\Services\DaftarUlangPricingService;
use App\Services\PpdbCapacityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B.7.4-B — Daftar Ulang consultation (KONSULTASI KE SEKOLAH).
 *
 * The property under test above all others is ISOLATION: a consultation is the
 * third decision path beside MELANJUTKAN and MENGUNDURKAN DIRI, and it must be
 * inert with respect to both. So these tests assert the consultation state
 * machine AND, at every step, that the official-student lifecycle, the seat and
 * the payment did not move.
 *
 * Covers: exactly-one-ACTIVE-per-invoice (proven at the index, not by a count),
 * idempotent request replay, the frozen parent request window across a
 * reschedule, the forbidden REQUESTED -> COMPLETED edge, terminal immutability,
 * IDOR on the parent cancel, the read-only admin queue, and eligibility gates.
 * TEST DB ONLY (ppdb_sekolah_test).
 */
class DaftarUlangConsultationTest extends TestCase
{
    use RefreshDatabase;

    private User $super;
    private User $admin;
    private User $admin2;
    private DaftarUlangPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('ppdb_sekolah_test', DB::selectOne('SELECT DATABASE() db')->db);
        $this->super = $this->actor('super_admin');
        $this->admin = $this->actor('admin');
        $this->admin2 = $this->actor('admin');
        $this->pricing = app(DaftarUlangPricingService::class);
        config(['daftar_ulang.agreement' => ['version' => 'DU-C1', 'text' => 'Consultation test agreement.']]);
        Setting::updateOrCreate(['key' => 'rekening_sd'], ['value' => '11111111']);
        Setting::updateOrCreate(['key' => 'bank_name'], ['value' => 'Bank']);
        Setting::updateOrCreate(['key' => 'bank_owner'], ['value' => 'Yayasan']);
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['jenjang' => 'SD']);
        $u->forceFill(['role' => $role])->save();
        return $u->refresh();
    }

    private function parent(string $jenjang = 'SD', string $status = 'LULUS'): User
    {
        $u = User::factory()->create(['jenjang' => $jenjang, 'jalur' => 'UMUM']);
        $u->forceFill(['role' => 'orang_tua', 'status_kelulusan' => $status])->save();
        $bio = array_fill_keys(['asal_sekolah', 'nik_siswa', 'no_kk', 'tempat_lahir', 'status_peserta_didik',
            'nama_ibu', 'nik_ibu', 'pendidikan_ibu', 'pekerjaan_ibu', 'penghasilan_ibu', 'nama_ayah', 'nik_ayah',
            'pendidikan_ayah', 'pekerjaan_ayah', 'penghasilan_ayah', 'alamat_lengkap', 'kota', 'kecamatan',
            'kelurahan', 'rt', 'rw', 'no_hp_ortu'], 'Test');
        Biodata::create([...$bio, 'user_id' => $u->id, 'nama_lengkap' => 'Consultation student',
            'jenis_kelamin' => 'L', 'tanggal_lahir' => '2018-01-01', 'anak_ke' => 1, 'jalur_pendaftaran' => 'UMUM']);
        PpdbSeatAllocation::create(['user_id' => $u->id, 'payment_id' => null, 'jenjang' => $jenjang,
            'status' => 'ALLOCATED', 'allocated_at' => now()]);
        return $u->refresh();
    }

    private function makeInvoice(User $user, int $amount = 100000): DaftarUlangInvoice
    {
        $this->pricing->updateDefault($this->super->id, $user->jenjang, [
            ['id' => 'tuition', 'name' => 'Tuition', 'quantity' => 2, 'unit_amount' => $amount],
            ['id' => 'kit', 'name' => 'Student kit', 'quantity' => 1, 'unit_amount' => 50000]], 10000);
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/daftar-ulang/invoices')->assertOk()->json('id');
        return DaftarUlangInvoice::findOrFail($id);
    }

    private function paySuccess(DaftarUlangInvoice $invoice): int
    {
        $paymentId = $invoice->payment_id;
        Sanctum::actingAs(User::findOrFail($invoice->user_id));
        $file = \Illuminate\Http\UploadedFile::fake()->image('b.jpg');
        $this->postJson('/api/daftar-ulang/payments/proof', ['bukti_bayar' => $file])->assertOk();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/daftar-ulang/payments/{$paymentId}/verify")->assertOk();
        return $paymentId;
    }

    /** A parent with a PENDING DU invoice — the pre-payment consultation case. */
    private function parentWithInvoice(string $jenjang = 'SD'): array
    {
        $parent = $this->parent($jenjang);
        return [$parent, $this->makeInvoice($parent)];
    }

    /** Ask for a consultation through the API and return the created row. */
    private function requestConsultation(User $parent, array $overrides = []): DaftarUlangConsultation
    {
        Sanctum::actingAs($parent);
        $payload = array_merge([
            'preferred_date' => now()->addDays(3)->toDateString(),
            'preferred_time' => '10:30',
            'note' => 'Mohon penjelasan cicilan',
        ], $overrides);
        $this->postJson('/api/daftar-ulang/consultations', $payload)->assertCreated();

        return DaftarUlangConsultation::where('user_id', $parent->id)->latest('id')->firstOrFail();
    }

    private function assertLifecycleUntouched(User $parent, ?int $paymentId = null): void
    {
        $fresh = $parent->refresh();
        $this->assertNull($fresh->status_siswa, 'A consultation must never set status_siswa.');
        $this->assertNull($fresh->continued_at, 'A consultation must never set continued_at.');
        $this->assertSame('LULUS', $fresh->status_kelulusan, 'status_kelulusan must not move.');

        $seat = PpdbSeatAllocation::where('user_id', $parent->id)->sole();
        $this->assertSame('ALLOCATED', $seat->status, 'The seat must stay allocated.');
        $this->assertNull($seat->released_at);

        if ($paymentId !== null) {
            $payment = Payment::findOrFail($paymentId);
            $this->assertSame(Payment::CATEGORY_DAFTAR_ULANG, $payment->payment_category);
        }
    }

    // ======================================================================
    // A. The headline invariant: a consultation is INERT.
    // ======================================================================

    /**
     * A consultation request is accepted BEFORE any payment (that is its whole
     * purpose) and must not move a single byte of the official-student lifecycle.
     */
    public function test_request_creates_consultation_without_touching_lifecycle(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');
        $paymentId = $invoice->payment_id;
        $this->assertSame('PENDING', Payment::findOrFail($paymentId)->status);

        $consultation = $this->requestConsultation($parent);

        $this->assertSame(DaftarUlangConsultation::STATUS_REQUESTED, $consultation->status);
        $this->assertSame($parent->id, $consultation->user_id);
        $this->assertSame($invoice->id, $consultation->invoice_id);
        $this->assertTrue($consultation->isActive());

        // No admin or resolution data on a fresh request.
        $this->assertNull($consultation->scheduled_date);
        $this->assertNull($consultation->scheduled_by);
        $this->assertNull($consultation->resolved_by);
        $this->assertNull($consultation->resolved_at);

        // THE POINT OF THE TEST: nothing outside du_consultations moved.
        $this->assertLifecycleUntouched($parent, $paymentId);
        $this->assertSame('PENDING', Payment::findOrFail($paymentId)->status);
        $this->assertNull($invoice->refresh()->withdrawn_at);
        $this->assertSame(0, \App\Models\DaftarUlangWithdrawal::where('invoice_id', $invoice->id)->count());
    }

    /**
     * Walking a consultation all the way to COMPLETED still leaves the student
     * un-TERDAFTAR and un-withdrawn, and leaves MELANJUTKAN available afterwards.
     * This is the strongest form of the isolation claim.
     */
    public function test_full_consultation_lifecycle_never_sets_status_siswa(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');
        // B.7.4-E.1: creation is only allowed BEFORE payment success, so the
        // request is made pre-payment and only the payment verification (which
        // the eligibility gate deliberately does NOT police retroactively)
        // happens mid-lifecycle.
        $consultation = $this->requestConsultation($parent);
        $paymentId = $this->paySuccess($invoice);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/daftar-ulang/consultations/{$consultation->id}/schedule", [
            'scheduled_date' => now()->addDays(4)->toDateString(),
            'scheduled_time' => '09:00',
        ])->assertOk()->assertJsonPath('consultation.status', 'SCHEDULED');

        $this->postJson("/api/admin/daftar-ulang/consultations/{$consultation->id}/resolve", [
            'action' => 'complete',
            'admin_note' => 'Keluarga memutuskan melanjutkan.',
        ])->assertOk()->assertJsonPath('consultation.status', 'COMPLETED');

        $this->assertSame('COMPLETED', $consultation->refresh()->status);
        $this->assertLifecycleUntouched($parent, $paymentId);
        $this->assertSame('SUCCESS', Payment::findOrFail($paymentId)->status);

        // Completing a consultation did NOT consume the continuation: the parent
        // can still officially continue, and that remains the only path to
        // TERDAFTAR.
        Sanctum::actingAs($parent);
        $this->postJson("/api/daftar-ulang/invoices/{$invoice->id}/agreement", ['agree' => true])->assertOk();
        $this->postJson('/api/daftar-ulang/continue')->assertOk()
            ->assertJsonPath('status_siswa', 'TERDAFTAR');
        $this->assertSame('TERDAFTAR', $parent->refresh()->status_siswa);
    }

    /**
     * B.7.4-E.1: an ALREADY-TERDAFTAR student may no longer OPEN a consultation
     * (that decision phase ended), but an existing pre-payment request stays
     * cancellable — history is preserved and the parent is never trapped with
     * an open request — and the terminal state is disturbed in no direction.
     */
    public function test_terdaftar_student_may_consult_without_disturbing_terminal_state(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');
        $consultation = $this->requestConsultation($parent);

        // Terminal official state, set exactly as the continuation service does.
        $parent->forceFill(['status_siswa' => User::SISWA_TERDAFTAR, 'continued_at' => now()])->save();

        // Creating a NEW request is now refused (422 eligibility) ...
        Sanctum::actingAs($parent);
        $this->postJson('/api/daftar-ulang/consultations', [
            'preferred_date' => now()->addDays(3)->toDateString(),
            'preferred_time' => '10:30',
        ])->assertUnprocessable()->assertJsonValidationErrors('eligibility');

        // ... but the pre-existing request is intact and still cancellable.
        $this->assertSame('REQUESTED', $consultation->refresh()->status);
        $this->postJson("/api/daftar-ulang/consultations/{$consultation->id}/cancel")->assertOk();
        $this->assertSame('CANCELLED', $consultation->refresh()->status);

        $fresh = $parent->refresh();
        $this->assertSame('TERDAFTAR', $fresh->status_siswa);
        $this->assertNotNull($fresh->continued_at);
        $this->assertSame('CANCELLED', $consultation->refresh()->status);
        $this->assertSame('TERDAFTAR', $parent->refresh()->status_siswa);
    }

    // ======================================================================
    // B. Exactly one ACTIVE consultation per invoice.
    // ======================================================================

    /**
     * The invariant lives in the partial unique index, so this bypasses BOTH the
     * service and the model entirely and proves the database itself refuses a
     * second ACTIVE row for the same invoice.
     */
    public function test_database_index_refuses_second_active_consultation(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');
        $this->requestConsultation($parent);

        $this->expectException(QueryException::class);
        DB::table('du_consultations')->insert([
            'user_id' => $parent->id,
            'invoice_id' => $invoice->id,
            'preferred_date' => now()->addDays(9)->toDateString(),
            'preferred_time' => '08:00',
            'status' => 'SCHEDULED',
            'scheduled_date' => now()->addDays(9)->toDateString(),
            'scheduled_time' => '08:00',
            'scheduled_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Terminal rows release the slot, so a second request is allowed after cancel. */
    public function test_terminal_consultation_frees_the_active_slot(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');
        $first = $this->requestConsultation($parent);

        Sanctum::actingAs($parent);
        $this->postJson("/api/daftar-ulang/consultations/{$first->id}/cancel")->assertOk();
        $this->assertSame('CANCELLED', $first->refresh()->status);

        // A brand-new request now succeeds and is a distinct row.
        $second = $this->requestConsultation($parent, ['note' => 'Pertanyaan kedua']);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('REQUESTED', $second->status);
        $this->assertSame(2, DaftarUlangConsultation::where('invoice_id', $invoice->id)->count());
    }

    /** A rejected second request must not mutate the first one's window. */
    public function test_rejected_second_request_leaves_original_window_intact(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');
        $first = $this->requestConsultation($parent, [
            'preferred_date' => now()->addDays(3)->toDateString(),
            'preferred_time' => '10:30',
            'note' => 'Pertanyaan asli',
        ]);

        // Insert a second ACTIVE row directly, bypassing the idempotent service.
        try {
            DB::table('du_consultations')->insert([
                'user_id' => $parent->id, 'invoice_id' => $invoice->id,
                'preferred_date' => now()->addDays(20)->toDateString(),
                'preferred_time' => '16:00', 'status' => 'REQUESTED',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('Expected the unique index to reject a second ACTIVE row.');
        } catch (QueryException $e) {
            $this->assertSame(1062, $e->errorInfo[1] ?? null);
            $this->assertStringContainsString('du_consult_active_invoice_unique', $e->getMessage());
        }

        $fresh = $first->refresh();
        $this->assertSame('REQUESTED', $fresh->status);
        $this->assertSame('Pertanyaan asli', $fresh->note);
        // Storage layer: MySQL TIME is HH:MM:SS.
        $this->assertSame('10:30:00', $fresh->preferred_time);
        $this->assertSame(1, DaftarUlangConsultation::where('invoice_id', $invoice->id)->count());
    }

    // ======================================================================
    // C. Idempotency of the parent request.
    // ======================================================================

    /**
     * A double submit (double-clicked button, retried request) must not create a
     * second row and must not rewrite the parent's original preferred window.
     * The replay is reported as 200, not 201, because nothing was created.
     */
    public function test_request_replay_is_idempotent(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');
        $originalDate = now()->addDays(3)->toDateString();
        $first = $this->requestConsultation($parent, [
            'preferred_date' => $originalDate,
            'preferred_time' => '10:30',
            'note' => 'Pertanyaan asli',
        ]);

        Sanctum::actingAs($parent);
        $res = $this->postJson('/api/daftar-ulang/consultations', [
            'preferred_date' => now()->addDays(30)->toDateString(),
            'preferred_time' => '23:00',
            'note' => 'Percobaan menimpa',
        ])->assertOk(); // 200, NOT 201

        $this->assertSame($first->id, $res->json('consultation.id'));
        $this->assertSame(1, DaftarUlangConsultation::where('invoice_id', $invoice->id)->count());

        // The original window survived the replay untouched. The API returns the
        // same HH:MM the parent submitted, so the value round-trips exactly.
        $this->assertSame('10:30', $res->json('consultation.request.preferred_time'));
        $this->assertSame($originalDate, $res->json('consultation.request.preferred_date'));
        $this->assertSame('Pertanyaan asli', $res->json('consultation.request.note'));

        $fresh = $first->refresh();
        $this->assertSame($originalDate, $fresh->preferred_date->toDateString());
        $this->assertSame('10:30:00', $fresh->preferred_time); // storage: HH:MM:SS
        $this->assertSame('Pertanyaan asli', $fresh->note);
    }

    // ======================================================================
    // D. State machine and admin desk.
    // ======================================================================

    /**
     * REQUESTED -> COMPLETED is the edge the spec forbids, because nothing can be
     * completed before it is scheduled. It must be a 409 conflict, not a 422,
     * and must leave the row REQUESTED.
     */
    public function test_requested_cannot_be_completed_directly(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');
        $consultation = $this->requestConsultation($parent);

        Sanctum::actingAs($this->admin);
        $res = $this->postJson("/api/admin/daftar-ulang/consultations/{$consultation->id}/resolve", [
            'action' => 'complete',
        ])->assertStatus(409);

        $this->assertSame('CONSULTATION_ILLEGAL_MOVE', $res->json('reason_code'));
        $this->assertSame('REQUESTED', $consultation->refresh()->status);
        $this->assertNull($consultation->resolved_by);
        $this->assertNull($consultation->resolved_at);
    }

    /**
     * A reschedule keeps scheduled_by pinned to the FIRST admin who took it and
     * never rewrites what the parent asked for.
     */
    public function test_reschedule_keeps_first_admin_and_freezes_parent_window(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');
        $askedDate = now()->addDays(3)->toDateString();
        $consultation = $this->requestConsultation($parent, [
            'preferred_date' => $askedDate,
            'preferred_time' => '10:30',
        ]);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/daftar-ulang/consultations/{$consultation->id}/schedule", [
            'scheduled_date' => now()->addDays(4)->toDateString(),
            'scheduled_time' => '09:00',
        ])->assertOk()->assertJsonPath('rescheduled', false);

        // A DIFFERENT admin reschedules.
        Sanctum::actingAs($this->admin2);
        $res = $this->postJson("/api/admin/daftar-ulang/consultations/{$consultation->id}/schedule", [
            'scheduled_date' => now()->addDays(8)->toDateString(),
            'scheduled_time' => '13:00',
        ])->assertOk()->assertJsonPath('rescheduled', true);

        $fresh = $consultation->refresh();
        $this->assertSame('SCHEDULED', $fresh->status);
        $this->assertSame($this->admin->id, $fresh->scheduled_by, 'scheduled_by is set-once.');
        $this->assertSame(now()->addDays(8)->toDateString(), $fresh->scheduled_date->toDateString());
        $this->assertSame('13:00:00', $fresh->scheduled_time); // storage: HH:MM:SS

        // The API speaks HH:MM both ways.
        $this->assertSame('13:00', $res->json('consultation.schedule.scheduled_time'));
        $this->assertSame('10:30', $res->json('consultation.request.preferred_time'));

        // The parent's request window is untouched by both scheduling calls.
        $this->assertSame($askedDate, $fresh->preferred_date->toDateString());
        $this->assertSame('10:30:00', $fresh->preferred_time);
        $this->assertNull($fresh->resolved_at);
    }

    /** A terminal row accepts no further mutation at all, not even an admin note. */
    public function test_terminal_consultation_rejects_every_further_change(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');
        $consultation = $this->requestConsultation($parent);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/daftar-ulang/consultations/{$consultation->id}/schedule", [
            'scheduled_date' => now()->addDays(4)->toDateString(),
            'scheduled_time' => '09:00',
        ])->assertOk();
        $this->postJson("/api/admin/daftar-ulang/consultations/{$consultation->id}/resolve", [
            'action' => 'complete',
        ])->assertOk();

        $completed = $consultation->refresh();
        $noteBefore = $completed->admin_note;

        // Every later attempt is a 409 and changes nothing.
        $this->postJson("/api/admin/daftar-ulang/consultations/{$consultation->id}/resolve", [
            'action' => 'cancel',
            'admin_note' => 'Mencoba membatalkan yang sudah selesai',
        ])->assertStatus(409)->assertJsonPath('reason_code', 'CONSULTATION_NOT_ACTIVE');

        $this->postJson("/api/admin/daftar-ulang/consultations/{$consultation->id}/schedule", [
            'scheduled_date' => now()->addDays(30)->toDateString(),
            'scheduled_time' => '08:00',
        ])->assertStatus(409)->assertJsonPath('reason_code', 'CONSULTATION_NOT_ACTIVE');

        Sanctum::actingAs($parent);
        $this->postJson("/api/daftar-ulang/consultations/{$consultation->id}/cancel")
            ->assertStatus(409)->assertJsonPath('reason_code', 'CONSULTATION_NOT_ACTIVE');

        $fresh = $consultation->refresh();
        $this->assertSame('COMPLETED', $fresh->status);
        $this->assertSame($noteBefore, $fresh->admin_note);
    }

    /** The model guard is the last line of defence behind the service and the DB. */
    public function test_model_guard_rejects_illegal_transition_and_deletion(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');
        $consultation = $this->requestConsultation($parent);

        // REQUESTED -> COMPLETED is not an edge in the state machine.
        try {
            $consultation->forceFill(['status' => 'COMPLETED', 'resolved_by' => $this->admin->id,
                'resolved_at' => now()])->save();
            $this->fail('Expected the model guard to reject REQUESTED -> COMPLETED.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Illegal Daftar Ulang consultation transition', $e->getMessage());
        }

        // Audit rows are never deleted; deleting would free the ACTIVE slot.
        try {
            $consultation->delete();
            $this->fail('Expected deletion of a consultation row to be refused.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('must be preserved', $e->getMessage());
        }

        $this->assertSame('REQUESTED', $consultation->refresh()->status);
    }

    // ======================================================================
    // E. Ownership, IDOR and input allowlists.
    // ======================================================================

    /**
     * Client-supplied ids are never trusted, and one parent cannot reach another
     * parent's consultation. Cross-user access is reported as 404 (not 403) so the
     * endpoint never confirms that somebody else's record exists.
     */
    public function test_parent_cannot_reach_another_parents_consultation(): void
    {
        [$parentA] = $this->parentWithInvoice('SD');
        $consultationA = $this->requestConsultation($parentA);

        [$parentB, $invoiceB] = $this->parentWithInvoice('SD');
        Sanctum::actingAs($parentB);

        // Foreign ids in the body are rejected by the strict allowlist.
        $this->postJson('/api/daftar-ulang/consultations', [
            'preferred_date' => now()->addDays(5)->toDateString(),
            'preferred_time' => '10:00',
            'user_id' => $parentA->id,
        ])->assertUnprocessable();

        $this->postJson('/api/daftar-ulang/consultations', [
            'preferred_date' => now()->addDays(5)->toDateString(),
            'preferred_time' => '10:00',
            'invoice_id' => $invoiceB->id,
        ])->assertUnprocessable();

        // Cancelling somebody else's id is a 404 and leaves their row REQUESTED.
        $this->postJson("/api/daftar-ulang/consultations/{$consultationA->id}/cancel")->assertNotFound();
        $this->assertSame('REQUESTED', $consultationA->refresh()->status);
        $this->assertNull($consultationA->resolved_by);
    }

    /** Preferred window validation: no past dates, no malformed values. */
    public function test_request_validation_rejects_bad_window_and_forbidden_fields(): void
    {
        [$parent] = $this->parentWithInvoice('SD');
        Sanctum::actingAs($parent);

        $this->postJson('/api/daftar-ulang/consultations', [
            'preferred_date' => now()->subDay()->toDateString(),
            'preferred_time' => '10:30',
        ])->assertUnprocessable();

        $this->postJson('/api/daftar-ulang/consultations', [
            'preferred_date' => '03-11-2026',
            'preferred_time' => '10:30',
        ])->assertUnprocessable();

        // Server-owned columns are never accepted from a client.
        foreach (['status', 'scheduled_by', 'resolved_by'] as $forbidden) {
            $this->postJson('/api/daftar-ulang/consultations', [
                'preferred_date' => now()->addDays(3)->toDateString(),
                'preferred_time' => '10:30',
                $forbidden => 'COMPLETED',
            ])->assertUnprocessable();
        }

        // Nothing was created by any rejected attempt.
        $this->assertSame(0, DaftarUlangConsultation::where('user_id', $parent->id)->count());
    }

    // ======================================================================
    // F. Admin queue: read-only, filtered, authorized.
    // ======================================================================

    /** The admin list is a pure read: it mutates nothing and filters correctly. */
    public function test_admin_list_is_read_only_and_filters(): void
    {
        [$sdParent, $sdInvoice] = $this->parentWithInvoice('SD');
        $sdConsult = $this->requestConsultation($sdParent);

        [$smpParent, $smpInvoice] = $this->parentWithInvoice('SMP');
        $this->requestConsultation($smpParent);

        // Move the SMP one to SCHEDULED so the status filter is meaningful.
        $smpConsult = DaftarUlangConsultation::where('user_id', $smpParent->id)->sole();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/daftar-ulang/consultations/{$smpConsult->id}/schedule", [
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'scheduled_time' => '11:00',
        ])->assertOk();

        $before = $this->lifecycleSnapshot();

        Sanctum::actingAs($this->admin);
        $all = $this->getJson('/api/admin/daftar-ulang/consultations')->assertOk()->json();
        $this->assertSame(2, $all['meta']['total']);
        $this->assertSame(1, $all['summary']['requested']);
        $this->assertSame(1, $all['summary']['scheduled']);
        $this->assertSame(2, $all['summary']['open']);
        $this->assertSame(0, $all['summary']['completed']);

        // Actionable-first ordering: REQUESTED before SCHEDULED.
        $this->assertSame('REQUESTED', $all['data'][0]['status']);
        $this->assertSame('SCHEDULED', $all['data'][1]['status']);

        // Filters.
        $this->getJson('/api/admin/daftar-ulang/consultations?jenjang=SMP')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'SCHEDULED');
        $this->getJson('/api/admin/daftar-ulang/consultations?status=REQUESTED')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sdConsult->id);
        // OPEN means both live states.
        $this->getJson('/api/admin/daftar-ulang/consultations?status=OPEN')->assertOk()
            ->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/daftar-ulang/consultations?status=COMPLETED')->assertOk()
            ->assertJsonCount(0, 'data');

        // A read-only endpoint mutated nothing anywhere.
        $this->assertSame($before, $this->lifecycleSnapshot());

        // No server-owned internals leak through the explicit response shape.
        foreach ($all['data'] as $row) {
            $this->assertArrayNotHasKey('scheduled_by', $row);
            $this->assertArrayNotHasKey('resolved_by', $row);
            $this->assertArrayNotHasKey('active_invoice', $row);
        }
    }

    /** A fingerprint of every lifecycle row a consultation must never disturb. */
    private function lifecycleSnapshot(): array
    {
        return [
            'users' => User::orderBy('id')->get()
                ->map(fn ($u) => [$u->id, $u->status_siswa, $u->status_kelulusan, (string) $u->continued_at])->all(),
            'seats' => PpdbSeatAllocation::orderBy('id')->get()
                ->map(fn ($s) => [$s->id, $s->status, $s->release_reason])->all(),
            'payments' => Payment::orderBy('id')->get()
                ->map(fn ($p) => [$p->id, $p->status, $p->amount, $p->payment_category])->all(),
            'invoices' => DaftarUlangInvoice::orderBy('id')->get()
                ->map(fn ($i) => [$i->id, $i->total, (string) $i->withdrawn_at])->all(),
            'consultations' => DaftarUlangConsultation::orderBy('id')->get()
                ->map(fn ($c) => [$c->id, $c->status, (string) $c->scheduled_date])->all(),
        ];
    }

    /** Parents cannot reach the admin desk; admins cannot reach the parent side. */
    public function test_consultation_authorization_boundaries(): void
    {
        [$parent] = $this->parentWithInvoice('SD');
        $consultation = $this->requestConsultation($parent);

        // A parent is not an admin: 403 on the desk.
        Sanctum::actingAs($parent);
        $this->getJson('/api/admin/daftar-ulang/consultations')->assertForbidden();
        $this->postJson("/api/admin/daftar-ulang/consultations/{$consultation->id}/schedule", [
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'scheduled_time' => '09:00',
        ])->assertForbidden();

        // A non-parent, non-admin role is rejected on the parent side.
        Sanctum::actingAs($this->actor('guru'));
        $this->postJson('/api/daftar-ulang/consultations', [
            'preferred_date' => now()->addDays(2)->toDateString(),
            'preferred_time' => '09:00',
        ])->assertForbidden();

        // super_admin runs the desk too (CheckRole VIP branch).
        Sanctum::actingAs($this->super);
        $this->getJson('/api/admin/daftar-ulang/consultations')->assertOk();

        $this->assertSame('REQUESTED', $consultation->refresh()->status);
    }

    /** Unauthenticated callers get a JSON 401, never a redirect. */
    public function test_consultation_guest_is_unauthorized(): void
    {
        $this->postJson('/api/daftar-ulang/consultations')->assertUnauthorized();
        $this->getJson('/api/daftar-ulang/consultations')->assertUnauthorized();
        $this->getJson('/api/admin/daftar-ulang/consultations')->assertUnauthorized();
        $this->postJson('/api/admin/daftar-ulang/consultations/1/schedule')->assertUnauthorized();
        $this->postJson('/api/admin/daftar-ulang/consultations/1/resolve')->assertUnauthorized();
    }

    // ======================================================================
    // G. Eligibility gates.
    // ======================================================================

    public function test_consultation_eligibility_gates(): void
    {
        // Non-LULUS candidates are outside the DU domain entirely.
        foreach (['BELUM', 'TIDAK_LULUS'] as $status) {
            $u = $this->parent('SD', $status);
            Sanctum::actingAs($u);
            $this->postJson('/api/daftar-ulang/consultations', [
                'preferred_date' => now()->addDays(2)->toDateString(),
                'preferred_time' => '09:00',
            ])->assertUnprocessable();
        }

        // LULUS but no DU invoice yet.
        $noInvoice = $this->parent('SD');
        Sanctum::actingAs($noInvoice);
        $this->postJson('/api/daftar-ulang/consultations', [
            'preferred_date' => now()->addDays(2)->toDateString(),
            'preferred_time' => '09:00',
        ])->assertNotFound();

        $this->assertSame(0, DaftarUlangConsultation::count());
    }

    /**
     * DU invoices are created lazily, so a LULUS parent who has not opened the DU
     * page yet has none. Their history read must be an EMPTY LIST - not a 404,
     * which would look like an error on a perfectly normal first visit.
     */
    public function test_history_read_without_invoice_is_empty_not_404(): void
    {
        $parent = $this->parent('SD');
        $this->assertSame(0, DaftarUlangInvoice::where('user_id', $parent->id)->count());

        Sanctum::actingAs($parent);
        $res = $this->getJson('/api/daftar-ulang/consultations')->assertOk();

        $this->assertSame([], $res->json('data'));
        $this->assertNull($res->json('active'));

        // Writing still needs a canonical invoice to attach to, so it stays a 404.
        $this->postJson('/api/daftar-ulang/consultations', [
            'preferred_date' => now()->addDays(2)->toDateString(),
            'preferred_time' => '09:00',
        ])->assertNotFound();

        $this->assertSame(0, DaftarUlangConsultation::count());
    }

    /**
     * Once a family has withdrawn, the parent side is closed to them: no new
     * request can be raised and no existing row can be rewritten. The withdrawal
     * stays the authoritative decision, and the open consultation is left as
     * untouched audit history for the admin desk to close out.
     */
    public function test_withdrawal_closes_parent_side_but_preserves_open_consultation(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');
        $consultation = $this->requestConsultation($parent);

        Sanctum::actingAs($parent);
        $this->postJson('/api/daftar-ulang/withdraw', ['note' => 'Pindah kota'])->assertOk();
        $this->assertSame('MENGUNDURKAN_DIRI', $parent->refresh()->status_siswa);

        // Parent-side consultation calls are all refused now.
        $this->postJson('/api/daftar-ulang/consultations', [
            'preferred_date' => now()->addDays(2)->toDateString(),
            'preferred_time' => '09:00',
        ])->assertUnprocessable();
        $this->getJson('/api/daftar-ulang/consultations')->assertUnprocessable();
        $this->postJson("/api/daftar-ulang/consultations/{$consultation->id}/cancel")->assertUnprocessable();

        // The pre-existing request is preserved verbatim, not silently cancelled.
        $fresh = $consultation->refresh();
        $this->assertSame('REQUESTED', $fresh->status);
        $this->assertNull($fresh->resolved_by);
        $this->assertSame(1, DaftarUlangConsultation::where('invoice_id', $invoice->id)->count());

        // The admin desk can still close it out.
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/daftar-ulang/consultations/{$consultation->id}/resolve", [
            'action' => 'cancel',
            'admin_note' => 'Keluarga sudah mengundurkan diri.',
        ])->assertOk()->assertJsonPath('consultation.status', 'CANCELLED');

        // And the withdrawal itself is still untouched by all of this.
        $this->assertSame('MENGUNDURKAN_DIRI', $parent->refresh()->status_siswa);
        $this->assertSame('LULUS', $parent->status_kelulusan);
        $this->assertNotNull($invoice->refresh()->withdrawn_at);
        $this->assertSame('RELEASED', PpdbSeatAllocation::where('user_id', $parent->id)->sole()->status);
    }

    // ======================================================================
    // B.7.4-E.1 — decision-phase eligibility for CREATING a consultation.
    // ======================================================================

    /**
     * The happy path this rule must NOT break: a LULUS parent with a pending DU
     * invoice (exactly the parentWithInvoice helper) can still create one.
     */
    public function test_eligible_lulus_parent_before_payment_can_create_consultation(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');

        Sanctum::actingAs($parent);
        $this->postJson('/api/daftar-ulang/consultations', [
            'preferred_date' => now()->addDays(3)->toDateString(),
            'preferred_time' => '10:30',
            'note' => 'Masih mempertimbangkan',
        ])->assertCreated()->assertJsonPath('consultation.status', 'REQUESTED');

        $this->assertSame(1, DaftarUlangConsultation::where('user_id', $parent->id)->count());
        $this->assertNull($parent->refresh()->status_siswa, 'Creating a consultation must never set status_siswa.');
    }

    /** SUCCESS on the canonical DU payment ends the decision phase: 422. */
    public function test_payment_success_blocks_creation(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');
        $paymentId = $this->paySuccess($invoice);

        Sanctum::actingAs($parent);
        $this->postJson('/api/daftar-ulang/consultations', [
            'preferred_date' => now()->addDays(3)->toDateString(),
            'preferred_time' => '10:30',
        ])->assertUnprocessable()->assertJsonValidationErrors('eligibility');

        $this->assertSame(0, DaftarUlangConsultation::count());
        // Purely a read-gate: nothing financial was mutated by the refusal.
        $this->assertSame('SUCCESS', Payment::findOrFail($paymentId)->status);
    }

    /** A paid parent who ALREADY has an active request keeps it — history and
     *  idempotency survive; the gate only refuses NEW rows. */
    public function test_payment_success_keeps_existing_active_request_and_replay(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');
        $consultation = $this->requestConsultation($parent);
        $this->paySuccess($invoice);

        // No silent rewrite or cancellation of the pre-payment request.
        $this->assertSame('REQUESTED', $consultation->refresh()->status);

        // History still lists it as the active one.
        Sanctum::actingAs($parent);
        $this->getJson('/api/daftar-ulang/consultations')->assertOk()
            ->assertJsonPath('active', $consultation->id)
            ->assertJsonCount(1, 'data');

        // The parent may still retract their own open request after paying.
        $this->postJson("/api/daftar-ulang/consultations/{$consultation->id}/cancel")->assertOk()
            ->assertJsonPath('consultation.status', 'CANCELLED');
    }

    /** status_siswa = MENGUNDURKAN_DIRI (set as the withdrawal service does) blocks creation. */
    public function test_mengundurkan_diri_blocks_creation(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');
        Sanctum::actingAs($parent);
        $this->postJson('/api/daftar-ulang/withdraw', ['note' => 'Pindah kota'])->assertOk();
        $this->assertSame('MENGUNDURKAN_DIRI', $parent->refresh()->status_siswa);

        // The withdrawal gate (invoice withdrawn_at) already refuses this with
        // the established `withdrawn` field; the request must not be created.
        $this->postJson('/api/daftar-ulang/consultations', [
            'preferred_date' => now()->addDays(3)->toDateString(),
            'preferred_time' => '10:30',
        ])->assertUnprocessable();

        $this->assertSame(0, DaftarUlangConsultation::count());
    }

    /** The admin desk still sees and resolves history after eligibility ended. */
    public function test_admin_history_and_resolution_survive_ended_eligibility(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');
        $consultation = $this->requestConsultation($parent);
        $this->paySuccess($invoice);
        // And go all the way to TERDAFTAR, so BOTH eligibility conditions hold.
        Sanctum::actingAs($parent);
        $this->postJson("/api/daftar-ulang/invoices/{$invoice->id}/agreement", ['agree' => true])->assertOk();
        $this->postJson('/api/daftar-ulang/continue')->assertOk();
        $this->assertSame('TERDAFTAR', $parent->refresh()->status_siswa);

        // Admin list still shows the row with participant context...
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/daftar-ulang/consultations')->assertOk()
            ->assertJsonPath('data.0.id', $consultation->id)
            ->assertJsonPath('data.0.participant.name', $parent->name)
            ->assertJsonPath('summary.requested', 1);

        // ...and admin schedule + resolve still work on it.
        $this->postJson("/api/admin/daftar-ulang/consultations/{$consultation->id}/schedule", [
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'scheduled_time' => '09:00',
        ])->assertOk()->assertJsonPath('consultation.status', 'SCHEDULED');

        $this->postJson("/api/admin/daftar-ulang/consultations/{$consultation->id}/resolve", [
            'action' => 'complete',
        ])->assertOk()->assertJsonPath('consultation.status', 'COMPLETED');

        // The lifecycle the parent already completed is disturbed in no way.
        $this->assertSame('TERDAFTAR', $parent->refresh()->status_siswa);
        $this->assertNotNull($parent->continued_at);
    }

    /** The gate is read-only: every consultation operation leaves the whole
     *  lifecycle surface (payment, invoice, agreement, seat, status fields)
     *  byte-for-byte identical, including on the new refusal paths. */
    public function test_eligibility_gate_never_mutates_lifecycle_records(): void
    {
        [$parent, $invoice] = $this->parentWithInvoice('SD');
        $consultation = $this->requestConsultation($parent);
        $paymentId = $this->paySuccess($invoice);
        $before = $this->lifecycleSnapshot();

        Sanctum::actingAs($parent);
        $this->postJson('/api/daftar-ulang/consultations', [
            'preferred_date' => now()->addDays(3)->toDateString(),
            'preferred_time' => '10:30',
        ])->assertUnprocessable();

        $this->postJson("/api/daftar-ulang/consultations/{$consultation->id}/cancel")->assertOk();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/daftar-ulang/consultations/{$consultation->id}/resolve", [
            'action' => 'cancel',
        ])->assertStatus(409); // terminal after the parent's cancel

        // The consultation row itself is the ONE thing allowed to change (the
        // parent's own cancel). Everything else must be byte-for-byte identical.
        $after = $this->lifecycleSnapshot();
        $this->assertEquals($before['users'], $after['users']);
        $this->assertEquals($before['seats'], $after['seats']);
        $this->assertEquals($before['payments'], $after['payments']);
        $this->assertEquals($before['invoices'], $after['invoices']);

        // The refusal created no row and the parent's cancel is recorded once.
        $this->assertSame(1, DaftarUlangConsultation::count());
        $fresh = $consultation->refresh();
        $this->assertSame('CANCELLED', $fresh->status);
        $this->assertSame($parent->id, $fresh->resolved_by);
        $this->assertNull($fresh->scheduled_date);
    }
}
