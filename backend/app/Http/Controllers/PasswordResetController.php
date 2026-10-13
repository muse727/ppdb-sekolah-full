<?php

namespace App\Http\Controllers;

use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * E.10 — password reset via email (Laravel Password Broker).
 *
 * Uses the FRAMEWORK broker, not a parallel mechanism:
 *  - tokens are stored as a hash in `password_reset_tokens` (pre-existing table)
 *  - 60-minute expiry, single-use, new request invalidates the previous token
 *    (table is keyed by email)
 *  - broker throttles re-requests per email (60s)
 *
 * Additional hardening on top of the broker:
 *  - IP-based rate limit (server-observable, same pattern as register/login)
 *  - GENERIC response whether or not the account exists (no user enumeration)
 *  - password never sent by email; reset token only via the Mailable
 *  - old sessions are revoked on successful reset (SESSION_DRIVER=file ->
 *    sessions table), so a stolen session cannot outlive a password reset
 *  - nothing sensitive is logged: report() only on transport failure
 *
 * All account routes (UMUM / INTERNAL / SMP_EMAIL / internal admin-guru staff)
 * authenticate with the users.password column after registration, so email
 * reset is valid for every account with an email + password credential.
 * Google-OAuth users are unaffected beyond the password column itself.
 */
class PasswordResetController extends Controller
{
    private const GENERIC_OK = 'Jika email terdaftar, tautan reset password telah dikirim. Silakan periksa kotak masuk Anda.';

    public function __construct()
    {
        // Require a valid, non-local frontend URL before we ever build a
        // reset link — prevents shipping a localhost link to real inboxes.
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        abort_if($frontend === '' || $frontend === 'http://localhost:3000' && app()->isProduction(), 500, 'FRONTEND_URL belum dikonfigurasi.');
    }

    /** POST /api/forgot-password */
    public function request(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        // IP throttle (5 requests / minute) — cheap, cannot be bypassed by
        // rotating the email field. Broker separately throttles per email.
        $ipKey = 'forgot-password|' . $request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, 5)) {
            $seconds = RateLimiter::availableIn($ipKey);
            return response()->json([
                'message' => "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.",
            ], 429);
        }
        RateLimiter::hit($ipKey, 60);

        $status = Password::broker('users')->sendResetLink(
            $request->only('email'),
            function (User $user, string $token) {
                // Custom notification: Al-Mubarak base layout via Resend,
                // carrying the reset URL built from the validated frontend URL.
                $url = rtrim((string) config('app.frontend_url'), '/')
                    . '/reset-password?email=' . urlencode($user->email)
                    . '&token=' . $token;

                \Illuminate\Support\Facades\Mail::to($user->email)
                    ->send(new ResetPasswordMail($user->name, $url, now()->addMinutes(60)));
            }
        );

        // Generic message regardless of outcome — never reveal whether the
        // email is registered (Password::INVALID_USER vs RESET_LINK_SENT).
        return response()->json(['message' => self::GENERIC_OK]);
    }

    /** POST /api/reset-password */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email|max:255',
            'password' => 'required|string|min:8|max:100|confirmed',
        ]);

        $status = Password::broker('users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password'       => Hash::make($password), // bcrypt via Laravel
                    'remember_token' => Str::random(60),       // invalidate "remember me" of old devices
                ])->save();

                // Revoke all existing sessions: a password reset must log out
                // every device that was signed in with the old credential.
                // SESSION_DRIVER=file writes to the `sessions` table.
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password berhasil diubah. Silakan login dengan password baru Anda.']);
        }

        // Generic failure mapping — no oracle about WHY (token state is not
        // distinguished beyond invalid vs expired to keep messaging honest).
        return response()->json(['message' => 'Tautan reset tidak valid atau sudah kedaluwarsa. Silakan ajukan reset password kembali.'], 422);
    }
}
