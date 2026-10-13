<?php

namespace Tests\Feature;

use App\Models\Biodata;
use App\Models\DaftarUlangAgreementAcceptance;
use App\Models\DaftarUlangInvoice;
use App\Models\DaftarUlangPricingRevision;
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

class DaftarUlangDomainTest extends TestCase
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
        config(['daftar_ulang.agreement' => ['version' => 'DU-1', 'text' => 'Reviewed test agreement.']]);
        $this->pricing->updateDefault($this->super->id, 'SD', $this->items(), 10000);
        $this->pricing->updateDefault($this->super->id, 'SMP', $this->items(200000), 20000);
        Setting::updateOrCreate(['key' => 'rekening_sd'], ['value' => '11111111']);
        Setting::updateOrCreate(['key' => 'rekening_smp'], ['value' => '22222222']);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['jenjang' => 'SD']);
        $user->forceFill(['role' => $role])->save();
        return $user;
    }

    private function parent(string $jenjang = 'SD', string $jalur = 'UMUM', string $status = 'LULUS'): User
    {
        $user = User::factory()->create(['jenjang' => $jenjang, 'jalur' => 'UMUM']);
        $user->forceFill(['role' => 'orang_tua', 'status_kelulusan' => $status])->save();
        $bio = array_fill_keys(['asal_sekolah', 'nik_siswa', 'no_kk', 'tempat_lahir', 'status_peserta_didik',
            'nama_ibu', 'nik_ibu', 'pendidikan_ibu', 'pekerjaan_ibu', 'penghasilan_ibu', 'nama_ayah', 'nik_ayah',
            'pendidikan_ayah', 'pekerjaan_ayah', 'penghasilan_ayah', 'alamat_lengkap', 'kota', 'kecamatan',
            'kelurahan', 'rt', 'rw', 'no_hp_ortu'], 'Test');
        Biodata::create([...$bio, 'user_id' => $user->id, 'nama_lengkap' => 'Canonical student',
            'jenis_kelamin' => 'L', 'tanggal_lahir' => '2018-01-01', 'anak_ke' => 1, 'jalur_pendaftaran' => $jalur]);
        PpdbSeatAllocation::create(['user_id' => $user->id, 'payment_id' => null, 'jenjang' => $jenjang,
            'status' => $status === 'TIDAK_LULUS' ? 'RELEASED' : 'ALLOCATED', 'allocated_at' => now()]);
        return $user;
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

    public function test_sd_and_smp_resolve_server_pricing_and_canonical_account(): void
    {
        foreach (['SD' => [240000, '11111111'], 'SMP' => [430000, '22222222']] as $school => [$total, $account]) {
            Sanctum::actingAs($this->parent($school));
            $this->getJson('/api/daftar-ulang/pricing')->assertOk()->assertJsonPath('source', 'DEFAULT')
                ->assertJsonPath('jenjang', $school)->assertJsonPath('total', $total)->assertJsonPath('account.rekening', $account);
        }
    }

    public function test_jalur_never_selects_custom_pricing_automatically(): void
    {
        foreach (['YAYASAN', 'UMUM', 'INTERNAL_TK', 'PUTRA_PUTRI_GURU'] as $jalur) {
            $user = $this->parent('SD', $jalur);
            Sanctum::actingAs($user);
            $this->getJson('/api/daftar-ulang/pricing')->assertOk()->assertJsonPath('source', 'DEFAULT')->assertJsonPath('total', 240000);
            $this->pricing->updateIndividual($this->super->id, $user->id, ['mode' => 'CUSTOM', 'active' => true,
                'items' => $this->items(70000), 'discount_nominal' => 0]);
            $this->getJson('/api/daftar-ulang/pricing')->assertOk()->assertJsonPath('source', 'CUSTOM')->assertJsonPath('total', 190000);
        }
    }

    public function test_inactive_and_default_mode_custom_heads_fall_back_to_default(): void
    {
        $user = $this->parent();
        $this->pricing->updateIndividual($this->super->id, $user->id, ['mode' => 'CUSTOM', 'active' => false,
            'items' => $this->items(70000), 'discount_nominal' => 0]);
        Sanctum::actingAs($user);
        $this->getJson('/api/daftar-ulang/pricing')->assertOk()->assertJsonPath('source', 'DEFAULT');
        $this->pricing->updateIndividual($this->super->id, $user->id, ['mode' => 'DEFAULT', 'active' => true]);
        $this->getJson('/api/daftar-ulang/pricing')->assertOk()->assertJsonPath('source', 'DEFAULT');
    }

    public function test_only_lulus_authenticated_parent_is_eligible(): void
    {
        $this->getJson('/api/daftar-ulang/pricing')->assertUnauthorized();
        foreach (['BELUM', 'TIDAK_LULUS'] as $status) {
            Sanctum::actingAs($this->parent('SD', 'UMUM', $status));
            $this->getJson('/api/daftar-ulang/pricing')->assertUnprocessable();
            $this->postJson('/api/daftar-ulang/invoices')->assertUnprocessable();
        }
        foreach (['guru', 'admin', 'super_admin'] as $role) {
            $actor = $this->actor($role);
            $actor->forceFill(['status_kelulusan' => 'LULUS'])->save();
            Sanctum::actingAs($actor);
            $this->getJson('/api/daftar-ulang/pricing')->assertForbidden();
            $this->postJson('/api/daftar-ulang/invoices')->assertForbidden();
        }
        $this->assertDatabaseCount('du_invoices', 0);
    }

    public function test_withdrawn_record_blocks_continuation_and_invoice_replay_without_implementing_withdrawal(): void
    {
        $user = $this->parent();
        $invoice = $this->invoice($user);
        // Fixture only: no application withdrawal endpoint or mutation exists.
        DB::table('du_invoices')->where('id', $invoice->id)->update(['withdrawn_at' => now()]);
        $this->getJson('/api/daftar-ulang/pricing')->assertUnprocessable();
        $this->postJson('/api/daftar-ulang/invoices')->assertUnprocessable();
        $this->postJson('/api/daftar-ulang/invoices/'.$invoice->id.'/agreement', ['agree' => true])->assertUnprocessable();
        $this->assertDatabaseCount('du_invoices', 1);
    }

    public function test_no_quota_or_seat_query_no_allocation_and_ppdb_payments_unchanged(): void
    {
        $user = $this->parent();
        $capacity = app(PpdbCapacityService::class);
        $capacity->increaseMaxCapacity('SD', 1);
        $this->assertSame(0, $capacity->getCapacity('SD')['sisa']);
        $ppdb = Payment::create(['user_id' => $user->id, 'invoice_number' => 'PPDB-FROZEN',
            'jenjang' => 'SD', 'amount' => 175000, 'status' => 'SUCCESS']);
        $ppdbBefore = $ppdb->fresh()->getAttributes();
        $seats = DB::table('ppdb_seat_allocations')->get()->toJson();
        $quota = $capacity->publicQuota();
        $this->app->bind(PpdbCapacityService::class, fn () => throw new \LogicException('No capacity dependency'));
        Sanctum::actingAs($user);
        DB::enableQueryLog();
        try {
            $this->getJson('/api/daftar-ulang/pricing')->assertOk();
            $this->postJson('/api/daftar-ulang/invoices')->assertOk();
            foreach (DB::getQueryLog() as $query) {
                $this->assertDoesNotMatchRegularExpression('/ppdb_(capacities|seat_allocations)/i', $query['query']);
            }
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame($seats, DB::table('ppdb_seat_allocations')->get()->toJson());
        $this->assertSame($quota, $capacity->publicQuota());
        $this->assertSame($ppdbBefore, $ppdb->fresh()->getAttributes());
    }

    public function test_parent_cannot_spoof_any_authoritative_identity_or_financial_field(): void
    {
        $user = $this->parent();
        Sanctum::actingAs($user);
        foreach (['user_id' => 999, 'jenjang' => 'SMP', 'jalur_pendaftaran' => 'YAYASAN', 'student_name' => 'Spoof',
            'items' => $this->items(1), 'amount' => 1, 'subtotal' => 1, 'discount_nominal' => 1,
            'total' => 1, 'source' => 'CUSTOM', 'pricing_source' => 'CUSTOM', 'payment_category' => 'PPDB',
            'rekening' => '99999999', 'role' => 'super_admin'] as $field => $value) {
            $this->postJson('/api/daftar-ulang/invoices', [$field => $value])->assertUnprocessable();
            $this->getJson('/api/daftar-ulang/pricing?'.http_build_query([$field => $value]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('du_invoices', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_invoice_and_agreement_ownership_cannot_be_selected_by_client(): void
    {
        $owner = $this->parent();
        $invoice = $this->invoice($owner);
        Sanctum::actingAs($this->parent());
        $this->getJson('/api/daftar-ulang/invoices/'.$invoice->id)->assertNotFound();
        $this->postJson('/api/daftar-ulang/invoices/'.$invoice->id.'/agreement', ['agree' => true])->assertNotFound();
        $this->getJson('/api/daftar-ulang/pricing?user_id='.$owner->id)->assertUnprocessable();
        $this->assertDatabaseCount('du_agreement_acceptances', 0);
    }

    public function test_admin_is_read_only_parent_has_no_configuration_access_and_super_admin_can_write(): void
    {
        $user = $this->parent();
        $payload = ['mode' => 'CUSTOM', 'active' => true, 'items' => $this->items(70000), 'discount_nominal' => 0];
        foreach (['orang_tua', 'guru', 'admin'] as $role) {
            Sanctum::actingAs($this->actor($role));
            $this->putJson('/api/admin/daftar-ulang/pricing/individual/'.$user->id, [...$payload, 'role' => 'super_admin'])->assertForbidden();
            $this->putJson('/api/admin/daftar-ulang/pricing/default/SD', ['items' => $this->items(), 'discount_nominal' => 0])->assertForbidden();
            $response = $this->getJson('/api/admin/daftar-ulang/pricing/default/SD');
            $role === 'admin' ? $response->assertOk() : $response->assertForbidden();
        }
        Sanctum::actingAs($this->super);
        $response = $this->putJson('/api/admin/daftar-ulang/pricing/individual/'.$user->id, $payload)->assertOk();
        $this->assertSame($this->super->id, $response->json('created_by'));
        $this->assertSame($this->super->id, $response->json('updated_by'));
        $this->putJson('/api/admin/daftar-ulang/pricing/individual/'.$user->id, ['active' => false])->assertOk()->assertJsonPath('active', false);
        $this->putJson('/api/admin/daftar-ulang/pricing/individual/'.$user->id, ['active' => true])->assertOk()->assertJsonPath('active', true);
        $this->assertDatabaseCount('du_individual_pricings', 1);
        $this->assertSame(5, DB::table('du_pricing_audits')->count());
        Sanctum::actingAs($this->actor('admin'));
        $this->getJson('/api/admin/daftar-ulang/pricing/individual/'.$user->id)->assertOk();
    }

    public function test_domain_authorization_cannot_be_bypassed_by_calling_the_service(): void
    {
        $user = $this->parent();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->pricing->updateIndividual($user->id, $user->id, ['mode' => 'CUSTOM', 'items' => $this->items(), 'discount_nominal' => 0]);
    }

    public function test_money_validation_rejects_floats_negatives_overflow_duplicates_and_client_line_totals(): void
    {
        Sanctum::actingAs($this->super);
        $bad = [
            ['items' => $this->items(), 'discount_nominal' => -1],
            ['items' => $this->items(), 'discount_nominal' => 1.5],
            ['items' => $this->items(), 'discount_nominal' => '10'],
            ['items' => $this->items(), 'discount_nominal' => 250001],
            ['items' => $this->items(-1), 'discount_nominal' => 0],
            ['items' => $this->items(2000000000), 'discount_nominal' => 0],
            ['items' => [], 'discount_nominal' => 0],
            ['items' => [['id' => 'x', 'name' => 'X', 'quantity' => 1.5, 'unit_amount' => 10]], 'discount_nominal' => 0],
            ['items' => [['id' => 'x', 'name' => 'X', 'quantity' => 1, 'unit_amount' => true]], 'discount_nominal' => 0],
            ['items' => [['id' => 'x', 'name' => 'X', 'quantity' => 1, 'unit_amount' => 10, 'line_total' => 1]], 'discount_nominal' => 0],
            ['items' => [$this->items()[0], $this->items()[0]], 'discount_nominal' => 0],
            ['items' => $this->items(), 'discount_nominal' => 0, 'total' => 1],
        ];
        $before = DaftarUlangPricingRevision::count();
        foreach ($bad as $payload) {
            $this->putJson('/api/admin/daftar-ulang/pricing/default/SD', $payload)->assertUnprocessable();
        }
        $this->assertSame($before, DaftarUlangPricingRevision::count());
    }

    public function test_invoice_snapshots_complete_default_revision_and_payment_uses_snapshot(): void
    {
        $user = $this->parent();
        $invoice = $this->invoice($user);
        $snapshot = $invoice->pricing_snapshot;
        foreach (['source', 'revision', 'pricing_revision_id', 'default_revision_id', 'jenjang', 'items', 'subtotal', 'discount_nominal', 'total', 'snapshot_at', 'agreement'] as $key) {
            $this->assertArrayHasKey($key, $snapshot);
        }
        $this->assertSame(['id' => 'tuition', 'name' => 'Tuition', 'quantity' => 2, 'unit_amount' => 100000, 'line_total' => 200000], $snapshot['items'][0]);
        $this->assertSame(250000, $snapshot['subtotal']);
        $this->assertSame(10000, $snapshot['discount_nominal']);
        $this->assertSame(240000, $invoice->total);
        $this->assertSame($invoice->total, $invoice->payment->amount);
        $this->assertSame('DAFTAR_ULANG', $invoice->payment->payment_category);
        $this->assertSame($user->id, $invoice->payment->user_id);
        $this->assertSame('SD', $invoice->payment->jenjang);
        $this->assertSame('CREATED', $invoice->financial_status);
        $this->assertSame($invoice->invoice_number, $invoice->payment->invoice_number);
        $this->assertSame('UMUM', $invoice->jalur_pendaftaran);
        $this->assertSame('Canonical student', $invoice->student_name);
    }

    public function test_default_revision_and_jalur_changes_do_not_mutate_existing_invoice_or_payment(): void
    {
        $user = $this->parent();
        $invoice = $this->invoice($user);
        $before = $invoice->getAttributes();
        $payment = $invoice->payment->getAttributes();
        $oldRevision = DaftarUlangPricingRevision::findOrFail($invoice->pricing_revision_id)->getAttributes();
        $new = $this->pricing->updateDefault($this->super->id, 'SD', $this->items(300000), 0);
        $user->biodata->update(['jalur_pendaftaran' => 'YAYASAN']);
        $user->update(['jalur' => 'INTERNAL']);
        $this->assertSame(2, $new->revision);
        $this->assertSame($oldRevision, DaftarUlangPricingRevision::findOrFail($invoice->pricing_revision_id)->getAttributes());
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertSame($payment, $invoice->payment->fresh()->getAttributes());
        $this->postJson('/api/daftar-ulang/invoices')->assertOk()->assertJsonPath('id', $invoice->id)->assertJsonPath('total', 240000);
    }

    public function test_custom_update_and_deactivation_preserve_old_invoice_snapshot(): void
    {
        $user = $this->parent('SD', 'YAYASAN');
        $this->pricing->updateIndividual($this->super->id, $user->id, ['mode' => 'CUSTOM', 'active' => true,
            'items' => $this->items(70000), 'discount_nominal' => 5000]);
        $invoice = $this->invoice($user);
        $this->assertSame('CUSTOM', $invoice->pricing_snapshot['source']);
        $this->assertSame(185000, $invoice->total);
        $before = $invoice->getAttributes();
        $this->pricing->updateIndividual($this->super->id, $user->id, ['items' => $this->items(90000), 'discount_nominal' => 0]);
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->pricing->updateIndividual($this->super->id, $user->id, ['active' => false]);
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->getJson('/api/daftar-ulang/pricing')->assertOk()->assertJsonPath('source', 'DEFAULT');
        $this->assertSame(185000, $invoice->payment->fresh()->amount);
    }

    public function test_replay_and_rejected_expired_invoices_do_not_create_unlimited_payments(): void
    {
        $user = $this->parent();
        $invoice = $this->invoice($user);
        foreach (['PENDING_MANUAL' => 'PENDING_PAYMENT', 'SUCCESS' => 'PAID', 'REJECTED' => 'REJECTED', 'EXPIRED' => 'EXPIRED', 'FAILED' => 'FAILED'] as $status => $state) {
            $invoice->payment->update(['status' => $status]);
            $this->postJson('/api/daftar-ulang/invoices')->assertOk()->assertJsonPath('id', $invoice->id)->assertJsonPath('financial_status', $state);
        }
        $this->assertDatabaseCount('du_invoices', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_agreement_is_invoice_bound_immutable_and_idempotent_and_never_marks_official(): void
    {
        $user = $this->parent();
        $invoice = $this->invoice($user);
        $this->postJson('/api/daftar-ulang/invoices/'.$invoice->id.'/agreement', ['agree' => false])->assertUnprocessable();
        $this->postJson('/api/daftar-ulang/invoices/'.$invoice->id.'/agreement', ['agree' => true, 'agreement_version' => 'fake'])->assertUnprocessable();
        $response = $this->postJson('/api/daftar-ulang/invoices/'.$invoice->id.'/agreement', ['agree' => true])->assertOk();
        $acceptance = DaftarUlangAgreementAcceptance::findOrFail($response->json('id'));
        $before = $acceptance->getAttributes();
        config(['daftar_ulang.agreement' => ['version' => 'DU-2', 'text' => 'New reviewed test terms.']]);
        $this->travel(2)->hours();
        $this->postJson('/api/daftar-ulang/invoices/'.$invoice->id.'/agreement', ['agree' => true])->assertOk()->assertJsonPath('id', $acceptance->id);
        $this->assertSame($before, $acceptance->fresh()->getAttributes());
        $this->assertSame('DU-1', $acceptance->agreement_version);
        $this->assertSame(hash('sha256', 'Reviewed test agreement.'), $acceptance->agreement_sha256);
        $this->assertSame('LULUS', $user->fresh()->status_kelulusan);
        $this->assertSame('PENDING', $invoice->payment->fresh()->status);
        $this->expectException(\LogicException::class);
        $acceptance->update(['agreement_version' => 'mutated']);
    }

    public function test_invoice_and_pricing_history_cannot_be_mutated_through_models(): void
    {
        $invoice = $this->invoice($this->parent());
        foreach ([$invoice, DaftarUlangPricingRevision::findOrFail($invoice->pricing_revision_id)] as $record) {
            try {
                $record->update(['total' => 1]);
                $this->fail('Historical pricing must be immutable.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
    }

    public function test_unconfigured_pricing_or_agreement_fails_closed_without_creating_payment(): void
    {
        $user = $this->parent();
        Sanctum::actingAs($user);
        config(['daftar_ulang.agreement' => ['version' => null, 'text' => null]]);
        $this->postJson('/api/daftar-ulang/invoices')->assertUnprocessable()->assertJsonValidationErrors('agreement');
        DB::table('du_default_pricings')->where('jenjang', 'SD')->update(['pricing_revision_id' => null]);
        $this->getJson('/api/daftar-ulang/pricing')->assertUnprocessable()->assertJsonValidationErrors('pricing');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('du_invoices', 0);
    }
}
