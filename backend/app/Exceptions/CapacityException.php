<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a capacity domain invariant is violated:
 *   - jenjang has no remaining seat (JENJANG_FULL)
 *   - payment already has an allocation (DUPLICATE_ALLOCATION)
 *   - user already holds an active seat (USER_ALREADY_HAS_SEAT)
 *   - new max below active allocation count (MAX_BELOW_ACTIVE)
 *
 * The `code` is a stable machine-readable reason so controllers can map it to
 * the correct HTTP status + message without string-matching.
 */
class CapacityException extends RuntimeException
{
    public const JENJANG_FULL         = 'JENJANG_FULL';
    public const DUPLICATE_ALLOCATION = 'DUPLICATE_ALLOCATION';
    public const USER_ALREADY_HAS_SEAT = 'USER_ALREADY_HAS_SEAT';
    public const MAX_BELOW_ACTIVE     = 'MAX_BELOW_ACTIVE';
    public const INVALID_JENJANG        = 'INVALID_JENJANG';
    public const ALLOCATION_NOT_FOUND  = 'ALLOCATION_NOT_FOUND';
    public const INVALID_ALLOCATION    = 'INVALID_ALLOCATION';
    public const INVALID_RELEASE_REASON = 'INVALID_RELEASE_REASON';

    public function __construct(string $reasonCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reasonCode);
        $this->code = 0;
        $this->reasonCode = $reasonCode;
    }

    public string $reasonCode;
}
