<?php

namespace Tests\Feature;

use App\Models\Biodata;
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
 * B.7.1 — Daftar Ulang withdrawal (MENGUNDURKAN DIRI) backend.
 *
 * Proves: exactly-once seat release with reason WITHDRAWN, status_kelulusan
 * and payment untouched, idempotent replay, IDOR/category isolation, the
 * admin read model reflecting MENGUNDURKAN_DIRI, and DU blocked afterwards.
 * TEST DB ONLY (ppdb_sekolah_test).
 */
class DaftarUlangWithdrawalTest extends TestCase
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
        config(['daftar_ulang.agreement' => ['version' => 'DU-W1', 'text' => 'Withdrawal test agreement.']]);
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
        Biodata::create([...$bio, 'user_id' => $u->id, 'nama_lengkap' => 'Withdrawal student',
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
        // Re-load a fresh invoice (the creating request updated related rows, so
        // an in-memory instance may hold stale relations / a detached user).
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

    // A + B + C + D — happy path, kelulusan isolation, payment isolation, exactly-once seat.
    public function test_withdrawal_releases_seat_without_touching_kelulusan_or_payment(): void
    {
        $parent = $this->parent('SD');
        $invoice = $this->makeInvoice($parent);
        $paymentId = $this->paySuccess($invoice);
        $this->assertSame('SUCCESS', Payment::findOrFail($paymentId)->status);
        $seatBefore = PpdbSeatAllocation::where('user_id', $parent->id)->count();
        $amountBefore = Payment::findOrFail($paymentId)->amount;

        Sanctum::actingAs($parent);
        $res = $this->postJson('/api/daftar-ulang/withdraw', ['note' => 'Pindah sekolah'])->assertOk();
        $this->assertTrue($res->json('withdrawn'));
        $this->assertTrue($res->json('changed'));
        $this->assertSame('MENGUNDURKAN_DIRI', $res->json('status'));

        // withdrawn_at set + immutable audit record created.
        $invoice->refresh();
        $this->assertNotNull($invoice->withdrawn_at);
        $audit = DaftarUlangWithdrawal::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('MENGUNDURKAN_DIRI', $audit->reason);
        $this->assertSame('Pindah sekolah', $audit->note);
        $this->assertSame('PARENT', $audit->initiated_by);

        // B. Kelulusan untouched (NOT converted to TIDAK_LULUS).
        $this->assertSame('LULUS', $parent->refresh()->status_kelulusan);

        // C. Payment untouched.
        $payment = Payment::findOrFail($paymentId);
        $this->assertSame('SUCCESS', $payment->status);
        $this->assertSame(Payment::CATEGORY_DAFTAR_ULANG, $payment->payment_category);
        $this->assertSame($amountBefore, $payment->amount);

        // D. Exactly one allocation released, history preserved (none created).
        $allocations = PpdbSeatAllocation::where('user_id', $parent->id)->get();
        $this->assertSame($seatBefore, $allocations->count());
        $released = $allocations->where('status', 'RELEASED');
        $this->assertSame(1, $released->count());
        $this->assertSame(PpdbCapacityService::RELEASE_WITHDRAWN, $released->first()->release_reason);
        $this->assertNotNull($released->first()->released_at);
    }

    // E — replay is idempotent (no duplicate release/record/payment change).
    public function test_withdrawal_replay_is_idempotent(): void
    {
        $parent = $this->parent('SD');
        $invoice = $this->makeInvoice($parent);
        $paymentId = $this->paySuccess($invoice);
        Sanctum::actingAs($parent);
        $this->postJson('/api/daftar-ulang/withdraw')->assertOk();
        $first = $invoice->refresh()->withdrawn_at;

        $res = $this->postJson('/api/daftar-ulang/withdraw')->assertOk();
        $this->assertFalse($res->json('changed')); // replay reports no change
        $this->assertSame($first->toIso8601String(), $invoice->refresh()->withdrawn_at->toIso8601String());
        $this->assertSame(1, DaftarUlangWithdrawal::where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, PpdbSeatAllocation::where('user_id', $parent->id)->where('status', 'RELEASED')->count());
        $this->assertSame('SUCCESS', Payment::findOrFail($paymentId)->status);
    }

    // F — IDOR: client-provided ids are ignored; parent A cannot reach parent B.
    public function test_withdrawal_ignores_client_ids_and_cannot_cross_users(): void
    {
        $a = $this->parent('SD');
        $invoiceA = $this->makeInvoice($a);
        $b = $this->parent('SD');
        $invoiceB = $this->makeInvoice($b);

        // B supplies A's user/invoice/allocation ids — all rejected by the allowlist.
        Sanctum::actingAs($b);
        $this->postJson('/api/daftar-ulang/withdraw', ['user_id' => $a->id])->assertUnprocessable();
        $this->postJson('/api/daftar-ulang/withdraw', ['invoice_id' => $invoiceA->id])->assertUnprocessable();
        $this->postJson('/api/daftar-ulang/withdraw', ['allocation_id' => 999])->assertUnprocessable();
        // A is untouched.
        $this->assertNull($invoiceA->refresh()->withdrawn_at);
        $this->assertSame('ALLOCATED', PpdbSeatAllocation::where('user_id', $a->id)->first()->status);
        // B withdrew nothing (no note-only request sent yet).
        $this->assertNull($invoiceB->refresh()->withdrawn_at);
    }

    // G — PPDB category isolation: a PPDB-only parent has no DU invoice to withdraw.
    public function test_ppdb_only_parent_cannot_withdraw_through_du(): void
    {
        $parent = $this->parent('SD');
        Payment::create(['user_id' => $parent->id, 'payment_category' => Payment::CATEGORY_PPDB,
            'invoice_number' => 'PPDB-'.uniqid(), 'jenjang' => 'SD', 'amount' => 175000,
            'payment_method' => 'MANUAL', 'status' => 'SUCCESS']);
        Sanctum::actingAs($parent);
        $this->postJson('/api/daftar-ulang/withdraw')->assertNotFound(); // no DU invoice
        $this->assertSame('ALLOCATED', PpdbSeatAllocation::where('user_id', $parent->id)->first()->status);
        $this->assertSame('SUCCESS', Payment::ppdb()->where('user_id', $parent->id)->first()->status);
    }

    // H — eligibility gates.
    public function test_withdrawal_eligibility_gates(): void
    {
        // non-LULUS -> 422
        foreach (['BELUM', 'TIDAK_LULUS'] as $status) {
            Sanctum::actingAs($this->parent('SD', $status));
            $this->postJson('/api/daftar-ulang/withdraw')->assertUnprocessable();
        }
        // LULUS but no DU invoice -> 404
        $noInvoice = $this->parent('SD');
        Sanctum::actingAs($noInvoice);
        $this->postJson('/api/daftar-ulang/withdraw')->assertNotFound();
        // missing active allocation -> 422 safe fail
        $orphan = $this->parent('SD');
        $this->makeInvoice($orphan);
        PpdbSeatAllocation::where('user_id', $orphan->id)->delete();
        Sanctum::actingAs($orphan);
        $this->postJson('/api/daftar-ulang/withdraw')->assertUnprocessable();
    }

    // I + J — admin read model + DU blocked after withdrawal.
    public function test_admin_status_reflects_withdrawal_and_du_blocked(): void
    {
        $parent = $this->parent('SD');
        $invoice = $this->makeInvoice($parent);
        Sanctum::actingAs($parent);
        $this->postJson('/api/daftar-ulang/withdraw')->assertOk();

        // I. Admin read model maps withdrawn_at to MENGUNDURKAN_DIRI.
        Sanctum::actingAs($this->admin);
        $detail = $this->getJson("/api/admin/daftar-ulang/students/{$parent->id}")->assertOk()->json();
        $this->assertSame('MENGUNDURKAN_DIRI', $detail['daftar_ulang']['status']);

        // J. DU eligibility now blocks further access.
        Sanctum::actingAs($parent);
        $this->getJson('/api/daftar-ulang/pricing')->assertUnprocessable();
        $this->postJson('/api/daftar-ulang/invoices')->assertUnprocessable();
    }

    // Authorization: non-parent roles and guests are rejected.
    public function test_withdrawal_requires_parent_role_and_auth(): void
    {
        foreach (['admin', 'guru'] as $role) {
            Sanctum::actingAs($this->actor($role));
            $this->postJson('/api/daftar-ulang/withdraw')->assertForbidden();
        }
        // super_admin passes the role gate but is rejected by the service's
        // orang_tua ownership check (403).
        Sanctum::actingAs($this->actor('super_admin'));
        $this->postJson('/api/daftar-ulang/withdraw')->assertForbidden();
    }

    public function test_withdrawal_guest_is_unauthorized(): void
    {
        $this->postJson('/api/daftar-ulang/withdraw')->assertUnauthorized();
    }

    // Withdrawal with a pending (non-SUCCESS) payment still releases the seat
    // and never mutates the payment (explicit B.7.0 decision).
    public function test_withdrawal_with_pending_payment_leaves_payment_untouched(): void
    {
        $parent = $this->parent('SD');
        $invoice = $this->makeInvoice($parent);
        $paymentId = $invoice->payment_id; // PENDING, no proof yet
        Sanctum::actingAs($parent);
        $this->postJson('/api/daftar-ulang/withdraw')->assertOk();
        $payment = Payment::findOrFail($paymentId);
        $this->assertSame('PENDING', $payment->status);
        $this->assertSame(Payment::CATEGORY_DAFTAR_ULANG, $payment->payment_category);
        $this->assertSame(1, PpdbSeatAllocation::where('user_id', $parent->id)->where('status', 'RELEASED')->count());
    }
}
