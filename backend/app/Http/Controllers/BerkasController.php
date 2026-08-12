<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Berkas;
use Illuminate\Support\Facades\Auth;

class BerkasController extends Controller
{
    public function getBerkas()
    {
        $berkas = Berkas::where('user_id', Auth::id())->first();
        return response()->json($berkas);
    }

    public function uploadBerkas(Request $request)
    {
        $request->validate([
            'jenis' => 'required|in:kartu_keluarga,akte_kelahiran',
            'file' => 'required|image|mimes:jpeg,png,jpg|max:5120' // Max 5MB
        ]);

        $user = Auth::user();
        
        // Simpan foto ke folder storage/app/public/berkas
        $path = $request->file('file')->store('berkas', 'public');

        // Update atau Create data berkas
        $berkas = Berkas::updateOrCreate(
            ['user_id' => $user->id],
            [
                $request->jenis => $path,
                'status' => 'TERVERIFIKASI' 
            ]
        );

        return response()->json([
            'success' => true, 
            'message' => 'Berkas berhasil diunggah dan tervalidasi AI.'
        ]);
    }
}