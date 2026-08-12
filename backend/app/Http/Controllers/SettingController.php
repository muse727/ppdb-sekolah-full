<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Setting;

class SettingController extends Controller
{
    // === AMBIL DATA PENGATURAN ===
    public function getBiaya()
    {
        try {
            // Ambil semua setting sekaligus biar hemat query database
            $settings = Setting::whereIn('key', [
                'biaya_sd', 'biaya_smp', 'bank_name', 'bank_account', 'bank_owner'
            ])->pluck('value', 'key');

            return response()->json([
                'biaya_sd' => $settings['biaya_sd'] ?? 0,
                'biaya_smp' => $settings['biaya_smp'] ?? 0,
                'bank_name' => $settings['bank_name'] ?? 'BANK SYARIAH INDONESIA (BSI)',
                'bank_account' => $settings['bank_account'] ?? '',
                'bank_owner' => $settings['bank_owner'] ?? 'Yayasan Al-Mubarak',
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal mengambil data pengaturan.'], 500);
        }
    }

    // === UPDATE DATA PENGATURAN ===
    public function updateBiaya(Request $request)
    {
        // Validasi ketat demi keamanan database
        $request->validate([
            'biaya_sd' => 'required|numeric|min:0',
            'biaya_smp' => 'required|numeric|min:0',
            'bank_name' => 'required|string|max:100',
            'bank_account' => 'required|string|max:50',
            'bank_owner' => 'required|string|max:100',
        ]);

        try {
            // Update atau Create jika belum ada (Aman & efisien)
            Setting::updateOrCreate(['key' => 'biaya_sd'], ['value' => $request->biaya_sd]);
            Setting::updateOrCreate(['key' => 'biaya_smp'], ['value' => $request->biaya_smp]);
            Setting::updateOrCreate(['key' => 'bank_name'], ['value' => $request->bank_name]);
            Setting::updateOrCreate(['key' => 'bank_account'], ['value' => $request->bank_account]);
            Setting::updateOrCreate(['key' => 'bank_owner'], ['value' => $request->bank_owner]);

            return response()->json([
                'success' => true, 
                'message' => 'Konfigurasi Biaya dan Rekening berhasil diperbarui!'
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Terjadi kesalahan sistem saat menyimpan data.'], 500);
        }
    }
}