<?php

namespace App\Domain\Wallet\Exceptions;

/**
 * A currency with no active rate was asked to take part in a charge.
 *
 * The rate table used to answer this with the USD row, so a code it had never
 * heard of converted at 1.0: a $5 cart posted as "ZZZ" became an order for
 * 5.00 ZZZ and that figure went to the gateway. Three letters are not a
 * currency; a live row in the rate table is. The message is written for the
 * customer, because more than one controller shows exception text verbatim.
 */
class UnknownCurrencyException extends RateUnavailableException
{
    public function __construct(public readonly string $currency)
    {
        parent::__construct("We cannot price this payment in {$currency} right now. Please choose another currency.");
    }
}
