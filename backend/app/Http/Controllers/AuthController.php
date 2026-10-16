<?php

namespace App\Http\Controllers;

use App\Exceptions\CapacityException;
use App\Models\PpdbSeatAllocation;
use App\Models\User;
use App\Services\PpdbCapacityService;
use App\Services\SmpEmailTokenException;
use App\Services\SmpEmailTokenService;
use App\Services\UmumTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    // === 1. LOGIN ===
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email|max:255',
            'password' => 'required|string',
        ]);

        // Brute force protection — 5 kali gagal, block 60 detik
        $throttleKey = strtolower($request->input('email')) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'message' => "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.",
            ], 429);
        }

        $remember = $request->boolean('remember');

        if (Auth::attempt(['email' => $request->email, 'password' => $request->password], $remember)) {
            RateLimiter::clear($throttleKey);

            // session()->regenerate() hanya dipanggil kalau session tersedia (web middleware)
            if ($request->hasSession()) {
                $request->session()->regenerate();
            }

            $user = Auth::user();

            return response()->json([
                'message' => 'Login berhasil',
                'user'    => [
                    'id'     => $user->id,
                    'name'   => $user->name,
                    'email'  => $user->email,
                    'role'   => $user->role,
                    'jalur'  => $user->jalur,
                    'jenjang'=> $user->jenjang,
                ],
            ]);
        }

        RateLimiter::hit($throttleKey, 60);

        // Pesan generik — tidak bocorkan apakah email terdaftar atau tidak
        return response()->json(['message' => 'Email atau password salah.'], 401);
    }

    // === 2. REGISTER ===
    public function register(Request $request, PpdbCapacityService $capacity)
    {
        // Registrasi publik tanpa throttle = oracle credential-stuffing untuk
        // kredensial UMUM / nomor INTERNAL, sekaligus amplification bcrypt +
        // transaksi DB per panggilan. Wajib untuk nomor INTERNAL 10 digit
        // (~33 bit): throttle inilah yang mematahkan brute-force, bukan entropy.
        //
        // Kunci = IP saja (server-observable). Bukan email/token, karena itu
        // identifier client-controlled yang bisa diputar untuk bypass. IP tidak
        // bisa di-rotate hanya dengan mengganti field request. Limit longgar
        // (10/menit) supaya jaringan NAT/sekolah yang berbagi satu IP tidak
        // kena false-positive, tapi cukup ketat untuk mematahkan brute-force.
        $throttleKey = 'register|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'message' => "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.",
            ], 429);
        }

        // Normalisasi untuk jalur INTERNAL: jika salah satu field (kredensial / nomor_internal) terisi,
        // sinkronkan ke keduanya agar kompatibel baik via API payload maupun form registrasi frontend.
        if ($request->jalur === 'INTERNAL') {
            if (!$request->filled('nomor_internal') && $request->filled('kredensial')) {
                $request->merge(['nomor_internal' => trim((string)$request->kredensial)]);
            }
            if (!$request->filled('kredensial') && $request->filled('nomor_internal')) {
                $request->merge(['kredensial' => trim((string)$request->nomor_internal)]);
            }
        }

        // Normalisasi jika jalur UMUM memasukkan format token SMP Email (SMP-XXXX-XXXX-XXXX-XXXX)
        if ($request->jalur === 'UMUM' && str_starts_with(strtoupper(trim((string)$request->kredensial)), 'SMP-')) {
            $request->merge(['jalur' => 'SMP_EMAIL']);
        }

        $request->validate([
            'name'       => 'required|string|max:255',
            'email'      => 'required|email|max:255|unique:users,email',
            'password'   => 'required|string|min:8|max:100',
            'jalur'      => 'required|in:UMUM,INTERNAL,SMP_EMAIL',
            'kredensial' => 'required|string|max:50',
            // P4-D.2: jalur INTERNAL credential is a single 10-digit registration
            // number (nomor_internal). No NISN, no NIK, no second access_code.
            'nomor_internal' => 'required_if:jalur,INTERNAL|nullable|string|digits:10',
        ], [
            'nomor_internal.required_if' => 'Nomor registrasi jalur internal wajib diisi.',
            'nomor_internal.digits'      => 'Nomor registrasi jalur internal harus terdiri dari 10 digit angka.',
        ]);

        // Hitung hanya attempt yang lolos validasi (well-formed), supaya
        // request malformed tidak menguras budget pengguna sah. Ditempatkan
        // sebelum bcrypt/token-lookup sehingga brute-force tetap terbendung.
        RateLimiter::hit($throttleKey, 60);

        // === GLOBAL & JALUR-SPECIFIC PPDB GATE (backend-enforced) ===
        if (!$capacity->isGlobalOpen()) {
            return response()->json([
                'message' => 'Pendaftaran PPDB sedang ditutup.',
            ], 403);
        }

        if ($request->jalur === 'INTERNAL') {
            if (!$capacity->isInternalOpen()) {
                return response()->json([
                    'message' => 'Pendaftaran Jalur Internal sedang ditutup oleh pihak sekolah.',
                ], 403);
            }
        } else {
            if (!$capacity->isUmumOpen()) {
                return response()->json([
                    'message' => 'Pendaftaran Jalur Reguler (Umum) belum dibuka. Saat ini hanya dibuka untuk Jalur Internal.',
                ], 403);
            }
        }

        $targetJenjang = null;
        $candidateGender = null;

        DB::beginTransaction();
        try {
            if ($request->jalur === 'INTERNAL') {
                // P4-D.2: INTERNAL credential = ONE 10-digit registration_number
                // (nomor_internal). It is a BEARER credential — no NISN, no NIK,
                // no second access_code. The number alone identifies and
                // authenticates the bound candidate. Identity (nama, jenjang) is
                // NEVER taken from the client; it comes from this locked record.
                $whitelist = DB::table('whitelists')
                    ->where('registration_number', $request->nomor_internal)
                    ->where('is_used', false)
                    ->lockForUpdate() // Cegah race — 2 request dengan nomor sama bersamaan
                    ->first();

                if (!$whitelist) {
                    DB::rollBack();
                    // Generic error: no enumeration clue whether the number exists,
                    // is wrong length, or is already consumed.
                    return response()->json(['message' => 'Nomor pendaftaran tidak valid atau sudah digunakan.'], 422);
                }

                // Jenjang is the candidate's SERVER-AUTHORITATIVE explicit value.
                $targetJenjang = $whitelist->jenjang;
                $candidateGender = $whitelist->jenis_kelamin;
                if (!in_array($targetJenjang, ['SD', 'SMP'], true)) {
                    DB::rollBack();
                    return response()->json(['message' => 'Data kandidat tidak valid (jenjang belum ditetapkan). Hubungi admin.'], 422);
                }

                $capacity->lockRegistrationCapacity($targetJenjang, $candidateGender);

            } elseif ($request->jalur === 'SMP_EMAIL') {
                // =========================================================
                // P3-C: SMP email-token registration (backend-authoritative).
                //
                // The email-token is the CREDENTIAL. Identity binding is
                // server-owned: the token was issued to a specific (email,
                // nama, jenjang) inside the issuance lock, so the account being
                // created MUST use that exact email. Client-controlled fields
                // (name/email/jenjang/jalur) can never rebind the token onto
                // another identity.
                //
                // Validation order (all server-side):
                //   lock email ledger -> re-read latest issuance under lock ->
                //   verify digest -> verify not consumed -> verify not expired ->
                //   consume atomically -> create user bound to the token.
                // =========================================================
                $smpSvc = app(SmpEmailTokenService::class);

                // The request email selects the issuance; the locked issuance
                // supplies every authoritative identity field.
                $boundEmail = strtolower(trim($request->email));

                try {
                    $smpToken = $smpSvc->lockForRegistration(
                        $boundEmail,
                        $smpSvc->normalize($request->kredensial)
                    );
                } catch (SmpEmailTokenException $e) {
                    DB::rollBack();
                    return $this->smpTokenFailure($e);
                }

                $targetJenjang = $smpToken->jenjang;
                $candidateGender = $smpToken->jenis_kelamin;
                $capacity->lockRegistrationCapacity($targetJenjang, $candidateGender);

                $user = User::create([
                    'name'     => $smpToken->nama,
                    'email'    => $smpToken->email,
                    'password' => Hash::make($request->password),
                    'jalur'    => 'SMP_EMAIL',
                    'jenjang'  => $smpToken->jenjang,
                ]);
                $user->forceFill(['role' => 'orang_tua'])->save();


            } else {
                // =========================================================
                // UMUM token (P3-C hardened): CSPRNG, >=64-bit entropy,
                // single-use, expiring, race-safe.
                // =========================================================
                $umumSvc = app(UmumTokenService::class);

                /** @var \App\Models\Token|null $token */
                $token = \App\Models\Token::where('code', $umumSvc->normalize($request->kredensial))
                    ->lockForUpdate() // Cegah race condition
                    ->first();

                // Re-read under the lock — this row is authoritative for this txn.
                if (!$token) {
                    DB::rollBack();
                    return response()->json(['message' => 'Token tidak valid atau sudah digunakan.'], 422);
                }

                // Jenjang is derived from the token code shape, NOT from the request.
                $targetJenjang = $umumSvc->deriveJenjang($token);

                if ($token->is_used) {
                    DB::rollBack();
                    return response()->json(['message' => 'Token tidak valid atau sudah digunakan.'], 422);
                }

                // Expiry is enforced under the lock; an expired token is never
                // consumed and never creates a user.
                if ($token->expires_at !== null && $token->expires_at->isPast()) {
                    DB::rollBack();
                    return response()->json(['message' => 'Token sudah kedaluwarsa.'], 410);
                }

                $capacity->lockRegistrationCapacity($targetJenjang);
            }

            // Role selalu 'orang_tua' — tidak bisa di-override dari request.
            // SMP_EMAIL already created its user from the locked issuance.
            if ($request->jalur !== 'SMP_EMAIL') {
                $user = User::create([
                    'name'     => $request->name,
                    'email'    => $request->email,
                    'password' => Hash::make($request->password),
                    'jalur'    => $request->jalur === 'INTERNAL' ? 'INTERNAL' : 'UMUM',
                    'jenjang'  => $targetJenjang,
                ]);

                // Paksa role via update langsung, bypass fillable
                $user->forceFill(['role' => 'orang_tua'])->save();
            }

            PpdbSeatAllocation::create([
                'user_id'       => $user->id,
                'payment_id'    => null,
                'jenjang'       => $targetJenjang,
                'jenis_kelamin' => $candidateGender,
                'status'        => PpdbSeatAllocation::STATUS_ALLOCATED,
                'allocated_at'  => now(),
            ]);

            if ($request->jalur === 'SMP_EMAIL') {
                $smpToken->consumed_at = now();
                $smpToken->used_by_user_id = $user->id;
                $smpToken->verified_at = now();
                $smpToken->save();
            }

            if ($request->jalur === 'UMUM' && isset($token)) {
                DB::table('tokens')->where('id', $token->id)->update(['user_id' => $user->id, 'is_used' => true]);
            }

            if ($request->jalur === 'INTERNAL' && isset($whitelist)) {
                DB::table('whitelists')->where('id', $whitelist->id)->update(['user_id' => $user->id, 'is_used' => true]);
            }

            DB::commit();

        } catch (CapacityException $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Terjadi kesalahan. Silakan coba lagi.'], 500);
        }

        Auth::login($user);
        // session()->regenerate() hanya dipanggil kalau session tersedia (web middleware).
        // Route /api/register berjalan tanpa session middleware (stateless API), jadi
        // memanggil ->session() akan melempar "Session store not set on request".
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'message' => 'Registrasi berhasil',
            'user'    => [
                'id'     => $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'role'   => $user->role,
                'jalur'  => $user->jalur,
                'jenjang'=> $user->jenjang,
            ],
        ]);
    }

    /**
     * P3-C: map a typed SMP email-token failure to stable HTTP semantics
     * without leaking whether the email was ever issued a token.
     */
    private function smpTokenFailure(SmpEmailTokenException $e)
    {
        return match ($e->reasonCode) {
            SmpEmailTokenException::EXPIRED      => response()->json(['message' => 'Token sudah kedaluwarsa.'], 410),
            SmpEmailTokenException::ALREADY_USED => response()->json(['message' => 'Token sudah digunakan.'], 410),
            default                              => response()->json(['message' => 'Token tidak valid.'], 422),
        };
    }

    // === 3. LOGOUT ===
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logout berhasil']);
    }

    // === 4. SOCIAL LOGIN ===
    public function redirectToProvider($provider)
    {
        // Whitelist provider yang diizinkan
        if (!in_array($provider, ['google'])) {
            return response()->json(['message' => 'Provider tidak didukung.'], 422);
        }

        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function handleProviderCallback($provider)
    {
        if (!in_array($provider, ['google'])) {
            return response()->json(['message' => 'Provider tidak didukung.'], 422);
        }

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Exception $e) {
            return response()->json(['message' => 'Autentikasi gagal.'], 401);
        }

        $user = User::where('email', $socialUser->getEmail())->first();

        if (!$user) {
            return response()->json(['message' => 'Akun tidak ditemukan. Silakan daftar terlebih dahulu.'], 404);
        }

        // Update provider token tapi tidak ubah role
        $user->forceFill([
            'provider'       => $provider,
            'provider_id'    => $socialUser->getId(),
            'provider_token' => $socialUser->token,
        ])->save();

        Auth::login($user);
        $request = request();
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Login berhasil',
            'user'    => [
                'id'     => $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'role'   => $user->role,
                'jalur'  => $user->jalur,
                'jenjang'=> $user->jenjang,
            ],
        ]);
    }
}
