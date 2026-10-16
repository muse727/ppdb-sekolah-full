<?php

namespace App\Http\Controllers;

use App\Services\SmpEmailTokenException;
use App\Services\SmpEmailTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * P3-C: public SMP email-token endpoints.
 *
 * - POST /api/smp/email-token/request : issue + email a fresh token.
 *   Throttled separately from /api/register (per-email AND per-IP) to prevent
 *   email-bombing/spam, WITHOUT weakening the register throttle.
 * - POST /api/smp/email-token/verify  : non-consuming status check for UX.
 *   Consumption itself happens atomically inside /api/register.
 *
 * The raw secret is NEVER returned by these endpoints. It is disclosed exactly
 * once: inside the transactional email to the bound address.
 */
class SmpEmailTokenController extends Controller
{
    public function __construct(private readonly SmpEmailTokenService $svc) {}

    public function request(Request $request, \App\Services\PpdbCapacityService $capacity)
    {
        // Cek gate PPDB umum
        if (!$capacity->isUmumOpen()) {
            return response()->json([
                'message' => 'Pendaftaran & permintaan token Jalur Umum belum dibuka. Saat ini hanya dibuka untuk Jalur Internal.',
            ], 403);
        }

        $validated = $request->validate([
            'nama'          => 'required|string|max:255',
            'email'         => 'required|email|max:255',
            'jenis_kelamin' => 'nullable|in:L,P',
        ]);

        $email  = strtolower($validated['email']);
        $gender = $validated['jenis_kelamin'] ?? null;

        // Cek kuota spesifik per gender untuk SMP jika ada gender
        if ($gender) {
            $gCaps = $capacity->getSmpGenderCapacity();
            if ($gender === 'L' && $gCaps['ikhwan']['sisa'] <= 0) {
                return response()->json(['message' => 'Kuota SMPIT Putra (Ikhwan) sudah penuh.'], 422);
            }
            if ($gender === 'P' && $gCaps['akhwat']['sisa'] <= 0) {
                return response()->json(['message' => 'Kuota SMPIT Putri (Akhwat) sudah penuh.'], 422);
            }
        }

        // Separate, bounded abuse throttle. Keyed on the server-meaningful
        // identity (email) AND the request IP so a single actor cannot spam
        // one inbox, and one IP cannot fan out across many inboxes.
        $emailKey = 'smp-token-req|email|' . sha1($email);
        $ipKey    = 'smp-token-req|ip|' . $request->ip();

        foreach ([[$emailKey, 3], [$ipKey, 20]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $seconds = RateLimiter::availableIn($key);
                return response()->json([
                    'message' => "Terlalu banyak permintaan token. Coba lagi dalam {$seconds} detik.",
                ], 429);
            }
        }

        // Count the attempt BEFORE doing work so delivery failures still cost
        // budget (prevents using a broken transport as a free spam loop).
        RateLimiter::hit($emailKey, 3600);
        RateLimiter::hit($ipKey, 3600);

        try {
            $this->svc->issueAndSend($email, $validated['nama'], $gender);
        } catch (\Throwable $e) {
            // Delivery/lifecycle failure: do NOT fake success. Report honestly
            // and do not leak internals.
            report($e);
            return response()->json([
                'message' => 'Gagal mengirim token ke email. Silakan coba lagi nanti.',
            ], 502);
        }

        // Generic success — never echoes whether the email was previously
        // issued, and never includes the token.
        return response()->json([
            'message' => 'Jika data valid, kode token telah dikirim ke email Anda.',
        ], 200);
    }

    /**
     * Non-consuming status check (UX pre-check). Distinct, stable HTTP
     * semantics per the existing API contract style, without revealing
     * anything about OTHER identities.
     */
    public function verify(Request $request)
    {
        $request->validate([
            'email' => 'required|email|max:255',
            'token' => 'required|string|max:40',
        ]);

        $status = $this->svc->checkStatus(
            strtolower((string) $request->input('email')),
            $this->svc->normalize((string) $request->input('token'))
        );

        return match ($status) {
            'valid'                              => response()->json(['status' => 'valid'], 200),
            SmpEmailTokenException::EXPIRED      => response()->json(['status' => 'expired', 'message' => 'Token sudah kedaluwarsa.'], 410),
            SmpEmailTokenException::ALREADY_USED => response()->json(['status' => 'used',    'message' => 'Token sudah digunakan.'], 410),
            default                              => response()->json(['status' => 'invalid', 'message' => 'Token tidak valid.'], 422),
        };
    }

    /**
     * P3-C: admin audit view of SMP email-token issuances. Masked metadata
     * only — no digest, no raw code, no secret material.
     */
    public function adminIndex()
    {
        return response()->json($this->svc->adminMetadata());
    }
}
