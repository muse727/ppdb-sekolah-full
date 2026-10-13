<?php

namespace App\Services;

use RuntimeException;

/**
 * Typed failure for SMP email-token verify/consume so the controller can map
 * it to stable HTTP semantics without string-matching messages.
 */
class SmpEmailTokenException extends RuntimeException
{
    public const INVALID      = 'invalid';
    public const EXPIRED      = 'expired';
    public const ALREADY_USED = 'already_used';
    public const SUPERSEDED   = 'superseded';

    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
