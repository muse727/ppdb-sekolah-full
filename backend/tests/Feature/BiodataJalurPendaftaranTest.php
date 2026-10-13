<?php

namespace Tests\Feature;

use App\Models\Biodata;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BiodataJalurPendaftaranTest extends TestCase
{
    use RefreshDatabase;

    private function payload(?string $jalur = 'YAYASAN'): array
    {
        return [
            'jalur_pendaftaran' => $jalur,
            'asal_sekolah' => 'TK Contoh', 'nama_lengkap' => 'Calon Siswa',
            'jenis_kelamin' => 'L', 'nik_siswa' => '1234567890123456',
            'no_kk' => '1234567890123456', 'tempat_lahir' => 'Jakarta',
            'tanggal_lahir' => '2020-01-01', 'anak_ke' => 1,
            'status_peserta_didik' => 'Lengkap', 'nama_ibu' => 'Ibu',
            'nik_ibu' => '1234567890123456', 'pendidikan_ibu' => 'S1',
            'pekerjaan_ibu' => 'Guru', 'penghasilan_ibu' => 'Rp 5.000.000',
            'nama_ayah' => 'Ayah', 'nik_ayah' => '1234567890123456',
            'pendidikan_ayah' => 'S1', 'pekerjaan_ayah' => 'Guru',
            'penghasilan_ayah' => 'Rp 5.000.000',
            'alamat_lengkap' => 'Jl Contoh', 'kota' => 'Jakarta',
            'kecamatan' => 'Cilandak', 'kelurahan' => 'Lebak Bulus',
            'rt' => '001', 'rw' => '002', 'no_hp_ortu' => '081234567890',
        ];
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['users', 'tokens', 'whitelists', 'smp_email_tokens', 'ppdb_capacities', 'ppdb_seat_allocations', 'payments'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy($table === 'ppdb_capacities' ? 'jenjang' : 'id')->get()->toJson();
        }
        return $snapshot;
    }

    public function test_all_choices_can_be_saved_read_and_edited_without_changing_account_or_business_data(): void
    {
        foreach (['INTERNAL', 'UMUM', 'SMP_EMAIL'] as $accountJalur) {
            $user = User::factory()->create(['jalur' => $accountJalur]);
            $user->forceFill(['role' => 'orang_tua'])->save();
            Sanctum::actingAs($user, ['*']);
            $before = $this->snapshot();

            foreach (Biodata::JALUR_PENDAFTARAN as $jalur) {
                $this->postJson('/api/biodata', array_merge($this->payload($jalur), [
                    'jalur' => 'INTERNAL', 'password' => 'Changed123', 'role' => 'admin',
                    'jenjang' => 'SMP', 'kredensial' => 'fake', 'user_id' => 999999,
                ]))->assertOk();
                $this->getJson('/api/biodata')->assertOk()->assertJsonPath('jalur_pendaftaran', $jalur);
                $this->assertDatabaseHas('biodatas', ['user_id' => $user->id, 'jalur_pendaftaran' => $jalur]);
                $this->assertSame($before, $this->snapshot());
                $this->assertSame($accountJalur, $user->fresh()->jalur);
            }
            $this->assertSame(1, Biodata::where('user_id', $user->id)->count());
        }
    }

    public function test_invalid_administrative_routes_are_rejected_without_writes(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        foreach (['INTERNAL', 'SMP_EMAIL', 'INVALID'] as $jalur) {
            $this->postJson('/api/biodata', $this->payload($jalur))
                ->assertUnprocessable()->assertJsonValidationErrors('jalur_pendaftaran');
        }
        $this->assertDatabaseCount('biodatas', 0);
    }

    public function test_legacy_payload_without_field_is_now_rejected_and_does_not_clear_existing_choice(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        // E.2: field wajib — payload tanpa jalur ditolak 422 dan tidak
        // mengubah pilihan yang sudah tersimpan.
        $this->postJson('/api/biodata', $this->payload())->assertOk();
        $legacy = $this->payload();
        unset($legacy['jalur_pendaftaran']);
        $this->postJson('/api/biodata', $legacy)
            ->assertUnprocessable()->assertJsonValidationErrors('jalur_pendaftaran');
        $this->getJson('/api/biodata')->assertOk()->assertJsonPath('jalur_pendaftaran', 'YAYASAN');
    }

    public function test_admin_filters_biodata_not_account_route_and_never_changes_data(): void
    {
        $ids = [];
        foreach (Biodata::JALUR_PENDAFTARAN as $jalur) {
            $user = User::factory()->create(['jalur' => 'UMUM']);
            $user->forceFill(['role' => 'orang_tua'])->save();
            Biodata::create(array_merge($this->payload($jalur), ['user_id' => $user->id]));
            $ids[$jalur] = $user->id;
        }
        $legacy = User::factory()->create(['jalur' => 'INTERNAL']);
        $legacy->forceFill(['role' => 'orang_tua'])->save();
        Biodata::create(array_merge($this->payload(null), ['user_id' => $legacy->id]));
        $unfinished = User::factory()->create();
        $unfinished->forceFill(['role' => 'orang_tua'])->save();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($admin, ['*']);
        $before = $this->snapshot();
        $biodataBefore = DB::table('biodatas')->orderBy('id')->get()->toJson();

        foreach ($ids as $jalur => $id) {
            $this->getJson('/api/admin/pendaftar?jalur_pendaftaran='.$jalur)
                ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $id)
                ->assertJsonPath('0.biodata.jalur_pendaftaran', $jalur);
        }
        $this->getJson('/api/admin/pendaftar')->assertOk()->assertJsonCount(6);
        $this->getJson('/api/admin/pendaftar?jalur_pendaftaran=INTERNAL')->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($biodataBefore, DB::table('biodatas')->orderBy('id')->get()->toJson());
    }

    public function test_parent_cannot_use_admin_filter(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'orang_tua'])->save();
        Sanctum::actingAs($user, ['*']);
        $this->getJson('/api/admin/pendaftar?jalur_pendaftaran=UMUM')->assertForbidden();
    }
}
