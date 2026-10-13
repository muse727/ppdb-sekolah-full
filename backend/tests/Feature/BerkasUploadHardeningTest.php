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
 * E.3 — upload berkas hardening:
 *  - status jujur MENUNGGU (server tidak memverifikasi isi dokumen; OCR hanya
 *    advisory di browser dan mudah di-bypass dengan POST langsung);
 *  - penggantian berkas menghapus file lama dari private disk (anti-orphan);
 *  - ownership dari sesi server-side; extra payload administratif diabaikan;
 *  - serve endpoint terbatas owner/admin; file disimpan di private disk.
 */
class BerkasUploadHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function parent(): User
    {
        $user = User::factory()->create(['jenjang' => 'SD']);
        $user->forceFill(['role' => 'orang_tua'])->save();
        return $user->refresh();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();
        return $user->refresh();
    }

    public function test_upload_stores_pending_status_not_verified(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        Sanctum::actingAs($user);

        $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file'  => UploadedFile::fake()->image('kk.png', 64, 64),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $berkas = Berkas::where('user_id', $user->id)->firstOrFail();
        // E.5: upload that passes backend technical validation is accepted
        // automatically — LOLOS_TEKNIS (technical pass, not authenticity).
        $this->assertSame('LOLOS_TEKNIS', $berkas->status);
        $this->assertNotNull($berkas->kartu_keluarga);
        // Stored on the private disk with a server-generated hashed name.
        Storage::disk('private')->assertExists($berkas->kartu_keluarga);
    }

    public function test_replacing_file_deletes_previous_file_and_keeps_one(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        Sanctum::actingAs($user);

        $this->post('/api/berkas/upload', [
            'jenis' => 'akte_kelahiran',
            'file'  => UploadedFile::fake()->image('akte1.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertOk();
        $firstPath = Berkas::where('user_id', $user->id)->firstOrFail()->akte_kelahiran;
        Storage::disk('private')->assertExists($firstPath);

        $this->post('/api/berkas/upload', [
            'jenis' => 'akte_kelahiran',
            'file'  => UploadedFile::fake()->image('akte2.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertOk();

        // Old file removed (anti-orphan), new file exists, DB points to the new one.
        Storage::disk('private')->assertMissing($firstPath);
        $secondPath = Berkas::where('user_id', $user->id)->firstOrFail()->akte_kelahiran;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('private')->assertExists($secondPath);
        $this->assertSame(1, Berkas::where('user_id', $user->id)->count());
    }

    public function test_replacing_one_jenis_keeps_the_other_jenis_file(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        Sanctum::actingAs($user);

        $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga', 'file' => UploadedFile::fake()->image('kk.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->post('/api/berkas/upload', [
            'jenis' => 'akte_kelahiran', 'file' => UploadedFile::fake()->image('ak.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertOk();

        $kkPath = Berkas::where('user_id', $user->id)->firstOrFail()->kartu_keluarga;
        $this->post('/api/berkas/upload', [
            'jenis' => 'akte_kelahiran', 'file' => UploadedFile::fake()->image('ak2.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertOk();

        // Replacing akte must not touch the kartu_keluarga file.
        Storage::disk('private')->assertExists($kkPath);
        $this->assertSame($kkPath, Berkas::where('user_id', $user->id)->firstOrFail()->kartu_keluarga);
    }

    public function test_invalid_jenis_and_extensions_are_rejected_without_writes(): void
    {
        Storage::fake('private');
        Sanctum::actingAs($this->parent());

        $this->post('/api/berkas/upload', [
            'jenis' => 'paspor', 'file' => UploadedFile::fake()->image('x.png', 8, 8),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        foreach (['exe', 'html', 'pdf', 'svg'] as $ext) {
            $this->post('/api/berkas/upload', [
                'jenis' => 'kartu_keluarga',
                // Disguise as png content-type; the extension/MIME allowlist rejects it.
                'file' => UploadedFile::fake()->create("doc.{$ext}", 20, $ext === 'html' ? 'text/html' : 'application/octet-stream'),
            ], ['Accept' => 'application/json'])->assertUnprocessable();
        }

        $this->assertSame(0, Berkas::count());
        $this->assertCount(0, Storage::disk('private')->allFiles());
    }

    public function test_ownership_from_session_and_extra_administrative_payload_ignored(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        Sanctum::actingAs($user);

        $before = $user->getAttributes();
        $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file'  => UploadedFile::fake()->image('kk.png', 8, 8),
            // Mass-assignment / privilege escalation attempts.
            'user_id' => 999999,
            'status' => 'TERVERIFIKASI',
            'role' => 'admin',
            'status_kelulusan' => 'LULUS',
            'status_siswa' => 'AKTIF',
        ], ['Accept' => 'application/json'])->assertOk();

        // Record bound to the authenticated user, not the client-supplied id.
        $berkas = Berkas::firstOrFail();
        $this->assertSame($user->id, $berkas->user_id);
        // Server-side status still LOLOS_TEKNIS despite the injected payload.
        $this->assertSame('LOLOS_TEKNIS', $berkas->status);
        // No user privilege/status mutation.
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_serve_berkas_requires_auth_and_owner_or_admin(): void
    {
        Storage::fake('private');
        $owner = $this->parent();
        Sanctum::actingAs($owner);
        $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file'  => UploadedFile::fake()->image('kk.png', 32, 32),
        ], ['Accept' => 'application/json'])->assertOk();
        $path = Berkas::where('user_id', $owner->id)->firstOrFail()->kartu_keluarga;
        $url = "/api/berkas/file/{$owner->id}/kartu_keluarga";

        // Unauthenticated: 401 (clear the actingAs user set for the upload).
        app('auth')->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
        // Unknown jenis: 404 even for the owner.
        Sanctum::actingAs($owner);
        $this->getJson("/api/berkas/file/{$owner->id}/paspor")->assertNotFound();

        // Another parent: 403, and never reads the file.
        $intruder = $this->parent();
        Sanctum::actingAs($intruder);
        $this->getJson($url)->assertForbidden();

        // Owner and admin both stream the private file.
        Sanctum::actingAs($owner);
        $this->get($url)->assertOk();
        Sanctum::actingAs($this->admin());
        $this->get($url)->assertOk();
        // Path traversal via jenis is impossible (strict allowlist).
        $this->getJson('/api/berkas/file/' . $owner->id . '/..%2F..%2Fenv')->assertNotFound();
        $this->assertNotNull($path);
    }

    public function test_get_berkas_returns_only_own_record(): void
    {
        Storage::fake('private');
        $owner = $this->parent();
        Sanctum::actingAs($owner);
        $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file'  => UploadedFile::fake()->image('kk.png', 8, 8),
        ], ['Accept' => 'application/json'])->assertOk();

        // A different parent sees no record (null), never the owner's.
        $other = $this->parent();
        Sanctum::actingAs($other);
        $response = $this->getJson('/api/berkas')->assertOk();
        $this->assertNull($response->json('user_id'));
        $this->assertNull($response->json('kartu_keluarga'));
    }
}

