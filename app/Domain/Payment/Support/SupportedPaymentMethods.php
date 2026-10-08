<?php

namespace App\Domain\Payment\Support;

/**
 * Which payment methods each display currency offers, in tile order.
 *
 * The answer comes from the payment switchboard (payment_routes, edited in
 * Management > Gateway settings), through PaymentRouteTable. Every surface that
 * offers or charges a method reads it here: the checkout tiles,
 * CheckoutController, the pay step, the wallet sheet, the mobile API and the
 * storefront strip. When the pay step once held a shorter list than checkout, a
 * customer could pick a method, get a real order, and then be refused at the
 * pay step; one source stops that coming back.
 *
 * The constants below are no longer the live list. They are what the platform
 * did before the switchboard existed, used only when the table cannot be read,
 * so a database fault degrades to the old behaviour rather than to no methods.
 */
final class SupportedPaymentMethods
{
    /**
     * The pre-switchboard method lists in tile order, kept as the fallback.
     * Mirrors the 2026-10-05 seed migration.
     *
     * @var array<string, list<string>>
     */
    public const DEFAULTS = [
        'USD' => ['card', 'mobile_money', 'apple_pay', 'crypto', 'wallet'],
        'EUR' => ['card', 'pay_with_bank', 'crypto', 'wallet'],
        'GBP' => ['card', 'pay_with_bank', 'apple_pay', 'crypto', 'wallet'],
        'NGN' => ['card', 'bank_transfer', 'pay_with_bank', 'ussd', 'bank_qr', 'mobile_wallet', 'apple_pay', 'crypto', 'wallet'],
        'GHS' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'XAF' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'XOF' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'KES' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'UGX' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'RWF' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'EGP' => ['card', 'mobile_money'],
        'TZS' => ['card', 'mobile_money'],
        'ZMW' => ['card'],
        'ZAR' => ['card'],
    ];

    /**
     * Methods listed in the defaults but switched off, with why.
     *
     * @var array<string, string>
     */
    public const DEFAULT_WITHHELD = [
        'apple_pay' => 'Not enabled',
    ];

    /**
     * Per currency, methods listed but switched off until a real payment
     * proves them. Mirrors the seed.
     *
     * @var array<string, list<string>>
     */
    public const DEFAULT_OFF_UNTIL_TESTED = [
        'EGP' => ['mobile_money'],
        'TZS' => ['mobile_money'],
    ];

    public const OFF_UNTIL_TESTED_NOTE = 'Not live yet: switch on after one real test payment succeeds';

    /**
     * Currencies with no row of their own only get the card, which every
     * fiat gateway can take.
     */
    private const FALLBACK = ['card'];

    /**
     * The methods a currency offers, in the order its tiles render.
     *
     * @return list<string>
     */
    public static function forCurrency(string $currency): array
    {
        $rows = PaymentRouteTable::forCurrency($currency);

        if ($rows === null) {
            return self::FALLBACK;
        }

        return array_values(array_map(
            fn (array $row): string => $row['method'],
            array_filter($rows, fn (array $row): bool => $row['offered']),
        ));
    }

    /**
     * Every currency's offered methods in tile order, for page scripts that
     * render the tiles themselves.
     *
     * @return array<string, list<string>>
     */
    public static function byCurrency(): array
    {
        $map = [];

        foreach (self::knownCurrencies() as $currency) {
            $map[$currency] = self::forCurrency($currency);
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    public static function fallback(): array
    {
        return self::FALLBACK;
    }

    /**
     * Methods listed somewhere but offered nowhere, with the reason recorded on
     * their rows.
     *
     * @return array<string, string>
     */
    public static function withheld(): array
    {
        $listed = [];
        $offered = [];

        foreach (PaymentRouteTable::all() as $rows) {
            foreach ($rows as $row) {
                $listed[$row['method']] ??= (string) ($row['note'] ?? '');

                if ($row['offered']) {
                    $offered[$row['method']] = true;
                }
            }
        }

        return array_diff_key($listed, $offered);
    }

    /**
     * @return bool True when the currency offers the method.
     */
    public static function allows(string $currency, ?string $method): bool
    {
        return $method !== null && in_array($method, self::forCurrency($currency), true);
    }

    /**
     * Every currency with rows of its own.
     *
     * @return list<string>
     */
    public static function knownCurrencies(): array
    {
        return array_keys(PaymentRouteTable::all());
    }

    /**
     * Currencies a product page may hand a customer over to: those offering
     * a local rail beyond the card, so a pick never lands on a checkout whose
     * only method is a card nobody promised. A currency with a row but card
     * alone (Egypt, Tanzania, Zambia, South Africa until their rails are
     * proven) is priced and payable, but not pushed.
     *
     * @return list<string>
     */
    public static function handoverCurrencies(): array
    {
        return array_values(array_filter(
            self::knownCurrencies(),
            fn (string $currency): bool => self::forCurrency($currency) !== ['card'] && self::forCurrency($currency) !== [],
        ));
    }

    /**
     * Every method offered in at least one currency.
     *
     * Used to prove the storefront's "Pay your way" strip only advertises rails
     * checkout can actually take - see PaymentStripBadgesTest.
     *
     * @return list<string>
     */
    public static function allMethods(): array
    {
        $methods = [];

        foreach (self::knownCurrencies() as $currency) {
            array_push($methods, ...self::forCurrency($currency));
        }

        return array_values(array_unique($methods));
    }
}
