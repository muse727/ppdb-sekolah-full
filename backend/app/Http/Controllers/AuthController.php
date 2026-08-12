<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter; // TAMBAHIN INI BUAT KEAMANAN
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    // === 1. LOGIN DENGAN RATE LIMITER & REMEMBER ME ===
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // FITUR ANTI HACKER (BRUTE FORCE PROTECTION)
        $throttleKey = strtolower($request->input('email')) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'message' => "Akses diblokir sementara karena terlalu banyak percobaan. Silakan coba lagi dalam $seconds detik."
            ], 429);
        }

        // FITUR REMEMBER ME (Ambil nilai boolean dari frontend)
        $remember = $request->boolean('remember');

        if (Auth::attempt(['email' => $request->email, 'password' => $request->password], $remember)) {
            RateLimiter::clear($throttleKey); // Bersihkan riwayat gagal
            $request->session()->regenerate(); // Mencegah Session Fixation Attack

            return response()->json([
                'message' => 'Login berhasil',
                'user' => Auth::user()
            ]);
        }

        // Kalau gagal, hitung sebagai 1 pelanggaran (Block selama 60 detik kalau udah 5 kali)
        RateLimiter::hit($throttleKey, 60);

        return response()->json(['message' => 'Email atau Password salah!'], 401);
    }

    // === 2. REGISTER DENGAN FILTER TOKEN & WHITELIST ===
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'jalur' => 'required|in:UMUM,INTERNAL',
            'kredensial' => 'required'
        ]);

        $targetJenjang = null;

        if ($request->jalur === 'INTERNAL') {
            $whitelist = DB::table('whitelists')->where('nisn', $request->kredensial)->where('is_used', false)->first();

            if (!$whitelist) {
                return response()->json(['message' => 'NISN tidak terdaftar di Jalur Internal atau sudah digunakan.'], 422);
            }

            if ($whitelist->asal_sekolah === 'SD IT Al-Mubarak') {
                $targetJenjang = 'SMP';
            } else if ($whitelist->asal_sekolah === 'TK IT Al-Mubarak') {
                $targetJenjang = 'SD';
            }

            DB::table('whitelists')->where('id', $whitelist->id)->update(['is_used' => true]);

        } else {
            $token = DB::table('tokens')->where('code', $request->kredensial)->where('is_used', false)->first();
            
            if (!$token) {
                return response()->json(['message' => 'Token Pendaftaran tidak valid atau sudah hangus.'], 422);
            }

            if (str_contains($token->code, 'AM-SMP-')) {
                $targetJenjang = 'SMP';
            } else {
                $targetJenjang = 'SD';
            }

            DB::table('tokens')->where('id', $token->id)->update(['is_used' => true]);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'orang_tua', // PASTIKAN REGISTER SELALU JADI ORANG TUA
            'jalur' => $request->jalur,
            'jenjang' => $targetJenjang
        ]);

        if ($request->jalur === 'UMUM' && isset($token)) {
            DB::table('tokens')->where('id', $token->id)->update(['user_id' => $user->id]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Registrasi berhasil',
            'user' => $user
        ]);
    }

    // === 3. LOGOUT ===
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logout berhasil']);
    }

   
}