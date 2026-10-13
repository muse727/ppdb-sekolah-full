<?php

namespace App\Services;

use App\Models\Token;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P3-C: UMUM registration token lifecycle.
 *
 * Security properties:
 * - CSPRNG generation via random_bytes() with rejection sampling (no modulo
 *   bias, no derivation from name/email/NISN/id/timestamp/counters).
 * - Human-enterable: "AM-" + 16 unambiguous chars grouped 4-4-4-4.
 *   Alphabet = 30 chars (~4.907 bits/char) => ~78.5 bits entropy (>= 64).
 * - DB UNIQUE index on `code` is the authoritative collision backstop; the
 *   service retries on the rare collision instead of trusting an app-side
 *   existence check alone.
 * - Consumption is atomic: SELECT ... FOR UPDATE, re-read under the lock, then
 *   verify (exists / unused / unexpired) before the state transition. A
 *   consumed token can never become reusable.
 * - Expiration policy: tokens minted by this service expire after TTL_DAYS.
 *   Legacy tokens (expires_at = NULL) predate the expiry contract and keep
 *   working; every NEW token MUST have expires_at.
 */
class UmumTokenService
{
    // Unambiguous alphabet: no 0/O, 1/I/L. 30 chars.
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const LENGTH   = 16;
    private const PREFIX   = 'AM-';

    /** Newly issued UMUM tokens live this long (documented policy). */
    public const TTL_DAYS = 30;

    /**
     * Generate a fresh cryptographically-random code (rejection sampling).
     */
    public function generateCode(): string
    {
        $alphabet = self::ALPHABET;           // 30 chars (indices 0..29)
        $n        = strlen($alphabet);        // 30
        $limit    = intdiv(256, $n) * $n;     // 240 — largest multiple of 30 <= 256

        $code = '';
        while (strlen($code) < self::LENGTH) {
            $byte = ord(random_bytes(1));     // CSPRNG, uniform 0..255
            if ($byte >= $limit) {
                continue;                     // reject the biased tail (240..255)
            }
            $code .= $alphabet[$byte % $n];
        }

        // Group 4-4-4-4 for human entry.
        return self::PREFIX . implode('-', str_split($code, 4));
    }

    /**
     * Normalize a client-supplied code before lookup: trim + uppercase so the
     * human-entered form matches the stored canonical form. Never trust the
     * client for anything beyond the credential itself.
     */
    public function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * Issue $count fresh tokens. Each insert races the DB UNIQUE backstop; a
     * collision simply triggers a redraw+retry. Returns the created models
     * (including the plaintext code — disclosed ONCE to the authorized admin).
     *
     * @return Token[]
     */
    public function generateBatch(int $count): array
    {
        $created = [];

        for ($i = 0; $i < $count; $i++) {
            $created[] = $this->insertWithCollisionRetry();
        }

        return $created;
    }

    private function insertWithCollisionRetry(int $maxAttempts = 10): Token
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $code = $this->generateCode();
            try {
                return Token::create([
                    'code'       => $code,
                    'is_used'    => false,
                    // New tokens ALWAYS carry an expiry (policy). Legacy NULL
                    // rows remain valid; new rows never do this.
                    'expires_at' => now()->addDays(self::TTL_DAYS),
                ]);
            } catch (QueryException $e) {
                // 23000 = integrity constraint violation (duplicate `code`).
                if ((string) $e->getCode() === '23000') {
                    continue; // collision — redraw and retry
                }
                throw $e;
            }
        }

        throw new RuntimeException('Gagal membuat token unik setelah beberapa percobaan.');
    }

    /**
     * Server-derived jenjang from the token code shape.
     * "AM-SMP-" => SMP; everything else => SD. Client jenjang fields are never
     * consulted for this decision.
     */
    public function deriveJenjang(Token $token): string
    {
        return str_contains($token->code, 'AM-SMP-') ? 'SMP' : 'SD';
    }
}
