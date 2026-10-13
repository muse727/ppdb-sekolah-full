<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E.12C — POST /api/admin/jadwal-observasi/{id} regression coverage.
 *
 * THE BUG UNDER TEST (reproduced before the fix, not theoretical)
 * ---------------------------------------------------------------
 * `jadwal_observasi`, `jam_observasi`, `ruang_observasi` and `perlengkapan`
 * are all listed in User::$guarded, because they are privileged columns only
 * admins may set. The old controller wrote them with `$user->update([...])`,
 * which routes through fill() -> isFillable(), and guarded keys are dropped
 * there silently. The four columns never became dirty, save() returned true
 * for a no-op, and the endpoint answered `success: true` while persisting
 * zero changes. The admin saw "Jadwal berhasil diperbarui" and the parent's
 * ticket page kept showing "Jadwal Belum Tersedia".
 *
 * WHY bulkUpdate DID NOT HAVE THE BUG
 * -----------------------------------
 * bulkUpdate() writes through the query builder (`User::whereIn()->update()`),
 * which bypasses mass assignment entirely. So the same four values saved
 * correctly in bulk and were silently discarded one-by-one — a divergence that
 * made the single-update failure easy to miss. Test parity below pins that.
 *
 * THE FIX
 * -------
 * forceFill() with an explicit, literal four-key allowlist, plus a real check
 * of save()'s return value. Validation now mirrors bulkUpdate so a malformed
 * `tanggal` fails as 422 instead of reaching the `date` column as a 500 under
 * STRICT_TRANS_TABLES.
 *
 * @see app/Http/Controllers/JadwalObservasiController.php
 */
class JadwalObservasiScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name = 'Parent', string $role = 'orang_tua'): User
    {
        $user = User::factory()->create(['name' => $name, 'jenjang' => 'SD']);
        $user->forceFill(['role' => $role])->save();

        return $user->refresh();
    }

    private function admin(): void
    {
        Sanctum::actingAs($this->user('Admin', 'admin'));
    }

    /**
     * The exact field names the modal posts (page.tsx handleSubmit sends
     * `tanggal`/`jam`/`ruangan`/`perlengkapan`), matching the shipped defaults
     * for the SD tab.
     */
    private function payload(
        string $tanggal = '2026-06-10',
        string $jam = '08:00 - 09:30 WIB',
        string $ruangan = 'Ruang Kelas 1A',
        string $perlengkapan = 'Alat Tulis & Meja Lipat'
    ): array {
        return [
            'tanggal' => $tanggal,
            'jam' => $jam,
            'ruangan' => $ruangan,
            'perlengkapan' => $perlengkapan,
        ];
    }

    /** The four schedule columns exactly as stored, read straight from MySQL. */
    private function scheduleOf(User $user): array
    {
        return (array) DB::table('users')->where('id', $user->id)
            ->first(['jadwal_observasi', 'jam_observasi', 'ruang_observasi', 'perlengkapan']);
    }

    /**
     * Requirement: "the schedule values the admin submits must actually persist".
     *
     * Reads the row from the database rather than from the response body, so a
     * controller that reports success without writing still fails here. This is
     * the primary regression assertion for the reported bug.
     */
    public function test_single_update_persists_all_four_schedule_columns(): void
    {
        $user = $this->user();
        $this->admin();

        // Pre-condition: the columns start NULL, so a silent drop is visible.
        $this->assertSame(
            ['jadwal_observasi' => null, 'jam_observasi' => null, 'ruang_observasi' => null, 'perlengkapan' => null],
            $this->scheduleOf($user),
            'fixture must start with an empty schedule'
        );

        $this->postJson('/api/admin/jadwal-observasi/'.$user->id, $this->payload())
            ->assertOk()
            ->assertJson(['success' => true]);

        // MySQL DATE columns normalise to Y-m-d on retrieval; assert that shape
        // so the test is not coupled to the request format.
        $this->assertSame(
            [
                'jadwal_observasi' => '2026-06-10',
                'jam_observasi' => '08:00 - 09:30 WIB',
                'ruang_observasi' => 'Ruang Kelas 1A',
                'perlengkapan' => 'Alat Tulis & Meja Lipat',
            ],
            $this->scheduleOf($user)
        );
    }

    /**
     * The schedule is also what the parent's ticket page renders, so confirm it
     * survives a read-back through the model and not only through raw SQL.
     */
    public function test_saved_schedule_is_visible_to_the_scheduled_user(): void
    {
        $user = $this->user();
        $this->admin();

        // `perlengkapan` is the only optional field the modal posts, and it
        // posts '' when the admin clears it. ConvertEmptyStringsToNull turns
        // that into null; the column is nullable, so null must be persisted
        // rather than an empty string.
        $this->postJson(
            '/api/admin/jadwal-observasi/'.$user->id,
            $this->payload('2026-07-15', '10:00 WIB', 'Aula', '')
        )->assertOk();

        $fresh = $user->fresh();

        $this->assertSame('2026-07-15', $fresh->jadwal_observasi);
        $this->assertSame('10:00 WIB', $fresh->jam_observasi);
        $this->assertSame('Aula', $fresh->ruang_observasi);
        $this->assertNull($fresh->perlengkapan);
    }

    /**
     * Requirement: "repeatedly saving must remain correct" and "the admin must
     * be able to change the schedule". An overwrite that silently no-ops is the
     * same defect as a first write that no-ops.
     */
    public function test_rescheduling_overwrites_the_previous_schedule(): void
    {
        $user = $this->user();
        $this->admin();

        $this->postJson('/api/admin/jadwal-observasi/'.$user->id, $this->payload())->assertOk();
        $this->postJson('/api/admin/jadwal-observasi/'.$user->id, $this->payload('2026-08-20', '13:00 WIB', 'Lab Komputer 1', 'Papan Ujian'))
            ->assertOk();

        $this->assertSame(
            [
                'jadwal_observasi' => '2026-08-20',
                'jam_observasi' => '13:00 WIB',
                'ruang_observasi' => 'Lab Komputer 1',
                'perlengkapan' => 'Papan Ujian',
            ],
            $this->scheduleOf($user)
        );

        // Idempotence: saving identical values twice must not error or drift.
        $again = $this->postJson('/api/admin/jadwal-observasi/'.$user->id, $this->payload('2026-08-20', '13:00 WIB', 'Lab Komputer 1', 'Papan Ujian'));
        $again->assertOk()->assertJson(['success' => true]);
        $this->assertSame('2026-08-20', $this->scheduleOf($user)['jadwal_observasi']);
    }

    /**
     * Requirement: "bulk update must not regress".
     *
     * Pins the single and bulk paths to the same persisted result for the same
     * input, which is exactly the property that was broken (bulk worked, single
     * did not). Uses two separate users so the comparison is unaffected by
     * write ordering.
     */
    public function test_single_and_bulk_update_persist_the_same_schedule(): void
    {
        $single = $this->user('Via Single');
        $bulk = $this->user('Via Bulk');
        $this->admin();

        $values = $this->payload('2026-09-01', '07:30 WIB', 'Ruang Kelas 2B', 'Alat Tulis');

        $this->postJson('/api/admin/jadwal-observasi/'.$single->id, $values)->assertOk();
        $this->postJson('/api/admin/jadwal-observasi/bulk', $values + ['user_ids' => [$bulk->id]])->assertOk();

        $this->assertSame($this->scheduleOf($single), $this->scheduleOf($bulk));
        $this->assertSame('2026-09-01', $this->scheduleOf($single)['jadwal_observasi']);
    }

    /**
     * Requirement: "the admin should not be able to submit something the
     * database would silently reject".
     *
     * `jadwal_observasi` is a MySQL DATE column. An empty or non-date string
     * used to be passed straight through and rejected by the server under
     * STRICT_TRANS_TABLES (a 500). Validation mirrors bulkUpdate, so the
     * request now fails fast with 422 and leaves the stored schedule intact.
     */
    public function test_invalid_schedules_are_rejected_before_reaching_the_database(): void
    {
        $user = $this->user();
        $this->admin();

        // Establish a known-good schedule that must survive every rejection.
        $this->postJson('/api/admin/jadwal-observasi/'.$user->id, $this->payload())->assertOk();
        $before = $this->scheduleOf($user);

        foreach ([
            'empty tanggal' => $this->payload(''),
            'not a date' => $this->payload('besok pagi'),
            'missing tanggal' => ['jam' => '08:00 WIB', 'ruangan' => 'Aula', 'perlengkapan' => 'Alat Tulis'],
            'missing jam' => ['tanggal' => '2026-06-10', 'ruangan' => 'Aula', 'perlengkapan' => 'Alat Tulis'],
            'missing ruangan' => ['tanggal' => '2026-06-10', 'jam' => '08:00 WIB', 'perlengkapan' => 'Alat Tulis'],
        ] as $label => $body) {
            $this->postJson('/api/admin/jadwal-observasi/'.$user->id, $body)
                ->assertStatus(422, $label.' must be rejected with 422');
        }

        $this->assertSame($before, $this->scheduleOf($user), 'no rejected request may mutate the row');
    }

    /**
     * Requirement: "only the fields the admin screen owns may be written".
     *
     * Two independent guarantees are asserted. (1) The route is admin-only.
     * (2) Even for an admin, the endpoint cannot be used as a mass-assignment
     * sink: extra body keys naming other $guarded columns (`role`,
     * `status_kelulusan`, `status_siswa`) must not reach the database.
     *
     * This pins the security property of forceFill() — the allowlist is a
     * literal four-key array in the controller, so attacker-controlled keys are
     * never forwarded. If someone later "simplifies" it to
     * forceFill($request->all()), this test fails.
     */
    public function test_only_the_four_schedule_fields_can_be_written(): void
    {
        $user = $this->user();
        $this->admin();

        $this->postJson('/api/admin/jadwal-observasi/'.$user->id, $this->payload() + [
            'role' => 'super_admin',
            'status_kelulusan' => 'LULUS',
            'status_siswa' => 'TERDAFTAR',
            'password' => 'pwned12345',
        ])->assertOk();

        $fresh = $user->fresh();

        $this->assertSame('orang_tua', $fresh->role);
        $this->assertSame('BELUM', $fresh->status_kelulusan);
        $this->assertNull($fresh->status_siswa);
        // The four owned fields did persist.
        $this->assertSame('2026-06-10', $fresh->jadwal_observasi);
    }

    /**
     * The single route must stay single. `user_ids` is meaningful only to
     * bulkUpdate; sending it here must not widen the write.
     */
    public function test_single_route_does_not_accept_a_bulk_id_list(): void
    {
        $target = $this->user('Target');
        $bystander = $this->user('Bystander');
        $this->admin();

        $this->postJson('/api/admin/jadwal-observasi/'.$target->id, $this->payload() + [
            'user_ids' => [$bystander->id],
        ])->assertOk();

        $this->assertSame('2026-06-10', $this->scheduleOf($target)['jadwal_observasi']);
        $this->assertNull($this->scheduleOf($bystander)['jadwal_observasi'], 'bystander must be untouched');
    }

    /** Existing authorization behaviour must not regress: admin-only. */
    public function test_route_stays_admin_only(): void
    {
        $user = $this->user();

        Sanctum::actingAs($this->user('Guru', 'guru'));
        $this->postJson('/api/admin/jadwal-observasi/'.$user->id, $this->payload())->assertForbidden();

        Sanctum::actingAs($this->user('Parent Two', 'orang_tua'));
        $this->postJson('/api/admin/jadwal-observasi/'.$user->id, $this->payload())->assertForbidden();

        $this->assertNull($this->scheduleOf($user)['jadwal_observasi']);
    }

    /** Unauthenticated access is still refused. */
    public function test_unauthenticated_request_is_refused(): void
    {
        $user = $this->user();

        $this->postJson('/api/admin/jadwal-observasi/'.$user->id, $this->payload())->assertUnauthorized();
        $this->assertNull($this->scheduleOf($user)['jadwal_observasi']);
    }

    /** An unknown id still answers 404 rather than creating a row. */
    public function test_unknown_id_returns_not_found(): void
    {
        $this->admin();

        $this->postJson('/api/admin/jadwal-observasi/999999', $this->payload())->assertNotFound();
    }
}
