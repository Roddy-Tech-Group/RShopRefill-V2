<?php

namespace App\Domain\Wallet\Exceptions;

use Exception;

/**
 * No exchange rate can be trusted for a conversion that is about to move money.
 *
 * Two things make a rate unusable: the one we have is too old to charge at
 * (StaleRateException), or there is no rate at all for one of the currencies
 * (UnknownCurrencyException). Callers that only want to know "can I price this
 * right now?" catch this parent; the two children exist so logs and tests can
 * tell the cases apart.
 */
class RateUnavailableException extends Exception
{
    //
}
