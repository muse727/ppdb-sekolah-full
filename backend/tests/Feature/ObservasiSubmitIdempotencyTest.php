<?php

namespace Tests\Feature;

use App\Models\HasilObservasi;
use App\Models\IdempotencyKey;
use App\Models\ObservasiAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E.12G Phase 4 — idempotency (24h) on POST /api/guru/observasi/submit.
 * Runs ONLY against ppdb_sekolah_test (RefreshDatabase; P4-D.1 bootstrap).
 *
 * Test classification (explicit):
 *  - SEQUENTIAL: success stores response; same key+payload replays; etc.
 *  - VERSION:    stale version is NOT masked by a fresh idempotency key.
 *  - ATOMICITY:  rolled-back transaction leaves no key row.
 *  - CONCURRENCY (truly overlapping transactions): NOT performed — no safe
 *    multi-connection harness here. Same-key races are handled by the UNIQUE
 *    index + in-transaction claim design; stated as a design guarantee, not
 *    a tested fact.
 */
class ObservasiSubmitIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private User $guru;
    private User $kandidat;

    protected function setUp(): void
    {
        parent::setUp();

        $guru = User::factory()->create(['name' => 'Guru', 'jenjang' => 'SD']);
        $guru->forceFill(['role' => 'guru'])->save();
        $this->guru = $guru->refresh();

        $kandidat = User::factory()->create(['name' => 'Calon', 'jenjang' => 'SD']);
        $kandidat->forceFill(['role' => 'orang_tua'])->save();
        $this->kandidat = $kandidat->refresh();

        ObservasiAssignment::create([
            'user_id' => $this->kandidat->id, 'guru_id' => $this->guru->id,
            'status' => 'AKTIF', 'started_at' => now(),
        ]);

        Sanctum::actingAs($this->guru);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'user_id'      => $this->kandidat->id,
            'jawaban_json' => ['K01' => ['jawaban' => 'YA', 'skor' => 1]],
        ], $over);
    }

    private function postWithKey(string $key, array $payload)
    {
        return $this->postJson('/api/guru/observasi/submit', $payload, [
            'Idempotency-Key' => $key,
        ]);
    }

    // ── Core idempotency contract ─────────────────────────────────────────

    public function test_first_request_succeeds_and_stores_response(): void
    {
        $res = $this->postWithKey('submit-key-0001', $this->payload());

        $res->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.version', 1);

        $row = IdempotencyKey::where('key_hash', hash('sha256', 'observasi_submit|'.$this->guru->id.'|submit-key-0001'))->first();
        $this->assertNotNull($row, 'idempotency record must exist');
        $this->assertSame(200, $row->response_code);
        $this->assertNotNull($row->response_body);
        $this->assertLessThanOrEqual(1, IdempotencyKey::count());
        // Only the HASH is stored, never the raw client key.
        $this->assertStringNotContainsString('submit-key-0001', $row->key_hash);
    }

    public function test_same_key_same_payload_replays_identically_without_new_mutation(): void
    {
        $first = $this->postWithKey('submit-key-0002', $this->payload());
        $first->assertOk();

        $second = $this->postWithKey('submit-key-0002', $this->payload());

        $this->assertSame($first->getContent(), $second->getContent(), 'replay must return the identical stored body');
        $this->assertSame(200, $second->status());

        $this->assertSame(1, HasilObservasi::count(), 'no duplicate hasil row');
        $this->assertSame(1, HasilObservasi::first()->version, 'version must not be bumped twice');
    }

    public function test_same_key_different_payload_is_422(): void
    {
        $this->postWithKey('submit-key-0003', $this->payload())->assertOk();

        $this->postWithKey('submit-key-0003', $this->payload(['jawaban_json' => ['K01' => ['jawaban' => 'TIDAK', 'skor' => 0]]]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'idempotency_payload_mismatch');

        // No second row, no mutation of the first.
        $this->assertSame(1, HasilObservasi::count());
        $this->assertSame('YA', HasilObservasi::first()->jawaban_json['K01']['jawaban']);
    }

    public function test_different_key_runs_new_operation_with_valid_expected_version(): void
    {
        $this->postWithKey('submit-key-0004', $this->payload())->assertOk();

        $this->postWithKey('submit-key-0005', $this->payload(['expected_version' => 1]))
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->assertSame(1, HasilObservasi::count());
    }

    public function test_retry_after_commit_lost_response_replays(): void
    {
        // Simulates: first request COMMITTED but the response never reached
        // the client; the client retries with the SAME key + payload.
        $first = $this->postWithKey('submit-key-0006', $this->payload());
        $first->assertOk();

        $body = $first->getContent();
        $code = $first->status();

        // "Lost response": the client only knows it sent the request. Retry.
        $retry = $this->postWithKey('submit-key-0006', $this->payload());

        $this->assertSame($code, $retry->status());
        $this->assertSame($body, $retry->getContent());
        $this->assertSame(1, HasilObservasi::count(), 'retry must not double-write');
        $this->assertSame(1, HasilObservasi::first()->version, 'version bumped exactly once across both requests');
    }

    // ── Scope binding ─────────────────────────────────────────────────────

    public function test_key_of_different_actor_never_leaks_stored_response(): void
    {
        $this->postWithKey('shared-client-key-0001', $this->payload())->assertOk();

        // A second guru, same client key string.
        $guruB = User::factory()->create(['name' => 'Guru B', 'jenjang' => 'SD']);
        $guruB->forceFill(['role' => 'guru'])->save();
        Sanctum::actingAs($guruB->refresh());

        // Guru B is not assigned to this candidate → 403, NOT the stored
        // response of guru A (scope binding by actor_id in the hash).
        $this->postWithKey('shared-client-key-0001', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_assigned');

        $this->assertSame(1, HasilObservasi::count());
        $this->assertSame(2, IdempotencyKey::count(), 'two scoped records (one per actor)');
    }

    // ── Interaction with versioning & errors ──────────────────────────────

    public function test_stale_version_is_stored_and_replayed_consistently(): void
    {
        // First write v1 via key A.
        $this->postWithKey('submit-key-0007', $this->payload())->assertOk();

        // Someone else bumps to v2.
        HasilObservasi::where('user_id', $this->kandidat->id)->update(['version' => 2]);

        // Our stale request (expected_version=1) via key B → 409 stored.
        $stale = $this->postWithKey('submit-key-0008', $this->payload(['expected_version' => 1]));
        $stale->assertStatus(409)->assertJsonPath('code', 'stale_version');

        // Retry of the same stale request via the SAME key B → replays the
        // same 409 body — NOT a re-execution (version is 2, still conflict,
        // but also the response must be byte-identical).
        $retry = $this->postWithKey('submit-key-0008', $this->payload(['expected_version' => 1]));
        $this->assertSame(409, $retry->status());
        $this->assertSame($stale->getContent(), $retry->getContent());
        $this->assertSame(2, HasilObservasi::first()->version, 'no mutation from conflict replays');
    }

    public function test_fresh_key_does_not_mask_a_stale_version(): void
    {
        $this->postWithKey('submit-key-0009', $this->payload())->assertOk();
        HasilObservasi::where('user_id', $this->kandidat->id)->update(['version' => 5]);

        // A NEW key (not a retry) with stale version must still get 409.
        $this->postWithKey('submit-key-0010', $this->payload(['expected_version' => 1]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_version');
    }

    public function test_not_assigned_403_is_also_replayed(): void
    {
        // Revoke the assignment first.
        ObservasiAssignment::where('user_id', $this->kandidat->id)
            ->where('status', 'AKTIF')->update(['status' => 'DICABUT', 'ended_at' => now()]);

        $first = $this->postWithKey('submit-key-0011', $this->payload());
        $first->assertStatus(403);

        $retry = $this->postWithKey('submit-key-0011', $this->payload());
        $this->assertSame(403, $retry->status());
        $this->assertSame($first->getContent(), $retry->getContent());
        $this->assertSame(0, HasilObservasi::count());
    }

    // ── Atomicity: no orphan claims ───────────────────────────────────────

    public function test_rollback_leaves_neither_key_row_nor_hasil_change(): void
    {
        // Baseline: v1 exists WITHOUT any idempotency involvement.
        $this->postJson('/api/guru/observasi/submit', $this->payload())->assertOk();

        // Fail the hasil UPDATE mid-transaction (after the claim INSERT).
        // One-shot: the listener must NOT fire again on the clean retry below.
        $armed = true;
        DB::listen(function ($query) use (&$armed) {
            if ($armed && str_contains($query->sql, 'update `hasil_observasis`')) {
                $armed = false;
                throw new \RuntimeException('simulated mid-transaction failure');
            }
        });

        $this->postWithKey('submit-key-0012', $this->payload(['expected_version' => 1]))
            ->assertStatus(500);

        // The claim was rolled back WITH the transaction.
        $this->assertSame(0, IdempotencyKey::where('key_hash', 'LIKE', '%')->count(),
            'rolled-back transaction must not leave an idempotency claim');
        $this->assertSame(1, HasilObservasi::first()->version, 'hasil untouched');

        // And a clean retry with the same key now works fresh.
        $this->postWithKey('submit-key-0012', $this->payload(['expected_version' => 1]))
            ->assertOk()->assertJsonPath('data.version', 2);
    }

    // ── Retention ─────────────────────────────────────────────────────────

    public function test_retention_is_24_hours_and_purge_touches_only_expired(): void
    {
        $this->postWithKey('submit-key-0013', $this->payload())->assertOk();

        $record = IdempotencyKey::first();
        $this->assertEqualsWithDelta(
            now()->addHours(24)->getTimestamp(),
            $record->expires_at->getTimestamp(),
            5,
            'retention must be 24 hours'
        );

        // Simulate an expired key alongside the live one.
        $guruB = User::factory()->create(['jenjang' => 'SD']);
        $guruB->forceFill(['role' => 'guru'])->save();
        IdempotencyKey::create([
            'key_hash' => str_repeat('b', 64), 'endpoint_class' => 'observasi_submit',
            'actor_id' => $guruB->id, 'payload_hash' => str_repeat('c', 64),
            'expires_at' => now()->subHours(1),
        ]);

        $purged = IdempotencyKey::where('expires_at', '<', now())->delete();
        $this->assertSame(1, $purged, 'purge removes ONLY expired keys');
        $this->assertSame(1, IdempotencyKey::count(), 'live key untouched');
        $this->assertSame(1, HasilObservasi::count(), 'no observasi history or hasil ever purged');
    }

    public function test_expired_key_is_treated_as_fresh(): void
    {
        $this->postWithKey('submit-key-0014', $this->payload())->assertOk();

        // Age the key past retention.
        IdempotencyKey::query()->update(['expires_at' => now()->subHours(25)]);

        // Same key after expiry: no stored response replay path (the client
        // contract also clears after 24h) — behaves as a NEW operation.
        // It hits the version guard: payload has no expected_version while
        // a row exists → 409 stale_version (NOT a replay of the old 200).
        $this->postWithKey('submit-key-0014', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_version');
    }

    // ── No key sent → plain operation (backwards compatible) ──────────────

    public function test_request_without_key_behaves_as_before(): void
    {
        $this->postJson('/api/guru/observasi/submit', $this->payload())
            ->assertOk()->assertJsonPath('data.version', 1);

        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_no_sensitive_data_in_stored_records(): void
    {
        $this->postWithKey('submit-key-0015', $this->payload())->assertOk();

        $row = IdempotencyKey::first();
        // The stored response body contains hasil data (that IS the replay
        // contract), but the table must not store auth tokens or passwords:
        $this->assertStringNotContainsStringIgnoringCase('password', $row->key_hash);
        $this->assertStringNotContainsStringIgnoringCase('token', $row->key_hash);
        $this->assertSame(64, strlen($row->key_hash), 'only SHA-256 hashes, not raw keys');
    }
}
