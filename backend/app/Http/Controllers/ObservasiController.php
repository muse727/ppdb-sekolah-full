<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\HasilObservasi;
use Illuminate\Support\Facades\Auth;

class ObservasiController extends Controller
{
    public function getAntrean()
    {
        $antrean = User::where('role', 'orang_tua')->get()->map(function ($user) {
            $user->sudah_dites = HasilObservasi::where('user_id', $user->id)->exists();
            return $user;
        });
        
        return response()->json($antrean);
    }

    public function submitHasil(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'jawaban_json' => 'required|array',
            'rekomendasi_akhir' => 'nullable|string'
        ]);

        $hasil = HasilObservasi::updateOrCreate(
            ['user_id' => $request->user_id],
            [
                'penilai_id' => Auth::id(), 
                'jawaban_json' => $request->jawaban_json,
                'catatan_tambahan' => 'Disubmit via Aplikasi Observasi',
            ]
        );

        if ($request->has('rekomendasi_akhir')) {
            $hasil->rekomendasi_akhir = $request->rekomendasi_akhir;
            $hasil->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Penilaian berhasil disimpan!',
            'data' => $hasil
        ]);
    }

    public function getHasil()
    {
        $hasil = \Illuminate\Support\Facades\DB::table('hasil_observasis')
            ->join('users', 'hasil_observasis.user_id', '=', 'users.id')
            ->select('hasil_observasis.*', 'users.name as nama_siswa')
            ->orderBy('hasil_observasis.updated_at', 'desc')
            ->get();

        $hasil->transform(function ($item) {
            $item->jawaban_json = json_decode($item->jawaban_json);
            return $item;
        });

        return response()->json($hasil);
    }
}