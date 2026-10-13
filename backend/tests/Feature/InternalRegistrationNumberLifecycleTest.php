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
 * INTERNAL registration-number LIFECYCLE contract.
 *
 * The credential model is: generate exactly once on creation, then keep the
 * number FIXED until an admin explicitly regenerates it.
 *
 *   CREATE -> GENERATE ONCE -> FIXED
 *                              |- COPY       (read-only, no mutation)
 *                              |- EXPORT     (read-only, no mutation)
 *                              '- REGENERATE (the ONLY replacement path)
 *
 * "Salin Nomor" must therefore be backed by a GET that performs zero writes.
 * A previous implementation pointed copy at the regenerate endpoint, which
 * silently invalidated the number the admin was trying to read.
 */
class InternalRegistrationNumberLifecycleTest extends TestCase
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

    // --- helpers ---------------------------------------------------------

    private ?User $admin = null;

    private function adminAct(): void
    {
        if ($this->admin === null) {
            $this->admin = User::factory()->create();
            $this->admin->forceFill(['role' => 'admin'])->save();
        }
        Sanctum::actingAs($this->admin->refresh(), ['*']);
    }

    /**
     * POST /api/register with an INTERNAL credential.
     *
     * WHY THE GUARD IS SWITCHED BACK
     * ------------------------------
     * register() ends with Auth::login($user) so the new parent is signed in.
     * Sanctum::actingAs() has meanwhile made 'sanctum' the DEFAULT guard, and
     * that guard is an Illuminate\Auth\RequestGuard, which has no login()
     * method ("Method RequestGuard::login does not exist"). Register is a
     * public route, so restoring the web guard both fixes that and exercises
     * the endpoint the way an unauthenticated browser actually does.
     */
    private function registerInternal(string $number, string $email): \Illuminate\Testing\TestResponse
    {
        app('auth')->shouldUse('web');

        return $this->postJson('/api/register', [
            'name'           => 'Ortu',
            'email'          => $email,
            'password'       => 'Secret123',
            'jalur'          => 'INTERNAL',
            'kredensial'     => 'ignored',
            'nomor_internal' => $number,
        ]);
    }

    private function parentAct(): void
    {
        $ortu = User::factory()->create();
        $ortu->forceFill(['role' => 'orang_tua'])->save();
        Sanctum::actingAs($ortu->refresh(), ['*']);
    }

    /** Create a candidate through the real HTTP endpoint; returns id + number. */
    private function createCandidate(string $nama = 'Kandidat', string $jenjang = 'SD'): array
    {
        $res = $this->postJson('/api/admin/whitelists', [
            'nama_siswa'   => $nama,
            'jenjang'      => $jenjang,
            'asal_sekolah' => 'TK IT Al-Mubarak',
        ])->assertOk();

        return [
            'id'     => (int) Whitelist::where('nama_siswa', $nama)->value('id'),
            'number' => $res->json('registration_number'),
        ];
    }

    private function numberRoute(int $id): string
    {
        return "/api/admin/whitelists/{$id}/registration-number";
    }

    private function regenerateRoute(int $id): string
    {
        return "/api/admin/whitelists/{$id}/regenerate-number";
    }

    /** Mark a candidate consumed, exactly as register() does. */
    private function markUsed(Whitelist $w, User $u): void
    {
        $w->forceFill(['is_used' => true, 'user_id' => $u->id])->save();
    }

    // === 1. create generates exactly one number ==========================

    public function test_create_generates_exactly_one_number(): void
    {
        $this->adminAct();

        ['id' => $id, 'number' => $number] = $this->createCandidate('Siti Aminah');

        $this->assertMatchesRegularExpression('/^\d{10}$/', $number);
        $this->assertSame($number, Whitelist::find($id)->registration_number);
        $this->assertDatabaseCount('whitelists', 1);
    }

    // === 2-6. COPY is read-only ==========================================

    public function test_repeated_get_returns_same_number(): void
    {
        $this->adminAct();

        ['id' => $id, 'number' => $number] = $this->createCandidate('Budi');

        // (2) repeated GET current number returns the same value
        // (4) copy twice returns the same number
        for ($i = 0; $i < 5; $i++) {
            $this->getJson($this->numberRoute($id))
                 ->assertOk()
                 ->assertJson(['success' => true, 'registration_number' => $number]);
        }

        $this->assertSame($number, Whitelist::find($id)->registration_number);
    }

    public function test_copy_does_not_mutate_the_row(): void
    {
        $this->adminAct();

        ['id' => $id, 'number' => $number] = $this->createCandidate('Rani');

        $before = Whitelist::find($id);
        $beforeState = [
            'registration_number' => $before->registration_number,
            'is_used'             => (int) $before->is_used,
            'user_id'             => $before->user_id,
            'updated_at'          => (string) $before->updated_at,
        ];

        // (3) copy does not mutate number
        $this->getJson($this->numberRoute($id))->assertOk();
        $this->getJson($this->numberRoute($id))->assertOk();

        $after = Whitelist::find($id);

        // (3) number unchanged
        $this->assertSame($beforeState['registration_number'], $after->registration_number);
        $this->assertSame($number, $after->registration_number);
        // (5) is_used unchanged
        $this->assertSame($beforeState['is_used'], (int) $after->is_used);
        $this->assertFalse((bool) $after->is_used);
        // (6) user_id unchanged
        $this->assertSame($beforeState['user_id'], $after->user_id);
        $this->assertNull($after->user_id);
        // timestamps not bumped by a read
        $this->assertSame($beforeState['updated_at'], (string) $after->updated_at);
    }

    public function test_copy_works_for_used_candidate_and_does_not_mutate(): void
    {
        $this->adminAct();

        ['id' => $id, 'number' => $number] = $this->createCandidate('Dewi');
        $user = User::factory()->create();
        $this->markUsed(Whitelist::find($id), $user);

        // Reading a consumed candidate's number stays read-only and succeeds;
        // it must NOT resurrect or re-issue the credential.
        $this->getJson($this->numberRoute($id))
             ->assertOk()
             ->assertJson(['registration_number' => $number]);

        $after = Whitelist::find($id);
        $this->assertSame($number, $after->registration_number);
        $this->assertTrue((bool) $after->is_used);
        $this->assertSame($user->id, (int) $after->user_id);
    }

    public function test_copy_candidate_without_number_is_an_error_not_a_generation(): void
    {
        $this->adminAct();

        // A row that somehow lacks a number must surface an error; the endpoint
        // must never implicitly generate one.
        $w = Whitelist::create([
            'nama_siswa' => 'Tanpa Nomor',
            'jenjang'    => 'SD',
            'registration_number' => null,
            'is_used'    => false,
        ]);

        $this->getJson($this->numberRoute($w->id))->assertStatus(409);
        $this->assertNull(Whitelist::find($w->id)->registration_number);
    }

    // === 7-9. REGENERATE is the only replacement path ====================

    public function test_explicit_regenerate_creates_a_new_number(): void
    {
        $this->adminAct();

        ['id' => $id, 'number' => $old] = $this->createCandidate('Joko');

        $res = $this->postJson($this->regenerateRoute($id))->assertOk();
        $new = $res->json('registration_number');

        // (7) a genuinely different number is issued
        $this->assertMatchesRegularExpression('/^\d{10}$/', $new);
        $this->assertNotSame($old, $new);
        $this->assertSame($new, Whitelist::find($id)->registration_number);
    }

    public function test_old_number_is_invalid_after_regenerate(): void
    {
        $this->adminAct();

        ['id' => $id, 'number' => $old] = $this->createCandidate('Lestari', 'SMP');
        $this->postJson($this->regenerateRoute($id))->assertOk();
        $new = Whitelist::find($id)->registration_number;

        // (8) the OLD number no longer matches any candidate
        $this->assertDatabaseMissing('whitelists', ['registration_number' => $old]);
        $this->assertDatabaseHas('whitelists', ['id' => $id, 'registration_number' => $new]);

        // ...and registration with the stale number is rejected.
        $this->registerInternal($old, 'stale@example.com')->assertStatus(422);

        $this->assertFalse((bool) Whitelist::find($id)->is_used);
    }

    public function test_new_number_is_accepted_after_regenerate(): void
    {
        $this->adminAct();

        ['id' => $id] = $this->createCandidate('Nadia', 'SMP');
        $this->postJson($this->regenerateRoute($id))->assertOk();
        $new = Whitelist::find($id)->registration_number;

        $this->registerInternal($new, 'fresh@example.com')->assertOk();

        $this->assertTrue((bool) Whitelist::find($id)->is_used);
    }

    public function test_regenerate_used_candidate_is_rejected(): void
    {
        $this->adminAct();

        ['id' => $id, 'number' => $number] = $this->createCandidate('Agus');
        $this->markUsed(Whitelist::find($id), User::factory()->create());

        // (9) never silently regenerate a consumed credential
        $this->postJson($this->regenerateRoute($id))->assertStatus(403);
        $this->assertSame($number, Whitelist::find($id)->registration_number);

        // Legacy alias must behave identically.
        $this->postJson("/api/admin/whitelists/{$id}/code")->assertStatus(403);
        $this->assertSame($number, Whitelist::find($id)->registration_number);
    }

    public function test_regenerate_nonexistent_candidate_is_404(): void
    {
        $this->adminAct();
        $this->postJson($this->regenerateRoute(999999))->assertStatus(404);
    }

    // === 10-12. DELETE ===================================================

    public function test_unused_candidate_deletion_succeeds(): void
    {
        $this->adminAct();

        ['id' => $id] = $this->createCandidate('Hapus Aku');
        $this->assertFalse((bool) Whitelist::find($id)->is_used);
        $this->assertNull(Whitelist::find($id)->user_id);

        // (10) is_used=false AND user_id=null -> deletion allowed
        $this->deleteJson("/api/admin/whitelists/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('whitelists', ['id' => $id]);
    }

    public function test_used_candidate_deletion_is_rejected_with_409(): void
    {
        $this->adminAct();

        ['id' => $id] = $this->createCandidate('Sudah Terpakai');
        $user = User::factory()->create();
        $this->markUsed(Whitelist::find($id), $user);

        // (11) is_used=true AND user_id!=null -> deletion forbidden, and it is a
        // business-state rejection (409), never a 500.
        $this->deleteJson("/api/admin/whitelists/{$id}")->assertStatus(409);
        $this->assertDatabaseHas('whitelists', ['id' => $id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_candidate_with_user_id_but_false_is_used_cannot_be_deleted(): void
    {
        $this->adminAct();

        // Defensive: a half-written consumption (user_id set, flag not) must
        // still be treated as used, so no orphan user is ever produced.
        ['id' => $id] = $this->createCandidate('Setengah Terpakai');
        $user = User::factory()->create();
        Whitelist::find($id)->forceFill(['is_used' => false, 'user_id' => $user->id])->save();

        $this->deleteJson("/api/admin/whitelists/{$id}")->assertStatus(409);
        $this->assertDatabaseHas('whitelists', ['id' => $id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_delete_nonexistent_candidate_is_404_not_500(): void
    {
        $this->adminAct();
        // (12)
        $this->deleteJson('/api/admin/whitelists/999999')->assertStatus(404);
    }

    public function test_delete_used_candidate_through_real_registration_is_409(): void
    {
        $this->adminAct();

        ['id' => $id, 'number' => $number] = $this->createCandidate('Konsumsi Nyata');

        // Consume via the REAL registration endpoint, then attempt deletion.
        $this->registerInternal($number, 'nyata@example.com')->assertOk();
        $this->adminAct(); // re-establish the admin actor after the guard switch

        $fresh = Whitelist::find($id);
        $this->assertTrue((bool) $fresh->is_used);
        $this->assertNotNull($fresh->user_id);

        $this->deleteJson("/api/admin/whitelists/{$id}")->assertStatus(409);
        $this->assertDatabaseHas('whitelists', ['id' => $id]);
    }

    // === 13-15. authorization ============================================

    public function test_unauthorized_copy_is_rejected(): void
    {
        $this->adminAct();
        ['id' => $id, 'number' => $number] = $this->createCandidate('Rahasia');

        // (13) A non-admin role is refused, and the secret is never disclosed.
        $this->parentAct();
        $res = $this->getJson($this->numberRoute($id))->assertStatus(403);
        $this->assertStringNotContainsString($number, $res->getContent());
        $this->assertSame('Rahasia', Whitelist::find($id)->nama_siswa);

        // Any other non-admin role is refused the same way.
        $guru = User::factory()->create();
        $guru->forceFill(['role' => 'guru'])->save();
        Sanctum::actingAs($guru->refresh(), ['*']);
        $this->getJson($this->numberRoute($id))->assertStatus(403);
    }

    public function test_unauthenticated_copy_is_401(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $w = Whitelist::create([
            'nama_siswa' => 'Tanpa Auth', 'jenjang' => 'SD',
            'registration_number' => $this->codes->generateCode(), 'is_used' => false,
        ]);

        $this->getJson($this->numberRoute($w->id))->assertStatus(401);
        $this->postJson($this->regenerateRoute($w->id))->assertStatus(401);
        $this->deleteJson("/api/admin/whitelists/{$w->id}")->assertStatus(401);
        $this->assertDatabaseHas('whitelists', ['id' => $w->id]);
    }

    public function test_unauthorized_regenerate_is_rejected(): void
    {
        $this->adminAct();
        ['id' => $id, 'number' => $number] = $this->createCandidate('Jangan Ubah');

        $this->parentAct();
        // (14)
        $this->postJson($this->regenerateRoute($id))->assertStatus(403);
        $this->postJson("/api/admin/whitelists/{$id}/code")->assertStatus(403);
        $this->assertSame($number, Whitelist::find($id)->registration_number);
    }

    public function test_unauthorized_delete_is_rejected(): void
    {
        $this->adminAct();
        ['id' => $id] = $this->createCandidate('Jangan Hapus');

        $this->parentAct();
        // (15)
        $this->deleteJson("/api/admin/whitelists/{$id}")->assertStatus(403);
        $this->assertDatabaseHas('whitelists', ['id' => $id]);
    }

    // === 16-17. EXPORT ===================================================

    public function test_export_source_list_does_not_mutate_numbers(): void
    {
        $this->adminAct();

        ['id' => $idA, 'number' => $numA] = $this->createCandidate('Ekspor A');
        ['id' => $idB, 'number' => $numB] = $this->createCandidate('Ekspor B', 'SMP');

        $updatedBefore = (string) Whitelist::find($idA)->updated_at;

        // (16) The card-level Export Data button reads this list payload. It
        // must not rotate, regenerate or otherwise mutate any credential.
        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/admin/whitelists')->assertOk();
        }

        $this->assertSame($numA, Whitelist::find($idA)->registration_number);
        $this->assertSame($numB, Whitelist::find($idB)->registration_number);
        $this->assertSame($updatedBefore, (string) Whitelist::find($idA)->updated_at);
        $this->assertFalse((bool) Whitelist::find($idA)->is_used);
        $this->assertNull(Whitelist::find($idA)->user_id);
    }

    public function test_export_list_exposes_no_plaintext_numbers(): void
    {
        $this->adminAct();

        ['number' => $numA] = $this->createCandidate('Ekspor Rahasia A');
        ['number' => $numB] = $this->createCandidate('Ekspor Rahasia B', 'SMP');

        // (17) Policy decision: the bulk export does NOT carry credentials, so
        // the list payload exposes only has_number + status. Plaintext is
        // reachable solely via the single-candidate authorized read.
        $body = $this->getJson('/api/admin/whitelists')->assertOk()->getContent();

        $this->assertStringNotContainsString($numA, $body);
        $this->assertStringNotContainsString($numB, $body);
        $this->assertStringNotContainsString('registration_number', $body);
        $this->assertStringContainsString('has_number', $body);
    }

    // === 18. leading zero =================================================

    public function test_leading_zero_survives_copy_and_read(): void
    {
        $this->adminAct();

        $w = Whitelist::create([
            'nama_siswa' => 'Anak Nol', 'jenjang' => 'SD',
            'registration_number' => '0012345678', 'is_used' => false,
        ]);

        // The string form must be preserved end-to-end (never int-cast).
        $this->getJson($this->numberRoute($w->id))
             ->assertOk()
             ->assertJson(['registration_number' => '0012345678']);

        $this->assertSame('0012345678', Whitelist::find($w->id)->registration_number);
        $this->assertStringContainsString('"registration_number":"0012345678"',
            $this->getJson($this->numberRoute($w->id))->getContent());
    }

    // === 19-20. delete/register serialisation ============================

    public function test_register_after_delete_is_rejected_and_creates_no_user(): void
    {
        $this->adminAct();

        ['id' => $id, 'number' => $number] = $this->createCandidate('Dulu Ada');
        $this->deleteJson("/api/admin/whitelists/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('whitelists', ['id' => $id]);

        // (19) DELETE won the race: the credential no longer exists, so a
        // registration attempt is refused...
        $this->registerInternal($number, 'ghost@example.com')->assertStatus(422);

        // (20) ...and no user is ever created from a deleted credential.
        $this->assertDatabaseMissing('users', ['email' => 'ghost@example.com']);
        $this->assertDatabaseMissing('whitelists', ['registration_number' => $number]);
    }

    public function test_delete_after_register_is_rejected_and_keeps_the_row(): void
    {
        $this->adminAct();

        ['id' => $id, 'number' => $number] = $this->createCandidate('Menang Daftar');

        // (19) REGISTRATION won the race: the row is consumed...
        $this->registerInternal($number, 'winner@example.com')->assertOk();
        $this->adminAct(); // re-establish the admin actor after the guard switch

        $userEmail = User::where('email', 'winner@example.com')->value('email');
        $this->assertNotNull($userEmail);

        // ...so delete is refused and neither the row nor its user is orphaned.
        $this->deleteJson("/api/admin/whitelists/{$id}")->assertStatus(409);

        $fresh = Whitelist::find($id);
        $this->assertNotNull($fresh);
        $this->assertTrue((bool) $fresh->is_used);
        $this->assertNotNull($fresh->user_id);
        $this->assertDatabaseHas('users', ['id' => $fresh->user_id]);
    }

    public function test_single_use_is_preserved_under_repeated_registration(): void
    {
        $this->adminAct();

        ['id' => $id, 'number' => $number] = $this->createCandidate('Sekali Pakai', 'SMP');

        $this->registerInternal($number, 'first@example.com')->assertOk();

        // No double consumption: the second attempt is refused.
        $this->registerInternal($number, 'second@example.com')->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
        $fresh = Whitelist::find($id);
        $this->assertTrue((bool) $fresh->is_used);
        $this->assertSame(1, User::where('email', 'first@example.com')->count());
    }
}
