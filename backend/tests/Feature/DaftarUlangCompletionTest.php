<?php

namespace Tests\Feature;

use App\Models\Biodata;
use App\Models\DaftarUlangAgreementAcceptance;
use App\Models\DaftarUlangInvoice;
use App\Models\DaftarUlangWithdrawal;
use App\Models\Payment;
use App\Models\PpdbSeatAllocation;
use App\Models\Setting;
use App\Models\User;
use App\Services\DaftarUlangPricingService;
use App\Services\PpdbCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B.7.2 — MELANJUTKAN completion: the only path to status_siswa = TERDAFTAR.
 *
 * Proves the full server-side precondition chain (role, ownership, LULUS,
 * canonical non-withdrawn DU invoice, agreement vs the invoice's authoritative
 * snapshot, canonical DAFTAR_ULANG payment SUCCESS, active seat), the
 * one-way/terminal transition, idempotent replay, strict IDOR allowlist,
 * withdrawal interaction, transaction rollback and the isolation invariants
 * (payment/seat/capacity/agreement/PPDB untouched, legacy users never
 * auto-promoted).
 *
 * TEST DB ONLY (ppdb_sekolah_test).
 */
class DaftarUlangCompletionTest extends TestCase
{
    use RefreshDatabase;

    private const AGREEMENT = ['version' => 'DU-C1', 'text' => 'Completion test agreement.'];

    private User $super;
    private DaftarUlangPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('ppdb_sekolah_test', DB::selectOne('SELECT DATABASE() db')->db);
        $this->super = $this->actor('super_admin');
        $this->pricing = app(DaftarUlangPricingService::class);
        config(['daftar_ulang.agreement' => self::AGREEMENT]);
        $this->pricing->updateDefault($this->super->id, 'SD', $this->items(), 0);
        Setting::updateOrCreate(['key' => 'rekening_sd'], ['value' => '11111111']);
        Setting::updateOrCreate(['key' => 'bank_name'], ['value' => 'Bank']);
        Setting::updateOrCreate(['key' => 'bank_owner'], ['value' => 'Yayasan']);
    }

    // ---------------------------------------------------------------- helpers

    private function items(): array
    {
        return [['id' => 'formulir', 'name' => 'Formulir', 'quantity' => 1, 'unit_amount' => 100000],
            ['id' => 'seragam', 'name' => 'Seragam', 'quantity' => 1, 'unit_amount' => 250000]];
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['jenjang' => 'SD']);
        $u->forceFill(['role' => $role])->save();
        return $u->refresh();
    }

    private function parent(string $status = 'LULUS'): User
    {
        $u = User::factory()->create(['jenjang' => 'SD', 'jalur' => 'UMUM']);
        $u->forceFill(['role' => 'orang_tua', 'status_kelulusan' => $status])->save();
        $bio = array_fill_keys(['asal_sekolah', 'nik_siswa', 'no_kk', 'tempat_lahir', 'status_peserta_didik',
            'nama_ibu', 'nik_ibu', 'pendidikan_ibu', 'pekerjaan_ibu', 'penghasilan_ibu', 'nama_ayah', 'nik_ayah',
            'pendidikan_ayah', 'pekerjaan_ayah', 'penghasilan_ayah', 'alamat_lengkap', 'kota', 'kecamatan',
            'kelurahan', 'rt', 'rw', 'no_hp_ortu'], 'Test');
        Biodata::create([...$bio, 'user_id' => $u->id, 'nama_lengkap' => 'Completion student',
            'jenis_kelamin' => 'L', 'tanggal_lahir' => '2018-01-01', 'anak_ke' => 1, 'jalur_pendaftaran' => 'UMUM']);
        PpdbSeatAllocation::create(['user_id' => $u->id, 'payment_id' => null, 'jenjang' => 'SD',
            'status' => $status === 'TIDAK_LULUS' ? 'RELEASED' : 'ALLOCATED', 'allocated_at' => now(),
            'released_at' => $status === 'TIDAK_LULUS' ? now() : null,
            'release_reason' => $status === 'TIDAK_LULUS' ? PpdbCapacityService::RELEASE_NOT_ACCEPTED : null]);
        return $u;
    }

    /** Real invoice through the DU service (authoritative agreement snapshot). */
    private function makeInvoice(User $parent): DaftarUlangInvoice
    {
        Sanctum::actingAs($parent);
        $this->postJson('/api/daftar-ulang/invoices')->assertOk();
        return DaftarUlangInvoice::where('user_id', $parent->id)->sole();
    }

    private function agree(User $parent, DaftarUlangInvoice $invoice): DaftarUlangAgreementAcceptance
    {
        Sanctum::actingAs($parent);
        $this->postJson("/api/daftar-ulang/invoices/{$invoice->id}/agreement", ['agree' => true])->assertOk();
        return DaftarUlangAgreementAcceptance::where('invoice_id', $invoice->id)->sole();
    }

    private function pay(DaftarUlangInvoice $invoice): Payment
    {
        $payment = Payment::findOrFail($invoice->payment_id);
        $payment->update(['status' => 'SUCCESS', 'verifier_id' => $this->super->id, 'verified_at' => now()]);
        return $payment->refresh();
    }

    /** Fully eligible student: LULUS + invoice + agreement + SUCCESS + seat. */
    private function eligible(): array
    {
        $parent = $this->parent();
        $invoice = $this->makeInvoice($parent);
        $this->agree($parent, $invoice);
        $payment = $this->pay($invoice);
        return [$parent, $invoice, $payment];
    }

    private function continueAs(User $user, array $body = [])
    {
        Sanctum::actingAs($user);
        return $this->postJson('/api/daftar-ulang/continue', $body);
    }

    private function assertUnmutated(User $parent): void
    {
        $this->assertNull($parent->refresh()->status_siswa);
        $this->assertNull($parent->continued_at);
        $this->assertNull(DaftarUlangInvoice::where('user_id', $parent->id)->value('withdrawn_at'));
    }

    // ------------------------------------------------------ happy path ----

    public function test_happy_path_continue_commits_terdaftar(): void
    {
        [$parent, $invoice, $payment] = $this->eligible();
        $seat = PpdbSeatAllocation::where('user_id', $parent->id)->where('status', 'ALLOCATED')->sole();

        $paymentBefore = Payment::findOrFail($payment->id)->getAttributes();
        $seatBefore = PpdbSeatAllocation::findOrFail($seat->id)->getAttributes();
        $capBefore = DB::table('ppdb_capacities')->get()->toJson();

        $res = $this->continueAs($parent)->assertOk();
        $this->assertSame('TERDAFTAR', $res->json('status_siswa'));
        $this->assertNotNull($res->json('continued_at'));
        $this->assertTrue($res->json('changed'));

        $parent->refresh();
        $this->assertSame('TERDAFTAR', $parent->status_siswa);
        $this->assertNotNull($parent->continued_at);

        // Payment unchanged (byte-for-byte), SUCCESS stays, category stays.
        $paymentAfter = Payment::findOrFail($payment->id)->getAttributes();
        $this->assertSame($paymentBefore, $paymentAfter);
        $this->assertSame('SUCCESS', $paymentAfter['status']);
        $this->assertSame(Payment::CATEGORY_DAFTAR_ULANG, $paymentAfter['payment_category']);

        // Same allocation, same status, same ownership; capacity untouched.
        $this->assertSame($seatBefore, PpdbSeatAllocation::findOrFail($seat->id)->getAttributes());
        $this->assertSame(1, PpdbSeatAllocation::where('user_id', $parent->id)->count());
        $this->assertSame($capBefore, DB::table('ppdb_capacities')->get()->toJson());

        // Agreement acceptance untouched; invoice not withdrawn; no withdrawal row.
        $this->assertSame(1, DaftarUlangAgreementAcceptance::where('invoice_id', $invoice->id)->count());
        $this->assertNull($invoice->refresh()->withdrawn_at);
        $this->assertSame(0, DaftarUlangWithdrawal::where('invoice_id', $invoice->id)->count());
    }

    public function test_idempotent_replay_keeps_original_timestamp(): void
    {
        [$parent] = $this->eligible();
        $first = $this->continueAs($parent)->assertOk()->json();
        $this->assertTrue($first['changed']);

        $paymentBefore = Payment::where('user_id', $parent->id)->daftarUlang()->sole()->getAttributes();
        $seatBefore = PpdbSeatAllocation::where('user_id', $parent->id)->sole()->getAttributes();

        $second = $this->continueAs($parent)->assertOk()->json();
        $this->assertFalse($second['changed']);
        $this->assertSame($first['continued_at'], $second['continued_at']);
        $this->assertSame('TERDAFTAR', $second['status_siswa']);
        $this->assertSame($paymentBefore, Payment::where('user_id', $parent->id)->daftarUlang()->sole()->getAttributes());
        $this->assertSame($seatBefore, PpdbSeatAllocation::where('user_id', $parent->id)->sole()->getAttributes());
        $this->assertSame(1, PpdbSeatAllocation::where('user_id', $parent->id)->count());
    }

    // ------------------------------------------------ invalid preconditions

    public function test_belum_status_is_rejected(): void
    {
        $parent = $this->parent('BELUM');
        $this->continueAs($parent)->assertUnprocessable();
        $this->assertUnmutated($parent);
    }

    public function test_tidak_lulus_is_rejected(): void
    {
        $parent = $this->parent('TIDAK_LULUS');
        $this->continueAs($parent)->assertUnprocessable();
        $this->assertUnmutated($parent);
    }

    public function test_no_invoice_is_404(): void
    {
        $parent = $this->parent();
        $this->continueAs($parent)->assertNotFound();
        $this->assertUnmutated($parent);
    }

    public function test_invoice_belonging_to_another_user_is_not_usable(): void
    {
        $victim = $this->parent();
        $this->makeInvoice($victim);
        $attacker = $this->parent();
        // Attacker has no invoice of their own: server-side ownership resolution
        // must not cross into the victim's invoice even though one exists.
        $this->continueAs($attacker)->assertNotFound();
        $this->assertNull(DaftarUlangInvoice::where('user_id', $victim->id)->sole()->refresh()->withdrawn_at);
        $this->assertNull($victim->refresh()->status_siswa);
        $this->assertNull($attacker->refresh()->status_siswa);
    }

    public function test_withdrawn_invoice_is_rejected(): void
    {
        [$parent, $invoice] = $this->eligible();
        $invoice->forceFill(['withdrawn_at' => now()])->save();
        $this->continueAs($parent)->assertUnprocessable();
        $this->assertNull($parent->refresh()->status_siswa);
    }

    public function test_no_agreement_is_rejected(): void
    {
        $parent = $this->parent();
        $invoice = $this->makeInvoice($parent);
        $this->pay($invoice);
        $this->continueAs($parent)->assertUnprocessable();
        $this->assertUnmutated($parent);
    }

    public function test_agreement_mismatch_is_rejected(): void
    {
        $parent = $this->parent();
        $invoice = $this->makeInvoice($parent);
        $this->pay($invoice);
        // Forged acceptance for a different version than the invoice snapshot.
        DaftarUlangAgreementAcceptance::create([
            'user_id' => $parent->id, 'invoice_id' => $invoice->id,
            'agreement_version' => 'DU-OLD', 'agreement_sha256' => hash('sha256', 'forged'),
            'agreed_at' => now(),
        ]);
        $this->continueAs($parent)->assertUnprocessable();
        $this->assertUnmutated($parent);
    }

    public function test_pending_payment_is_rejected(): void
    {
        $parent = $this->parent();
        $invoice = $this->makeInvoice($parent);
        $this->agree($parent, $invoice); // payment stays PENDING
        $this->continueAs($parent)->assertUnprocessable();
        $this->assertUnmutated($parent);
    }

    public function test_rejected_payment_is_rejected(): void
    {
        $parent = $this->parent();
        $invoice = $this->makeInvoice($parent);
        $this->agree($parent, $invoice);
        Payment::findOrFail($invoice->payment_id)->update(['status' => 'REJECTED', 'rejection_reason' => 'x']);
        $this->continueAs($parent)->assertUnprocessable();
        $this->assertUnmutated($parent);
    }

    public function test_ppdb_payment_success_does_not_count(): void
    {
        $parent = $this->parent();
        $invoice = $this->makeInvoice($parent);
        $this->agree($parent, $invoice); // DU payment still PENDING
        // A SUCCESS payment in the PPDB category must never satisfy the gate.
        Payment::create(['user_id' => $parent->id, 'payment_category' => Payment::CATEGORY_PPDB,
            'invoice_number' => 'PPDB-X', 'jenjang' => 'SD', 'amount' => 1, 'status' => 'SUCCESS',
            'verified_at' => now()]);
        $this->continueAs($parent)->assertUnprocessable();
        $this->assertUnmutated($parent);
    }

    public function test_no_active_seat_is_rejected(): void
    {
        [$parent] = $this->eligible();
        PpdbSeatAllocation::where('user_id', $parent->id)->sole()->update([
            'status' => 'RELEASED', 'released_at' => now(),
            'release_reason' => PpdbCapacityService::RELEASE_NOT_ACCEPTED,
        ]);
        $this->continueAs($parent)->assertUnprocessable();
        $this->assertNull($parent->refresh()->status_siswa);
    }

    public function test_mengundurkan_diri_state_is_terminal(): void
    {
        [$parent] = $this->eligible();
        $parent->forceFill(['status_siswa' => User::SISWA_MENGUNDURKAN_DIRI])->save();
        $this->continueAs($parent)->assertUnprocessable();
        $this->assertSame('MENGUNDURKAN_DIRI', $parent->refresh()->status_siswa);
        $this->assertNull($parent->continued_at);
    }

    // ------------------------------------------------------------ security

    public function test_guest_is_unauthorized(): void
    {
        $this->postJson('/api/daftar-ulang/continue')->assertUnauthorized();
    }

    public function test_admin_and_guru_are_forbidden(): void
    {
        $this->continueAs($this->actor('admin'))->assertForbidden();
        $this->continueAs($this->actor('guru'))->assertForbidden();
    }

    public function test_super_admin_bypass_cannot_set_state_without_prerequisites(): void
    {
        // CheckRole grants super_admin VIP access, but the service must still
        // refuse because the canonical user is not role orang_tua.
        $this->continueAs($this->actor('super_admin'))->assertForbidden();
        $this->assertNull(User::find($this->super->id)->status_siswa);
    }

    public function test_client_supplied_identity_fields_are_rejected(): void
    {
        [$victim] = $this->eligible();
        $attacker = $this->parent();
        $invoiceId = DaftarUlangInvoice::where('user_id', $victim->id)->value('id');
        $paymentId = DaftarUlangInvoice::where('user_id', $victim->id)->value('payment_id');
        $allocationId = PpdbSeatAllocation::where('user_id', $victim->id)->value('id');

        foreach ([
            ['user_id' => $victim->id],
            ['invoice_id' => $invoiceId],
            ['payment_id' => $paymentId],
            ['allocation_id' => $allocationId],
            ['status_siswa' => 'TERDAFTAR'],
            ['jenjang' => 'SD'],
        ] as $body) {
            $this->continueAs($attacker, $body)->assertUnprocessable();
        }

        $this->assertNull($attacker->refresh()->status_siswa);
        $this->assertNull($victim->refresh()->status_siswa);
    }

    // ---------------------------------------------- state immutability ----

    public function test_terminal_state_transitions_are_rejected_by_model(): void
    {
        [$parent] = $this->eligible();
        $this->continueAs($parent)->assertOk();

        // TERDAFTAR -> NULL and TERDAFTAR -> MENGUNDURKAN_DIRI are rejected
        // at the model layer (LogicException); TERDAFTAR -> MENGUNDURKAN_DIRI
        // would additionally violate the DB CHECK constraint (defence in
        // depth), so we accept either guard firing.
        foreach ([null, 'MENGUNDURKAN_DIRI'] as $next) {
            try {
                $parent->forceFill(['status_siswa' => $next])->save();
                $this->fail('Transition away from TERDAFTAR must be rejected.');
            } catch (\LogicException|\Illuminate\Database\QueryException $e) {
                // model guard and/or DB CHECK — both are correct rejections
            }
        }
        $this->assertSame('TERDAFTAR', $parent->refresh()->status_siswa);

        // continued_at is set exactly once and can never be overwritten.
        try {
            $parent->forceFill(['continued_at' => now()->addDay()])->save();
            $this->fail('continued_at overwrite must be rejected.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('continued_at', $e->getMessage());
        }

        // MENGUNDURKAN_DIRI -> TERDAFTAR / NULL must both throw too.
        $withdrawn = $this->parent();
        $withdrawn->forceFill(['status_siswa' => User::SISWA_MENGUNDURKAN_DIRI])->save();
        foreach (['TERDAFTAR', null] as $next) {
            try {
                $withdrawn->forceFill(['status_siswa' => $next])->save();
                $this->fail('Transition away from MENGUNDURKAN_DIRI must be rejected.');
            } catch (\LogicException|\Illuminate\Database\QueryException $e) {
                // model guard and/or DB CHECK — both are correct rejections
            }
        }
        $this->assertSame('MENGUNDURKAN_DIRI', $withdrawn->refresh()->status_siswa);
    }

    public function test_withdrawal_marks_canonical_state_and_blocks_continue(): void
    {
        [$parent, $invoice] = $this->eligible();
        Sanctum::actingAs($parent);
        $this->postJson('/api/daftar-ulang/withdraw')->assertOk();

        $parent->refresh();
        $this->assertSame('MENGUNDURKAN_DIRI', $parent->status_siswa);
        $this->assertNull($parent->continued_at);

        $this->continueAs($parent)->assertUnprocessable();
        $this->assertSame('MENGUNDURKAN_DIRI', $parent->refresh()->status_siswa);
    }

    public function test_withdraw_is_rejected_after_terdaftar(): void
    {
        [$parent] = $this->eligible();
        $this->continueAs($parent)->assertOk();
        Sanctum::actingAs($parent);
        $this->postJson('/api/daftar-ulang/withdraw')->assertUnprocessable();
        $this->assertSame('TERDAFTAR', $parent->refresh()->status_siswa);
        $this->assertNotNull($parent->continued_at);
    }

    public function test_withdrawal_state_is_terminal_against_continue(): void
    {
        [$parent] = $this->eligible();
        $parent->forceFill(['status_siswa' => User::SISWA_MENGUNDURKAN_DIRI])->save();
        $this->continueAs($parent)->assertUnprocessable();
        $this->assertSame('MENGUNDURKAN_DIRI', $parent->refresh()->status_siswa);
    }

    // --------------------------------------------------------- rollback ----

    public function test_failure_after_transition_rolls_back_everything(): void
    {
        [$parent, $invoice, $payment] = $this->eligible();
        $seat = PpdbSeatAllocation::where('user_id', $parent->id)->sole();

        $paymentBefore = Payment::findOrFail($payment->id)->getAttributes();
        $seatBefore = PpdbSeatAllocation::findOrFail($seat->id)->getAttributes();
        $capBefore = DB::table('ppdb_capacities')->get()->toJson();
        $invoiceBefore = $invoice->refresh()->getAttributes();
        $agreementBefore = DaftarUlangAgreementAcceptance::where('invoice_id', $invoice->id)->sole()->getAttributes();

        // Inject a failure AFTER the users.status_siswa/continued_at save by
        // registering a throwing updated-listener on User. The listener fires
        // after the row write but still inside the completion transaction, so
        // the exception must roll back the ENTIRE transaction.
        User::updated(function (User $u) use ($parent) {
            if ($u->id === $parent->id && $u->status_siswa === User::SISWA_TERDAFTAR) {
                throw new \RuntimeException('Injected post-mutation failure');
            }
        });

        try {
            app(\App\Services\DaftarUlangCompletionService::class)->continue($parent->id);
            $this->fail('Injected failure must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected post-mutation failure', $e->getMessage());
        } finally {
            User::flushEventListeners();
        }

        // No partial official state survived the rollback: every domain row is
        // byte-for-byte identical to before the attempt.
        $this->assertNull($parent->refresh()->status_siswa);
        $this->assertNull($parent->continued_at);
        $this->assertSame($invoiceBefore, $invoice->refresh()->getAttributes());
        $this->assertSame($paymentBefore, Payment::findOrFail($payment->id)->getAttributes());
        $this->assertSame($seatBefore, PpdbSeatAllocation::findOrFail($seat->id)->getAttributes());
        $this->assertSame($capBefore, DB::table('ppdb_capacities')->get()->toJson());
        $this->assertSame($agreementBefore,
            DaftarUlangAgreementAcceptance::where('invoice_id', $invoice->id)->sole()->getAttributes());

        // And the transition still succeeds cleanly once the failure is gone
        // (proving the rollback left no half-committed guard state behind).
        $this->continueAs($parent)->assertOk();
        $this->assertSame('TERDAFTAR', $parent->refresh()->status_siswa);
    }

    // ------------------------------------------- legacy / auto-promotion ----

    public function test_legacy_users_are_not_auto_promoted(): void
    {
        // A user with every legacy prerequisite but who has NOT explicitly
        // continued must remain NULL.
        [$parent] = $this->eligible();
        $this->assertNull($parent->refresh()->status_siswa);
        $this->assertNull($parent->continued_at);
    }

    public function test_status_siswa_mass_assignment_is_blocked(): void
    {
        $parent = $this->parent();
        $parent->update(['status_siswa' => 'TERDAFTAR', 'continued_at' => now()]);
        $this->assertNull($parent->refresh()->status_siswa);
        $this->assertNull($parent->continued_at);
    }
}
