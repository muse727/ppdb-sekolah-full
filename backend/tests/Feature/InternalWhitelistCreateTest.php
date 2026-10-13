<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Whitelist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression: creating an INTERNAL candidate must not 500 when the optional
 * asal_sekolah is omitted (previously SQLSTATE 1048 on a NOT NULL column).
 */
class InternalWhitelistCreateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['role' => 'admin'])->save();

        return $u->refresh();
    }

    public function test_admin_creates_internal_candidate_without_asal_sekolah(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        // asal_sekolah intentionally OMITTED (it is optional in the UI).
        $response = $this->postJson('/api/admin/whitelists', [
            'nama_siswa' => 'Asep Nugraha',
            'jenjang'    => 'SD',
        ]);

        $response->assertOk()
                 ->assertJson(['success' => true])
                 ->assertJsonStructure(['registration_number']);

        $number = $response->json('registration_number');
        $this->assertMatchesRegularExpression('/^\d{10}$/', $number, 'registration_number must be 10 digits');

        $this->assertDatabaseCount('whitelists', 1);
        $this->assertDatabaseHas('whitelists', [
            'nama_siswa'          => 'Asep Nugraha',
            'jenjang'             => 'SD',
            'asal_sekolah'        => null,
            'registration_number' => $number,
            'is_used'             => false,
        ]);
    }

    public function test_admin_creates_internal_candidate_with_asal_sekolah(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $response = $this->postJson('/api/admin/whitelists', [
            'nama_siswa'   => 'Bunga Lestari',
            'jenjang'      => 'SMP',
            'asal_sekolah' => 'SDIT Al Mubarak',
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertMatchesRegularExpression('/^\d{10}$/', $response->json('registration_number'));
        $this->assertDatabaseHas('whitelists', [
            'nama_siswa'   => 'Bunga Lestari',
            'jenjang'      => 'SMP',
            'asal_sekolah' => 'SDIT Al Mubarak',
        ]);
    }

    public function test_no_nisn_or_nik_is_required(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        // Payload contains ONLY the three display fields — no legacy credentials.
        $response = $this->postJson('/api/admin/whitelists', [
            'nama_siswa' => 'Citra',
            'jenjang'    => 'SD',
            'asal_sekolah' => 'TK Contoh',
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('whitelists', ['nama_siswa' => 'Citra', 'asal_sekolah' => null]);
    }

    public function test_registration_number_is_unique_per_candidate(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $n1 = $this->postJson('/api/admin/whitelists', ['nama_siswa' => 'A', 'jenjang' => 'SD'])->json('registration_number');
        $n2 = $this->postJson('/api/admin/whitelists', ['nama_siswa' => 'B', 'jenjang' => 'SMP'])->json('registration_number');

        $this->assertNotSame($n1, $n2);
        $this->assertDatabaseCount('whitelists', 2);
    }

    public function test_invalid_input_is_rejected(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        // Missing nama_siswa
        $this->postJson('/api/admin/whitelists', ['jenjang' => 'SD'])->assertStatus(422);
        // Bad jenjang
        $this->postJson('/api/admin/whitelists', ['nama_siswa' => 'X', 'jenjang' => 'TK'])->assertStatus(422);

        $this->assertDatabaseCount('whitelists', 0);
    }
}
