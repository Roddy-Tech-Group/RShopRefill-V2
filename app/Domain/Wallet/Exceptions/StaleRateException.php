<?php

namespace App\Domain\Wallet\Exceptions;

/**
 * The live rate for a pair is too old to charge at. See RateUnavailableException.
 */
class StaleRateException extends RateUnavailableException
{
    //
}
