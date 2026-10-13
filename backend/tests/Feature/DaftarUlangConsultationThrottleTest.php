<?php

namespace Tests\Feature;

use App\Models\Biodata;
use App\Models\DaftarUlangConsultation;
use App\Models\DaftarUlangInvoice;
use App\Models\PpdbSeatAllocation;
use App\Models\Setting;
use App\Models\User;
use App\Services\DaftarUlangPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B.7.4-E.2 (F-3) — consultation endpoint rate limiting.
 *
 * Each consultation endpoint is throttled by a NAMED limiter (see
 * AppServiceProvider) keyed by the authenticated user id, with separate
 * read/write buckets per side. These tests prove:
 *   - the configured threshold returns HTTP 429 with the app's normal
 *     {message} JSON body (Indonesian, understood by httpErrorMessage);
 *   - the bucket is scoped per ACTOR: one parent hammering the endpoint
 *     never throttles a different parent, and admin activity does not
 *     consume the parent buckets (or vice versa);
 *   - authorization is untouched: a throttled caller is still authenticated
 *     and role-checked (a guest gets 419/429-style middleware responses
 *     from auth before any limiter, and a parent can still never reach the
 *     admin desk);
 *   - ordinary workflows (a handful of requests) stay well under the limits.
 *
 * RateLimiter state lives in the cache; RefreshDatabase does not clear it,
 * so every test flushes the consultation keys explicitly.
 *
 * TEST DB ONLY (ppdb_sekolah_test).
 */
class DaftarUlangConsultationThrottleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $admin2;
    private User $parent;
    private User $parent2;
    private DaftarUlangPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('ppdb_sekolah_test', DB::selectOne('SELECT DATABASE() db')->db);
        cache()->flush();

        config(['daftar_ulang.agreement' => ['version' => 'DU-TH', 'text' => 'Throttle test agreement.']]);
        Setting::updateOrCreate(['key' => 'rekening_sd'], ['value' => '11111111']);
        Setting::updateOrCreate(['key' => 'bank_name'], ['value' => 'Bank']);
        Setting::updateOrCreate(['key' => 'bank_owner'], ['value' => 'Yayasan']);

        $this->pricing = app(DaftarUlangPricingService::class);
        $super = User::factory()->create(['jenjang' => 'SD']);
        $super->forceFill(['role' => 'super_admin'])->save();

        $actor = function (): User {
            $u = User::factory()->create(['jenjang' => 'SD', 'jalur' => 'UMUM']);
            $u->forceFill(['role' => 'admin'])->save();
            return $u->refresh();
        };
        $this->admin = $actor();
        $this->admin2 = $actor();

        $this->parent = $this->lulusParent();
        $this->parent2 = $this->lulusParent();

        // Both parents need their canonical DU invoice up front: the POST
        // create path 404s without one (same as the main suite's helpers).
        $this->makeInvoice($this->parent);
        $this->makeInvoice($this->parent2);
    }

    protected function tearDown(): void
    {
        cache()->flush();
        parent::tearDown();
    }

    /** Same parent factory as the main consultation suite (biodata + seat + LULUS). */
    private function lulusParent(): User
    {
        $u = User::factory()->create(['jenjang' => 'SD', 'jalur' => 'UMUM']);
        $u->forceFill(['role' => 'orang_tua', 'status_kelulusan' => 'LULUS'])->save();
        $bio = array_fill_keys(['asal_sekolah', 'nik_siswa', 'no_kk', 'tempat_lahir', 'status_peserta_didik',
            'nama_ibu', 'nik_ibu', 'pendidikan_ibu', 'pekerjaan_ibu', 'penghasilan_ibu', 'nama_ayah', 'nik_ayah',
            'pendidikan_ayah', 'pekerjaan_ayah', 'penghasilan_ayah', 'alamat_lengkap', 'kota', 'kecamatan',
            'kelurahan', 'rt', 'rw', 'no_hp_ortu'], 'Test');
        Biodata::create([...$bio, 'user_id' => $u->id, 'nama_lengkap' => 'Throttle student',
            'jenis_kelamin' => 'L', 'tanggal_lahir' => '2018-01-01', 'anak_ke' => 1, 'jalur_pendaftaran' => 'UMUM']);
        PpdbSeatAllocation::create(['user_id' => $u->id, 'payment_id' => null, 'jenjang' => 'SD',
            'status' => 'ALLOCATED', 'allocated_at' => now()]);
        return $u->refresh();
    }

    /** Seed default pricing as the main suite does, then create the invoice. */
    /**
     * Seed default pricing and create the parent's canonical DU invoice.
     * Makes NO consultation request, so limiter accounting stays exact.
     */
    private function makeInvoice(User $parent): DaftarUlangInvoice
    {
        $super = User::where('role', 'super_admin')->firstOrFail();
        $this->pricing->updateDefault($super->id, 'SD', [
            ['id' => 'tuition', 'name' => 'Tuition', 'quantity' => 2, 'unit_amount' => 100000],
            ['id' => 'kit', 'name' => 'Student kit', 'quantity' => 1, 'unit_amount' => 50000]], 10000);
        Sanctum::actingAs($parent);
        $id = $this->postJson('/api/daftar-ulang/invoices')->assertOk()->json('id');
        return DaftarUlangInvoice::findOrFail($id);
    }

    private function flushConsultationLimiters(): void
    {
        cache()->flush();
    }

    private function openConsultation(User $parent): DaftarUlangConsultation
    {
        Sanctum::actingAs($parent);
        $this->postJson('/api/daftar-ulang/consultations', [
            'preferred_date' => now()->addDays(3)->toDateString(),
            'preferred_time' => '10:30',
        ])->assertCreated();
        return DaftarUlangConsultation::where('user_id', $parent->id)->sole();
    }

    // ------------------------------------------------------------- parent --

    /** Parent WRITE (create): the 11th request in a minute is 429 with the
     *  application's {message} body, and it does NOT create a row. */
    public function test_parent_create_limit_is_10_per_minute_with_json_body(): void
    {
        Sanctum::actingAs($this->parent);
        $payload = ['preferred_date' => now()->addDays(3)->toDateString(), 'preferred_time' => '10:30'];

        // The FIRST create is 201; idempotent replays then return 200. All
        // 10 attempts inside the minute succeed, then the 11th is throttled.
        $this->postJson('/api/daftar-ulang/consultations', $payload)->assertCreated();
        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/daftar-ulang/consultations', $payload)->assertOk();
        }

        $response = $this->postJson('/api/daftar-ulang/consultations', $payload);
        $response->assertStatus(429);
        $this->assertSame('Terlalu banyak permintaan. Coba lagi dalam 60 detik.', $response->json('message'));
        $response->assertHeader('Retry-After');
        $this->assertSame(1, DaftarUlangConsultation::count(), 'A throttled request must not create a row.');
    }

    /** The write bucket is per ACTOR: parent A exhausted does not throttle B. */
    public function test_parent_write_bucket_is_scoped_per_user(): void
    {
        Sanctum::actingAs($this->parent);
        $payload = ['preferred_date' => now()->addDays(3)->toDateString(), 'preferred_time' => '10:30'];
        for ($i = 0; $i < 11; $i++) {
            $this->postJson('/api/daftar-ulang/consultations', $payload);
        }
        $this->postJson('/api/daftar-ulang/consultations', $payload)->assertStatus(429);

        // A DIFFERENT parent is untouched: normal 200 replay.
        Sanctum::actingAs($this->parent2);
        $this->postJson('/api/daftar-ulang/consultations', $payload)->assertCreated();
    }

    /** Parent READ (history): 31 requests in a minute trip the read limit, and
     *  the read limit does not consume the write budget. */
    public function test_parent_history_read_limit_is_30_and_independent_of_write(): void
    {
        Sanctum::actingAs($this->parent);
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/daftar-ulang/consultations')->assertOk();
        }
        $this->getJson('/api/daftar-ulang/consultations')->assertStatus(429);

        // The WRITE budget is untouched by 31 reads.
        $this->postJson('/api/daftar-ulang/consultations', [
            'preferred_date' => now()->addDays(3)->toDateString(),
            'preferred_time' => '10:30',
        ])->assertCreated();
    }

    /** Parent CANCEL shares the parent-write budget (10/min). */
    public function test_parent_cancel_shares_the_write_budget(): void
    {
        $consultation = $this->openConsultation($this->parent);

        // Drain the write bucket with idempotent replays: the consultation is
        // still ACTIVE, so POST create keeps returning 200 without mutating.
        // openConsultation consumed attempt 1; 9 replays reach exactly 10.
        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/daftar-ulang/consultations', [
                'preferred_date' => now()->addDays(3)->toDateString(),
                'preferred_time' => '10:30',
            ])->assertOk();
        }

        // The 11th write-shaped request (the cancel) is throttled BEFORE the
        // state machine: 429, and the row is left exactly as it was.
        $this->postJson("/api/daftar-ulang/consultations/{$consultation->id}/cancel")->assertStatus(429);
        $this->assertSame('REQUESTED', $consultation->refresh()->status);

        // A different parent's cancel is not throttled by this parent's bucket.
        $other = $this->openConsultation($this->parent2);
        $this->postJson("/api/daftar-ulang/consultations/{$other->id}/cancel")->assertOk();
    }

    // -------------------------------------------------------------- admin --

    /** Admin LIST read: 61 filter requests in a minute trip the admin read limit. */
    public function test_admin_list_limit_is_60_per_minute(): void
    {
        Sanctum::actingAs($this->admin);
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/admin/daftar-ulang/consultations')->assertOk();
        }
        $this->getJson('/api/admin/daftar-ulang/consultations')->assertStatus(429);
    }

    /** Admin WRITE (schedule/resolve): 30/min, and it is separate from the
     *  admin READ budget. */
    public function test_admin_write_limit_is_30_and_independent_of_read(): void
    {
        $consultation = $this->openConsultation($this->parent);
        Sanctum::actingAs($this->admin);

        // A SCHEDULED row can be rescheduled arbitrarily often, so use the
        // schedule endpoint as the write-shaped request. First set it SCHEDULED.
        $this->postJson("/api/admin/daftar-ulang/consultations/{$consultation->id}/schedule", [
            'scheduled_date' => now()->addDays(2)->toDateString(), 'scheduled_time' => '09:00',
        ])->assertOk();

        // 29 more reschedules = 30 writes total...
        for ($i = 0; $i < 29; $i++) {
            $this->postJson("/api/admin/daftar-ulang/consultations/{$consultation->id}/schedule", [
                'scheduled_date' => now()->addDays(2)->toDateString(), 'scheduled_time' => '09:00',
            ])->assertOk();
        }
        // ...the 31st write is 429.
        $this->postJson("/api/admin/daftar-ulang/consultations/{$consultation->id}/schedule", [
            'scheduled_date' => now()->addDays(2)->toDateString(), 'scheduled_time' => '09:00',
        ])->assertStatus(429);

        // The admin READ bucket is untouched by 30 writes: still 200.
        $this->getJson('/api/admin/daftar-ulang/consultations')->assertOk();
    }

    /** Admin buckets are per admin: admin A exhausted does not throttle admin B. */
    public function test_admin_buckets_are_scoped_per_user(): void
    {
        Sanctum::actingAs($this->admin);
        for ($i = 0; $i < 61; $i++) {
            $this->getJson('/api/admin/daftar-ulang/consultations');
        }
        $this->getJson('/api/admin/daftar-ulang/consultations')->assertStatus(429);

        Sanctum::actingAs($this->admin2);
        $this->getJson('/api/admin/daftar-ulang/consultations')->assertOk();
    }

    /** Parent and admin buckets never share budget, and authorization is
     *  unchanged: a parent still cannot reach the admin desk (role 403),
     *  whatever their limiter state. */
    public function test_authorization_unchanged_and_sides_do_not_share_buckets(): void
    {
        // Drain the parent read bucket.
        Sanctum::actingAs($this->parent);
        for ($i = 0; $i < 31; $i++) {
            $this->getJson('/api/daftar-ulang/consultations');
        }
        $this->getJson('/api/daftar-ulang/consultations')->assertStatus(429);

        // The parent's OWN-side throttling does not affect the ADMIN desk...
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/daftar-ulang/consultations')->assertOk();

        // ...and a parent remains forbidden from the admin desk even before
        // any limiter would apply (role gate first in ordinary flow).
        Sanctum::actingAs($this->parent2);
        $this->getJson('/api/admin/daftar-ulang/consultations')->assertForbidden();
    }

    /** Ordinary workflows stay far under every threshold: a realistic
     *  parent session (list + create + list) and admin session (browse with
     *  filters + schedule + resolve) never see a 429. */
    public function test_ordinary_workflows_are_not_throttled(): void
    {
        // Parent: a handful of history reads and one create.
        Sanctum::actingAs($this->parent);
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/daftar-ulang/consultations')->assertOk();
        }
        $this->postJson('/api/daftar-ulang/consultations', [
            'preferred_date' => now()->addDays(3)->toDateString(), 'preferred_time' => '10:30',
        ])->assertCreated();
        $this->getJson('/api/daftar-ulang/consultations')->assertOk();

        // Admin: filter browsing across all dimensions + schedule + resolve.
        Sanctum::actingAs($this->admin);
        $consultationId = DaftarUlangConsultation::sole()->id;
        foreach (['', '?status=REQUESTED', '?status=OPEN', '?jenjang=SD', '?jalur_pendaftaran=UMUM', '?search=Orang'] as $query) {
            $this->getJson('/api/admin/daftar-ulang/consultations' . $query)->assertOk();
        }
        $this->postJson("/api/admin/daftar-ulang/consultations/{$consultationId}/schedule", [
            'scheduled_date' => now()->addDays(2)->toDateString(), 'scheduled_time' => '09:00',
        ])->assertOk();
        $this->postJson("/api/admin/daftar-ulang/consultations/{$consultationId}/resolve", [
            'action' => 'complete', 'admin_note' => 'Selesai.',
        ])->assertOk();

        $this->assertSame('COMPLETED', DaftarUlangConsultation::sole()->status);
        // Lifecycle untouched by the whole session.
        $this->assertNull($this->parent->refresh()->status_siswa);
    }
}
