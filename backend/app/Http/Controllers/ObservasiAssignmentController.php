<?php

namespace App\Http\Controllers;

use App\Models\ObservasiAssignment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * E.12G Phase 2 — admin management of observasi teacher assignments.
 *
 * BUSINESS RULES (approved):
 *  - One ACTIVE guru per SD candidate (uq_active backs this at the DB).
 *  - users.jenjang is the jenjang source of truth; only SD candidates enter
 *    this flow. Guru must have role 'guru'; candidate role 'orang_tua'.
 *  - History is PERMANENT: ganti = old row -> DIGANTI + new AKTIF row in one
 *    transaction; cabut = row -> DICABUT. Nothing is ever deleted here.
 *  - penilai_id on hasil_observasis is NEVER consulted for assignment.
 *
 * LOCKING PROTOCOL (consistent with the approved design):
 *    (1) lockForUpdate() the CANDIDATE's users row (parent lock — also the
 *        serialization point when no assignment row exists yet),
 *    (2) then lockForUpdate() the assignment AKTIF row,
 *    (3) mutate history inside the same transaction.
 *  uq_active is the last line of defense; a duplicate-insert race is mapped
 *  to a controlled 409, never a raw SQL error.
 *
 * STALE/ABA PROTECTION: replace/revoke carry the assignment id in the path —
 * it doubles as expected_assignment_id. A mismatch (or a non-AKTIF row)
 * yields 409 'assignment_conflict' with the current active assignment so the
 * admin UI can reload deterministically. Retries after a lost response see
 * the changed row and get the same controlled 409 — no duplicate history.
 */
class ObservasiAssignmentController extends Controller
{
    // ── Reads ─────────────────────────────────────────────────────────────

    /**
     * SD candidates with their current active assignment (if any).
     */
    public function index()
    {
        $rows = User::where('users.role', 'orang_tua')
            ->where('users.jenjang', 'SD')
            ->leftJoin('observasi_assignments as aktif', function ($join) {
                $join->on('users.id', '=', 'aktif.user_id')
                    ->where('aktif.status', '=', ObservasiAssignment::STATUS_AKTIF);
            })
            ->leftJoin('users as guru', 'guru.id', '=', 'aktif.guru_id')
            ->select(
                'users.id',
                'users.name',
                'users.jadwal_observasi',
                'aktif.id as assignment_id',
                'aktif.guru_id',
                'guru.name as guru_name',
                'aktif.started_at'
            )
            ->orderBy('users.created_at', 'desc')
            ->get();

        return response()->json($rows);
    }

    /**
     * Eligible pickers: role 'guru' users + SD candidates (with assignment
     * state so the UI can offer create vs reassign).
     */
    public function options()
    {
        $gurus = User::where('role', 'guru')
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json(['gurus' => $gurus]);
    }

    /**
     * Full permanent history of a candidate's assignments (admin only).
     */
    public function history($userId)
    {
        $kandidat = User::where('id', $userId)
            ->where('role', 'orang_tua')
            ->where('jenjang', 'SD')
            ->select('id', 'name')
            ->firstOrFail();

        $history = ObservasiAssignment::where('user_id', $userId)
            ->leftJoin('users as guru', 'guru.id', '=', 'observasi_assignments.guru_id')
            ->leftJoin('users as pelaku', 'pelaku.id', '=', 'observasi_assignments.assigned_by')
            ->select(
                'observasi_assignments.id',
                'observasi_assignments.guru_id',
                'guru.name as guru_name',
                'observasi_assignments.status',
                'observasi_assignments.revoked_reason',
                'pelaku.name as assigned_by_name',
                'observasi_assignments.started_at',
                'observasi_assignments.ended_at'
            )
            ->orderByDesc('observasi_assignments.started_at')
            ->orderByDesc('observasi_assignments.id')
            ->get();

        return response()->json(['kandidat' => $kandidat, 'history' => $history]);
    }

    // ── Writes ────────────────────────────────────────────────────────────

    /**
     * Create the FIRST assignment for a candidate (no active row may exist).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'guru_id' => 'required|integer|exists:users,id',
        ]);

        $guru = $this->resolveGuru($data['guru_id']);
        if (! $guru instanceof User) {
            return $guru; // 422
        }

        try {
            $assignment = DB::transaction(function () use ($data, $request) {
                // (1) parent lock — serializes all assignment ops per candidate,
                // including two concurrent "first assignments" (no row to lock yet).
                $kandidat = User::whereKey($data['user_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $kandidat || $kandidat->role !== 'orang_tua' || $kandidat->jenjang !== 'SD') {
                    return $this->invalidCandidate();
                }

                $existing = ObservasiAssignment::aktif($kandidat->id)
                    ->lockForUpdate()
                    ->first();

                // Idempotensi-by-state: an active row already existing means
                // either a business duplicate or a retry after a lost response.
                // Both get the same controlled 409 — no second AKTIF, ever.
                if ($existing) {
                    return $this->conflict($existing);
                }

                return ObservasiAssignment::create([
                    'user_id'     => $kandidat->id,
                    'guru_id'     => $data['guru_id'],
                    'status'      => ObservasiAssignment::STATUS_AKTIF,
                    'assigned_by' => $request->user()->id,
                    'started_at'  => now(),
                ]);
            });
        } catch (QueryException $e) {
            return $this->uniqueViolationAsConflict($e, $data['user_id']);
        }

        if ($assignment instanceof \Illuminate\Http\JsonResponse) {
            return $assignment;
        }

        return response()->json([
            'success' => true,
            'message' => 'Penugasan berhasil dibuat.',
            'data'    => $assignment,
        ], 201);
    }

    /**
     * Replace the active guru: old row -> DIGANTI, new AKTIF row, one tx.
     * The {assignment} path id IS the expected_assignment_id.
     */
    public function ganti(Request $request, $assignment)
    {
        $data = $request->validate([
            'guru_id' => 'required|integer|exists:users,id',
        ]);

        $guru = $this->resolveGuru($data['guru_id']);
        if (! $guru instanceof User) {
            return $guru; // 422
        }

        $target = ObservasiAssignment::whereKey($assignment)->first();
        if (! $target) {
            return response()->json([
                'success' => false,
                'code'    => 'assignment_not_found',
                'message' => 'Penugasan tidak ditemukan.',
            ], 404);
        }

        $targetUserId = $target->user_id;

        try {
            $result = DB::transaction(function () use ($request, $data, $assignment, $targetUserId) {
                // (1) parent lock
                $kandidat = User::whereKey($targetUserId)->lockForUpdate()->first();

                // (2) lock the CURRENT active row — NOT the stale target row.
                $aktif = ObservasiAssignment::aktif($targetUserId)
                    ->lockForUpdate()
                    ->first();

                // expected_assignment_id mismatch covers: already replaced,
                // already revoked, or ABA (replaced then replaced back).
                if (! $aktif || $aktif->id !== (int) $assignment) {
                    return $this->conflict($aktif);
                }

                if ($aktif->guru_id === (int) $data['guru_id']) {
                    // Same target guru: not a business change — controlled 422.
                    return response()->json([
                        'success' => false,
                        'code'    => 'same_guru',
                        'message' => 'Guru ini sudah menjadi penilai aktif untuk calon murid tersebut.',
                    ], 422);
                }

                // (3) history mutation — same transaction, no half-states.
                $aktif->update([
                    'status'    => ObservasiAssignment::STATUS_DIGANTI,
                    'ended_at'  => now(),
                ]);

                $baru = ObservasiAssignment::create([
                    'user_id'     => $aktif->user_id,
                    'guru_id'     => $data['guru_id'],
                    'status'      => ObservasiAssignment::STATUS_AKTIF,
                    'assigned_by' => $request->user()->id,
                    'started_at'  => now(),
                ]);

                return $baru;
            });
        } catch (QueryException $e) {
            return $this->uniqueViolationAsConflict($e, $targetUserId);
        }

        if ($result instanceof \Illuminate\Http\JsonResponse) {
            return $result;
        }

        return response()->json([
            'success' => true,
            'message' => 'Penugasan berhasil diganti.',
            'data'    => $result,
        ]);
    }

    /**
     * Revoke the active assignment: row -> DICABUT (history kept).
     * The {assignment} path id IS the expected_assignment_id.
     */
    public function cabut(Request $request, $assignment)
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $target = ObservasiAssignment::whereKey($assignment)->first();
        if (! $target) {
            return response()->json([
                'success' => false,
                'code'    => 'assignment_not_found',
                'message' => 'Penugasan tidak ditemukan.',
            ], 404);
        }

        $result = DB::transaction(function () use ($data, $target, $assignment) {
            // (1) parent lock
            User::whereKey($target->user_id)->lockForUpdate()->first();

            // (2) lock current active row
            $aktif = ObservasiAssignment::aktif($target->user_id)
                ->lockForUpdate()
                ->first();

            if (! $aktif || $aktif->id !== (int) $assignment) {
                return $this->conflict($aktif);
            }

            // (3) revoke — history preserved by design
            $aktif->update([
                'status'         => ObservasiAssignment::STATUS_DICABUT,
                'revoked_reason' => $data['reason'] ?? null,
                'ended_at'       => now(),
            ]);

            return $aktif->fresh();
        });

        if ($result instanceof \Illuminate\Http\JsonResponse) {
            return $result;
        }

        return response()->json([
            'success' => true,
            'message' => 'Penugasan berhasil dicabut.',
            'data'    => $result,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Server-side guru validation — role must be 'guru'. The route group
     * already gates admin access; this guards the BUSINESS rule that only
     * guru-role users can be assignees.
     */
    private function resolveGuru(int $guruId): User|\Illuminate\Http\JsonResponse
    {
        $guru = User::whereKey($guruId)->first();

        if (! $guru || $guru->role !== 'guru') {
            return response()->json([
                'success' => false,
                'code'    => 'invalid_guru',
                'message' => 'Guru yang dipilih tidak valid atau bukan berperan sebagai guru.',
            ], 422);
        }

        return $guru;
    }

    private function invalidCandidate(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'code'    => 'invalid_candidate',
            'message' => 'Calon murid tidak valid. Hanya calon murid SD yang dapat ditugaskan.',
        ], 422);
    }

    /**
     * Controlled 409 with the current active assignment (may be null) so the
     * admin UI can reload deterministically.
     */
    private function conflict(?ObservasiAssignment $aktif): \Illuminate\Http\JsonResponse
    {
        $current = null;
        if ($aktif) {
            $current = [
                'assignment_id' => $aktif->id,
                'user_id'       => $aktif->user_id,
                'guru_id'       => $aktif->guru_id,
                'status'        => $aktif->status,
                'started_at'    => $aktif->started_at?->toISOString(),
            ];
        }

        return response()->json([
            'success' => false,
            'code'    => 'assignment_conflict',
            'message' => 'Penugasan telah berubah sejak dimuat. Muat ulang data terbaru.',
            'current' => $current,
        ], 409);
    }

    /**
     * uq_active violation (non-protocol writer or lost race) → controlled 409.
     * Raw SQL never reaches the client.
     *
     * NOTE: no logging calls here — the repository enforces a strict
     * no-logging policy in app source (CredentialLoggingPolicyTest); operator
     * diagnostics for this branch rely on the generic 500 path and standard
     * Laravel error reporting, not app-level logging.
     */
    private function uniqueViolationAsConflict(QueryException $e, ?int $userId): \Illuminate\Http\JsonResponse
    {
        if ($e->errorInfo[1] ?? null === 1062) {
            $aktif = $userId ? ObservasiAssignment::aktif($userId)->first() : null;

            return $this->conflict($aktif);
        }

        // Unexpected database error — generic message only, nothing internal.
        return response()->json([
            'success' => false,
            'message' => 'Terjadi kesalahan saat menyimpan penugasan. Silakan coba lagi.',
        ], 500);
    }
}
