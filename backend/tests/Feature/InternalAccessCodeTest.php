<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\Whitelist;
use App\Services\InternalAccessCodeService;
use App\Services\PpdbCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P4-D.2 — INTERNAL candidate registration uses a SINGLE 10-digit
 * registration_number (nomor_internal). No NISN, no NIK, no access_code.
 * Server-authoritative identity; client fields never override the candidate.
 */
class InternalAccessCodeTest extends TestCase
{
    use RefreshDatabase;

    private PpdbCapacityService $capacity;
    private InternalAccessCodeService $codes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capacity = app(PpdbCapacityService::class);
        $this->codes    = app(InternalAccessCodeService::class);
        RateLimiter::clear('register|127.0.0.1');
        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'BUKA']);
        $this->capacity->increaseMaxCapacity('SD', 100);
        $this->capacity->increaseMaxCapacity('SMP', 100);
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('register|127.0.0.1');
        parent::tearDown();
    }

    private function candidate(string $nama, string $jenjang, ?string $number = null): Whitelist
    {
        return Whitelist::create([
            'nama_siswa'          => $nama,
            'jenjang'             => $jenjang,
            'asal_sekolah'        => 'TK IT Al-Mubarak',
            'registration_number' => $number ?? $this->codes->generateCode(),
            'is_used'             => false,
        ]);
    }

    private function regPayload(string $number, string $email, array $extra = []): array
    {
        return array_merge([
            'name' => 'Ortu', 'email' => $email, 'password' => 'Secret123',
            'jalur' => 'INTERNAL', 'kredensial' => 'ignored', 'nomor_internal' => $number,
        ], $extra);
    }

    private function adminAct(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($admin->refresh(), ['*']);
    }

    // === Generation invariants ===========================================

    public function test_generated_number_is_ten_digits_and_unique(): void
    {
        $a = $this->codes->generateCode();
        $b = $this->codes->generateCode();
        $this->assertMatchesRegularExpression('/^\d{10}$/', $a);
        $this->assertMatchesRegularExpression('/^\d{10}$/', $b);
        $this->assertNotSame($a, $b);
    }

    public function test_leading_zero_is_preserved(): void
    {
        $c = $this->candidate('Anak Nol', 'SD', '0123456789');
        $this->assertSame('0123456789', $c->registration_number);
        $this->postJson('/api/register', $this->regPayload('0123456789', 'zero@test.dev'))
            ->assertStatus(200);
        $this->assertTrue((bool) $c->fresh()->is_used);
    }

    public function test_admin_cannot_choose_the_number(): void
    {
        $this->adminAct();
        $res = $this->postJson('/api/admin/whitelists', [
            'nama_siswa' => 'Anak Pilih', 'jenjang' => 'SD', 'asal_sekolah' => 'TK',
            'registration_number' => '9999999999', // client-chosen must be ignored
        ])->assertOk();

        $issued = $res->json('registration_number');
        $this->assertMatchesRegularExpression('/^\d{10}$/', $issued);
        $this->assertNotSame('9999999999', $issued);
    }

    // === Registration =====================================================

    public function test_valid_number_registers_successfully(): void
    {
        $c = $this->candidate('Anak Satu', 'SD');
        $this->postJson('/api/register', $this->regPayload($c->registration_number, 'ok@test.dev'))
            ->assertStatus(200);

        $this->assertDatabaseHas('users', ['email' => 'ok@test.dev', 'jalur' => 'INTERNAL', 'jenjang' => 'SD']);
        $fresh = $c->fresh();
        $this->assertTrue((bool) $fresh->is_used);
        $this->assertNotNull($fresh->user_id);
    }

    public function test_nonexistent_number_rejected(): void
    {
        $this->postJson('/api/register', $this->regPayload('0000000001', 'bad@test.dev'))
            ->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'bad@test.dev']);
    }

    public function test_invalid_length_rejected(): void
    {
        $this->postJson('/api/register', $this->regPayload('12345', 'short@test.dev'))->assertStatus(422);
        $this->postJson('/api/register', $this->regPayload('12345678901', 'long@test.dev'))->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'short@test.dev']);
        $this->assertDatabaseMissing('users', ['email' => 'long@test.dev']);
    }

    public function test_non_numeric_rejected(): void
    {
        $this->postJson('/api/register', $this->regPayload('ABCDE12345', 'nan@test.dev'))->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'nan@test.dev']);
    }

    public function test_candidate_a_number_does_not_create_candidate_b(): void
    {
        $a = $this->candidate('Anak A', 'SD');
        $b = $this->candidate('Anak B', 'SMP');
        $this->postJson('/api/register', $this->regPayload($a->registration_number, 'a@test.dev', ['name' => 'Attacker']))
            ->assertStatus(200);
        $this->assertDatabaseHas('users', ['email' => 'a@test.dev', 'jenjang' => 'SD']);
        $this->assertFalse((bool) $b->fresh()->is_used);
        $this->assertSame('Anak B', $b->fresh()->nama_siswa);
    }

    public function test_client_name_does_not_override_candidate_identity(): void
    {
        $c = $this->candidate('Anak Nama', 'SD');
        $this->postJson('/api/register', $this->regPayload($c->registration_number, 'n@test.dev', ['name' => 'Attacker Name']))
            ->assertStatus(200);
        $this->assertSame('Anak Nama', $c->fresh()->nama_siswa);
    }

    public function test_client_jenjang_spoof_is_ignored(): void
    {
        $c = $this->candidate('Anak Jenjang', 'SMP');
        $this->postJson('/api/register', $this->regPayload($c->registration_number, 'spoofj@test.dev', ['jenjang' => 'SD']))
            ->assertStatus(200);
        $this->assertDatabaseHas('users', ['email' => 'spoofj@test.dev', 'jenjang' => 'SMP']);
    }

    public function test_client_jalur_cannot_override_internal(): void
    {
        $c = $this->candidate('Anak Jalur', 'SD');
        $this->postJson('/api/register', $this->regPayload($c->registration_number, 'j@test.dev', ['jalur' => 'INTERNAL']))
            ->assertStatus(200);
        $this->assertDatabaseHas('users', ['email' => 'j@test.dev', 'jalur' => 'INTERNAL']);
    }

    public function test_candidate_with_null_jenjang_cannot_register_and_number_not_consumed(): void
    {
        $c = Whitelist::create([
            'nama_siswa' => 'Legacy', 'jenjang' => null,
            'asal_sekolah' => 'Unknown School', 'registration_number' => $this->codes->generateCode(), 'is_used' => false,
        ]);
        $this->postJson('/api/register', $this->regPayload($c->registration_number, 'nullj@test.dev'))
            ->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'nullj@test.dev']);
        $this->assertFalse((bool) $c->fresh()->is_used);
    }

    public function test_used_number_cannot_register_again(): void
    {
        $c = $this->candidate('Anak Pakai', 'SD');
        $this->postJson('/api/register', $this->regPayload($c->registration_number, 'first@test.dev'))->assertStatus(200);
        $this->postJson('/api/register', $this->regPayload($c->registration_number, 'second@test.dev'))
            ->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'second@test.dev']);
    }

    // === Regeneration =====================================================

    public function test_regenerate_unused_number_invalidates_old(): void
    {
        $c = $this->candidate('Anak Regen', 'SD');
        $old = $c->registration_number;
        $new = $this->codes->regenerate($c)->registration_number;
        $this->assertNotSame($old, $new);

        $this->postJson('/api/register', $this->regPayload($old, 'oldnum@test.dev'))->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'oldnum@test.dev']);
        $this->postJson('/api/register', $this->regPayload($new, 'newnum@test.dev'))->assertStatus(200);
    }

    public function test_regenerate_used_candidate_is_rejected(): void
    {
        $c = $this->candidate('Anak Kunci', 'SD');
        $this->postJson('/api/register', $this->regPayload($c->registration_number, 'used@test.dev'))->assertStatus(200);

        try {
            $this->codes->regenerate($c->fresh());
            $this->fail('Expected RuntimeException for used candidate regeneration.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sudah mendaftar', $e->getMessage());
        }
        $this->assertSame($c->registration_number, $c->fresh()->registration_number);
    }

    public function test_admin_generate_code_endpoint_for_used_candidate_returns_403(): void
    {
        $this->adminAct();
        $c = $this->candidate('Anak 403', 'SD');
        $admin = User::latest('id')->first();
        $c->forceFill(['is_used' => true, 'user_id' => $admin->id])->save();
        $this->postJson("/api/admin/whitelists/{$c->id}/code")->assertStatus(403);
    }

    public function test_admin_generate_code_endpoint_regenerates_unused(): void
    {
        $this->adminAct();
        $c = $this->candidate('Anak Endpoint', 'SD');
        $old = $c->registration_number;
        $res = $this->postJson("/api/admin/whitelists/{$c->id}/code")->assertOk();
        $this->assertNotSame($old, $res->json('registration_number'));
        $this->assertSame($res->json('registration_number'), $c->fresh()->registration_number);
    }

    // === List payload secrecy ============================================

    public function test_whitelist_list_does_not_expose_registration_number_values(): void
    {
        $this->adminAct();
        $c = $this->candidate('Anak Rahasia', 'SD');
        $res = $this->getJson('/api/admin/whitelists')->assertOk();
        $this->assertStringNotContainsString($c->registration_number, $res->getContent());
        $this->assertStringNotContainsString('registration_number', $res->getContent());
    }

    // === Admin create without NISN/NIK ====================================

    public function test_admin_creates_candidate_without_nisn_nik(): void
    {
        $this->adminAct();
        $res = $this->postJson('/api/admin/whitelists', [
            'nama_siswa' => 'Anak Bersih', 'jenjang' => 'SD', 'asal_sekolah' => 'TK Al-Mubarak',
        ])->assertOk();

        $this->assertMatchesRegularExpression('/^\d{10}$/', $res->json('registration_number'));
        $w = Whitelist::where('nama_siswa', 'Anak Bersih')->first();
        $this->assertNotNull($w);
        $this->assertArrayNotHasKey('nisn', $w->getAttributes());
        $this->assertArrayNotHasKey('nik', $w->getAttributes());
    }

    // === CSV import ========================================================

    public function test_csv_import_without_nisn_nik_works(): void
    {
        $this->adminAct();
        $csv = "NAMA,JENJANG,ASAL\nAnak Import,SD,TK Al-Mubarak\nAnak Import2,SMP,\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('import.csv', $csv);
        $this->postJson('/api/admin/whitelists/import', ['file' => $file])->assertOk();

        $a = Whitelist::where('nama_siswa', 'Anak Import')->first();
        $b = Whitelist::where('nama_siswa', 'Anak Import2')->first();
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertMatchesRegularExpression('/^\d{10}$/', $a->registration_number);
        $this->assertMatchesRegularExpression('/^\d{10}$/', $b->registration_number);
        $this->assertNotSame($a->registration_number, $b->registration_number);
        $this->assertSame('SD', $a->jenjang);
        $this->assertSame('SMP', $b->jenjang);
    }

    public function test_csv_duplicate_does_not_mutate_existing_candidate(): void
    {
        $this->adminAct();
        $existing = $this->candidate('Anak Dup', 'SD');
        $origNumber = $existing->registration_number;

        $csv = "NAMA,JENJANG,ASAL\nAnak Dup,SD,TK Lain\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('import.csv', $csv);
        $this->postJson('/api/admin/whitelists/import', ['file' => $file])->assertOk();

        $fresh = $existing->fresh();
        $this->assertSame('SD', $fresh->jenjang);
        $this->assertSame('Anak Dup', $fresh->nama_siswa);
        $this->assertSame($origNumber, $fresh->registration_number);
        $this->assertSame(1, Whitelist::where('nama_siswa', 'Anak Dup')->where('jenjang', 'SD')->count());
    }

    public function test_import_rejects_unrecognized_jenjang(): void
    {
        $this->adminAct();
        $csv = "NAMA,JENJANG,ASAL\nAnak Baru,TK,TK Al-Mubarak\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('import.csv', $csv);
        $this->postJson('/api/admin/whitelists/import', ['file' => $file])->assertOk();
        $this->assertDatabaseMissing('whitelists', ['nama_siswa' => 'Anak Baru']);
    }

    // === Capacity still enforced ==========================================

    public function test_capacity_full_blocks_and_preserves_number(): void
    {
        $this->capacity->increaseMaxCapacity('SD', 0); // SD full
        $c = $this->candidate('Anak Penuh', 'SD');
        $this->postJson('/api/register', $this->regPayload($c->registration_number, 'full@test.dev'))
            ->assertStatus(409);
        $this->assertFalse((bool) $c->fresh()->is_used);
        $this->assertDatabaseMissing('users', ['email' => 'full@test.dev']);
    }

    // === NISN/NIK are not INTERNAL credentials ============================

    public function test_nisn_nik_are_not_internal_credentials(): void
    {
        $c = $this->candidate('Anak Nisn', 'SD');
        // A 10-digit value that is not the issued number must NOT authenticate —
        // there is no NISN/NIK fallback for jalur INTERNAL.
        $fake = '9876543210';
        $this->assertNotSame($fake, $c->registration_number);
        $this->postJson('/api/register', $this->regPayload($fake, 'nisn@test.dev'))->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'nisn@test.dev']);
        $this->assertFalse((bool) $c->fresh()->is_used);
    }
}
