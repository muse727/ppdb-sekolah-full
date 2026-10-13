<?php

namespace App\Services;

use App\Mail\TokenSmpMail;
use App\Models\SmpEmailToken;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * P3-C: backend-authoritative SMP email-token lifecycle.
 *
 * Security properties:
 * - Generation happens ONLY here, server-side, via random_bytes() with
 *   rejection sampling (no Math.random / rand / mt_rand / uniqid / timestamp /
 *   user-derived input anywhere in the credential path).
 * - Code = "SMP-" + 16 unambiguous chars grouped 4-4-4-4. 30-char alphabet
 *   (~4.907 bits/char) => ~78.5 bits entropy (>= 64).
 * - Only the HMAC-SHA256 digest (keyed with APP_KEY) is stored. The raw code
 *   is disclosed exactly once: inside the transactional email to the bound
 *   address. It is never written to logs and never returned by list/admin
 *   endpoints.
 * - Identity binding (email + nama + jenjang) is fixed at issuance inside the
 *   email serialization lock; request fields can never rebind it afterwards.
 * - Resend invalidates the previous active token: verification accepts ONLY
 *   the latest issuance for the email; older codes are rejected.
 * - Single-use: verification consumes the issuance atomically (row lock +
 *   re-read) and registration binds it to exactly one user.
 */
class SmpEmailTokenService
{
    // Unambiguous alphabet: no 0/O, 1/I/L. 30 chars (~4.907 bits/char).
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const LENGTH   = 16;
    private const PREFIX   = 'SMP-';

    /** Newly issued SMP email tokens live this long (documented policy). */
    public const TTL_HOURS = 24;

    /**
     * Keyed digest of a normalized code. HMAC-SHA256 with the application key
     * as pepper: DB compromise alone cannot recover codes, and lookup remains
     * an indexed exact match (deterministic normalization + random secret, so
     * a per-row salted slow hash is unnecessary — there is no user-chosen
     * password here to brute-force offline at scale).
     */
    public function digest(string $normalizedCode): string
    {
        return hash_hmac('sha256', $normalizedCode, (string) config('app.key'));
    }

    /** CSPRNG code, rejection sampling (no modulo bias). */
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

        return self::PREFIX . implode('-', str_split($code, 4));
    }

    /** Canonical form for lookup: trim + uppercase (human-enterable). */
    public function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * Issue a new token for (email, nama) and send it. Any previous active
     * token for this email is implicitly invalidated because verification only
     * accepts the LATEST issuance.
     *
     * The advisory lock serializes all issuance/verification for the same
     * email so concurrent resends/races cannot interleave ambiguously.
     *
     * Returns the created issuance row (digest only — never the raw code).
     *
     * @throws \Throwable if the mail transport fails (the caller must NOT
     *                    report success in that case — no fake delivery).
     */
    public function issueAndSend(string $email, string $nama): SmpEmailToken
    {
        $email = strtolower(trim($email));
        $lock  = $this->emailLock($email);

        // A `log`/`array` transport discards the message instead of delivering
        // it. Outside local dev/testing that is a SILENT fake delivery — the
        // parent would see HTTP 200 yet never receive the code. Fail loudly
        // instead so the controller reports an honest 502.
        if (in_array(config('mail.default'), ['log', 'array'], true)
            && !in_array(app()->environment(), ['local', 'testing'], true)) {
            throw new \RuntimeException('Mail transport misconfigured for this environment (non-delivering mailer).');
        }

        // Resend requires a configured API key. A missing key would surface as
        // a 401 at send-time anyway, but failing early (before the issuance row
        // is persisted) keeps the token table free of undeliverable rows and
        // gives a clearer signal in the log — without leaking the key itself.
        if (config('mail.default') === 'resend'
            && empty(trim((string) config('services.resend.key')))) {
            throw new \RuntimeException('Resend mailer selected but RESEND_API_KEY is not configured.');
        }

        try {
            $code     = $this->generateCode();
            $digest   = $this->digest($this->normalize($code));
            $issuedAt = now();

            $row = SmpEmailToken::create([
                'token_digest' => $digest,
                'email'        => $email,
                'nama'         => trim($nama),
                'jenjang'      => 'SMP',
                'issued_at'    => $issuedAt,
                'expires_at'   => $issuedAt->copy()->addHours(self::TTL_HOURS),
            ]);

            // Delivery boundary: the raw secret exists ONLY inside this email.
            // A transport exception propagates — the API must not fake success.
            Mail::to($email)->send(new TokenSmpMail(trim($nama), $code, $row->expires_at));

            return $row;
        } finally {
            $lock->release();
        }
    }

    /**
     * Verify + consume a code for registration. Atomic and single-use.
     *
     * MUST be called inside an open DB transaction (the caller owns the
     * surrounding user-creation work so everything commits or rolls back
     * together). The issuance row is re-read under FOR UPDATE before any
     * state transition.
     *
     * @throws SmpEmailTokenException with a stable reason code on failure.
     */
    public function consumeForRegistration(string $email, string $normalizedCode, ?int $userId = null): SmpEmailToken
    {
        $row = $this->lockForRegistration($email, $normalizedCode);
        $row->consumed_at = now();
        $row->used_by_user_id = $userId;
        $row->save();

        return $row;
    }

    /** Validate without consumption; the row lock is held until caller commits. */
    public function lockForRegistration(string $email, string $normalizedCode): SmpEmailToken
    {
        if (\Illuminate\Support\Facades\DB::transactionLevel() < 1) {
            throw new \LogicException('Credential locking requires a transaction.');
        }
        $email = strtolower(trim($email));
        $lock  = $this->emailLock($email);

        try {
            // Latest issuance for this email is the ONLY acceptable one.
            /** @var SmpEmailToken|null $row */
            $row = SmpEmailToken::where('email', $email)
                ->orderByDesc('issued_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (!$row) {
                throw new SmpEmailTokenException(SmpEmailTokenException::INVALID);
            }

            // Re-read under the lock — row is now authoritative for this txn.
            if (!hash_equals($row->token_digest, $this->digest($normalizedCode))) {
                // A wrong digest is rejected. Whether it matches an OLDER
                // (superseded) issuance or nothing at all is intentionally NOT
                // distinguished in the response — no enumeration side channel.
                throw new SmpEmailTokenException(SmpEmailTokenException::INVALID);
            }
            if ($row->consumed_at !== null) {
                throw new SmpEmailTokenException(SmpEmailTokenException::ALREADY_USED);
            }
            if ($row->expires_at->isPast()) {
                throw new SmpEmailTokenException(SmpEmailTokenException::EXPIRED);
            }

            return $row;
        } finally {
            $lock->release();
        }
    }

    /**
     * Non-consuming status check for the UX pre-check endpoint. Does NOT
     * mutate anything.
     */
    public function checkStatus(string $email, string $normalizedCode): string
    {
        $email = strtolower(trim($email));

        $row = SmpEmailToken::where('email', $email)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->first();

        if (!$row || !hash_equals($row->token_digest, $this->digest($normalizedCode))) {
            return SmpEmailTokenException::INVALID;
        }
        if ($row->consumed_at !== null) {
            return SmpEmailTokenException::ALREADY_USED;
        }
        if ($row->expires_at->isPast()) {
            return SmpEmailTokenException::EXPIRED;
        }

        return 'valid';
    }

    /**
     * Admin-safe metadata: no digest, no raw code, no secret material.
     */
    public function adminMetadata(): \Illuminate\Support\Collection
    {
        return SmpEmailToken::orderByDesc('issued_at')
            ->get(['id', 'email', 'nama', 'jenjang', 'issued_at', 'expires_at', 'consumed_at', 'verified_at', 'used_by_user_id'])
            ->map(fn (SmpEmailToken $t) => [
                'id'              => $t->id,
                'email'           => $t->email,
                'nama'            => $t->nama,
                'jenjang'         => $t->jenjang,
                'issued_at'       => $t->issued_at,
                'expires_at'      => $t->expires_at,
                'is_expired'      => $t->expires_at->isPast(),
                'is_consumed'     => $t->consumed_at !== null,
                'verified_at'     => $t->verified_at,
                'used_by_user_id' => $t->used_by_user_id,
            ]);
    }

    /**
     * Per-email advisory serialization for issuance/consumption. Uses a cache
     * lock (works on every driver) and is always released in `finally`.
     */
    private function emailLock(string $email): Lock
    {
        $lock = Cache::lock('smp-email-token|' . sha1($email), 15);
        $lock->block(10);

        return $lock;
    }
}
