<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\SettingAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    private const KEYS = ['biaya_sd', 'biaya_smp', 'bank_name', 'bank_owner', 'rekening_sd', 'rekening_smp'];

    private function settings(): array
    {
        $settings = Setting::whereIn('key', [...self::KEYS, 'bank_account', 'nomor_rekening'])->pluck('value', 'key');
        // Transitional fallback only when a new key is absent. Never write legacy keys.
        $legacy = $settings['bank_account'] ?? $settings['nomor_rekening'] ?? '';
        return [
            'biaya_sd' => $settings['biaya_sd'] ?? 0,
            'biaya_smp' => $settings['biaya_smp'] ?? 0,
            'bank_name' => $settings['bank_name'] ?? 'BANK SYARIAH INDONESIA (BSI)',
            'bank_owner' => $settings['bank_owner'] ?? 'Yayasan Al-Mubarak',
            'rekening_sd' => $settings->has('rekening_sd') ? $settings['rekening_sd'] : $legacy,
            'rekening_smp' => $settings->has('rekening_smp') ? $settings['rekening_smp'] : $legacy,
        ];
    }

    public function getBiaya(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);
        return response()->json($this->settings())->header('Cache-Control', 'private, no-store');
    }

    public function paymentInstructions(Request $request)
    {
        $jenjang = $request->user()->jenjang;
        abort_unless(in_array($jenjang, ['SD', 'SMP'], true), 422, 'Jenjang pendaftaran tidak valid.');
        $settings = $this->settings();
        return response()->json([
            'jenjang' => $jenjang,
            'amount' => (int) $settings['biaya_' . strtolower($jenjang)],
            'rekening' => $settings['rekening_' . strtolower($jenjang)],
            'bank_name' => $settings['bank_name'],
            'bank_owner' => $settings['bank_owner'],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function updateBiaya(Request $request)
    {
        // Defense in depth: the actor always comes from Sanctum, never client role fields.
        abort_unless($request->user()->role === 'super_admin', 403);
        $unknown = array_diff(array_keys($request->all()), self::KEYS);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['settings' => 'Field konfigurasi tidak diizinkan.']);
        }
        foreach (['rekening_sd', 'rekening_smp'] as $key) {
            if (is_string($request->input($key))) {
                $request->merge([$key => trim($request->input($key))]);
            }
        }
        $data = $request->validate([
            'biaya_sd' => 'sometimes|required|numeric|min:0',
            'biaya_smp' => 'sometimes|required|numeric|min:0',
            'bank_name' => 'sometimes|required|string|max:100',
            'bank_owner' => 'sometimes|required|string|max:100',
            'rekening_sd' => ['sometimes', 'required', 'string', 'regex:/\A[0-9]{6,30}\z/'],
            'rekening_smp' => ['sometimes', 'required', 'string', 'regex:/\A[0-9]{6,30}\z/'],
        ]);
        if ($data === []) {
            throw ValidationException::withMessages(['settings' => 'Konfigurasi tidak boleh kosong.']);
        }

        DB::transaction(function () use ($data, $request) {
            // Serialize financial updates, including creation of previously absent keys.
            DB::table('users')->where('role', 'super_admin')->orderBy('id')->lockForUpdate()->get(['id']);
            $before = $this->settings();
            foreach ($data as $key => $value) {
                $value = (string) $value;
                $setting = Setting::where('key', $key)->lockForUpdate()->first();
                $old = $setting?->value;
                Setting::updateOrCreate(['key' => $key], ['value' => $value]);
                if (in_array($key, ['rekening_sd', 'rekening_smp', 'bank_name', 'bank_owner'], true) && $old !== $value) {
                    $old = $before[$key];
                    $account = str_starts_with($key, 'rekening_');
                    SettingAuditLog::create([
                        'actor_id' => $request->user()->id,
                        'action' => 'UPDATE_PAYMENT_ACCOUNT',
                        'setting_key' => $key,
                        'old_value' => $account ? $this->mask($old) : $old,
                        'new_value' => $account ? $this->mask($value) : $value,
                        'result' => 'SUCCESS',
                    ]);
                }
            }
        });
        return response()->json(['success' => true, 'message' => 'Konfigurasi Biaya dan Rekening berhasil diperbarui!']);
    }

    private function mask(?string $value): ?string
    {
        if ($value === null || $value === '') return $value;
        return str_repeat('*', max(0, strlen($value) - 4)) . substr($value, -4);
    }
}
