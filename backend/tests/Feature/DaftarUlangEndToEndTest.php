<?php

namespace Tests\Feature;

use App\Models\Biodata;
use App\Models\DaftarUlangAgreementAcceptance;
use App\Models\DaftarUlangInvoice;
use App\Models\Payment;
use App\Models\PpdbSeatAllocation;
use App\Models\Setting;
use App\Models\User;
use App\Services\DaftarUlangPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B.6 FINAL — end-to-end Daftar Ulang integration audit.
 *
 * Verifies B.5 -> B.6A -> B.6B -> B.6C as ONE flow on the test DB only:
 * pricing -> invoice -> agreement -> payment -> proof -> admin verify/reject,
 * plus immutable snapshot, agreement/bank consistency, IDOR, category
 * isolation, seat/quota invariants and idempotency. TEST DB ONLY.
 */
class DaftarUlangEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private User $super;
    private User $admin;
    private DaftarUlangPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('ppdb_sekolah_test', DB::selectOne('SELECT DATABASE() db')->db);
        $this->super = $this->actor('super_admin');
        $this->admin = $this->actor('admin');
        $this->pricing = app(DaftarUlangPricingService::class);
        config(['daftar_ulang.agreement' => ['version' => 'DU-E2E-1', 'text' => 'Official Daftar Ulang agreement (E2E).']]);
        Setting::updateOrCreate(['key' => 'rekening_sd'], ['value' => '11111111']);
        Setting::updateOrCreate(['key' => 'rekening_smp'], ['value' => '22222222']);
        Setting::updateOrCreate(['key' => 'bank_name'], ['value' => 'Bank E2E']);
        Setting::updateOrCreate(['key' => 'bank_owner'], ['value' => 'Yayasan E2E']);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['jenjang' => 'SD']);
        $user->forceFill(['role' => $role])->save();
        return $user->refresh();
    }

    private function parent(string $jenjang = 'SD', string $status = 'LULUS'): User
    {
        $user = User::factory()->create(['jenjang' => $jenjang, 'jalur' => 'UMUM']);
        $user->forceFill(['role' => 'orang_tua', 'status_kelulusan' => $status])->save();
        $bio = array_fill_keys(['asal_sekolah', 'nik_siswa', 'no_kk', 'tempat_lahir', 'status_peserta_didik',
            'nama_ibu', 'nik_ibu', 'pendidikan_ibu', 'pekerjaan_ibu', 'penghasilan_ibu', 'nama_ayah', 'nik_ayah',
            'pendidikan_ayah', 'pekerjaan_ayah', 'penghasilan_ayah', 'alamat_lengkap', 'kota', 'kecamatan',
            'kelurahan', 'rt', 'rw', 'no_hp_ortu'], 'Test');
        Biodata::create([...$bio, 'user_id' => $user->id, 'nama_lengkap' => 'Canonical student',
            'jenis_kelamin' => 'L', 'tanggal_lahir' => '2018-01-01', 'anak_ke' => 1, 'jalur_pendaftaran' => 'UMUM']);
        PpdbSeatAllocation::create(['user_id' => $user->id, 'payment_id' => null, 'jenjang' => $jenjang,
            'status' => 'ALLOCATED', 'allocated_at' => now()]);
        return $user->refresh();
    }

    private function items(int $amount = 100000): array
    {
        return [['id' => 'tuition', 'name' => 'Tuition', 'quantity' => 2, 'unit_amount' => $amount],
            ['id' => 'kit', 'name' => 'Student kit', 'quantity' => 1, 'unit_amount' => 50000]];
    }

    private function setDefaultPricing(string $jenjang, array $items, int $discount): void
    {
        $this->pricing->updateDefault($this->super->id, $jenjang, $items, $discount);
    }

    private function makeInvoice(User $user): DaftarUlangInvoice
    {
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/daftar-ulang/invoices')->assertOk()->json('id');
        return DaftarUlangInvoice::findOrFail($id);
    }

    private function uploadProof(): void
    {
        $file = UploadedFile::fake()->image('bukti.jpg', 600, 400)->size(100);
        $this->postJson('/api/daftar-ulang/payments/proof', ['bukti_bayar' => $file]);
    }

    private function seatsSnapshot(): array
    {
        return [
            'allocations' => DB::table('ppdb_seat_allocations')->orderBy('id')->get()->toJson(),
            'capacities' => DB::table('ppdb_capacities')->orderBy('jenjang')->get()->toJson(),
        ];
    }

    // PHASE B — happy path: pricing -> invoice -> agreement -> proof -> admin verify.
    public function test_happy_path_end_to_end(): void
    {
        $this->setDefaultPricing('SD', $this->items(), 10000);
        $parent = $this->parent('SD');
        // Snapshot seats AFTER the participant exists (their initial PPDB seat is
        // the baseline). The audit proves DU never adds/removes/changes seats.
        $seatsBefore = $this->seatsSnapshot();

        // 1-3. eligible parent resolves pricing + jenjang-derived bank account.
        Sanctum::actingAs($parent);
        $pricing = $this->getJson('/api/daftar-ulang/pricing')->assertOk()->json();
        $this->assertSame('DEFAULT', $pricing['source']);
        $this->assertSame('SD', $pricing['jenjang']);
        $this->assertSame('11111111', $pricing['account']['rekening']); // rekening_sd

        // 4-5. invoice created + idempotent.
        $invId = $this->postJson('/api/daftar-ulang/invoices')->assertOk()->json('id');
        $this->assertSame($invId, $this->postJson('/api/daftar-ulang/invoices')->assertOk()->json('id'));
        $this->assertSame(1, DaftarUlangInvoice::where('user_id', $parent->id)->count());

        // 6-8. agreement from snapshot; accept; replay idempotent.
        $invoice = $this->getJson("/api/daftar-ulang/invoices/{$invId}")->assertOk()->json();
        $this->assertSame('DU-E2E-1', $invoice['pricing_snapshot']['agreement']['version']);
        $this->assertSame('Official Daftar Ulang agreement (E2E).', $invoice['pricing_snapshot']['agreement']['text']);
        $this->postJson("/api/daftar-ulang/invoices/{$invId}/agreement", ['agree' => true])->assertOk();
        $this->postJson("/api/daftar-ulang/invoices/{$invId}/agreement", ['agree' => true])->assertOk();
        $this->assertSame(1, DaftarUlangAgreementAcceptance::where('invoice_id', $invId)->count());

        // 9-11. payment is DAFTAR_ULANG, amount equals total.
        $invoiceModel = DaftarUlangInvoice::findOrFail($invId);
        $payment = $invoiceModel->payment;
        $this->assertSame(Payment::CATEGORY_DAFTAR_ULANG, $payment->payment_category);
        $this->assertSame($invoiceModel->total, $payment->amount);
        $this->assertSame('PENDING', $payment->status);

        // 12-13. upload proof -> PENDING_MANUAL.
        $this->uploadProof();
        $this->assertSame('PENDING_MANUAL', $payment->refresh()->status);
        $this->assertSame($seatsBefore, $this->seatsSnapshot()); // seat invariant through payment

        // 14. admin DU inbox shows it.
        Sanctum::actingAs($this->admin);
        $inbox = $this->getJson('/api/admin/daftar-ulang/payments/pending')->assertOk()->json();
        $this->assertContains($payment->id, collect($inbox)->pluck('id'));

        // 15. admin opens proof (authorized).
        $this->getJson("/api/admin/daftar-ulang/payments/{$payment->id}/evidence")->assertOk();

        // 16-17. admin verifies -> SUCCESS.
        $this->postJson("/api/admin/daftar-ulang/payments/{$payment->id}/verify")->assertOk();
        $this->assertSame('SUCCESS', $payment->refresh()->status);
        $this->assertNotNull($payment->verified_at);
        $this->assertSame($this->admin->id, $payment->verifier_id);

        // 18-19. parent refresh sees SUCCESS on the SAME canonical invoice/payment.
        Sanctum::actingAs($parent);
        $after = $this->getJson("/api/daftar-ulang/invoices/{$invId}")->assertOk()->json();
        $this->assertSame($invId, $after['id']);
        $this->assertSame($payment->id, $after['payment']['id']);
        $this->assertSame('SUCCESS', $after['payment']['status']);
        $this->assertSame($seatsBefore, $this->seatsSnapshot()); // no seat consumed at any stage
    }

    // PHASE C — rejection then re-upload then verify; metadata resets; same identity.
    public function test_reject_reupload_verify_path(): void
    {
        $this->setDefaultPricing('SD', $this->items(), 10000);
        $parent = $this->parent('SD');
        $invoice = $this->makeInvoice($parent);
        $payment = $invoice->payment;

        Sanctum::actingAs($parent);
        $this->uploadProof();
        $this->assertSame('PENDING_MANUAL', $payment->refresh()->status);
        $firstProof = $payment->proof_of_payment;

        // Admin rejects with a reason.
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/daftar-ulang/payments/{$payment->id}/reject", ['reason' => 'Bukti tidak jelas'])->assertOk();
        $payment->refresh();
        $this->assertSame('REJECTED', $payment->status);
        $this->assertSame('Bukti tidak jelas', $payment->rejection_reason);

        // Parent refresh sees REJECTED + exact reason.
        Sanctum::actingAs($parent);
        $rejected = $this->getJson("/api/daftar-ulang/invoices/{$invoice->id}")->assertOk()->json();
        $this->assertSame('REJECTED', $rejected['payment']['status']);
        $this->assertSame('Bukti tidak jelas', $rejected['payment']['rejection_reason']);

        // Re-upload -> PENDING_MANUAL, prior review trail cleared, new proof stored.
        $this->uploadProof();
        $payment->refresh();
        $this->assertSame('PENDING_MANUAL', $payment->status);
        $this->assertNull($payment->verifier_id);
        $this->assertNull($payment->verified_at);
        $this->assertNull($payment->rejection_reason);
        $this->assertNotSame($firstProof, $payment->proof_of_payment);

        // Verify -> SUCCESS on the SAME payment identity.
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/daftar-ulang/payments/{$payment->id}/verify")->assertOk();
        $this->assertSame('SUCCESS', $payment->refresh()->status);
        $this->assertSame($invoice->payment_id, $payment->id);
    }

    // PHASE D — immutable pricing snapshot: pricing change must not alter history.
    public function test_immutable_snapshot_survives_pricing_change(): void
    {
        $this->setDefaultPricing('SD', $this->items(100000), 10000); // total = 250000 - 10000 = 240000
        $a = $this->parent('SD');
        $invoiceA = $this->makeInvoice($a);
        $snapshotA = $invoiceA->pricing_snapshot;
        $this->assertSame(240000, $invoiceA->total);

        // Change current pricing AFTER invoice A exists.
        $this->setDefaultPricing('SD', $this->items(500000), 0);

        // Existing invoice A unchanged.
        $invoiceA->refresh();
        $this->assertSame(240000, $invoiceA->total);
        $this->assertSame($snapshotA, $invoiceA->pricing_snapshot);

        // A new user's invoice uses pricing B.
        $b = $this->parent('SD');
        $invoiceB = $this->makeInvoice($b);
        $this->assertSame(1050000, $invoiceB->total); // 2*500000 + 50000 - 0
        $this->assertNotSame($invoiceA->id, $invoiceB->id);
    }

    // PHASE E — agreement consistency between parent and admin views.
    public function test_agreement_snapshot_consistent_between_parent_and_admin(): void
    {
        $this->setDefaultPricing('SD', $this->items(), 10000);
        $parent = $this->parent('SD');
        $invoice = $this->makeInvoice($parent);
        Sanctum::actingAs($parent);
        $this->postJson("/api/daftar-ulang/invoices/{$invoice->id}/agreement", ['agree' => true])->assertOk();
        $parentView = $this->getJson("/api/daftar-ulang/invoices/{$invoice->id}")->assertOk()->json();

        Sanctum::actingAs($this->admin);
        $adminView = $this->getJson("/api/admin/daftar-ulang/students/{$parent->id}")->assertOk()->json();

        $this->assertSame($parentView['pricing_snapshot']['agreement']['version'], $adminView['daftar_ulang']['agreement_version']);
        $this->assertSame($parentView['pricing_snapshot']['agreement']['sha256'], $adminView['daftar_ulang']['agreement_sha256']);
        $this->assertTrue($adminView['daftar_ulang']['agreement_accepted']);
    }

    // PHASE F — bank account consistency: SD -> rekening_sd, SMP -> rekening_smp.
    public function test_bank_account_derived_from_jenjang(): void
    {
        $this->setDefaultPricing('SD', $this->items(), 10000);
        $this->setDefaultPricing('SMP', $this->items(200000), 20000);

        Sanctum::actingAs($this->parent('SD'));
        $this->assertSame('11111111', $this->getJson('/api/daftar-ulang/pricing')->json('account.rekening'));

        Sanctum::actingAs($this->parent('SMP'));
        $this->assertSame('22222222', $this->getJson('/api/daftar-ulang/pricing')->json('account.rekening'));

        // Client cannot spoof the account/jenjang via extra fields.
        Sanctum::actingAs($this->parent('SD'));
        $this->postJson('/api/daftar-ulang/invoices', ['rekening' => '999', 'jenjang' => 'SMP'])->assertUnprocessable();
    }

    // PHASE G — IDOR: a parent cannot reach another parent's DU resources.
    public function test_parent_cannot_access_another_parents_resources(): void
    {
        $this->setDefaultPricing('SD', $this->items(), 10000);
        $a = $this->parent('SD');
        $invoiceA = $this->makeInvoice($a);
        Sanctum::actingAs($a);
        $this->uploadProof();

        $b = $this->parent('SD');
        Sanctum::actingAs($b);
        // B reading A's invoice -> 404 (scoped to auth user).
        $this->getJson("/api/daftar-ulang/invoices/{$invoiceA->id}")->assertNotFound();
        // B accepting A's agreement -> 404 (scoped to auth user).
        $this->postJson("/api/daftar-ulang/invoices/{$invoiceA->id}/agreement", ['agree' => true])->assertNotFound();
        // B has no DU payment of their own -> proof upload 404 (cannot touch A's).
        $this->postJson('/api/daftar-ulang/payments/proof', ['bukti_bayar' => UploadedFile::fake()->image('x.jpg')])->assertNotFound();
    }

    // PHASE H — category isolation: PPDB payment is never reachable via DU endpoints.
    public function test_category_isolation_ppdb_vs_daftar_ulang(): void
    {
        $this->setDefaultPricing('SD', $this->items(), 10000);
        $parent = $this->parent('SD');
        $invoice = $this->makeInvoice($parent);
        $ppdbPayment = Payment::create(['user_id' => $parent->id, 'payment_category' => Payment::CATEGORY_PPDB,
            'invoice_number' => 'PPDB-'.uniqid(), 'jenjang' => 'SD', 'amount' => 175000,
            'payment_method' => 'MANUAL', 'status' => 'PENDING_MANUAL', 'proof_of_payment' => 'bukti_bayar/x.png']);

        Sanctum::actingAs($this->admin);
        // DU evidence endpoint with a PPDB payment id -> 404, never the bytes.
        $this->getJson("/api/admin/daftar-ulang/payments/{$ppdbPayment->id}/evidence")->assertNotFound();
        // DU verify/reject with a PPDB id -> 404, no cross-category mutation.
        $this->postJson("/api/admin/daftar-ulang/payments/{$ppdbPayment->id}/verify")->assertNotFound();
        $this->postJson("/api/admin/daftar-ulang/payments/{$ppdbPayment->id}/reject", ['reason' => 'x'])->assertNotFound();
        $this->assertSame('PENDING_MANUAL', $ppdbPayment->refresh()->status);

        // DU admin inbox contains only the DU payment, never PPDB.
        $inbox = $this->getJson('/api/admin/daftar-ulang/payments/pending')->assertOk()->json();
        $this->assertNotContains($ppdbPayment->id, collect($inbox)->pluck('id'));
        foreach ($inbox as $row) { $this->assertSame('DAFTAR_ULANG', Payment::find($row['id'])->payment_category); }
        $this->assertSame(Payment::CATEGORY_DAFTAR_ULANG, $invoice->payment->payment_category);
    }

    // PHASE G(admin) — non-admin roles cannot use admin DU endpoints or verify/reject.
    public function test_admin_endpoints_require_admin_role(): void
    {
        $this->setDefaultPricing('SD', $this->items(), 10000);
        $parent = $this->parent('SD');
        $invoice = $this->makeInvoice($parent);
        Sanctum::actingAs($parent);
        $this->uploadProof();

        foreach (['orang_tua', 'guru'] as $role) {
            Sanctum::actingAs($this->actor($role));
            $this->getJson('/api/admin/daftar-ulang/students')->assertForbidden();
            $this->getJson("/api/admin/daftar-ulang/students/{$parent->id}")->assertForbidden();
            $this->getJson('/api/admin/daftar-ulang/payments/pending')->assertForbidden();
            $this->postJson("/api/admin/daftar-ulang/payments/{$invoice->payment_id}/verify")->assertForbidden();
            $this->postJson("/api/admin/daftar-ulang/payments/{$invoice->payment_id}/reject", ['reason' => 'x'])->assertForbidden();
        }
    }

    // PHASE I — seat/quota invariants across the whole DU lifecycle.
    public function test_seat_quota_never_mutated_by_daftar_ulang(): void
    {
        $this->setDefaultPricing('SD', $this->items(), 10000);
        $parent = $this->parent('SD');
        $before = $this->seatsSnapshot();

        Sanctum::actingAs($parent);
        $this->getJson('/api/daftar-ulang/pricing')->assertOk();
        $this->assertSame($before, $this->seatsSnapshot());

        $invoice = $this->makeInvoice($parent);
        $this->assertSame($before, $this->seatsSnapshot());

        $this->postJson("/api/daftar-ulang/invoices/{$invoice->id}/agreement", ['agree' => true])->assertOk();
        $this->assertSame($before, $this->seatsSnapshot());

        $this->uploadProof();
        $this->assertSame($before, $this->seatsSnapshot());

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/daftar-ulang/payments/{$invoice->payment_id}/reject", ['reason' => 'r'])->assertOk();
        $this->assertSame($before, $this->seatsSnapshot());

        Sanctum::actingAs($parent);
        $this->uploadProof();
        $this->assertSame($before, $this->seatsSnapshot());

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/daftar-ulang/payments/{$invoice->payment_id}/verify")->assertOk();
        $this->assertSame($before, $this->seatsSnapshot());
    }

    // PHASE J — idempotency/replay guards on the settlement path.
    public function test_settlement_replay_is_safely_guarded(): void
    {
        $this->setDefaultPricing('SD', $this->items(), 10000);
        $parent = $this->parent('SD');
        $invoice = $this->makeInvoice($parent);

        Sanctum::actingAs($parent);
        $this->uploadProof();
        // PENDING_MANUAL replay upload -> 409 (already recorded).
        $this->postJson('/api/daftar-ulang/payments/proof', ['bukti_bayar' => UploadedFile::fake()->image('y.jpg')])->assertStatus(409);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/daftar-ulang/payments/{$invoice->payment_id}/verify")->assertOk();
        // Verify replay -> 409 (already SUCCESS).
        $this->postJson("/api/admin/daftar-ulang/payments/{$invoice->payment_id}/verify")->assertStatus(409);
        // Reject after SUCCESS -> 409.
        $this->postJson("/api/admin/daftar-ulang/payments/{$invoice->payment_id}/reject", ['reason' => 'r'])->assertStatus(409);

        // SUCCESS replay upload by parent -> 409.
        Sanctum::actingAs($parent);
        $this->postJson('/api/daftar-ulang/payments/proof', ['bukti_bayar' => UploadedFile::fake()->image('z.jpg')])->assertStatus(409);

        // No duplicate canonical invoice/payment/payment-row created at any point.
        $this->assertSame(1, DaftarUlangInvoice::where('user_id', $parent->id)->count());
        $this->assertSame(1, Payment::daftarUlang()->where('user_id', $parent->id)->count());
    }

    // PHASE 1 — eligibility: only LULUS may proceed (server-authoritative).
    public function test_non_lulus_parent_is_denied(): void
    {
        $this->setDefaultPricing('SD', $this->items(), 10000);
        foreach (['BELUM', 'TIDAK_LULUS'] as $status) {
            Sanctum::actingAs($this->parent('SD', $status));
            $this->getJson('/api/daftar-ulang/pricing')->assertUnprocessable();
            $this->postJson('/api/daftar-ulang/invoices')->assertUnprocessable();
        }
    }

    // Unauthenticated access to the parent DU endpoints is rejected (401).
    public function test_guest_parent_endpoints_are_unauthenticated(): void
    {
        $this->getJson('/api/daftar-ulang/pricing')->assertUnauthorized();
        $this->postJson('/api/daftar-ulang/invoices')->assertUnauthorized();
        $this->postJson('/api/daftar-ulang/payments/proof', [])->assertUnauthorized();
    }
}
