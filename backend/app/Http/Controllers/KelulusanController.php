<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class KelulusanController extends Controller
{
    // Mengambil data pendaftar yang efisien memori (Anti-Lag query select khusus)
    public function index()
    {
        $users = User::where('users.role', 'orang_tua')
            ->leftJoin('payments', 'users.id', '=', 'payments.user_id')
            ->select('users.id', 'users.name', 'users.email', 'users.status_kelulusan', 'payments.jenjang')
            ->orderBy('users.created_at', 'desc')
            ->get()
            ->unique('id')
            ->values();
                     
        return response()->json($users);
    }

    // Set Kelulusan Satuan Aman
    public function update(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:LULUS,TIDAK_LULUS,BELUM'
        ]);

        $user = User::where('id', $id)->where('role', 'orang_tua')->firstOrFail();
        $user->update(['status_kelulusan' => $request->status]);

        return response()->json(['success' => true, 'message' => 'Status berhasil diubah']);
    }

    // Set Kelulusan Massal dengan Transaksi (Anti-Lag & Proteksi Error Tumpuk)
    public function bulkUpdate(Request $request)
    {
        $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
            'status' => 'required|in:LULUS,TIDAK_LULUS,BELUM'
        ]);

        // Gunakan DB Transaction mencegah crash parsial di server jika data ribuan
        DB::transaction(function () use ($request) {
            User::whereIn('id', $request->user_ids)
                ->where('role', 'orang_tua')
                ->update(['status_kelulusan' => $request->status]);
        });

        return response()->json(['success' => true, 'message' => 'Kelulusan massal diamankan']);
    }

    // Ambil data waktu rilis setting
    public function getAllJadwalRilis()
    {
        $sd = Setting::where('key', 'waktu_rilis_sd')->first();
        $smp = Setting::where('key', 'waktu_rilis_smp')->first();

        return response()->json([
            'SD' => $sd ? $sd->value : '',
            'SMP' => $smp ? $smp->value : ''
        ]);
    }

    // Simpan pengaturan waktu rilis pengumuman
    public function saveJadwalRilis(Request $request)
    {
        $request->validate([
            'jenjang' => 'required|in:SD,SMP',
            'waktu_rilis' => 'required|string'
        ]);

        $key = 'waktu_rilis_' . strtolower($request->jenjang);
        
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $request->waktu_rilis]
        );

        return response()->json(['success' => true, 'message' => 'Jadwal countdown terkunci']);
    }

    // API Publik Proteksi Tinggi Ortu (ANTI-HACK CLOCK BYPASS)
    public function getJadwalRilisAndStatus($jenjang, Request $request)
    {
        $key = 'waktu_rilis_' . strtolower($jenjang);
        $setting = Setting::where('key', $key)->first();
        
        $user = $request->user(); // Ambil data ortu yang sedang login
        $waktuRilis = $setting ? $setting->value : null;

        // Ambil status asli pendaftar
        $statusKelulusan = $user->status_kelulusan ?? 'BELUM';

        if ($waktuRilis) {
            $waktuTarget = strtotime($waktuRilis);
            $waktuSekarang = time(); // Waktu server mutlak (Tidak bisa diakali jam HP ortu!)

            // JIKA BELUM WAKTUNYA RILIS, PAKSA STATUS JADI 'BELUM' DI API RESPONSE!
            if ($waktuSekarang < $waktuTarget) {
                $statusKelulusan = 'BELUM';
            }
        } else {
            // Jika admin belum nyetel waktu rilis, status kelulusan disembunyikan total
            $statusKelulusan = 'BELUM';
        }

        return response()->json([
            'jenjang' => $jenjang,
            'waktu' => $waktuRilis,
            'secured_status' => $statusKelulusan // Kirim status yang udah terproteksi jam server
        ]);
    }
}