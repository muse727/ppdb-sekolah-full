<?php

namespace Tests\Feature;

use App\Models\Biodata;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Digit rules for identity fields on POST /api/biodata must match the frontend:
 * nisn optional (nullable) but exactly 10 ASCII digits when filled;
 * nik_siswa / no_kk / nik_ibu exactly 16 digits and required per product rules.
 */
class BiodataDigitValidationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'jalur_pendaftaran' => 'UMUM',
            'asal_sekolah' => 'TK Contoh', 'nama_lengkap' => 'Calon Siswa',
            'jenis_kelamin' => 'L', 'nisn' => null,
            'nik_siswa' => '1234567890123456',
            'no_kk' => '1234567890123456', 'tempat_lahir' => 'Jakarta',
            'tanggal_lahir' => '2020-01-01', 'anak_ke' => 1,
            'status_peserta_didik' => 'Lengkap', 'nama_ibu' => 'Ibu',
            'nik_ibu' => '1234567890123456', 'pendidikan_ibu' => 'S1',
            'pekerjaan_ibu' => 'Guru', 'penghasilan_ibu' => 'Rp 5.000.000',
            'nama_ayah' => 'Ayah', 'nik_ayah' => '1234567890123456',
            'pendidikan_ayah' => 'S1', 'pekerjaan_ayah' => 'Guru', 'penghasilan_ayah' => 'Rp 5.000.000',
            'alamat_lengkap' => 'Jl Contoh', 'kota' => 'Jakarta Selatan',
            'kecamatan' => 'Cilandak', 'kelurahan' => 'Lebak Bulus',
            'rt' => '001', 'rw' => '002', 'no_hp_ortu' => '081234567890',
        ], $overrides);
    }

    private function parent(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'orang_tua'])->save();
        return $user;
    }

    private function biodataSnapshot(): string
    {
        return DB::table('biodatas')->orderBy('id')->get()->toJson();
    }

    public function test_nisn_is_optional_and_empty_is_accepted(): void
    {
        Sanctum::actingAs($this->parent(), ['*']);
        $this->postJson('/api/biodata', $this->payload(['nisn' => null]))->assertOk();
        $this->postJson('/api/biodata', $this->payload(['nisn' => '']))->assertOk();
        $this->assertDatabaseHas('biodatas', ['nisn' => null]);
    }

    public function test_nisn_wrong_length_or_non_digits_is_rejected(): void
    {
        Sanctum::actingAs($this->parent(), ['*']);
        foreach (['123456789' => 9, '12345678901' => 11, '12a4567890' => 'letters', '12345-678' => 'symbols'] as $value => $case) {
            $response = $this->postJson('/api/biodata', $this->payload(['nisn' => $value]));
            $response->assertUnprocessable()->assertJsonValidationErrors('nisn');
            $this->assertDatabaseMissing('biodatas', ['nisn' => $value]);
            unset($case);
        }
    }

    public function test_nisn_exactly_ten_digits_is_accepted_and_kept_as_string(): void
    {
        Sanctum::actingAs($this->parent(), ['*']);
        // Leading zero must survive storage (string column, never cast to int).
        $this->postJson('/api/biodata', $this->payload(['nisn' => '0123456789']))->assertOk();
        $this->assertDatabaseHas('biodatas', ['nisn' => '0123456789']);
    }

    public function test_nik_siswa_required_and_exactly_sixteen_digits(): void
    {
        Sanctum::actingAs($this->parent(), ['*']);

        $this->postJson('/api/biodata', $this->payload(['nik_siswa' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('nik_siswa');
        $this->postJson('/api/biodata', $this->payload(['nik_siswa' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors('nik_siswa');

        foreach (['123456789012345' => 15, '12345678901234567' => 17, '12345678901234ab' => 'letters', '1234567890 12345' => 'space'] as $value) {
            $this->postJson('/api/biodata', $this->payload(['nik_siswa' => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors('nik_siswa');
            $this->assertDatabaseMissing('biodatas', ['nik_siswa' => $value]);
        }

        $this->postJson('/api/biodata', $this->payload(['nik_siswa' => '0034567890123456']))->assertOk();
        $this->assertDatabaseHas('biodatas', ['nik_siswa' => '0034567890123456']);
    }

    public function test_other_optional_identity_fields_with_bad_format_are_rejected(): void
    {
        Sanctum::actingAs($this->parent(), ['*']);
        // nik_ayah kini wajib (E.1): kosong ditolak dengan 422, bukan 500 SQL.
        $this->postJson('/api/biodata', $this->payload(['nik_ayah' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('nik_ayah');
        $this->postJson('/api/biodata', $this->payload(['nik_ayah' => '12345']))
            ->assertUnprocessable()->assertJsonValidationErrors('nik_ayah');
        $this->postJson('/api/biodata', $this->payload(['email_siswa' => 'bukan-email']))
            ->assertUnprocessable()->assertJsonValidationErrors('email_siswa');
    }

    public function test_optional_nik_ayah_filled_with_valid_value_is_accepted(): void
    {
        Sanctum::actingAs($this->parent(), ['*']);
        $this->postJson('/api/biodata', $this->payload(['nik_ayah' => '0012345678901234']))->assertOk();
        $this->assertDatabaseHas('biodatas', ['nik_ayah' => '0012345678901234']);
    }

    /**
     * E.1 — nullability consistency: every ayah field is required by the form
     * labels and NOT NULL columns; an empty value must produce a 422 field
     * error BEFORE any database write, never a 500 SQL integrity error.
     */
    public function test_empty_ayah_fields_return_422_not_500(): void
    {
        Sanctum::actingAs($this->parent(), ['*']);
        foreach (['nama_ayah', 'nik_ayah', 'pendidikan_ayah', 'pekerjaan_ayah', 'penghasilan_ayah'] as $field) {
            $response = $this->postJson('/api/biodata', $this->payload([$field => '']));
            $response->assertStatus(422)->assertJsonValidationErrors($field);
            $this->assertSame(0, Biodata::count(), "Row must not persist when {$field} is empty.");
        }
    }

    public function test_optional_fields_accept_empty_values(): void
    {
        Sanctum::actingAs($this->parent(), ['*']);
        // Kolom nullable + aturan nullable: boleh kosong (nama_panggilan,
        // email_siswa, nama_wali, no_hp_wali). jalur_pendaftaran kini WAJIB.
        $this->postJson('/api/biodata', $this->payload([
            'nama_panggilan' => '', 'email_siswa' => '',
            'nama_wali' => '', 'no_hp_wali' => '',
        ]))->assertOk();
        $this->assertDatabaseHas('biodatas', ['nama_wali' => null]);
    }

    /** E.2: jalur_pendaftaran wajib untuk pendaftar baru. */
    public function test_jalur_pendaftaran_is_required_and_must_be_known_value(): void
    {
        Sanctum::actingAs($this->parent(), ['*']);
        // Payload tanpa jalur → 422.
        $missing = $this->payload();
        unset($missing['jalur_pendaftaran']);
        $this->postJson('/api/biodata', $missing)
            ->assertUnprocessable()->assertJsonValidationErrors('jalur_pendaftaran');
        $this->postJson('/api/biodata', $this->payload(['jalur_pendaftaran' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors('jalur_pendaftaran');
        $this->postJson('/api/biodata', $this->payload(['jalur_pendaftaran' => 'TIDAK_ADA']))
            ->assertUnprocessable()->assertJsonValidationErrors('jalur_pendaftaran');
        $this->assertSame(0, Biodata::count());
        $this->postJson('/api/biodata', $this->payload(['jalur_pendaftaran' => 'UMUM']))
            ->assertOk();
        $this->assertDatabaseHas('biodatas', ['jalur_pendaftaran' => 'UMUM']);
    }

    public function test_invalid_request_does_not_mutate_existing_row(): void
    {
        $user = $this->parent();
        Sanctum::actingAs($user, ['*']);
        $this->postJson('/api/biodata', $this->payload(['nisn' => '0123456789']))->assertOk();
        $before = $this->biodataSnapshot();

        $this->postJson('/api/biodata', $this->payload(['nisn' => '12345', 'nik_siswa' => '123']))
            ->assertUnprocessable();

        $this->assertSame($before, $this->biodataSnapshot());
    }

    public function test_user_cannot_write_biodata_owned_by_another_account(): void
    {
        $owner = $this->parent();
        Sanctum::actingAs($owner, ['*']);
        $this->postJson('/api/biodata', $this->payload())->assertOk();
        $ownerRow = Biodata::where('user_id', $owner->id)->firstOrFail();

        $intruder = $this->parent();
        Sanctum::actingAs($intruder, ['*']);
        // Even injecting the owner's user_id, the write must bind to the
        // authenticated user (Auth::id()), never to a client-supplied id.
        $this->postJson('/api/biodata', $this->payload(['user_id' => $owner->id, 'id' => $ownerRow->id]))
            ->assertOk();

        $this->assertSame(2, Biodata::count());
        $this->assertDatabaseHas('biodatas', ['user_id' => $intruder->id]);
        $this->assertDatabaseHas('biodatas', ['user_id' => $owner->id, 'nik_siswa' => '1234567890123456']);
        // GET only ever returns the caller's own row.
        $this->getJson('/api/biodata')->assertOk()->assertJsonPath('user_id', $intruder->id);
    }

    public function test_administrative_and_payment_fields_cannot_be_mutated_via_biodata_endpoint(): void
    {
        $user = $this->parent();
        Sanctum::actingAs($user, ['*']);
        $usersBefore = DB::table('users')->orderBy('id')->get()->toJson();
        $paymentsBefore = DB::table('payments')->get()->toJson();

        $this->postJson('/api/biodata', $this->payload([
            'status_kelulusan' => 'LULUS',
            'status_siswa' => 'MENGUNDURKAN_DIRI',
            'role' => 'admin',
            'jenjang' => 'SMP',
            'amount' => 1,
            'status' => 'SUCCESS',
            'harga' => 1,
            'quota' => 999,
        ]))->assertOk();

        // Whitelisted biodata columns only: none of the injected administrative
        // fields exist on biodatas (status_kelulusan etc. live on `users` and
        // payments), and no other table was touched.
        $this->assertSame($usersBefore, DB::table('users')->orderBy('id')->get()->toJson());
        $this->assertSame($paymentsBefore, DB::table('payments')->get()->toJson());
        $fresh = $user->fresh();
        $this->assertSame('orang_tua', $fresh->role);
        $this->assertSame('BELUM', $fresh->status_kelulusan);
    }
}
