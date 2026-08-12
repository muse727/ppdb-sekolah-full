<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;

class JadwalObservasiController extends Controller
{
    public function index()
    {
        // Join tabel payments buat dapet info jenjang
        $users = User::where('users.role', 'orang_tua')
            ->leftJoin('payments', 'users.id', '=', 'payments.user_id')
            ->select('users.*', 'payments.jenjang')
            ->orderBy('users.created_at', 'desc')
            ->get()
            ->unique('id') // <--- INI OBATNYA BRO BIAR GA DOUBLE
            ->values();    // <--- Rapiin ulang urutan array-nya
                     
        return response()->json($users);
    }

    // Update jadwal observasi
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        $user->update([
            'jadwal_observasi' => $request->tanggal,
            'jam_observasi' => $request->jam,
            'ruang_observasi' => $request->ruangan,
            'perlengkapan' => $request->perlengkapan
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Jadwal berhasil diperbarui'
        ]);
    }

    public function bulkUpdate(Request $request)
    {
        $request->validate([
            'user_ids' => 'required|array',
            'tanggal' => 'required|date',
            'jam' => 'required|string',
            'ruangan' => 'required|string',
            'perlengkapan' => 'nullable|string'
        ]);

        User::whereIn('id', $request->user_ids)->update([
            'jadwal_observasi' => $request->tanggal,
            'jam_observasi' => $request->jam,
            'ruang_observasi' => $request->ruangan,
            'perlengkapan' => $request->perlengkapan
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Jadwal massal berhasil disimpan'
        ]);
    }
}