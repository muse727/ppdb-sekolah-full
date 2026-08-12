<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Setting;
use App\Models\Whitelist;
use Illuminate\Support\Facades\DB;

class SystemConfigController extends Controller
{
    // === 1. PENGATURAN UMUM ===
    public function getSettings()
    {
        $keys = ['ppdb_status', 'kuota_sd', 'kuota_smp'];
        $settings = Setting::whereIn('key', $keys)->pluck('value', 'key');
        
        return response()->json([
            'ppdb_status' => $settings->get('ppdb_status', 'TUTUP'),
            'kuota_sd' => $settings->get('kuota_sd', 100),
            'kuota_smp' => $settings->get('kuota_smp', 50),
        ]);
    }

    public function saveSettings(Request $request)
    {
        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => $request->ppdb_status]);
        Setting::updateOrCreate(['key' => 'kuota_sd'], ['value' => $request->kuota_sd]);
        Setting::updateOrCreate(['key' => 'kuota_smp'], ['value' => $request->kuota_smp]);
        return response()->json(['success' => true]);
    }

    // === 2. MANAJEMEN WHITELIST (INTERNAL) ===
    public function getWhitelists()
    {
        return response()->json(Whitelist::orderBy('created_at', 'desc')->get());
    }

    public function storeWhitelist(Request $request)
    {
        $request->validate(['nisn' => 'required|unique:whitelists', 'nama_siswa' => 'required', 'asal_sekolah' => 'required']);
        Whitelist::create($request->all());
        return response()->json(['success' => true]);
    }

    public function deleteWhitelist($id)
    {
        $whitelist = Whitelist::findOrFail($id);
        if ($whitelist->is_used) return response()->json(['message' => 'Sudah dipakai mendaftar.'], 403);
        $whitelist->delete();
        return response()->json(['success' => true]);
    }

    // === 3. FITUR IMPORT CSV ===
    public function importWhitelist(Request $request)
    {
        $request->validate(['file' => 'required|mimes:csv,txt|max:2048']);
        $path = $request->file('file')->getRealPath();
        $data = array_map('str_getcsv', file($path));

        // Skip header (Baris pertama)
        $headerSkipped = false;
        $sukses = 0;

        DB::beginTransaction();
        try {
            foreach ($data as $row) {
                if (!$headerSkipped) { $headerSkipped = true; continue; }
                if (count($row) < 3 || empty($row[0])) continue; // Skip jika data gak lengkap

                Whitelist::updateOrCreate(
                    ['nisn' => trim($row[0])], // Update kalau NISN udah ada, Insert kalau belum
                    [
                        'nama_siswa' => trim($row[1]),
                        'asal_sekolah' => trim($row[2])
                    ]
                );
                $sukses++;
            }
            DB::commit();
            return response()->json(['success' => true, 'message' => "$sukses data berhasil di-import."]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['message' => 'Terjadi kesalahan format CSV.'], 500);
        }
    }

    // === 4. MANAJEMEN GENERATE TOKEN (JALUR UMUM) ===
    public function getTokens()
    {
        // Ambil token teranyar, sertakan nama user jika sudah terpakai
        $tokens = DB::table('tokens')
            ->leftJoin('users', 'tokens.user_id', '=', 'users.id')
            ->select('tokens.*', 'users.name as nama_pendaftar')
            ->orderBy('tokens.created_at', 'desc')
            ->get();

        return response()->json($tokens);
    }

    public function generateTokens(Request $request)
    {
        $request->validate([
            'jumlah' => 'required|numeric|min:1|max:100'
        ]);

        $jumlah = $request->jumlah;
        $sukses = 0;

        for ($i = 0; $i < $jumlah; $i++) {
            // Generate kode acak premium, misal: AM-829A1B
            $randomCode = 'AM-' . strtoupper(bin2hex(random_bytes(3)));

            // Validasi anti-kembar di database
            while (DB::table('tokens')->where('code', $randomCode)->exists()) {
                $randomCode = 'AM-' . strtoupper(bin2hex(random_bytes(3)));
            }

            \App\Models\Token::create([
                'code' => $randomCode,
                'is_used' => false
            ]);
            $sukses++;
        }

        return response()->json(['success' => true, 'message' => "$sukses Token PPDB baru berhasil diproduksi."]);
    }

    public function deleteToken($id)
    {
        $token = \App\Models\Token::findOrFail($id);
        if ($token->is_used) {
            return response()->json(['message' => 'Token sudah hangus terpakai pendaftar.'], 403);
        }
        $token->delete();
        return response()->json(['success' => true]);
    }
}