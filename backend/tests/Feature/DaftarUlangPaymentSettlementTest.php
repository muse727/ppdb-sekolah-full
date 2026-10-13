<?php

namespace Tests\Feature;

use App\Models\Biodata;
use App\Models\DaftarUlangInvoice;
use App\Models\Payment;
use App\Models\PpdbSeatAllocation;
use App\Models\Setting;
use App\Models\User;
use App\Services\DaftarUlangPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B.6A — Daftar Ulang agreement config + payment settlement.
 *
 * Covers env-backed agreement config (fail-closed when missing/blank), DU proof
 * upload (parent/own payment/server-owned category), DU admin pending/evidence/
 * verify/reject, PPDB<->DU category isolation, IDOR/RBAC, upload security, and
 * the seat+quota financial-only guarantee.
 */
class DaftarUlangPaymentSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $super;
    private DaftarUlangPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('ppdb_sekolah_test', DB::selectOne('SELECT DATABASE() db')->db);
        $this->super = $this->actor('super_admin');
        $this->pricing = app(DaftarUlangPricingService::class);
        config(['daftar_ulang.agreement' => ['version' => 'DU-TEST-1', 'text' => 'Reviewed settlement test agreement.']]);
        $this->pricing->updateDefault($this->super->id, 'SD', $this->items(), 10000);
        Setting::updateOrCreate(['key' => 'rekening_sd'], ['value' => '11111111']);
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
            'status' => $status === 'TIDAK_LULUS' ? 'RELEASED' : 'ALLOCATED', 'allocated_at' => now()]);
        return $user->refresh();
    }

    private function items(int $amount = 100000): array
    {
        return [['id' => 'tuition', 'name' => 'Tuition', 'quantity' => 2, 'unit_amount' => $amount],
            ['id' => 'kit', 'name' => 'Student kit', 'quantity' => 1, 'unit_amount' => 50000]];
    }

    private function invoice(User $user): DaftarUlangInvoice
    {
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/daftar-ulang/invoices')->assertOk()->json('id');
        return DaftarUlangInvoice::findOrFail($id);
    }

    private function duPayment(User $user, string $status): Payment
    {
        $payment = $user->payments()->daftarUlang()->latest('id')->firstOrFail();
        $payment->update(['status' => $status, 'proof_of_payment' => 'bukti_bayar/du-proof.png']);
        return $payment->refresh();
    }

    private function ppdbPayment(User $user, string $status = 'PENDING_MANUAL'): Payment
    {
        return Payment::create([
            'user_id' => $user->id, 'payment_category' => Payment::CATEGORY_PPDB,
            'invoice_number' => 'PPDB-'.uniqid(), 'jenjang' => 'SD', 'amount' => 175000,
            'payment_method' => 'MANUAL', 'status' => $status,
        ]);
    }

    private function upload(array $payload = [], ?UploadedFile $file = null): \Illuminate\Testing\TestResponse
    {
        if ($file) {
            $payload['bukti_bayar'] = $file;
        }
        return $this->post('/api/daftar-ulang/payments/proof', $payload, ['Accept' => 'application/json']);
    }

    private function seatState(): string
    {
        return DB::table('ppdb_seat_allocations')->orderBy('id')->get()->toJson()
            .'|'.DB::table('ppdb_capacities')->orderBy('jenjang')->get()->toJson();
    }

    public function test_configured_agreement_allows_invoice_creation(): void
    {
        $user = $this->parent();
        Sanctum::actingAs($user);
        $response = $this->postJson('/api/daftar-ulang/invoices')->assertOk();
        $snapshot = DaftarUlangInvoice::findOrFail($response->json('id'))->pricing_snapshot;
        $this->assertSame('DU-TEST-1', $snapshot['agreement']['version']);
        $this->assertSame(hash('sha256', 'Reviewed settlement test agreement.'), $snapshot['agreement']['sha256']);
    }

    public function test_missing_agreement_version_fails_closed(): void
    {
        config(['daftar_ulang.agreement' => ['version' => null, 'text' => 'Reviewed settlement test agreement.']]);
        Sanctum::actingAs($this->parent());
        $this->postJson('/api/daftar-ulang/invoices')->assertUnprocessable()->assertJsonValidationErrors('agreement');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_missing_agreement_text_fails_closed(): void
    {
        config(['daftar_ulang.agreement' => ['version' => 'DU-TEST-1', 'text' => null]]);
        Sanctum::actingAs($this->parent());
        $this->postJson('/api/daftar-ulang/invoices')->assertUnprocessable()->assertJsonValidationErrors('agreement');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_blank_agreement_version_fails_closed(): void
    {
        config(['daftar_ulang.agreement' => ['version' => "   \n\t", 'text' => 'Reviewed settlement test agreement.']]);
        Sanctum::actingAs($this->parent());
        $this->postJson('/api/daftar-ulang/invoices')->assertUnprocessable()->assertJsonValidationErrors('agreement');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_blank_agreement_text_fails_closed(): void
    {
        config(['daftar_ulang.agreement' => ['version' => 'DU-TEST-1', 'text' => "  \n "]]);
        Sanctum::actingAs($this->parent());
        $this->postJson('/api/daftar-ulang/invoices')->assertUnprocessable()->assertJsonValidationErrors('agreement');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_owner_can_upload_du_proof_and_status_becomes_pending_manual(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        $invoice = $this->invoice($user);
        $seats = $this->seatState();
        Sanctum::actingAs($user);

        $this->upload([], UploadedFile::fake()->image('bukti.png'))->assertOk()
            ->assertJsonPath('success', true);

        $payment = $user->payments()->daftarUlang()->sole();
        $this->assertSame('PENDING_MANUAL', $payment->status);
        $this->assertNotNull($payment->proof_of_payment);
        $this->assertSame($invoice->payment_id, $payment->id);
        Storage::disk('private')->assertExists($payment->proof_of_payment);
        $this->assertSame($seats, $this->seatState());
    }

    public function test_upload_rejects_any_non_payload_field_as_spoof(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        $this->invoice($user);
        Sanctum::actingAs($user);

        foreach (['user_id' => 999, 'payment_category' => 'PPDB', 'jenjang' => 'SMP',
            'invoice_id' => 999, 'amount' => 1, 'subtotal' => 1, 'discount_nominal' => 1,
            'total' => 1, 'status' => 'SUCCESS', 'rekening' => '999', 'payment_id' => 999] as $field => $value) {
            $this->upload([$field => $value], UploadedFile::fake()->image('b.png'))->assertUnprocessable();
        }
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('PENDING', $user->payments()->daftarUlang()->sole()->status);
    }

    public function test_upload_requires_existing_invoice_and_payment(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        Sanctum::actingAs($user);
        $this->upload([], UploadedFile::fake()->image('b.png'))->assertNotFound();
    }

    public function test_duplicate_upload_is_blocked_once_pending_manual(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        $this->invoice($user);
        Sanctum::actingAs($user);

        $this->upload([], UploadedFile::fake()->image('a.png'))->assertOk();
        $this->upload([], UploadedFile::fake()->image('b.png'))->assertStatus(409);
        $this->assertSame(1, Payment::daftarUlang()->where('user_id', $user->id)->count());
    }

    public function test_rejected_payment_can_be_resubmitted_and_clears_review_trail(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        $this->invoice($user);
        Sanctum::actingAs($user);
        $payment = $this->duPayment($user, 'REJECTED');
        $payment->update(['verifier_id' => $this->super->id, 'verified_at' => now(), 'rejection_reason' => 'blur']);

        $this->upload([], UploadedFile::fake()->image('fixed.png'))->assertOk();
        $payment->refresh();
        $this->assertSame('PENDING_MANUAL', $payment->status);
        $this->assertNull($payment->verifier_id);
        $this->assertNull($payment->verified_at);
        $this->assertNull($payment->rejection_reason);
    }

    public function test_success_payment_cannot_be_overwritten_by_upload(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        $this->invoice($user);
        Sanctum::actingAs($user);
        $this->duPayment($user, 'SUCCESS');

        $this->upload([], UploadedFile::fake()->image('b.png'))->assertStatus(409);
    }

    public function test_parent_cannot_upload_for_another_parents_du_payment(): void
    {
        Storage::fake('private');
        $parentA = $this->parent();
        $parentB = $this->parent();
        $invoiceB = $this->invoice($parentB);

        Sanctum::actingAs($parentA);
        $this->upload([], UploadedFile::fake()->image('a.png'))->assertNotFound();
        $this->assertSame('PENDING', $invoiceB->payment->fresh()->status);
        $this->assertNull($invoiceB->payment->fresh()->proof_of_payment);

        $invoiceA = $this->invoice($parentA);
        Sanctum::actingAs($parentA);
        $this->upload([], UploadedFile::fake()->image('a.png'))->assertOk();
        $this->assertSame('PENDING', $invoiceB->payment->fresh()->status);
        $this->assertSame('PENDING_MANUAL', $invoiceA->payment->fresh()->status);
    }

    public function test_parent_cannot_verify_reject_list_or_read_admin_endpoints(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        $this->invoice($user);
        $payment = $this->duPayment($user, 'PENDING_MANUAL');
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/daftar-ulang/payments/pending')->assertForbidden();
        $this->getJson("/api/admin/daftar-ulang/payments/{$payment->id}/evidence")->assertForbidden();
        $this->postJson("/api/admin/daftar-ulang/payments/{$payment->id}/verify")->assertForbidden();
        $this->postJson("/api/admin/daftar-ulang/payments/{$payment->id}/reject", ['reason' => 'x'])->assertForbidden();
    }

    public function test_non_admin_roles_are_blocked_from_admin_settlement(): void
    {
        Storage::fake('private');
        $owner = $this->parent();
        $this->invoice($owner);
        $payment = $this->duPayment($owner, 'PENDING_MANUAL');

        foreach (['guru', 'orang_tua'] as $role) {
            Sanctum::actingAs($this->actor($role));
            $this->getJson('/api/admin/daftar-ulang/payments/pending')->assertForbidden();
            $this->postJson("/api/admin/daftar-ulang/payments/{$payment->id}/verify")->assertForbidden();
            $this->postJson("/api/admin/daftar-ulang/payments/{$payment->id}/reject", ['reason' => 'x'])->assertForbidden();
        }
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->post('/api/daftar-ulang/payments/proof', [], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->getJson('/api/admin/daftar-ulang/payments/pending')->assertUnauthorized();
        $this->postJson('/api/admin/daftar-ulang/payments/1/verify')->assertUnauthorized();
        $this->postJson('/api/admin/daftar-ulang/payments/1/reject', ['reason' => 'x'])->assertUnauthorized();
    }

    public function test_ppdb_payment_sent_to_du_upload_endpoint_is_rejected(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        $ppdb = $this->ppdbPayment($user);
        Sanctum::actingAs($user);
        $this->upload([], UploadedFile::fake()->image('b.png'))->assertNotFound();
        $this->assertSame('PENDING_MANUAL', $ppdb->fresh()->status);
    }

    public function test_du_payment_sent_to_ppdb_upload_endpoint_is_untouched(): void
    {
        Storage::fake('private');
        Setting::updateOrCreate(['key' => 'biaya_sd'], ['value' => '175000']);
        $user = $this->parent();
        $invoice = $this->invoice($user);
        $du = $invoice->payment;
        $duBefore = $du->fresh()->getAttributes();
        Sanctum::actingAs($user);

        $this->post('/api/payment/manual', [
            'jenjang' => 'SD',
            'bukti_bayar' => UploadedFile::fake()->image('b.png'),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame($duBefore, $du->fresh()->getAttributes());
        $this->assertSame(1, Payment::ppdb()->where('user_id', $user->id)->count());
    }

    public function test_du_pending_endpoint_returns_only_daftar_ulang_payments(): void
    {
        $duOwner = $this->parent();
        $this->invoice($duOwner);
        $duPayment = $this->duPayment($duOwner, 'PENDING_MANUAL');
        $ppdbOwner = $this->parent();
        $ppdb = $this->ppdbPayment($ppdbOwner, 'PENDING_MANUAL');

        Sanctum::actingAs($this->actor('admin'));
        $ids = collect($this->getJson('/api/admin/daftar-ulang/payments/pending')->assertOk()->json())->pluck('id');

        $this->assertTrue($ids->contains($duPayment->id));
        $this->assertFalse($ids->contains($ppdb->id));
    }

    public function test_du_evidence_is_admin_accessible_and_private(): void
    {
        $owner = $this->parent();
        $this->invoice($owner);
        $image = UploadedFile::fake()->image('du-proof.png');
        Storage::disk('private')->put('bukti_bayar/du-proof.png', file_get_contents($image->getPathname()));
        $payment = $this->duPayment($owner, 'PENDING_MANUAL');

        Sanctum::actingAs($this->actor('admin'));
        $response = $this->getJson("/api/admin/daftar-ulang/payments/{$payment->id}/evidence")->assertOk();
        $this->assertSame('image/png', $response->headers->get('content-type'));
    }

    public function test_ppdb_payment_through_du_evidence_endpoint_is_rejected_and_vice_versa(): void
    {
        $owner = $this->parent();
        $this->invoice($owner);
        $image = UploadedFile::fake()->image('proof.png');
        Storage::disk('private')->put('bukti_bayar/proof.png', file_get_contents($image->getPathname()));
        $du = $this->duPayment($owner, 'PENDING_MANUAL');
        $ppdb = $this->ppdbPayment($owner, 'PENDING_MANUAL');
        $ppdb->update(['proof_of_payment' => 'bukti_bayar/proof.png']);

        Sanctum::actingAs($this->actor('admin'));
        // DU endpoint rejects the PPDB id; PPDB endpoint rejects the DU id.
        $this->getJson("/api/admin/daftar-ulang/payments/{$ppdb->id}/evidence")->assertNotFound();
        $this->getJson("/api/payments/{$du->id}/evidence")->assertNotFound();
        // Each serves its own category.
        $this->getJson("/api/admin/daftar-ulang/payments/{$du->id}/evidence")->assertOk();
        $this->getJson("/api/payments/{$ppdb->id}/evidence")->assertOk();
    }

    public function test_admin_can_verify_du_payment_and_seats_unchanged(): void
    {
        $owner = $this->parent();
        $this->invoice($owner);
        $payment = $this->duPayment($owner, 'PENDING_MANUAL');
        $seats = $this->seatState();

        Sanctum::actingAs($admin = $this->actor('admin'));
        $this->postJson("/api/admin/daftar-ulang/payments/{$payment->id}/verify")->assertOk()
            ->assertJsonPath('success', true);

        $payment->refresh();
        $this->assertSame('SUCCESS', $payment->status);
        $this->assertSame($admin->id, $payment->verifier_id);
        $this->assertNotNull($payment->verified_at);
        $this->assertSame($seats, $this->seatState());
    }

    public function test_ppdb_payment_through_du_verify_is_rejected(): void
    {
        $owner = $this->parent();
        $ppdb = $this->ppdbPayment($owner, 'PENDING_MANUAL');
        Sanctum::actingAs($this->actor('admin'));
        $this->postJson("/api/admin/daftar-ulang/payments/{$ppdb->id}/verify")->assertNotFound();
        $this->assertSame('PENDING_MANUAL', $ppdb->fresh()->status);
    }

    public function test_verify_is_race_safe_and_idempotent_via_state_check(): void
    {
        $owner = $this->parent();
        $this->invoice($owner);
        $payment = $this->duPayment($owner, 'PENDING_MANUAL');
        Sanctum::actingAs($this->actor('admin'));

        $this->postJson("/api/admin/daftar-ulang/payments/{$payment->id}/verify")->assertOk();
        // Second verify sees SUCCESS -> 409, never double-writes.
        $this->postJson("/api/admin/daftar-ulang/payments/{$payment->id}/verify")->assertStatus(409);
        $this->assertSame('SUCCESS', $payment->fresh()->status);
    }

    public function test_admin_can_reject_du_payment_with_reason_and_seats_unchanged(): void
    {
        $owner = $this->parent();
        $this->invoice($owner);
        $payment = $this->duPayment($owner, 'PENDING_MANUAL');
        $seats = $this->seatState();

        Sanctum::actingAs($admin = $this->actor('admin'));
        $this->postJson("/api/admin/daftar-ulang/payments/{$payment->id}/reject", ['reason' => 'bukti buram'])
            ->assertOk()->assertJsonPath('success', true);

        $payment->refresh();
        $this->assertSame('REJECTED', $payment->status);
        $this->assertSame('bukti buram', $payment->rejection_reason);
        $this->assertSame($admin->id, $payment->verifier_id);
        $this->assertNotNull($payment->verified_at);
        $this->assertSame($seats, $this->seatState());
    }

    public function test_ppdb_payment_through_du_reject_is_rejected(): void
    {
        $owner = $this->parent();
        $ppdb = $this->ppdbPayment($owner, 'PENDING_MANUAL');
        Sanctum::actingAs($this->actor('admin'));
        $this->postJson("/api/admin/daftar-ulang/payments/{$ppdb->id}/reject", ['reason' => 'x'])->assertNotFound();
        $this->assertSame('PENDING_MANUAL', $ppdb->fresh()->status);
    }

    public function test_verify_and_reject_compete_to_a_single_terminal_state(): void
    {
        $owner = $this->parent();
        $this->invoice($owner);
        $payment = $this->duPayment($owner, 'PENDING_MANUAL');
        Sanctum::actingAs($this->actor('admin'));

        $this->postJson("/api/admin/daftar-ulang/payments/{$payment->id}/verify")->assertOk();
        // Reject after verify sees SUCCESS (not PENDING_MANUAL) -> 409.
        $this->postJson("/api/admin/daftar-ulang/payments/{$payment->id}/reject", ['reason' => 'x'])->assertStatus(409);
        $this->assertSame('SUCCESS', $payment->fresh()->status);
    }

    public function test_du_settlement_makes_zero_seat_or_capacity_calls(): void
    {
        $owner = $this->parent();
        $this->invoice($owner);
        $payment = $this->duPayment($owner, 'PENDING_MANUAL');
        Sanctum::actingAs($this->actor('admin'));

        DB::enableQueryLog();
        try {
            $this->getJson('/api/admin/daftar-ulang/payments/pending')->assertOk();
            $this->postJson("/api/admin/daftar-ulang/payments/{$payment->id}/verify")->assertOk();
            foreach (DB::getQueryLog() as $query) {
                $this->assertDoesNotMatchRegularExpression('/ppdb_(capacities|seat_allocations)/i', $query['query']);
            }
        } finally {
            DB::disableQueryLog();
        }
    }
    // ============================================ 5. UPLOAD SECURITY ========

    public function test_upload_rejects_non_image_mime(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        $this->invoice($user);
        Sanctum::actingAs($user);

        $this->upload([], UploadedFile::fake()->create('bukti.txt', 10, 'text/plain'))
            ->assertUnprocessable()->assertJsonValidationErrors('bukti_bayar');
        $this->assertSame('PENDING', $user->payments()->daftarUlang()->sole()->status);
    }

    public function test_upload_rejects_php_payload_renamed_to_png(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        $this->invoice($user);
        Sanctum::actingAs($user);

        $tmp = tempnam(sys_get_temp_dir(), 'probe');
        file_put_contents($tmp, "<?php echo 'pwned'; ?>");
        $this->upload([], new UploadedFile($tmp, 'bukti.png', 'image/png', null, true))
            ->assertUnprocessable()->assertJsonValidationErrors('bukti_bayar');
        $this->assertSame('PENDING', $user->payments()->daftarUlang()->sole()->status);
        @unlink($tmp);
    }

    public function test_upload_rejects_oversized_file(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        $this->invoice($user);
        Sanctum::actingAs($user);

        $this->upload([], UploadedFile::fake()->create('big.png', 3000, 'image/png'))
            ->assertUnprocessable()->assertJsonValidationErrors('bukti_bayar');
        $this->assertSame('PENDING', $user->payments()->daftarUlang()->sole()->status);
    }

    public function test_upload_rejects_missing_evidence_file(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        $this->invoice($user);
        Sanctum::actingAs($user);

        $this->upload([])->assertUnprocessable()->assertJsonValidationErrors('bukti_bayar');
        $this->assertSame('PENDING', $user->payments()->daftarUlang()->sole()->status);
    }

    public function test_du_evidence_stored_on_private_disk_not_public(): void
    {
        Storage::fake('private');
        Storage::fake('public');
        $user = $this->parent();
        $this->invoice($user);
        Sanctum::actingAs($user);

        $this->upload([], UploadedFile::fake()->image('b.png'))->assertOk();
        $path = $user->payments()->daftarUlang()->sole()->proof_of_payment;
        Storage::disk('private')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_evidence_returns_404_for_nonexistent_payment(): void
    {
        Sanctum::actingAs($this->actor('admin'));
        $this->getJson('/api/admin/daftar-ulang/payments/99999/evidence')->assertNotFound();
        $this->postJson('/api/admin/daftar-ulang/payments/99999/verify')->assertNotFound();
        $this->postJson('/api/admin/daftar-ulang/payments/99999/reject', ['reason' => 'x'])->assertNotFound();
    }

    public function test_evidence_404_when_no_proof_stored(): void
    {
        $owner = $this->parent();
        $this->invoice($owner);
        $payment = $owner->payments()->daftarUlang()->sole(); // PENDING, no proof
        Sanctum::actingAs($this->actor('admin'));
        $this->getJson("/api/admin/daftar-ulang/payments/{$payment->id}/evidence")->assertNotFound();
    }
}
