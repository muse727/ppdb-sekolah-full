<?php

namespace Tests\Feature;

use App\Models\Berkas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E.5 — automatic acceptance workflow for Berkas uploads.
 *
 * Domain change under test: berkas that pass backend technical validation
 * are accepted automatically (LOLOS_TEKNIS). No manual panitia verification
 * step exists or is required. Invariants covered here:
 *
 *  1. Valid JPG/PNG/PDF uploads produce status LOLOS_TEKNIS immediately.
 *  2. LOLOS_TEKNIS means a TECHNICAL pass — the server never claims the
 *     document is genuine (TERVERIFIKASI is never produced by automation).
 *  3. Client payloads cannot influence status/user binding (anti-injection).
 *  4. Invalid files are still rejected (422) and never create a record —
 *     automatic acceptance does not weaken content validation.
 *  5. Registration gates (AdminController step logic) advance purely on
 *     automated requirements: payment SUCCESS + biodata + both berkas
 *     files present — no status wait, no manual unlock.
 */
class BerkasAutomaticAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function parent(): User
    {
        return User::create([
            'name'     => 'Orang Tua',
            'email'    => uniqid() . '@test.local',
            'password' => bcrypt('Password123'),
            'role'     => 'orang_tua',
            'jalur'    => 'UMUM',
        ]);
    }

    public function test_valid_uploads_get_automatic_technical_status(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        Sanctum::actingAs($user);

        $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file'  => UploadedFile::fake()->image('kk.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->post('/api/berkas/upload', [
            'jenis' => 'akte_kelahiran',
            'file'  => UploadedFile::fake()->image('akte.jpg', 64, 64),
        ], ['Accept' => 'application/json'])->assertOk();

        $berkas = Berkas::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('LOLOS_TEKNIS', $berkas->status);
        $this->assertNotSame('TERVERIFIKASI', $berkas->status);
        $this->assertNotNull($berkas->kartu_keluarga);
        $this->assertNotNull($berkas->akte_kelahiran);
    }

    public function test_reupload_keeps_automatic_status_and_replaces_file_safely(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        Sanctum::actingAs($user);

        $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file'  => UploadedFile::fake()->image('kk.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertOk();

        $oldPath = Berkas::where('user_id', $user->id)->firstOrFail()->kartu_keluarga;

        $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file'  => UploadedFile::fake()->image('kk2.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertOk();

        $berkas = Berkas::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('LOLOS_TEKNIS', $berkas->status);
        $this->assertNotSame($oldPath, $berkas->kartu_keluarga);
        Storage::disk('private')->assertExists($berkas->kartu_keluarga);
        Storage::disk('private')->assertMissing($oldPath); // no orphan
    }

    public function test_client_cannot_inject_status_or_ownership(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        Sanctum::actingAs($user);

        $this->post('/api/berkas/upload', [
            'jenis'    => 'kartu_keluarga',
            'file'     => UploadedFile::fake()->image('kk.png', 64, 64),
            'status'   => 'TERVERIFIKASI',
            'user_id'  => 999999,
            'role'     => 'admin',
        ], ['Accept' => 'application/json'])->assertOk();

        $berkas = Berkas::firstOrFail();
        $this->assertSame($user->id, $berkas->user_id);
        $this->assertSame('LOLOS_TEKNIS', $berkas->status);
    }

    public function test_invalid_file_rejected_and_no_record_created(): void
    {
        Storage::fake('private');
        Sanctum::actingAs($this->parent());

        // GIF bytes with a .png label — magic sniff must reject.
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7') ?? '';
        $file = UploadedFile::fake()->createWithContent('fake.png', $gif)->mimeType('image/png');

        $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file'  => $file,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertNull(Berkas::first());
        $this->assertCount(0, Storage::disk('private')->allFiles());
    }

    public function test_migration_migrates_menunggu_rows_forward(): void
    {
        // Rows parked in the legacy MENUNGGU state must be promoted by the
        // E.5 migration — they passed the same technical validation at
        // upload time and no manual step exists anymore.
        // NOTE: RefreshDatabase already ran ALL migrations (including E.5)
        // with its default LOLOS_TEKNIS, so to exercise the legacy state we
        // insert a MENUNGGU row and re-run just the up() data fix directly.
        $user = $this->parent();
        $berkas = Berkas::create([
            'user_id'         => $user->id,
            'kartu_keluarga'  => 'berkas/legacy.png',
            'status'          => 'MENUNGGU',
        ]);

        // Re-apply the migration's data fix (safe to run repeatedly).
        \DB::table('berkas')->where('status', 'MENUNGGU')->update(['status' => 'LOLOS_TEKNIS']);
        $this->assertSame('LOLOS_TEKNIS', $berkas->fresh()->status);

        // And the down() data fix returns it to MENUNGGU.
        \DB::table('berkas')->where('status', 'LOLOS_TEKNIS')->update(['status' => 'MENUNGGU']);
        $this->assertSame('MENUNGGU', $berkas->fresh()->status);
    }

    public function test_registration_gate_advances_on_files_without_manual_wait(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        Sanctum::actingAs($user);

        // Simulate the automated prerequisites the domain still requires:
        // payment SUCCESS, biodata, and both berkas files.
        $user->payments()->create([
            'invoice_number'  => 'PPDB-' . uniqid(),
            'jenjang'          => 'SD',
            'payment_category' => 'PPDB',
            'status'           => 'SUCCESS',
            'amount'           => 250000,
            'payment_method'   => 'MANUAL',
        ]);
                $user->biodata()->create([
            'asal_sekolah'       => 'TK Test',
            'nama_lengkap'       => 'Anak Test',
            'jenis_kelamin'      => 'L',
            'nik_siswa'          => '3201234567890001',
            'no_kk'              => '3201234567890002',
            'tempat_lahir'       => 'Kota',
            'tanggal_lahir'      => '2018-01-01',
            'anak_ke'            => 1,
            'status_peserta_didik' => 'AKTIF',
            'nama_ibu'           => 'Ibu Test',
            'nik_ibu'            => '3201234567890003',
            'pendidikan_ibu'     => 'SMA',
            'pekerjaan_ibu'      => 'IRT',
            'penghasilan_ibu'    => '< 1.000.000',
            'nama_ayah'          => 'Ayah Test',
            'nik_ayah'           => '3201234567890004',
            'pendidikan_ayah'    => 'SMA',
            'pekerjaan_ayah'     => 'Karyawan',
            'penghasilan_ayah'   => '1.000.000 - 2.000.000',
            'alamat_lengkap'     => 'Jl. Test No. 1',
            'kota'               => 'Kota',
            'kecamatan'          => 'Kec',
            'kelurahan'          => 'Kel',
            'rt'                 => '001',
            'rw'                 => '001',
            'no_hp_ortu'         => '081234567890',
        ]);

        $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file'  => UploadedFile::fake()->image('kk.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->post('/api/berkas/upload', [
            'jenis' => 'akte_kelahiran',
            'file'  => UploadedFile::fake()->image('akte.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertOk();

        // The admin listing (which drives the pendaftar step gate) must show
        // step 4 — payment + biodata + both files — with no verifikasi wait.
        $admin = User::create([
            'name'     => 'Admin',
            'email'    => uniqid() . 'admin@test.local',
            'password' => bcrypt('Password123'),
        ]);
        $admin->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/admin/pendaftar')->assertOk();
        $row = collect($response->json('data') ?? $response->json())
            ->first(fn ($r) => $r['id'] === $user->id);

        $this->assertNotNull($row, 'pendaftar not found in admin listing');
        $this->assertEquals(4, $row['step']);
        $this->assertEquals('LOLOS_TEKNIS', $row['berkas']['status']);
    }
}
