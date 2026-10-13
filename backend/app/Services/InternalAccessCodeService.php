<?php

namespace App\Services;

use App\Models\Whitelist;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P4-D.2 — INTERNAL candidate credential lifecycle.
 *
 * The SOLE credential for jalur INTERNAL is a single 10-digit random
 * registration_number (e.g. 5831047291). There is no NISN, no NIK, and no
 * second access_code. The number is a BEARER credential: possessing it is both
 * necessary and sufficient to register the bound candidate.
 *
 * Generation policy:
 *   - CSPRNG only (random_int), one decimal digit at a time.
 *   - Leading zero is a legitimate value — the number is stored/validated as a
 *     10-character STRING, never an integer.
 *   - Never derived from nisn / nik / nama / tanggal_lahir / id / timestamp /
 *     counter.
 *
 * Entropy note (honest): a 10-digit space is ~10^10 (~33.2 bits), NOT 64+ bits.
 * Security therefore does NOT rest on secrecy of a large space — it rests on
 * the surrounding controls: registration throttling, single-use consumption,
 * lockForUpdate, the UNIQUE constraint, and a generic invalid-number error that
 * gives no enumeration oracle. Do not treat this as a high-entropy token.
 */
class InternalAccessCodeService
{
    private const LENGTH = 10;

    /**
     * Generate a fresh cryptographically-random 10-digit registration number.
     *
     * Collision-safe: the random draw is checked against existing numbers and
     * the DB UNIQUE index on registration_number is the authoritative backstop.
     * random_int(0, 9) is unbiased (CSPRNG over a 10-element range).
     */
    public function generateCode(): string
    {
        do {
            $number = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $number .= (string) random_int(0, 9); // CSPRNG digit; leading zero allowed
            }
        } while (Whitelist::where('registration_number', $number)->exists());

        return $number;
    }

    /**
     * READ-ONLY accessor for the candidate's CURRENT registration number.
     *
     * This is the backing call for "Salin Nomor". It performs ZERO mutation:
     * no new number is drawn, registration_number is untouched, is_used and
     * user_id are untouched, and updated_at is not bumped.
     *
     * Repeated calls always return the identical stored value, which is what
     * makes "copy" safe to click any number of times.
     *
     * @throws RuntimeException when the candidate exists but has no number yet.
     *                          The caller must surface that as an error — it
     *                          must NEVER implicitly generate a replacement.
     */
    public function currentNumber(Whitelist $candidate): string
    {
        $number = $candidate->registration_number;

        if ($number === null || $number === '') {
            throw new RuntimeException('Kandidat belum memiliki nomor pendaftaran.');
        }

        return (string) $number;
    }

    /**
     * Generate (or regenerate) the registration number for an UNUSED candidate.
     * Regeneration atomically replaces the old number, immediately invalidating
     * it. A used candidate's number can never be regenerated.
     *
     * This is the ONLY replacement path in the system. "Salin Nomor" must never
     * reach it — copying is read-only, see currentNumber().
     *
     * @throws RuntimeException when the candidate is already used (409 semantics).
     */
    public function regenerate(Whitelist $candidate): Whitelist
    {
        return DB::transaction(function () use ($candidate) {
            $locked = Whitelist::whereKey($candidate->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new RuntimeException('Kandidat tidak ditemukan.');
            }
            if ($locked->is_used) {
                throw new RuntimeException('Kandidat sudah mendaftar. Nomor tidak dapat diubah.');
            }

            $locked->registration_number = $this->generateCode();
            $locked->save();

            return $locked->refresh();
        });
    }
}
