<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\HasilObservasi;
use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ObservasiController extends Controller
{
    /**
     * E.12C — canonical `rekomendasi_akhir` contract (matches the aligned DB
     * enum and the frontend domain type):
     *   LULUS           — accepted
     *   LULUS BERSYARAT — accepted with special conditions/agreement
     *   TIDAK LULUS     — rejected
     * Single source of truth for validating the guru's recommendation writes.
     */
    public const REKOMENDASI_AKHIR_VALUES = ['LULUS', 'LULUS BERSYARAT', 'TIDAK LULUS'];

    public function getAntrean()
    {
        // Fix N+1: subquery EXISTS langsung di SQL, bukan loop PHP
        // Fix data leak: select hanya field yang dibutuhkan guru, bukan seluruh user object
        //
        // E.12D — jenjang scope: SD dan SMP dinilai dengan mekanisme yang BERBEDA.
        // SD memakai instrumen observasi fisik berkode (kertas), yang kode-nya
        // dimasukkan ke aplikasi untuk direkam di arena ini. SMP memakai CBT
        // (sesi ujian + PIN di /guru/cbt, pengerjaan di /cbt, hasil di
        // /guru/rekapan-cbt) dan TIDAK BOLEH masuk arena instrumen observasi SD.
        // users.jenjang adalah sumber otoritatif (ditetapkan saat registrasi,
        // di bawah issuance-lock; tidak pernah null untuk kandidat eksternal),
        // sehingga difilter di sini tanpa mengubah skema apa pun.
        // E.12G — antrean HARUS dibatasi pada calon murid yang punya penugasan
        // observasi berstatus AKTIF kepada guru yang sedang login. Sebelumnya
        // query ini mengembalikan SEMUA calon murid SD, sehingga peserta tanpa
        // penugasan (atau yang ditugaskan ke guru lain) tetap muncul sebagai
        // pilihan. Identitas guru diambil dari autentikasi server (Auth::id()),
        // bukan dari input frontend. Submit tetap memvalidasi ulang assignment
        // aktif di dalam transaksi — filter ini adalah untuk visibilitas, bukan
        // pengganti otorisasi tulis.
        $guruId = Auth::id();
        $antrean = User::where('role', 'orang_tua')
            ->where('jenjang', 'SD')
            ->whereExists(function ($q) use ($guruId) {
                $q->select(DB::raw(1))
                    ->from('observasi_assignments')
                    ->whereColumn('observasi_assignments.user_id', 'users.id')
                    ->where('observasi_assignments.guru_id', $guruId)
                    ->where('observasi_assignments.status', \App\Models\ObservasiAssignment::STATUS_AKTIF);
            })
            ->select('id', 'name', 'email', 'jadwal_observasi', 'jam_observasi', 'ruang_observasi', 'jenjang')
            ->withExists('hasilObservasi as sudah_dites')
            ->orderBy('jadwal_observasi')
            ->get();

        return response()->json($antrean);
    }

    public function submitHasil(Request $request)
    {
        $request->validate([
            'user_id'          => 'required|exists:users,id',
            'jawaban_json'     => 'required|array',
            // E.12C: restrict to the canonical contract so an invalid value is
            // rejected with 422 BEFORE any query touches the database. The old
            // `nullable|string|max:1000` rule let arbitrary strings through to
            // the enum column, which under STRICT_TRANS_TABLES produced a 500
            // (SQLSTATE[01000] 1265 Data truncated) and left nothing stored.
            'rekomendasi_akhir' => 'nullable|in:'.implode(',', self::REKOMENDASI_AKHIR_VALUES),
            // E.12G Phase 3 — optimistic concurrency. REQUIRED for updates;
            // must be absent/null for a candidate's FIRST hasil row. Nullable
            // here so the first-write contract (no version yet) stays 200.
            'expected_version' => 'nullable|integer|min:1',
        ]);

        // Pastikan user_id yang dinilai memang role orang_tua
        $targetUser = User::where('id', $request->user_id)
            ->where('role', 'orang_tua')
            ->firstOrFail();

        // E.12D — jenjang guard: arena observasi ini HANYA untuk calon murid SD
        // (instrumen fisik berkode). Kandidat SMP dinilai melalui alur CBT
        // yang sudah tersedia dan tidak boleh menerima hasil observasi SD.
        if (($targetUser->jenjang ?? null) !== 'SD') {
            return response()->json([
                'success' => false,
                'message' => 'Calon murid ini bukan peserta observasi SD. Kandidat SMP dinilai melalui alur CBT.',
            ], 422);
        }

        $data = [
            'penilai_id'       => Auth::id(),
            'jawaban_json'     => $request->jawaban_json,
            'catatan_tambahan' => 'Disubmit via Aplikasi Observasi',
        ];

        if ($request->filled('rekomendasi_akhir')) {
            $data['rekomendasi_akhir'] = $request->rekomendasi_akhir;
        }

        // ── E.12G Phase 3: authorization + optimistic concurrency, single tx ──
        //
        // LOCKING PROTOCOL (global order agreed in the E.12G design):
        //   (1) users kandidat row      — parent lock; the serialization point
        //       shared with assignment writes (Fase 2), so a submit and a
        //       penugasan change on the same candidate can never interleave.
        //       For a FIRST hasil (no row to lock yet) this parent lock is the
        //       only serialization available — the protocol does not rely on
        //       locking a non-existent hasil row.
        //   (2) hasil_observasis row(s) for the candidate.
        //   (3) assignment AKTIF row — checked (not merely pre-validated) INSIDE
        //       the write transaction, so a guru whose assignment was revoked
        //       or replaced mid-flight is refused before anything is written.
        //   (4) idempotency_keys — E.12G Phase 4: claimed AFTER every business
        //       lock, immediately BEFORE version checks, so a replayed request
        //       that already committed (lost response) is answered from the
        //       stored response BEFORE its now-stale expected_version can be
        //       misread as a version conflict. Claim and completion are in THIS
        //       transaction: no CLAIMED state can ever be orphaned (a failed
        //       transaction rolls the claim back with everything else).
        try {
            $hasil = DB::transaction(function () use ($request, $targetUser, $data) {
                // ... (locks (1)-(2) & duplicate guard unchanged — see above)
                $kandidat = User::whereKey($targetUser->id)->lockForUpdate()->first();

                $rows = HasilObservasi::where('user_id', $targetUser->id)
                    ->lockForUpdate()
                    ->orderBy('id')
                    ->get();

                if ($rows->count() > 1) {
                    $resp = $this->duplicateRowsConflict();
                    $this->completeIdempotency($request, $resp);

                    return $resp;
                }

                // (4) idempotency claim — AFTER all business locks, BEFORE any
                // validation that a committed retry would now fail (version).
                $replay = $this->claimIdempotency($request, $targetUser);
                if ($replay !== null) {
                    return $replay; // stored response (same payload) or 422 (mismatch)
                }

                $existing = $rows->first();

                // (3) assignment check INSIDE the transaction.
                $aktif = \App\Models\ObservasiAssignment::aktif($targetUser->id)
                    ->lockForUpdate()
                    ->first();

                if (! $aktif || (int) $aktif->guru_id !== (int) Auth::id()) {
                    $resp = response()->json([
                        'success' => false,
                        'code'    => 'not_assigned',
                        'message' => 'Anda tidak ditugaskan sebagai penilai aktif untuk calon murid ini.',
                    ], 403);
                    $this->completeIdempotency($request, $resp);

                    return $resp;
                }

                // ── Version rules (identical for BOTH writers: arena & editor) ──
                if ($existing) {
                    if ((int) $request->expected_version !== (int) $existing->version) {
                        // Stale (or missing) expected_version on an existing
                        // row: refuse. Newest data wins — no auto-merge.
                        $resp = response()->json([
                            'success' => false,
                            'code'    => 'stale_version',
                            'message' => 'Hasil observasi ini telah diperbarui oleh orang lain. Muat ulang data terbaru.',
                            'current' => [
                                'version'        => (int) $existing->version,
                                'penilai_id'     => $existing->penilai_id,
                                'updated_at'     => $existing->updated_at?->toISOString(),
                                'rekomendasi_akhir' => $existing->rekomendasi_akhir,
                            ],
                        ], 409);
                        $this->completeIdempotency($request, $resp);

                        return $resp;
                    }

                    $existing->update($data + ['version' => $existing->version + 1]);
                    $hasil = $existing->fresh();
                } else {
                    // FIRST write: expected_version MUST be absent/null. A client
                    // sending a version for a candidate with no row has stale state.
                    if ($request->expected_version !== null) {
                        $resp = response()->json([
                            'success' => false,
                            'code'    => 'stale_version',
                            'message' => 'Kandidat ini belum memiliki hasil observasi; kirim tanpa expected_version, atau muat ulang data.',
                        ], 409);
                        $this->completeIdempotency($request, $resp);

                        return $resp;
                    }

                    $hasil = HasilObservasi::create($data + [
                        'user_id' => $targetUser->id,
                        'version' => 1,
                    ]);
                }

                // Success response — stored in the SAME transaction as the
                // mutation (E.12G Phase 4). Replays return this exact body.
                $resp = response()->json([
                    'success' => true,
                    'message' => 'Penilaian berhasil disimpan!',
                    'data'    => $hasil,
                ]);
                $this->completeIdempotency($request, $resp);

                return $resp;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Controlled error envelope — raw SQL never reaches the client.
            // Deliberately NOT stored for idempotent replay: the transaction
            // rolled back, so a retry with the same key re-executes cleanly.
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan penilaian. Silakan coba lagi.',
            ], 500);
        }

        return $hasil; // already a JsonResponse (success, replay, or controlled conflict)
    }

    public function getHasil()
    {
        // Select eksplisit — tidak bocorkan kolom sensitif users
        $hasil = DB::table('hasil_observasis')
            ->join('users', 'hasil_observasis.user_id', '=', 'users.id')
            ->select(
                'hasil_observasis.id',
                'hasil_observasis.user_id',
                'hasil_observasis.penilai_id',
                'hasil_observasis.jawaban_json',
                'hasil_observasis.rekomendasi_akhir',
                'hasil_observasis.catatan_tambahan',
                'hasil_observasis.version',
                'hasil_observasis.updated_at',
                'users.name as nama_siswa'
            )
            ->orderBy('hasil_observasis.updated_at', 'desc')
            ->get();

        $hasil->transform(function ($item) {
            $item->jawaban_json = json_decode($item->jawaban_json);
            return $item;
        });

        return response()->json($hasil);
    }

    /**
     * E.12G Phase 3 — hasil_observasis.user_id has NO unique constraint yet
     * (deliberately: adding one is a separate E.12F decision gated on
     * per-environment duplicate audits). Legacy/non-protocol writers can
     * therefore leave multiple rows for one candidate. We NEVER pick a
     * winner, merge, or delete — write access is refused until an operator
     * resolves the duplicates manually. No personal data in the response.
     */
    private function duplicateRowsConflict(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'code'    => 'duplicate_hasil_rows',
            'message' => 'Calon murid ini memiliki lebih dari satu baris hasil observasi. '
                .'Penyimpanan ditolak sampai data duplikat ditangani manual oleh operator. '
                .'Silakan hubungi admin.',
        ], 409);
    }

    // ── E.12G Phase 4: idempotency (24h retention) ────────────────────────────

    /**
     * CANONICAL PAYLOAD (documented contract — must stay deterministic):
     *   1. Start from ONLY the fields that define the operation's meaning:
     *      user_id, jawaban_json, rekomendasi_akhir (when present),
     *      expected_version (when present).
     *   2. Recursively ksort() every object level (array key order must not
     *      change the hash), then JSON-encode with JSON_THROW_ON_ERROR and
     *      unescaped slashes for a stable serialization.
     *   3. Transport-only fields (Idempotency-Key header itself, any future
     *      CSRF/header noise) are NOT part of the payload hash by construction.
     * Two structurally different payloads → different hashes. The same
     * logical payload sent twice → identical hash.
     */
    private function canonicalPayloadHash(Request $request): string
    {
        $payload = [
            'user_id'  => (int) $request->user_id,
            'jawaban_json' => $request->jawaban_json,
        ];

        if ($request->filled('rekomendasi_akhir')) {
            $payload['rekomendasi_akhir'] = $request->rekomendasi_akhir;
        }

        if ($request->expected_version !== null) {
            $payload['expected_version'] = (int) $request->expected_version;
        }

        $this->deepKsort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function deepKsort(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$v) {
            if (is_array($v)) {
                $this->deepKsort($v);
            }
        }
    }

    /**
     * Claim the idempotency record — called INSIDE the write transaction,
     * AFTER all business locks (slot 4 in the global lock order).
     *
     * Returns:
     *   - null  → fresh key claimed (or no key sent at all); proceed with the
     *             operation. On completion, completeIdempotency() fills the
     *             stored response IN THIS transaction.
     *   - JsonResponse → replay of the stored response (identical payload),
     *             or 422 idempotency_payload_mismatch (different payload).
     *
     * SCOPE BINDING: key_hash = SHA-256(endpoint_class | actor_id | client key).
     * A client key sent by a different actor (or to a different endpoint
     * class) hashes to a DIFFERENT key_hash → it can never read or collide
     * with another actor's stored response. Only the hash is stored, never
     * the raw client key.
     *
     * CONCURRENCY SEMANTICS (MariaDB 10.11, REPEATABLE-READ): a concurrent
     * duplicate INSERT hits UNIQUE(key_hash) → we lockForUpdate() the
     * holder's row → this blocks until the first transaction COMMITS (row
     * visible in its snapshot? no — after commit the fresh SELECT re-reads
     * committed data, see below) or ROLLS BACK (row vanishes → re-claim by
     * INSERT retry, once). We do NOT rely on FOR UPDATE locking a row that
     * does not exist (gap-lock assumption) — the unique index is the arbiter.
     */
    private function claimIdempotency(Request $request, $targetUser): ?\Illuminate\Http\JsonResponse
    {
        $clientKey = $request->header('Idempotency-Key');

        if (! $clientKey || ! preg_match('/^[A-Za-z0-9._-]{8,128}$/', $clientKey)) {
            return null; // no (valid) key → not an idempotent request; plain operation
        }

        $keyHash = $this->idempotencyKeyHash($clientKey);
        $payloadHash = $this->canonicalPayloadHash($request);

        try {
            IdempotencyKey::create([
                'key_hash'       => $keyHash,
                'endpoint_class' => IdempotencyKey::CLASS_SUBMIT,
                'actor_id'       => Auth::id(),
                'payload_hash'   => $payloadHash,
                'expires_at'     => now()->addHours(IdempotencyKey::RETENTION_HOURS),
            ]);

            return null; // fresh claim — proceed
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Someone (maybe our own earlier, committed attempt) holds this key.
            $existing = IdempotencyKey::where('key_hash', $keyHash)
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                // The holder's transaction ROLLED BACK between the INSERT
                // violation and this read (row invisible in our snapshot or
                // genuinely gone after commit-rollback race). Safe to re-claim.
                IdempotencyKey::create([
                    'key_hash'       => $keyHash,
                    'endpoint_class' => IdempotencyKey::CLASS_SUBMIT,
                    'actor_id'       => Auth::id(),
                    'payload_hash'   => $payloadHash,
                    'expires_at'     => now()->addHours(IdempotencyKey::RETENTION_HOURS),
                ]);

                return null;
            }

            // Payload mismatch → refuse. Never leak another payload's data.
            if ($existing->payload_hash !== $payloadHash) {
                return response()->json([
                    'success' => false,
                    'code'    => 'idempotency_payload_mismatch',
                    'message' => 'Idempotency-Key ini sudah dipakai untuk payload yang berbeda.',
                ], 422);
            }

            // EXPIRED record → treat the key as fresh (retention window over):
            // reclaim by refreshing the record (keeps the same hash row), and
            // continue as a new operation. The stored response is no longer
            // replayed.
            if ($existing->expires_at->isPast()) {
                $existing->update([
                    'payload_hash'   => $payloadHash,
                    'response_code'  => null,
                    'response_body'  => null,
                    'expires_at'     => now()->addHours(IdempotencyKey::RETENTION_HOURS),
                ]);

                return null; // proceed as a fresh operation
            }

            // Identical payload + completed record → replay the stored response.
            if ($existing->response_code !== null) {
                return response()->json(
                    json_decode($existing->response_body, true),
                    $existing->response_code
                );
            }

            // In-flight record without a completed response: by design this
            // cannot happen for long (claim and completion share one tx), but
            // under a COMMIT race the winner may not be visible yet. Treat as
            // transient: client may retry the SAME key shortly.
            return response()->json([
                'success' => false,
                'code'    => 'idempotency_in_flight',
                'message' => 'Operasi dengan key yang sama sedang berlangsung. Ulangi dengan key yang sama beberapa saat lagi.',
            ], 503);
        }
    }

    private function idempotencyKeyHash(string $clientKey): string
    {
        return hash('sha256', IdempotencyKey::CLASS_SUBMIT.'|'.Auth::id().'|'.$clientKey);
    }

    /**
     * Complete the idempotency record with the final response — inside the
     * SAME transaction as the hasil mutation, so a replay can never exist
     * without its underlying committed operation (and vice versa).
     */
    private function completeIdempotency(Request $request, \Illuminate\Http\JsonResponse $resp): void
    {
        $clientKey = $request->header('Idempotency-Key');
        if (! $clientKey || ! preg_match('/^[A-Za-z0-9._-]{8,128}$/', $clientKey)) {
            return;
        }

        IdempotencyKey::where('key_hash', $this->idempotencyKeyHash($clientKey))
            ->where('actor_id', Auth::id())
            ->update([
                'response_code' => $resp->status(),
                'response_body' => $resp->getContent(),
            ]);
    }
}
