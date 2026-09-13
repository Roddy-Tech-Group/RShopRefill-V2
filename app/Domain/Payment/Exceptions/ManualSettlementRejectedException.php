<?php

namespace App\Domain\Payment\Exceptions;

use Exception;

/**
 * An operator asked to settle a short crypto payment that is not in a state
 * where that is allowed. The message is written for the admin screen.
 */
class ManualSettlementRejectedException extends Exception
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
