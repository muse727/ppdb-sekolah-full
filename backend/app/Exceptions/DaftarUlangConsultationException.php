<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a Daftar Ulang consultation invariant is violated (B.7.4-B):
 *   - CONSULTATION_ACTIVE_EXISTS  : the invoice already has an ACTIVE consultation
 *   - CONSULTATION_NOT_ACTIVE     : the consultation is COMPLETED/CANCELLED
 *   - CONSULTATION_ILLEGAL_MOVE   : the requested edge is not in the state machine
 *                                   (e.g. REQUESTED -> COMPLETED)
 *   - CONSULTATION_FORBIDDEN_ROLE : an admin-only or parent-only action was
 *                                   attempted by the other side
 *
 * Following the CapacityException precedent, `reasonCode` is a stable
 * machine-readable reason so the controller can map it to the correct HTTP
 * status (409 for a state conflict, 422 for a bad request, 403 for a role
 * breach) WITHOUT string-matching the message.
 *
 * This is deliberately a distinct exception from ValidationException: an illegal
 * transition is a CONFLICT with concurrently-committed state, not malformed
 * input, so it must not be reported as a 422 validation error.
 */
class DaftarUlangConsultationException extends RuntimeException
{
    public const CONSULTATION_ACTIVE_EXISTS = 'CONSULTATION_ACTIVE_EXISTS';
    public const CONSULTATION_NOT_ACTIVE = 'CONSULTATION_NOT_ACTIVE';
    public const CONSULTATION_ILLEGAL_MOVE = 'CONSULTATION_ILLEGAL_MOVE';
    public const CONSULTATION_FORBIDDEN_ROLE = 'CONSULTATION_FORBIDDEN_ROLE';

    /** Reason codes that represent a state conflict -> HTTP 409. */
    private const CONFLICT_CODES = [
        self::CONSULTATION_ACTIVE_EXISTS,
        self::CONSULTATION_NOT_ACTIVE,
        self::CONSULTATION_ILLEGAL_MOVE,
    ];

    public string $reasonCode;

    public function __construct(string $reasonCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reasonCode);
        $this->code = 0;
        $this->reasonCode = $reasonCode;
    }

    /** The HTTP status this conflict must be reported with. */
    public function httpStatus(): int
    {
        return match ($this->reasonCode) {
            self::CONSULTATION_FORBIDDEN_ROLE => 403,
            default => in_array($this->reasonCode, self::CONFLICT_CODES, true) ? 409 : 422,
        };
    }
}
