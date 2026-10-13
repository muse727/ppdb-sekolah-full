<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\CbtAttempt;
use App\Models\CbtSession;
use App\Models\CbtQuestion;
use App\Models\CbtResult;
use App\Http\Resources\CbtQuestionGuruResource;
use App\Http\Resources\CbtQuestionStudentResource;

class CbtController extends Controller
{
    // AMBIL SEMUA SESI UJIAN
    public function getSessions()
    {
        $sessions = CbtSession::orderBy('tanggal_ujian', 'desc')
            ->orderBy('jam_mulai', 'asc')
            ->get();
        return response()->json($sessions);
    }

    // BUAT ATAU UPDATE SESI UJIAN
    public function storeSession(Request $request)
    {
        $request->validate([
            'nama_ujian'    => 'required|string|max:255',
            'tanggal_ujian' => 'required|date',
            'jam_mulai'     => 'required',
            'jam_selesai'   => 'required',
            'ruangan'       => 'required|string|max:100',
            'token'         => 'required|string|size:6',
            'is_active'     => 'nullable|boolean',
        ]);

        // P3-C: the exam PIN is normalized to the canonical form before it is
        // stored so a human-entered lowercase/padded PIN still matches. The
        // PIN remains a short shared secret by design (typable under exam
        // conditions); it is throttled at login time and, unlike registration
        // credentials, is NOT the sole gate for a student's identity.
        $data = $request->only(['nama_ujian', 'tanggal_ujian', 'jam_mulai', 'jam_selesai', 'ruangan', 'is_active']);
        $data['token'] = strtoupper(trim((string) $request->input('token')));

        $session = CbtSession::updateOrCreate(['id' => $request->id], $data);

        return response()->json([
            'success' => true,
            'message' => 'Sesi ujian berhasil disimpan',
            'data'    => $session,
        ]);
    }

    /**
     * P3-C: server-side CSPRNG generator for the 6-char exam PIN.
     *
     * Replaces the client-side Math.random() "generate" button. The PIN is a
     * short shared secret by design (typable under exam conditions), so the
     * server uses random_bytes() with rejection sampling over the same
     * unambiguous alphabet the UI displays. The client may still override the
     * value before saving (it is editable), but the DEFAULT generation is no
     * longer predictable.
     */
    public function generateSessionToken()
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 32 chars, no 0/O/1/I/L
        $n        = strlen($alphabet);
        $limit    = intdiv(256, $n) * $n;               // 256 — no biased tail

        $code = '';
        while (strlen($code) < 6) {
            $byte = ord(random_bytes(1));               // CSPRNG
            if ($byte >= $limit) {
                continue;
            }
            $code .= $alphabet[$byte % $n];
        }

        return response()->json(['token' => $code]);
    }

    // HAPUS SESI UJIAN
    public function destroySession($id)
    {
        CbtSession::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Sesi berhasil dihapus']);
    }

    // MANAJEMEN SOAL — guru only
    public function getQuestions($sessionId)
    {
        /**
         * P4-B: the guru legitimately needs the answer key, so this path uses the
         * guru resource. The decision to include `kunci_jawaban` is made by the
         * route-level `auth:sanctum` + `CheckRole:guru` gate, NOT by anything the
         * caller sends — there is no flag or parameter that selects this shape.
         *
         * `->resolve()` keeps the exact response body this endpoint has always
         * produced: a bare JSON array. Returning the collection itself would wrap
         * it in `{"data": [...]}` and break both the guru question list and the
         * rekapan esai viewer, which expect an array.
         */
        $questions = CbtQuestion::where('cbt_session_id', $sessionId)
            ->orderBy('id')
            ->get();

        return response()->json(CbtQuestionGuruResource::collection($questions)->resolve());
    }

    public function storeQuestion(Request $request, $sessionId)
    {
        $request->validate([
            'jenis_soal'    => 'required|in:PG,ESAI',
            'pertanyaan'    => 'required|string',
            'gambar'        => 'nullable|string|max:500000', // Base64, batasi ukuran
            'opsi_a'        => 'nullable|string|max:500',
            'opsi_b'        => 'nullable|string|max:500',
            'opsi_c'        => 'nullable|string|max:500',
            'opsi_d'        => 'nullable|string|max:500',
            'kunci_jawaban' => 'nullable|string|max:1',
        ]);

        // sessionId dari URL, bukan dari request — cegah inject session lain
        CbtQuestion::updateOrCreate(
            ['id' => $request->id],
            array_merge(
                $request->only(['jenis_soal', 'pertanyaan', 'gambar', 'opsi_a', 'opsi_b', 'opsi_c', 'opsi_d', 'kunci_jawaban']),
                ['cbt_session_id' => $sessionId]
            )
        );

        return response()->json(['success' => true]);
    }

    public function destroyQuestion($id)
    {
        CbtQuestion::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    // === API UNTUK SISWA ===

    public function loginCbt(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'orang_tua') {
            return response()->json(['success' => false, 'message' => 'Akun orang tua wajib login untuk mengikuti CBT.'], $user ? 403 : 401);
        }
        $request->validate(['token' => 'required|string|size:6']);
        $key = 'cbt-login:' . $request->ip() . ':' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 30)) return response()->json(['success' => false, 'message' => 'Terlalu banyak percobaan.'], 429);
        RateLimiter::hit($key, 60);
        $session = CbtSession::where('token', $request->input('token'))->where('is_active', true)->first();
        if (!$session) return response()->json(['success' => false, 'message' => 'Token tidak valid atau sesi belum aktif.'], 422);
        if (CbtAttempt::where('cbt_session_id', $session->id)->where('user_id', $user->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Akun ini telah memulai atau menyelesaikan ujian ini.'], 403);
        }
        $startedAt = now();
        $windowStart = Carbon::parse($session->tanggal_ujian->format('Y-m-d') . ' ' . $session->jam_mulai);
        $expiresAt = Carbon::parse($session->tanggal_ujian->format('Y-m-d') . ' ' . $session->jam_selesai);
        if ($expiresAt->lessThanOrEqualTo($windowStart)) $expiresAt->addDay();
        if ($startedAt->lessThan($windowStart) || $startedAt->greaterThanOrEqualTo($expiresAt)) {
            return response()->json(['success' => false, 'message' => 'Ujian di luar jadwal.'], 403);
        }
        try {
            $attempt = CbtAttempt::create([
                'cbt_session_id' => $session->id, 'user_id' => $user->id,
                'attempt_token' => Str::random(64), 'started_at' => $startedAt,
                'expires_at' => $expiresAt, 'status' => 'IN_PROGRESS',
            ]);
        } catch (QueryException $exception) {
            if (!$this->isAttemptUserSessionUniqueConflict($exception)) {
                throw $exception;
            }

            return response()->json(['success' => false, 'message' => 'Akun ini telah memulai atau menyelesaikan ujian ini.'], 403);
        }
        /**
         * P4-B: student-facing shape. Same allowlist that used to be inlined
         * here, now shared with any other student path so there is exactly one
         * definition of "what a student may receive" — and it never contains
         * `kunci_jawaban`.
         */
        $questions = CbtQuestion::where('cbt_session_id', $session->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true, 'attempt_token' => $attempt->attempt_token,
            'student_name' => $user->name, 'expires_at' => $attempt->expires_at->toISOString(),
            'session' => ['id' => $session->id, 'nama_ujian' => $session->nama_ujian, 'tanggal_ujian' => $session->tanggal_ujian, 'jam_mulai' => $session->jam_mulai, 'jam_selesai' => $session->jam_selesai, 'ruangan' => $session->ruangan],
            'questions' => CbtQuestionStudentResource::collection($questions)->resolve(),
        ]);
    }

    private function isAttemptUserSessionUniqueConflict(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo;
        $driverCode = $errorInfo[1] ?? null;
        $message = $errorInfo[2] ?? $exception->getMessage();

        return (int) $driverCode === 1062
            && str_contains($message, 'cbt_attempts_cbt_session_id_user_id_unique');
    }

    public function submitCbt(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'orang_tua') return response()->json(['success' => false, 'message' => 'Akses CBT ditolak.'], $user ? 403 : 401);
        $request->validate(['attempt_token' => 'required|string|size:64', 'jawaban' => 'required|array', 'pelanggaran' => 'nullable|integer|min:0']);
        $key = 'cbt-submit:' . $request->ip() . ':' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 10)) return response()->json(['success' => false, 'message' => 'Terlalu banyak percobaan submit.'], 429);
        RateLimiter::hit($key, 60);
        return DB::transaction(function () use ($request, $user) {
            $attempt = CbtAttempt::where('attempt_token', $request->input('attempt_token'))->lockForUpdate()->first();
            if (!$attempt || (int) $attempt->user_id !== (int) $user->id) return response()->json(['success' => false, 'message' => 'Attempt tidak valid.'], 403);
            if ($attempt->status !== 'IN_PROGRESS' || $attempt->submitted_at || now()->greaterThanOrEqualTo($attempt->expires_at)) {
                return response()->json(['success' => false, 'message' => 'Attempt sudah selesai atau kedaluwarsa.'], 403);
            }
            if (!$attempt->session?->is_active || now()->lessThan($attempt->started_at)) {
                return response()->json(['success' => false, 'message' => 'Sesi ujian tidak tersedia.'], 403);
            }
            $questions = CbtQuestion::where('cbt_session_id', $attempt->cbt_session_id)->get();
            $answers = $request->input('jawaban'); $benar = 0; $salah = 0;
            foreach ($questions as $q) if ($q->jenis_soal === 'PG') { (($answers[$q->id] ?? null) === $q->kunci_jawaban) ? $benar++ : $salah++; }
            $skor = round(($benar / max(1, $questions->where('jenis_soal', 'PG')->count())) * 100, 2);
            CbtResult::create([
                'cbt_session_id' => $attempt->cbt_session_id, 'cbt_attempt_id' => $attempt->id,
                'user_id' => $attempt->user_id, 'nama_siswa' => $user->name, 'jawaban_json' => json_encode($answers),
                'benar' => $benar, 'salah' => $salah, 'skor' => $skor, 'jumlah_pelanggaran' => $request->input('pelanggaran', 0),
            ]);
            $attempt->update(['status' => 'SUBMITTED', 'submitted_at' => now()]);
            return response()->json(['success' => true, 'student_name' => $user->name, 'skor' => $skor, 'benar' => $benar, 'salah' => $salah]);
        });
    }

    // HASIL CBT — guru only
    public function getResults()
    {
        $results = CbtResult::join('cbt_sessions', 'cbt_results.cbt_session_id', '=', 'cbt_sessions.id')
            ->select('cbt_results.*', 'cbt_sessions.nama_ujian', 'cbt_sessions.token')
            ->orderBy('cbt_results.created_at', 'desc')
            ->get();

        $results->transform(function ($item) {
            $item->jawaban_json = json_decode($item->jawaban_json);
            return $item;
        });

        return response()->json($results);
    }

    // SIMPAN NILAI ESAI — guru only
    public function updateSkorEsai(Request $request, $id)
    {
        $request->validate([
            'skor_esai' => 'required|numeric|min:0|max:100',
        ]);

        $result = CbtResult::findOrFail($id);
        $result->update([
            'skor_esai'        => $request->skor_esai,
            'is_koreksi_esai'  => true,
        ]);

        return response()->json(['success' => true]);
    }
}
