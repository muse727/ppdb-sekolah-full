<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CbtSession;

class CbtController extends Controller
{
    // AMBIL SEMUA SESI UJIAN
    public function getSessions()
    {
        $sessions = CbtSession::orderBy('tanggal_ujian', 'desc')->orderBy('jam_mulai', 'asc')->get();
        return response()->json($sessions);
    }

    // BIKIN ATAU UPDATE SESI UJIAN BARU
    public function storeSession(Request $request)
    {
        $request->validate([
            'nama_ujian' => 'required|string',
            'tanggal_ujian' => 'required|date',
            'jam_mulai' => 'required',
            'jam_selesai' => 'required',
            'ruangan' => 'required|string',
            'token' => 'required|string|max:6',
        ]);

        $session = CbtSession::updateOrCreate(
            ['id' => $request->id],
            [
                'nama_ujian' => $request->nama_ujian,
                'tanggal_ujian' => $request->tanggal_ujian,
                'jam_mulai' => $request->jam_mulai,
                'jam_selesai' => $request->jam_selesai,
                'ruangan' => $request->ruangan,
                'token' => $request->token,
                'is_active' => $request->is_active ?? false,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Sesi ujian berhasil disimpan',
            'data' => $session
        ]);
    }

    // FUNGSI HAPUS SESI UJIAN
    public function destroySession($id)
    {
        $session = CbtSession::findOrFail($id);
        $session->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sesi berhasil dihapus'
        ]);
    }

    // --- MANAJEMEN SOAL ---
    public function getQuestions($sessionId)
    {
        $questions = \App\Models\CbtQuestion::where('cbt_session_id', $sessionId)->get();
        return response()->json($questions);
    }

    public function storeQuestion(Request $request, $sessionId)
    {
        $request->validate(['jenis_soal' => 'required', 'pertanyaan' => 'required']);
        $question = \App\Models\CbtQuestion::updateOrCreate(
            ['id' => $request->id],
            [
                'cbt_session_id' => $sessionId,
                'jenis_soal' => $request->jenis_soal,
                'pertanyaan' => $request->pertanyaan,
                'gambar' => $request->gambar, // Base64 image
                'opsi_a' => $request->opsi_a, 'opsi_b' => $request->opsi_b, 'opsi_c' => $request->opsi_c, 'opsi_d' => $request->opsi_d,
                'kunci_jawaban' => $request->kunci_jawaban,
            ]
        );
        return response()->json(['success' => true]);
    }

    // === API UNTUK SISWA ===
    public function loginCbt(Request $request)
    {
        $session = \App\Models\CbtSession::where('token', $request->token)->first();
        if (!$session) return response()->json(['success' => false, 'message' => 'Token tidak ditemukan'], 404);

        // Ambil soal, TAPI SEMBUNYIKAN KUNCI JAWABAN BIAR GAK DIHACK SISWA LEWAT INSPECT ELEMENT
        $questions = \App\Models\CbtQuestion::where('cbt_session_id', $session->id)->get()->map(function($q) {
            return [
                'id' => $q->id, 'jenis_soal' => $q->jenis_soal, 'pertanyaan' => $q->pertanyaan, 'gambar' => $q->gambar,
                'opsi_a' => $q->opsi_a, 'opsi_b' => $q->opsi_b, 'opsi_c' => $q->opsi_c, 'opsi_d' => $q->opsi_d
            ];
        });

        return response()->json(['success' => true, 'session' => $session, 'questions' => $questions]);
    }

    public function submitCbt(Request $request)
    {
        $session = \App\Models\CbtSession::find($request->session_id);
        $answers = $request->jawaban; 
        $questions = \App\Models\CbtQuestion::where('cbt_session_id', $session->id)->get();

        $benar = 0; $salah = 0;
        $pembahasan = []; // Array buat dikirim ke layar review siswa

        foreach ($questions as $q) {
            $jawaban_siswa = $answers[$q->id] ?? null;
            $is_correct = false;

            if ($q->jenis_soal == 'PG') {
                if ($jawaban_siswa == $q->kunci_jawaban) {
                    $benar++;
                    $is_correct = true;
                } else {
                    $salah++;
                }
            }

            // Masukin data buat direview siswa
            $pembahasan[] = [
                'id' => $q->id,
                'pertanyaan' => $q->pertanyaan,
                'gambar' => $q->gambar,
                'jenis_soal' => $q->jenis_soal,
                'opsi_a' => $q->opsi_a,
                'opsi_b' => $q->opsi_b,
                'opsi_c' => $q->opsi_c,
                'opsi_d' => $q->opsi_d,
                'kunci_jawaban' => $q->kunci_jawaban,
                'jawaban_siswa' => $jawaban_siswa,
                'is_correct' => $is_correct
            ];
        }

        $totalPG = max(1, $questions->where('jenis_soal', 'PG')->count());
        $skor = ($benar / $totalPG) * 100;

        // Simpan ke database
        \App\Models\CbtResult::create([
            'cbt_session_id' => $session->id, 'nama_siswa' => $request->nama_siswa,
            'jawaban_json' => json_encode($answers), 'benar' => $benar, 'salah' => $salah,
            'skor' => $skor, 'jumlah_pelanggaran' => $request->pelanggaran 
        ]);

        // Balikin data skor dan pembahasan ke React
        return response()->json([
            'success' => true, 
            'skor' => $skor,
            'benar' => $benar,
            'salah' => $salah,
            'pembahasan' => $pembahasan
        ]);
    }

    public function destroyQuestion($id)
    {
        \App\Models\CbtQuestion::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    public function getResults()
    {
        $results = \App\Models\CbtResult::join('cbt_sessions', 'cbt_results.cbt_session_id', '=', 'cbt_sessions.id')
            ->select('cbt_results.*', 'cbt_sessions.nama_ujian', 'cbt_sessions.token')
            ->orderBy('cbt_results.created_at', 'desc')
            ->get();

        $results->transform(function ($item) {
            $item->jawaban_json = json_decode($item->jawaban_json);
            return $item;
        });

        return response()->json($results);
    }

    // API UNTUK GURU SIMPAN NILAI ESAI
    public function updateSkorEsai(Request $request, $id)
    {
        $result = \App\Models\CbtResult::findOrFail($id);
        $result->update([
            'skor_esai' => $request->skor_esai,
            'is_koreksi_esai' => true
        ]);
        return response()->json(['success' => true]);
    }
}