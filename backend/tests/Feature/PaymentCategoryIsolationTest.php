<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentCategoryIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name = 'Parent', string $role = 'orang_tua'): User
    {
        $user = User::factory()->create(['name' => $name, 'jenjang' => 'SD']);
        $user->forceFill(['role' => $role])->save();
        return $user->refresh();
    }

    private function payment(User $user, string $category = Payment::CATEGORY_PPDB, string $status = 'PENDING_MANUAL', ?string $invoice = null): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'payment_category' => $category,
            'invoice_number' => $invoice ?? strtoupper($category).'-'.uniqid(),
            'jenjang' => 'SD',
            'amount' => 175000,
            'payment_method' => 'MANUAL',
            'status' => $status,
        ]);
    }

    private function admin(): User
    {
        return $this->user('Admin', 'admin');
    }

    public function test_legacy_and_new_ppdb_payments_have_explicit_ppdb_category(): void
    {
        $user = $this->user();
        $payment = Payment::create([
            'user_id' => $user->id, 'invoice_number' => 'DEFAULT-'.uniqid(),
            'jenjang' => 'SD', 'amount' => 175000, 'status' => 'SUCCESS',
        ]);
        $this->assertSame(Payment::CATEGORY_PPDB, $payment->payment_category);
        $this->assertSame(Payment::CATEGORY_PPDB, Payment::findOrFail($payment->id)->payment_category);
        $this->assertSame('PPDB', DB::table('payments')->where('id', $payment->id)->value('payment_category'));
    }

    public function test_daftar_ulang_is_a_separate_valid_domain_category(): void
    {
        $payment = $this->payment($this->user(), Payment::CATEGORY_DAFTAR_ULANG);
        $this->assertSame(Payment::CATEGORY_DAFTAR_ULANG, $payment->payment_category);
        $this->assertCount(1, Payment::daftarUlang()->get());
        $this->assertCount(0, Payment::ppdb()->get());
    }

    public function test_category_validation_rejects_unknown_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->payment($this->user(), 'NOT_A_CATEGORY');
    }

    public function test_invoice_uniqueness_is_scoped_to_category(): void
    {
        $invoice = 'SHARED-INVOICE-'.uniqid();
        $this->payment($this->user('PPDB'), Payment::CATEGORY_PPDB, invoice: $invoice);
        $this->payment($this->user('DAFTAR ULANG'), Payment::CATEGORY_DAFTAR_ULANG, invoice: $invoice);

        $this->expectException(QueryException::class);
        $this->payment($this->user('PPDB DUPLICATE'), Payment::CATEGORY_PPDB, invoice: $invoice);
    }

    public function test_daftar_ulang_invoice_uniqueness_is_enforced_independently(): void
    {
        $user = $this->user();
        $this->payment($user, Payment::CATEGORY_DAFTAR_ULANG, invoice: 'DU-UNIQUE');
        $this->payment($user, Payment::CATEGORY_PPDB, invoice: 'DU-UNIQUE');
        $this->assertSame(2, Payment::where('invoice_number', 'DU-UNIQUE')->count());
        $this->expectException(QueryException::class);
        $this->payment($user, Payment::CATEGORY_DAFTAR_ULANG, invoice: 'DU-UNIQUE');
    }

    public function test_rollback_refuses_to_erase_daftar_ulang_category(): void
    {
        $du = $this->payment($this->user(), Payment::CATEGORY_DAFTAR_ULANG);
        $before = $du->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_10_07_000000_add_payment_category_to_payments.php');
        try {
            $migration->down();
            $this->fail('Rollback must refuse existing Daftar Ulang data.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Daftar Ulang payments exist', $e->getMessage());
        }
        $this->assertSame($before, $du->fresh()->getAttributes());
    }

    public function test_ppdb_queries_and_duplicate_guard_exclude_daftar_ulang(): void
    {
        $user = $this->user();
        $daftarUlang = $this->payment($user, Payment::CATEGORY_DAFTAR_ULANG, 'SUCCESS');
        $this->assertTrue(Payment::hasBlockingPayment($user->id, Payment::CATEGORY_DAFTAR_ULANG));
        $this->assertFalse(Payment::hasBlockingPayment($user->id, Payment::CATEGORY_PPDB));

        Sanctum::actingAs($user);
        $this->getJson('/api/payment/status')->assertOk()->assertJson(['has_payment' => false]);

        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/payments/{$daftarUlang->id}/verify")->assertNotFound();
        $this->assertSame('SUCCESS', $daftarUlang->fresh()->status);
    }

    public function test_daftar_ulang_queries_exclude_ppdb(): void
    {
        $user = $this->user();
        $ppdb = $this->payment($user, Payment::CATEGORY_PPDB, 'SUCCESS');
        $daftarUlang = $this->payment($user, Payment::CATEGORY_DAFTAR_ULANG, 'PENDING_MANUAL');

        $this->assertSame($ppdb->id, Payment::ppdb()->where('user_id', $user->id)->value('id'));
        $this->assertSame($daftarUlang->id, Payment::daftarUlang()->where('user_id', $user->id)->value('id'));
        $this->assertFalse(Payment::ppdb()->whereKey($daftarUlang->id)->exists());
        $this->assertFalse(Payment::daftarUlang()->whereKey($ppdb->id)->exists());
    }

    public function test_ppdb_admin_report_does_not_replace_ppdb_with_daftar_ulang(): void
    {
        $user = $this->user();
        $ppdb = $this->payment($user, Payment::CATEGORY_PPDB, 'SUCCESS');
        $daftarUlang = $this->payment($user, Payment::CATEGORY_DAFTAR_ULANG, 'SUCCESS');

        Sanctum::actingAs($this->admin());
        $response = $this->getJson('/api/admin/data-pembayaran')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $user->id);
        $this->assertSame($ppdb->id, $row['payment']['id']);
        $this->assertNotSame($daftarUlang->id, $row['payment']['id']);
    }

    public function test_ppdb_proof_endpoint_rejects_daftar_ulang_id_and_foreign_owner(): void
    {
        Storage::fake('private');
        $owner = $this->user('Owner');
        $stranger = $this->user('Stranger');
        $path = 'bukti_bayar/category-proof.png';
        $image = \Illuminate\Http\UploadedFile::fake()->image('proof.png');
        Storage::disk('private')->put($path, file_get_contents($image->getPathname()));
        $ppdb = $this->payment($owner, Payment::CATEGORY_PPDB, 'PENDING_MANUAL');
        $ppdb->update(['proof_of_payment' => $path]);
        $daftarUlang = $this->payment($owner, Payment::CATEGORY_DAFTAR_ULANG, 'PENDING_MANUAL');
        $daftarUlang->update(['proof_of_payment' => $path]);

        Sanctum::actingAs($owner);
        $this->getJson("/api/payments/{$daftarUlang->id}/evidence")->assertNotFound();
        $this->getJson("/api/payments/{$ppdb->id}/evidence")->assertOk();

        Sanctum::actingAs($stranger);
        $this->getJson("/api/payments/{$ppdb->id}/evidence")->assertForbidden();
        $this->getJson('/api/payment/status?user_id='.$owner->id.'&payment_id='.$ppdb->id)
            ->assertOk()->assertJsonPath('has_payment', false);
        $this->getJson('/api/admin/data-pembayaran/'.$owner->id)->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->getJson("/api/payments/{$ppdb->id}/evidence")->assertOk();
        $this->getJson("/api/payments/{$daftarUlang->id}/evidence")->assertNotFound();
        $this->getJson("/api/admin/data-pembayaran/{$owner->id}/payments/{$ppdb->id}/proof")->assertOk();
        $this->getJson("/api/admin/data-pembayaran/{$owner->id}/payments/{$daftarUlang->id}/proof")->assertNotFound();
        $this->getJson("/api/admin/data-pembayaran/{$stranger->id}/payments/{$ppdb->id}/proof")->assertNotFound();
    }

    public function test_raw_insert_defaults_to_ppdb_and_unknown_category_is_rejected_by_database(): void
    {
        $user = $this->user();
        $fields = ['user_id' => $user->id, 'invoice_number' => 'RAW-'.uniqid(),
            'jenjang' => 'SD', 'amount' => 123456, 'status' => 'SUCCESS'];
        $id = DB::table('payments')->insertGetId($fields);
        $this->assertSame('PPDB', DB::table('payments')->where('id', $id)->value('payment_category'));
        $this->expectException(QueryException::class);
        DB::table('payments')->insert(array_merge($fields, ['invoice_number' => 'INVALID-'.uniqid(), 'payment_category' => 'BOGUS']));
    }

    public function test_duplicate_guards_are_independent_in_both_directions_and_upload_ignores_category_spoof(): void
    {
        Storage::fake('private');
        \App\Models\Setting::updateOrCreate(['key' => 'biaya_sd'], ['value' => '175000']);
        $user = $this->user();
        $du = $this->payment($user, Payment::CATEGORY_DAFTAR_ULANG, 'SUCCESS');
        $duBefore = $du->fresh()->getAttributes();
        Sanctum::actingAs($user);
        $upload = fn () => $this->post('/api/payment/manual', [
            'jenjang' => 'SD', 'payment_category' => 'DAFTAR_ULANG',
            'bukti_bayar' => \Illuminate\Http\UploadedFile::fake()->image('p.png'),
        ], ['Accept' => 'application/json']);
        $upload()->assertOk();
        $this->assertSame(1, Payment::ppdb()->where('user_id', $user->id)->count());
        $upload()->assertStatus(409);
        $this->assertSame(1, Payment::ppdb()->where('user_id', $user->id)->count());
        $this->assertSame($duBefore, $du->fresh()->getAttributes());
        $this->assertCount(1, Storage::disk('private')->allFiles());
        $other = $this->user();
        $this->payment($other, Payment::CATEGORY_PPDB, 'SUCCESS');
        $this->assertTrue(Payment::hasBlockingPayment($other->id, Payment::CATEGORY_PPDB));
        $this->assertFalse(Payment::hasBlockingPayment($other->id, Payment::CATEGORY_DAFTAR_ULANG));
    }

    public function test_report_detail_export_pending_list_and_domain_report_scopes_are_isolated(): void
    {
        $user = $this->user();
        $p = $this->payment($user, Payment::CATEGORY_PPDB, 'SUCCESS', 'PPDB-REPORT');
        $du = $this->payment($user, Payment::CATEGORY_DAFTAR_ULANG, 'PENDING_MANUAL', 'DU-REPORT');
        $du->update(['jenjang' => 'SMP']);
        $letters = app(\App\Services\KelulusanLetterService::class);
        $resolve = new \ReflectionMethod($letters, 'resolveCandidateJenjang');
        $this->assertSame('SD', $resolve->invoke($letters, $user));
        $reports = app(\App\Services\AdminReportService::class);
        $this->assertSame($p->id, (int) $reports->query('pembayaran')->where('u.id', $user->id)->first()->payment_id);
        $this->assertSame($du->id, (int) $reports->query('pembayaran', [], Payment::CATEGORY_DAFTAR_ULANG)->where('u.id', $user->id)->first()->payment_id);
        Sanctum::actingAs($user);
        $this->getJson('/api/payment/status')->assertOk()->assertJsonPath('payment.id', $p->id)->assertJsonPath('payment.status', 'SUCCESS');
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/admin/data-pembayaran/'.$user->id.'?payment_category=DAFTAR_ULANG')
            ->assertOk()->assertJsonPath('payment.id', $p->id);
        $csv = $this->get('/api/admin/data-pembayaran/export?payment_category=DAFTAR_ULANG')->assertOk()->streamedContent();
        $this->assertStringContainsString('PPDB-REPORT', $csv);
        $this->assertStringNotContainsString('DU-REPORT', $csv);
        $this->getJson('/api/admin/payments/manual')->assertOk()->assertJsonCount(0);
        $row = collect($this->getJson('/api/admin/pendaftar')->assertOk()->json())->firstWhere('id', $user->id);
        $this->assertSame($p->id, $row['payment_id']);
        foreach (['/api/admin/jadwal-observasi', '/api/admin/kelulusan'] as $url) {
            $row = collect($this->getJson($url)->assertOk()->json())->firstWhere('id', $user->id);
            $this->assertSame('SD', $row['jenjang']);
        }
    }

    public function test_no_category_checks_quota_or_mutates_allocations_and_du_is_not_reviewable_via_ppdb(): void
    {
        $user = $this->user();
        $p = $this->payment($user);
        $du = $this->payment($user, Payment::CATEGORY_DAFTAR_ULANG);
        $svc = app(\App\Services\PpdbCapacityService::class);
        $svc->increaseMaxCapacity('SD', 1);
        \App\Models\PpdbSeatAllocation::create(['user_id' => $user->id, 'payment_id' => null,
            'jenjang' => 'SD', 'status' => 'ALLOCATED', 'allocated_at' => now()]);
        $before = DB::table('ppdb_seat_allocations')->orderBy('id')->get()->toJson();
        $quota = $svc->publicQuota();
        $this->assertSame(0, $quota['sd']['sisa']);
        $duBefore = $du->fresh()->getAttributes();
        $this->app->bind(\App\Services\PpdbCapacityService::class, fn () => throw new \LogicException('No capacity dependency'));
        Sanctum::actingAs($this->admin());
        DB::enableQueryLog();
        try {
            $this->postJson("/api/admin/payments/{$p->id}/verify")->assertOk();
            $this->postJson("/api/admin/payments/{$du->id}/verify")->assertNotFound();
            $this->postJson("/api/admin/payments/{$du->id}/reject", ['reason' => 'x'])->assertNotFound();
            foreach (DB::getQueryLog() as $query) {
                $this->assertDoesNotMatchRegularExpression('/ppdb_(capacities|seat_allocations)/i', $query['query']);
            }
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame('SUCCESS', $p->fresh()->status);
        $this->assertSame($duBefore, $du->fresh()->getAttributes());
        $this->assertSame($before, DB::table('ppdb_seat_allocations')->orderBy('id')->get()->toJson());
        $this->assertSame($quota, $svc->publicQuota());
    }

    public function test_ppdb_verification_remains_financial_only_and_does_not_mutate_seats(): void
    {
        $user = $this->user();
        $payment = $this->payment($user, Payment::CATEGORY_PPDB);
        $before = DB::table('ppdb_seat_allocations')->orderBy('id')->get()->toJson();
        $admin = $this->admin();

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/payments/{$payment->id}/verify")->assertOk();

        $this->assertSame('SUCCESS', $payment->fresh()->status);
        $this->assertSame($before, DB::table('ppdb_seat_allocations')->orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::table('ppdb_seat_allocations')->count());
    }
}
