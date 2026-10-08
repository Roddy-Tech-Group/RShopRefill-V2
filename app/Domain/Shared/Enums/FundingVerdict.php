<?php

namespace App\Domain\Shared\Enums;

/**
 * What the gateway said when asked whether a wallet top-up was paid.
 *
 * Paid and NotPaid are the gateway's own verdicts and may be acted on.
 * Unknown means it could not be asked (an outage, a rate limit, a lookup
 * that returned nothing): nothing is known about the money, so nothing may
 * be failed or credited on its account; the question is asked again later.
 */
enum FundingVerdict: string
{
    case Paid = 'paid';
    case NotPaid = 'not_paid';
    case Unknown = 'unknown';
}
