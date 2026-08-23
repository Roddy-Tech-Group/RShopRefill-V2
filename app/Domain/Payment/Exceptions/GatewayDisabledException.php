<?php

namespace App\Domain\Payment\Exceptions;

use Exception;

/**
 * A payment was attempted on a gateway an admin has switched off.
 *
 * Thrown pre-flight, before any durable write, so a refused attempt costs
 * zero rows and the customer keeps their cart. The message names the rail in
 * customer language (never the provider) and frames it as temporary, matching
 * the greyed-out tiles on checkout.
 */
class GatewayDisabledException extends Exception
{
    public function __construct(public readonly string $gateway)
    {
        parent::__construct(self::customerMessageFor($gateway));
    }

    public function customerMessage(): string
    {
        return $this->getMessage();
    }

    public static function customerMessageFor(string $gateway): string
    {
        return match ($gateway) {
            // 'fiat' is thrown when NO fiat gateway (primary or backup) can
            // take the payment; the customer-facing wording is identical to
            // the flutterwave case because customers see rails, not vendors.
            'flutterwave', 'paystack', 'korapay', 'fiat' => 'Card and bank payments are temporarily unavailable. Please try another payment method or check back shortly.',
            'nowpayments' => 'Crypto payments are temporarily unavailable. Please try another payment method or check back shortly.',
            'wallet' => 'Wallet payments are temporarily unavailable. Please try another payment method or check back shortly.',
            default => 'This payment method is temporarily unavailable. Please try another payment method or check back shortly.',
        };
    }
}
