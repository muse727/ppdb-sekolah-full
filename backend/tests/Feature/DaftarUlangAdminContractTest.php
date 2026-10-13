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
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B.6A-C — read-only admin Daftar Ulang participant contract.
 *
 * The participant definition is grounded in du_invoices (an invoice is the
 * canonical entry into the Daftar Ulang flow), NOT in status_kelulusan alone.
 * The linked payment is always re-scoped to DAFTAR_ULANG. All invoice values
 * come from the immutable pricing_snapshot. These endpoints are read-only.
 */
class DaftarUlangAdminContractTest extends TestCase
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
        config(['daftar_ulang.agreement' => ['version' => 'DU-TEST-1', 'text' => 'Reviewed contract test agreement.']]);
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

    private function adminList(array $query = [])
    {
        Sanctum::actingAs($this->actor('admin'));
        return $this->getJson('/api/admin/daftar-ulang/students'.($query ? '?'.http_build_query($query) : ''));
    }

    // 1 & 2: admin can list participants; only students with actual DU records appear.
    public function test_admin_lists_only_students_with_du_invoice(): void
    {
        $withInvoice = $this->parent();
        $invoice = $this->invoice($withInvoice);
        $this->parent(); // LULUS but NO invoice
        $this->parent();

        $response = $this->adminList()->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame([$withInvoice->id], $ids->all());
        $this->assertSame(1, $response->json('meta.total'));
    }

    // 3: LULUS without a DU invoice does not appear.
    public function test_lulus_without_invoice_is_not_a_participant(): void
    {
        $this->parent(); // LULUS, no invoice
        $response = $this->adminList()->assertOk();
        $this->assertCount(0, $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
    }

    // 4: PPDB payment is not returned as the DU payment.
    public function test_ppdb_payment_is_not_exposed_as_du_payment(): void
    {
        $user = $this->parent();
        $this->invoice($user);
        Payment::create(['user_id' => $user->id, 'payment_category' => Payment::CATEGORY_PPDB,
            'invoice_number' => 'PPDB-'.uniqid(), 'jenjang' => 'SD', 'amount' => 175000,
            'payment_method' => 'MANUAL', 'status' => 'SUCCESS']);

        $row = collect($this->adminList()->assertOk()->json('data'))->firstWhere('id', $user->id);
        $this->assertSame('DAFTAR_ULANG', $row['payment']['payment_category']);
        $this->assertNotSame(175000, $row['payment']['amount']);
    }

    // 5 & 6: detail resolves by users.id and contains the immutable invoice snapshot.
    public function test_detail_resolves_by_user_id_and_contains_snapshot(): void
    {
        $user = $this->parent();
        $invoice = $this->invoice($user);

        Sanctum::actingAs($this->actor('admin'));
        $detail = $this->getJson("/api/admin/daftar-ulang/students/{$user->id}")->assertOk()->json();

        $this->assertSame($user->id, $detail['id']);
        $this->assertSame($invoice->invoice_number, $detail['invoice']['invoice_number']);
        $this->assertSame($invoice->total, $detail['invoice']['total']);
        $this->assertSame($invoice->subtotal, $detail['invoice']['subtotal']);
        $this->assertSame($invoice->pricing_snapshot, $detail['invoice']['pricing_snapshot']);
        $this->assertSame($invoice->pricing_snapshot['items'], $detail['invoice']['items']);
        $this->assertSame($invoice->pricing_snapshot['source'], $detail['invoice']['pricing_source']);
    }

    // 7: current pricing changes do not alter historical detail.
    public function test_pricing_change_does_not_alter_historical_invoice_detail(): void
    {
        $user = $this->parent();
        $invoice = $this->invoice($user);
        $originalTotal = $invoice->total;

        // Change the default pricing AFTER the invoice was created.
        $this->pricing->updateDefault($this->super->id, 'SD', $this->items(999000), 5000);

        Sanctum::actingAs($this->actor('admin'));
        $detail = $this->getJson("/api/admin/daftar-ulang/students/{$user->id}")->assertOk()->json();
        $this->assertSame($originalTotal, $detail['invoice']['total']);
        $this->assertSame($invoice->pricing_snapshot, $detail['invoice']['pricing_snapshot']);
    }

    // 8: agreement data returned correctly.
    public function test_detail_returns_agreement_state(): void
    {
        $user = $this->parent();
        $invoice = $this->invoice($user);
        $snapshot = $invoice->pricing_snapshot;
        DaftarUlangAgreementAcceptance::create(['user_id' => $user->id, 'invoice_id' => $invoice->id,
            'agreement_version' => $snapshot['agreement']['version'],
            'agreement_sha256' => $snapshot['agreement']['sha256'], 'agreed_at' => now()]);

        Sanctum::actingAs($this->actor('admin'));
        $detail = $this->getJson("/api/admin/daftar-ulang/students/{$user->id}")->assertOk()->json();
        $this->assertTrue($detail['daftar_ulang']['agreement_accepted']);
        $this->assertSame('DU-TEST-1', $detail['daftar_ulang']['agreement_version']);
        $this->assertNotNull($detail['daftar_ulang']['accepted_at']);
    }

    // 9: payment data returned correctly (status, verifier, rejection, evidence flag).
    public function test_detail_returns_payment_with_review_metadata(): void
    {
        $user = $this->parent();
        $invoice = $this->invoice($user);
        $payment = $invoice->payment;
        $admin = $this->actor('admin');
        $payment->update(['status' => 'REJECTED', 'proof_of_payment' => 'bukti_bayar/du.png',
            'verifier_id' => $admin->id, 'verified_at' => now(), 'rejection_reason' => 'buram']);

        Sanctum::actingAs($this->actor('admin'));
        $detail = $this->getJson("/api/admin/daftar-ulang/students/{$user->id}")->assertOk()->json();
        $this->assertSame('REJECTED', $detail['payment']['status']);
        $this->assertSame('buram', $detail['payment']['rejection_reason']);
        $this->assertSame($admin->id, $detail['payment']['verifier']['id']);
        $this->assertTrue($detail['payment']['has_proof']);
        $this->assertArrayNotHasKey('proof_of_payment', $detail['payment']); // no storage path leaked
    }

    // 10 & 11: RBAC — parent and guru are denied; unauthenticated 401.
    public function test_non_admin_roles_and_guests_are_denied(): void
    {
        $owner = $this->parent();
        $this->invoice($owner);

        Sanctum::actingAs($this->actor('orang_tua'));
        $this->getJson('/api/admin/daftar-ulang/students')->assertForbidden();
        $this->getJson("/api/admin/daftar-ulang/students/{$owner->id}")->assertForbidden();

        Sanctum::actingAs($this->actor('guru'));
        $this->getJson('/api/admin/daftar-ulang/students')->assertForbidden();
        $this->getJson("/api/admin/daftar-ulang/students/{$owner->id}")->assertForbidden();
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->getJson('/api/admin/daftar-ulang/students')->assertUnauthorized();
    }

    // 12: a parent cannot IDOR another student's detail (even with a valid id).
    public function test_parent_cannot_idor_another_students_detail(): void
    {
        $victim = $this->parent();
        $this->invoice($victim);
        Sanctum::actingAs($this->parent());
        $this->getJson("/api/admin/daftar-ulang/students/{$victim->id}")->assertForbidden();
    }

    // 13: unknown user id returns normal 404.
    public function test_unknown_user_id_returns_404(): void
    {
        Sanctum::actingAs($this->actor('admin'));
        $this->getJson('/api/admin/daftar-ulang/students/999999')->assertNotFound();
    }

    // 14: invalid filters rejected per existing API convention (422).
    public function test_invalid_filters_are_rejected(): void
    {
        $this->adminList(['jenjang' => 'BOGUS'])->assertUnprocessable();
        $this->adminList(['status_pembayaran' => 'BOGUS'])->assertUnprocessable();
        $this->adminList(['jalur_pendaftaran' => 'BOGUS'])->assertUnprocessable();
        $this->adminList(['per_page' => 999])->assertUnprocessable();
        $this->adminList(['unknown_field' => 'x'])->assertUnprocessable(); // allowlist
    }

    // pagination: page/per_page honoured server-side.
    public function test_pagination_is_server_side(): void
    {
        foreach (range(1, 3) as $i) {
            $this->invoice($this->parent());
        }
        $page1 = $this->adminList(['per_page' => 2, 'page' => 1])->assertOk();
        $this->assertCount(2, $page1->json('data'));
        $this->assertSame(2, $page1->json('meta.last_page'));
        $this->assertSame(3, $page1->json('meta.total'));
        $page2 = $this->adminList(['per_page' => 2, 'page' => 2])->assertOk();
        $this->assertCount(1, $page2->json('data'));
    }

    // filtering by payment status / jenjang / search works against real data.
    public function test_filters_narrow_participants(): void
    {
        $paid = $this->parent();
        $this->invoice($paid)->payment->update(['status' => 'SUCCESS']);
        $pending = $this->parent();
        $this->invoice($pending); // stays PENDING

        $success = $this->adminList(['status_pembayaran' => 'SUCCESS'])->assertOk();
        $this->assertSame([$paid->id], collect($success->json('data'))->pluck('id')->all());

        $sd = $this->adminList(['jenjang' => 'SD'])->assertOk();
        $this->assertCount(2, $sd->json('data'));

        $byName = $this->adminList(['search' => $paid->name])->assertOk();
        $this->assertSame([$paid->id], collect($byName->json('data'))->pluck('id')->all());
    }

    // 15: read-only — no seat/quota/user/invoice/payment mutation.
    public function test_endpoints_are_read_only(): void
    {
        $user = $this->parent();
        $invoice = $this->invoice($user);
        $before = [
            'seats' => DB::table('ppdb_seat_allocations')->orderBy('id')->get()->toJson(),
            'capacity' => DB::table('ppdb_capacities')->orderBy('jenjang')->get()->toJson(),
            'invoices' => DB::table('du_invoices')->orderBy('id')->get()->toJson(),
            'payments' => DB::table('payments')->orderBy('id')->get()->toJson(),
            'agreements' => DB::table('du_agreement_acceptances')->orderBy('id')->get()->toJson(),
        ];

        $this->adminList()->assertOk();
        Sanctum::actingAs($this->actor('admin'));
        $this->getJson("/api/admin/daftar-ulang/students/{$user->id}")->assertOk();

        $this->assertSame($before['seats'], DB::table('ppdb_seat_allocations')->orderBy('id')->get()->toJson());
        $this->assertSame($before['capacity'], DB::table('ppdb_capacities')->orderBy('jenjang')->get()->toJson());
        $this->assertSame($before['invoices'], DB::table('du_invoices')->orderBy('id')->get()->toJson());
        $this->assertSame($before['payments'], DB::table('payments')->orderBy('id')->get()->toJson());
        $this->assertSame($before['agreements'], DB::table('du_agreement_acceptances')->orderBy('id')->get()->toJson());
    }
}
