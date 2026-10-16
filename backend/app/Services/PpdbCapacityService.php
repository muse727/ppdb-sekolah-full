<?php

namespace App\Services;

use App\Exceptions\CapacityException;
use App\Models\PpdbCapacity;
use App\Models\PpdbSeatAllocation;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * PPDB capacity domain service.
 *
 * The DATABASE is authoritative. Remaining seats are ALWAYS derived as
 *   remaining = max_seats - COUNT(active ALLOCATED allocations)
 * and never stored as a writable column.
 *
 * SD and SMP are independent rows in ppdb_capacities, so each is locked and
 * mutated independently; one jenjang's exhaustion cannot affect the other.
 */
class PpdbCapacityService
{
    public const JENJANGS = ['SD', 'SMP'];
    public const RELEASE_NOT_ACCEPTED = 'NOT_ACCEPTED';
    // B.7.1: Daftar Ulang withdrawal releases the student's EXISTING initial
    // PPDB seat with its own reason. NOT_ACCEPTED stays the academic-failure
    // lifecycle event; WITHDRAWN is the parent-initiated Daftar Ulang event.
    // Both reasons are released through a guarded, exactly-once engine.
    public const RELEASE_WITHDRAWN = 'WITHDRAWN';
    public const RELEASE_REASONS = [self::RELEASE_NOT_ACCEPTED, self::RELEASE_WITHDRAWN];

    // ── Queries ─────────────────────────────────────────────

    public function getCapacity(string $jenjang): array
    {
        $jenjang = strtoupper($jenjang);
        $row     = PpdbCapacity::find($jenjang);
        $max     = $row ? (int) $row->max_seats : 0;
        $used    = $this->activeCount($jenjang);

        return [
            'jenjang'  => $jenjang,
            'total'    => $max,
            'terpakai' => $used,
            'sisa'     => max(0, $max - $used),
            'tersedia' => ($max - $used) > 0,
        ];
    }

    public function activeCount(string $jenjang): int
    {
        return (int) PpdbSeatAllocation::where('jenjang', $jenjang)
            ->where('status', PpdbSeatAllocation::STATUS_ALLOCATED)
            ->count();
    }

    public function hasRemaining(string $jenjang): bool
    {
        return $this->getCapacity($jenjang)['sisa'] > 0;
    }

    public function isGlobalOpen(): bool
    {
        return (Setting::where('key', 'ppdb_status')->value('value') ?? 'TUTUP') === 'BUKA';
    }

    public function isUmumOpen(): bool
    {
        // Global switch harus BUKA dan gate_jalur_umum harus BUKA
        if (!$this->isGlobalOpen()) return false;
        return (Setting::where('key', 'gate_jalur_umum')->value('value') ?? 'TUTUP') === 'BUKA';
    }

    public function isInternalOpen(): bool
    {
        // Global switch harus BUKA dan gate_jalur_internal harus BUKA
        if (!$this->isGlobalOpen()) return false;
        return (Setting::where('key', 'gate_jalur_internal')->value('value') ?? 'BUKA') === 'BUKA';
    }

    public function getSmpGenderCapacity(): array
    {
        $maxIkhwan = (int) (Setting::where('key', 'kuota_smp_ikhwan')->value('value') ?? 30);
        $maxAkhwat = (int) (Setting::where('key', 'kuota_smp_akhwat')->value('value') ?? 30);

        // Hitung alokasi terpakai per gender di SMP
        $usedIkhwan = (int) PpdbSeatAllocation::where('jenjang', 'SMP')
            ->where('status', PpdbSeatAllocation::STATUS_ALLOCATED)
            ->where('jenis_kelamin', 'L')
            ->count();

        $usedAkhwat = (int) PpdbSeatAllocation::where('jenjang', 'SMP')
            ->where('status', PpdbSeatAllocation::STATUS_ALLOCATED)
            ->where('jenis_kelamin', 'P')
            ->count();

        return [
            'ikhwan' => [
                'total'    => $maxIkhwan,
                'terpakai' => $usedIkhwan,
                'sisa'     => max(0, $maxIkhwan - $usedIkhwan),
                'tersedia' => ($maxIkhwan - $usedIkhwan) > 0,
            ],
            'akhwat' => [
                'total'    => $maxAkhwat,
                'terpakai' => $usedAkhwat,
                'sisa'     => max(0, $maxAkhwat - $usedAkhwat),
                'tersedia' => ($maxAkhwat - $usedAkhwat) > 0,
            ],
            'total'    => $maxIkhwan + $maxAkhwat,
            'terpakai' => $usedIkhwan + $usedAkhwat,
            'sisa'     => max(0, ($maxIkhwan + $maxAkhwat) - ($usedIkhwan + $usedAkhwat)),
            'tersedia' => (($maxIkhwan + $maxAkhwat) - ($usedIkhwan + $usedAkhwat)) > 0,
        ];
    }

    /** Registration reserves capacity before creating its canonical user owner. */
    public function lockRegistrationCapacity(string $jenjang, ?string $gender = null): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Registration capacity requires a transaction.');
        }
        if (!in_array($jenjang, self::JENJANGS, true)) {
            throw new CapacityException(CapacityException::INVALID_JENJANG, 'Jenjang tidak valid.');
        }

        $capacity = PpdbCapacity::where('jenjang', $jenjang)->lockForUpdate()->first();
        // A locking read sees the latest committed seats even at REPEATABLE READ.
        $activeQuery = PpdbSeatAllocation::where('jenjang', $jenjang)
            ->where('status', PpdbSeatAllocation::STATUS_ALLOCATED)
            ->lockForUpdate();

        $active = $activeQuery->pluck('id')->count();
        if (!$capacity || $active >= (int) $capacity->max_seats) {
            throw new CapacityException(CapacityException::JENJANG_FULL, "Kuota {$jenjang} sudah penuh.");
        }

        // Cek kuota gender khusus SMP jika gender disertakan
        if ($jenjang === 'SMP' && $gender !== null) {
            $g = strtoupper($gender);
            if ($g === 'L') {
                $maxL = (int) (Setting::where('key', 'kuota_smp_ikhwan')->value('value') ?? 30);
                $activeL = PpdbSeatAllocation::where('jenjang', 'SMP')
                    ->where('status', PpdbSeatAllocation::STATUS_ALLOCATED)
                    ->where('jenis_kelamin', 'L')
                    ->count();
                if ($activeL >= $maxL) {
                    throw new CapacityException(CapacityException::JENJANG_FULL, "Kuota SMPIT Putra (Ikhwan) sudah penuh.");
                }
            } elseif ($g === 'P') {
                $maxP = (int) (Setting::where('key', 'kuota_smp_akhwat')->value('value') ?? 30);
                $activeP = PpdbSeatAllocation::where('jenjang', 'SMP')
                    ->where('status', PpdbSeatAllocation::STATUS_ALLOCATED)
                    ->where('jenis_kelamin', 'P')
                    ->count();
                if ($activeP >= $maxP) {
                    throw new CapacityException(CapacityException::JENJANG_FULL, "Kuota SMPIT Putri (Akhwat) sudah penuh.");
                }
            }
        }
    }

    // ── Mutations ───────────────────────────────────────────

    /**
     * Consume exactly one seat for a jenjang. RACE-SAFE: locks the jenjang
     * capacity row, re-counts active allocations under that lock, rejects when
     * full, and prevents a duplicate allocation for the same payment/user.
     *
     * Legacy allocation helper; not used by payment verification. Must run
     * inside an existing transaction. Released history never grants a second
     * allocation to the same candidate.
     *
     * @throws CapacityException JENJANG_FULL | DUPLICATE_ALLOCATION | USER_ALREADY_HAS_SEAT | INVALID_JENJANG
     */
    public function consumeSeat(string $jenjang, int $userId, int $paymentId): PpdbSeatAllocation
    {
        $jenjang = strtoupper($jenjang);
        if (!in_array($jenjang, self::JENJANGS, true)) {
            throw new CapacityException(CapacityException::INVALID_JENJANG, "Jenjang tidak valid: {$jenjang}");
        }

        // Lock the jenjang capacity row FOR UPDATE to serialize concurrent
        // consumers of this jenjang. If the row doesn't exist yet we treat max
        // as 0 (no remaining). Insert-if-missing is done via a locked read on a
        // sentinel: we upsert the row first so there is always a row to lock.
        PpdbCapacity::firstOrCreate(['jenjang' => $jenjang], ['max_seats' => 0]);
        $capacity = PpdbCapacity::where('jenjang', $jenjang)->lockForUpdate()->first();
        $max      = (int) $capacity->max_seats;

        // Under the capacity-row lock, re-count active allocations.
        $active = $this->activeCount($jenjang);

        if ($max - $active <= 0) {
            throw new CapacityException(CapacityException::JENJANG_FULL, "Kuota {$jenjang} sudah penuh.");
        }

        // Idempotency / one-payment-one-seat: if this payment already has an
        // allocation, return it instead of creating a second.
        $existing = PpdbSeatAllocation::where('payment_id', $paymentId)->first();
        if ($existing) {
            throw new CapacityException(CapacityException::DUPLICATE_ALLOCATION, 'Pembayaran ini sudah memiliki alokasi kursi.');
        }

        // Allocation history is permanent: no second seat after release.
        $userActive = PpdbSeatAllocation::where('user_id', $userId)->exists();
        if ($userActive) {
            throw new CapacityException(CapacityException::USER_ALREADY_HAS_SEAT, 'Pengguna sudah memiliki kursi aktif.');
        }

        return PpdbSeatAllocation::create([
            'user_id'      => $userId,
            'payment_id'   => $paymentId,
            'jenjang'      => $jenjang,
            'status'       => PpdbSeatAllocation::STATUS_ALLOCATED,
            'allocated_at' => now(),
        ]);
    }

    /**
     * NOT_ACCEPTED is a status transition, not a generic seat mutation. The
     * lifecycle service locks capacity, owner and history and commits both states.
     *
     * This generic path is ONLY for NOT_ACCEPTED: it force-transitions the user to
     * TIDAK_LULUS. The WITHDRAWN reason is deliberately excluded here — a Daftar
     * Ulang withdrawal must NOT touch status_kelulusan and is released exclusively
     * through DaftarUlangWithdrawalService, which owns that dedicated lifecycle.
     */
    public function releaseSeat(PpdbSeatAllocation $allocation, string $reason): PpdbSeatAllocation
    {
        if ($reason !== self::RELEASE_NOT_ACCEPTED) {
            throw new CapacityException(CapacityException::INVALID_RELEASE_REASON, 'Alasan pelepasan kursi tidak valid.');
        }

        $seats = app(KelulusanLifecycleService::class)->transition(
            [$allocation->user_id => 'TIDAK_LULUS'], (int) $allocation->getKey()
        );
        return $seats[$allocation->user_id];
    }

    /**
     * Apply the NOT_ACCEPTED lifecycle event to a canonical user. Repeated
     * events return the existing released history without rewriting it.
     */
    public function releaseSeatForUser(int $userId, string $reason): ?PpdbSeatAllocation
    {
        if ($reason !== self::RELEASE_NOT_ACCEPTED) {
            throw new CapacityException(CapacityException::INVALID_RELEASE_REASON, 'Alasan pelepasan kursi tidak valid.');
        }
        return app(KelulusanLifecycleService::class)->transition([$userId => 'TIDAK_LULUS'])[$userId];
    }

    /**
     * Raise (or change) the configured total for a jenjang.
     * Lowering below the current ACTIVE allocation count is REJECTED.
     *
     * @throws CapacityException MAX_BELOW_ACTIVE | INVALID_JENJANG
     */
    public function increaseMaxCapacity(string $jenjang, int $newMax): array
    {
        $jenjang = strtoupper($jenjang);
        if (!in_array($jenjang, self::JENJANGS, true)) {
            throw new CapacityException(CapacityException::INVALID_JENJANG, "Jenjang tidak valid: {$jenjang}");
        }

        return DB::transaction(function () use ($jenjang, $newMax) {
            PpdbCapacity::firstOrCreate(['jenjang' => $jenjang], ['max_seats' => 0]);
            $capacity = PpdbCapacity::where('jenjang', $jenjang)->lockForUpdate()->first();
            $active   = $this->activeCount($jenjang);

            if ($newMax < $active) {
                throw new CapacityException(
                    CapacityException::MAX_BELOW_ACTIVE,
                    "Kapasitas {$jenjang} tidak boleh lebih kecil dari kursi terpakai ({$active})."
                );
            }

            $capacity->max_seats = $newMax;
            $capacity->save();

            return $this->getCapacity($jenjang);
        });
    }

    /**
     * If BOTH jenjang are full, auto-close the global gate. Otherwise leave it
     * unchanged. Returns the resulting global status. Called after a consume.
     */
    public function maybeAutoCloseGlobal(): string
    {
        $sdFull  = !$this->hasRemaining('SD');
        $smpFull = !$this->hasRemaining('SMP');

        if ($sdFull && $smpFull) {
            Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'TUTUP']);
            Setting::updateOrCreate(['key' => 'ppdb_auto_closed'], ['value' => '1']);
        }

        return $this->isGlobalOpen() ? 'BUKA' : 'TUTUP';
    }

    /** Public quota payload for GET /api/public/kuota. */
    public function publicQuota(): array
    {
        $sd  = $this->getCapacity('SD');
        $smp = $this->getCapacity('SMP');
        $smpGender = $this->getSmpGenderCapacity();

        return [
            'status'         => $this->isGlobalOpen() ? 'BUKA' : 'TUTUP',
            'status_umum'    => $this->isUmumOpen() ? 'BUKA' : 'TUTUP',
            'status_internal'=> $this->isInternalOpen() ? 'BUKA' : 'TUTUP',
            'sd'     => [
                'total'    => $sd['total'],
                'terpakai' => $sd['terpakai'],
                'sisa'     => $sd['sisa'],
                'tersedia' => $sd['tersedia'],
            ],
            'smp'    => [
                'total'    => $smp['total'],
                'terpakai' => $smp['terpakai'],
                'sisa'     => $smp['sisa'],
                'tersedia' => $smp['tersedia'],
                'ikhwan'   => $smpGender['ikhwan'],
                'akhwat'   => $smpGender['akhwat'],
            ],
        ];
    }
}
