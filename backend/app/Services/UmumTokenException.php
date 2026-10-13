<?php

namespace App\Services;

use RuntimeException;

/**
 * Typed failure for UMUM token consumption so the controller can map it to
 * stable HTTP semantics without string-matching messages.
 */
class UmumTokenException extends RuntimeException
{
    public const INVALID      = 'invalid';
    public const EXPIRED      = 'expired';
    public const ALREADY_USED = 'already_used';

    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
