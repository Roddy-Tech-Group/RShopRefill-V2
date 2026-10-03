<?php

namespace App\Domain\Payment\Support;

/**
 * Single source of truth for which payment methods a display currency accepts.
 *
 * Three layers have to agree on this list: the checkout page's method tabs,
 * CheckoutController (which creates the order + PaymentAttempt) and
 * PaymentSessionController::pay (which actually charges it). When the pay step
 * held a shorter list than checkout, a customer could pick USSD / Pay With Bank
 * / Bank QR / Mobile Wallet, get a real order, and then be told the method was
 * "not available for currency" - leaving an order that could not be paid by any
 * means. Both controllers now read this list so they cannot drift again.
 *
 * The mapping mirrors what is enabled on the gateway dashboard, so we never
 * offer a method the provider would then reject.
 */
final class SupportedPaymentMethods
{
    /**
     * Display currency => allowed method keys.
     *
     * @var array<string, list<string>>
     */
    private const BY_CURRENCY = [
        // Apple Pay only where the gateway collects it: USD, GBP and NGN.
        // Every other currency answers "Currency not supported for Apple Pay"
        // before the merchant is even looked at (sandbox-verified 2026-09-10),
        // so offering the tile there was a guaranteed failure. It is listed
        // here as the gateway's capability and HELD BACK by WITHHELD below
        // until the live account takes it; do not delete it from these rows.
        // Mobile money on USD rides the Francophone rail in CFA francs (see
        // MobileMoneyRail): a dollar order can be paid over MTN or Orange
        // without the customer switching currency first.
        'USD' => ['card', 'apple_pay', 'mobile_money', 'crypto', 'wallet'],
        'EUR' => ['card', 'crypto', 'wallet', 'pay_with_bank'],
        'GBP' => ['card', 'apple_pay', 'crypto', 'wallet', 'pay_with_bank'],
        'NGN' => ['card', 'apple_pay', 'bank_transfer', 'crypto', 'wallet', 'ussd', 'pay_with_bank', 'bank_qr', 'mobile_wallet'],
        'GHS' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'XAF' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'XOF' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'KES' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'UGX' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'RWF' => ['card', 'mobile_money', 'crypto', 'wallet'],
    ];

    /**
     * Currencies without an explicit entry only get the globally available
     * methods.
     */
    private const FALLBACK = ['card'];

    /**
     * Methods the gateway lists but the live account cannot take, so they are
     * offered nowhere: not the checkout tiles, not the wallet sheet, not the
     * storefront strip, not the pay step. Keyed by method with the reason, so
     * the day the reason stops being true the line is deleted and every surface
     * gets the method back at once.
     *
     * apple_pay: 17 of 17 production attempts were refused by Flutterwave
     * between 2026-09-02 and 2026-10-01 ("failed compliance checks", then "the
     * currency for this payment is currently not supported"), in the very
     * currencies the sandbox accepted it in. Apple Pay is not enabled on the
     * live merchant account. Every attempt cost a customer a failed checkout
     * and a strike toward the payment pause.
     *
     * @var array<string, string>
     */
    private const WITHHELD = [
        'apple_pay' => 'Not enabled on the live Flutterwave account: every production attempt was refused as of 2026-10-01.',
    ];

    /**
     * @return list<string>
     */
    public static function forCurrency(string $currency): array
    {
        $methods = self::BY_CURRENCY[strtoupper(trim($currency))] ?? self::FALLBACK;

        return array_values(array_diff($methods, array_keys(self::WITHHELD)));
    }

    /**
     * The methods currently held back from every surface, with why.
     *
     * @return array<string, string>
     */
    public static function withheld(): array
    {
        return self::WITHHELD;
    }

    /**
     * @return bool True when the currency accepts the method.
     */
    public static function allows(string $currency, ?string $method): bool
    {
        return $method !== null && in_array($method, self::forCurrency($currency), true);
    }

    /**
     * Every currency with an explicit method list.
     *
     * @return list<string>
     */
    public static function knownCurrencies(): array
    {
        return array_keys(self::BY_CURRENCY);
    }

    /**
     * Every method key accepted by at least one currency.
     *
     * Used to prove the storefront's "Pay your way" strip only advertises rails
     * checkout can actually take - see PaymentStripBadgesTest.
     *
     * @return list<string>
     */
    public static function allMethods(): array
    {
        $methods = array_unique(array_merge(...array_values(self::BY_CURRENCY)));

        return array_values(array_diff($methods, array_keys(self::WITHHELD)));
    }
}
