<?php

namespace App\Domain\Payment\Support;

/**
 * Which Flutterwave mobile-money rail charges each display currency, and the
 * networks a customer there can pick.
 *
 * Flutterwave runs one charge endpoint per market (`/charges?type=...`) and
 * each accepts only its own currency. The provider used to send every currency
 * to the Francophone rail with the country hard-coded to Cameroon, so Ghana,
 * Kenya, Uganda and Rwanda customers were refused with "This payment option is
 * not allowed for your currency" on every attempt while the checkout kept
 * offering them the method. This map is the single source the provider, the
 * pay endpoint's validation and every network picker read, so the screens and
 * the charge can no longer disagree.
 *
 * Network keys are what the screens show and send; values are the codes the
 * rail expects. Rails with an empty network list (M-Pesa, Rwanda) take the
 * phone number alone and the pickers hide themselves.
 *
 * A currency with `charged_in` has no rail of its own and borrows one: the
 * order stays priced in that currency, and the provider converts the total
 * into the rail's currency at charge time (see chargeCurrency()). USD is the
 * one such currency today, so a dollar order can be paid over MTN or Orange.
 */
final class MobileMoneyRail
{
    /**
     * `precision` is how many decimals the rail is charged in. The franc rails
     * take whole francs; everything else the default two.
     *
     * @var array<string, array{type: string, networks: array<string, string>, country: ?string, description: string, charged_in?: string, precision?: int}>
     */
    private const RAILS = [
        'GHS' => [
            'type' => 'mobile_money_ghana',
            'networks' => ['MTN' => 'MTN', 'Vodafone' => 'VODAFONE', 'AirtelTigo' => 'TIGO'],
            'country' => null,
            'description' => 'MTN, Vodafone, AirtelTigo Mobile Money',
        ],
        'KES' => [
            'type' => 'mpesa',
            'networks' => [],
            'country' => null,
            'description' => 'M-Pesa',
        ],
        'UGX' => [
            'type' => 'mobile_money_uganda',
            'networks' => ['MTN' => 'MTN', 'Airtel' => 'AIRTEL'],
            'country' => null,
            'description' => 'MTN or Airtel Mobile Money',
        ],
        'RWF' => [
            'type' => 'mobile_money_rwanda',
            'networks' => [],
            'country' => null,
            'description' => 'MTN or Airtel Mobile Money',
        ],
        'XAF' => [
            'type' => 'mobile_money_franco',
            'networks' => ['MTN' => 'MTN', 'Orange' => 'ORANGE'],
            'country' => 'CM',
            'description' => 'MTN Mobile Money or Orange Money',
            'precision' => 0,
        ],
        'XOF' => [
            'type' => 'mobile_money_franco',
            'networks' => ['MTN' => 'MTN', 'Orange' => 'ORANGE'],
            'country' => 'CI',
            'description' => 'MTN Mobile Money or Orange Money',
            'precision' => 0,
        ],
        // No rail takes dollars, so a USD order is charged as CFA francs on
        // the Francophone rail: XAF by default (Cameroon), or XOF when the
        // number's dialling prefix puts the customer in a West African franc
        // country. The franc figure is recorded on the attempt so verification
        // and refunds read what the rail was actually asked for.
        'USD' => [
            'type' => 'mobile_money_franco',
            'networks' => ['MTN' => 'MTN', 'Orange' => 'ORANGE'],
            'country' => 'CM',
            'description' => 'MTN Mobile Money or Orange Money',
            'charged_in' => 'XAF',
        ],
    ];

    /**
     * The Francophone rail is shared by a dozen countries and wants the
     * customer's own, so it is read off the dialling prefix when the number
     * carries one; the rail's default above covers local-format numbers.
     *
     * @var array<string, string>
     */
    private const FRANCO_COUNTRY_BY_PREFIX = [
        '237' => 'CM', '241' => 'GA', '242' => 'CG', '235' => 'TD', '236' => 'CF', '240' => 'GQ',
        '221' => 'SN', '225' => 'CI', '226' => 'BF', '223' => 'ML', '229' => 'BJ', '228' => 'TG', '227' => 'NE',
    ];

    public static function supports(string $currency): bool
    {
        return isset(self::RAILS[self::key($currency)]);
    }

    /** The `type` query value for `/charges`, or null when no rail serves the currency. */
    public static function chargeType(string $currency): ?string
    {
        return self::RAILS[self::key($currency)]['type'] ?? null;
    }

    /** Whether the rail needs a network in the charge, and so the picker must show. */
    public static function requiresNetwork(string $currency): bool
    {
        return self::networkOptions($currency) !== [];
    }

    /**
     * Network labels for the pickers, in display order.
     *
     * @return list<string>
     */
    public static function networkOptions(string $currency): array
    {
        return array_keys(self::RAILS[self::key($currency)]['networks'] ?? []);
    }

    /**
     * The gateway's code for what the customer picked, matched without regard
     * to case against either the label or the code itself. Null when the rail
     * takes no network or the value is not one of its options.
     */
    public static function gatewayNetwork(string $currency, ?string $input): ?string
    {
        $networks = self::RAILS[self::key($currency)]['networks'] ?? [];
        $needle = strtolower(trim((string) $input));

        if ($needle === '') {
            return null;
        }

        foreach ($networks as $label => $code) {
            if (strtolower($label) === $needle || strtolower($code) === $needle) {
                return $code;
            }
        }

        return null;
    }

    /**
     * The label the screens show for what the customer picked, matched the
     * way gatewayNetwork() matches. Null when the rail takes no network or
     * the value is not one of its options.
     */
    public static function networkLabel(string $currency, ?string $input): ?string
    {
        $networks = self::RAILS[self::key($currency)]['networks'] ?? [];
        $needle = strtolower(trim((string) $input));

        if ($needle === '') {
            return null;
        }

        foreach ($networks as $label => $code) {
            if (strtolower($label) === $needle || strtolower($code) === $needle) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Whether the rail approves on the handset: the operator pushes a prompt
     * the customer answers with their PIN, and the charge response carries
     * no authorization page of its own. The Francophone rail echoes the
     * merchant's return URL under the redirect key (its reference shows
     * exactly that), so a redirect there is never an authorization step.
     * The others answer with a hosted page the customer must visit.
     */
    public static function promptsOnHandset(string $currency): bool
    {
        return in_array(self::chargeType($currency), ['mobile_money_franco', 'mpesa'], true);
    }

    /** ISO country for rails that need one (Francophone only), else null. */
    public static function countryFor(string $currency, string $phoneNumber): ?string
    {
        $default = self::RAILS[self::key($currency)]['country'] ?? null;

        if ($default === null) {
            return null;
        }

        $digits = self::normalisePhone($phoneNumber);

        foreach (self::FRANCO_COUNTRY_BY_PREFIX as $prefix => $country) {
            // A prefix is only a prefix on an international-length number; a
            // local number that happens to start with the same digits is not.
            if (strlen($digits) >= 11 && str_starts_with($digits, $prefix)) {
                return $country;
            }
        }

        return $default;
    }

    public static function description(string $currency): string
    {
        return self::RAILS[self::key($currency)]['description'] ?? 'Mobile Money';
    }

    /** Decimals the rail is charged in: whole francs, two elsewhere. */
    public static function precision(string $currency): int
    {
        return self::RAILS[self::key($currency)]['precision'] ?? 2;
    }

    /**
     * Whether the rail charges a different currency from the one the order is
     * priced in. Only USD today, which rides the Francophone rail in francs.
     */
    public static function isConverted(string $currency): bool
    {
        return isset(self::RAILS[self::key($currency)]['charged_in']);
    }

    /**
     * The currency the rail is asked to charge: the order's own wherever a
     * market has a rail of its own; for a converted currency, the franc of the
     * customer's country, read off the dialling prefix the same way the
     * country is, with the rail's default when the number carries none.
     */
    public static function chargeCurrency(string $currency, string $phoneNumber = ''): string
    {
        $currency = self::key($currency);
        $chargedIn = self::RAILS[$currency]['charged_in'] ?? null;

        if ($chargedIn === null) {
            return $currency;
        }

        $country = self::countryFor($currency, $phoneNumber);
        $local = strtoupper((string) (config('country_currency.map', [])[$country] ?? ''));

        // The prefix may only move the charge onto a currency that has its
        // own rail of the same kind (XAF or XOF); anything else keeps the
        // default rather than sending the rail a currency it refuses.
        $sameRail = array_keys(array_filter(
            self::RAILS,
            fn (array $rail): bool => $rail['type'] === self::RAILS[$currency]['type'] && ! isset($rail['charged_in']),
        ));

        return in_array($local, $sameRail, true) ? $local : $chargedIn;
    }

    /**
     * Converted currency => the currency it is charged in by default, for the
     * screens that tell the customer their mobile money account is debited in
     * francs rather than in the currency on the page.
     *
     * @return array<string, string>
     */
    public static function chargeCurrencyByCurrency(): array
    {
        $map = [];

        foreach (self::RAILS as $currency => $rail) {
            if (isset($rail['charged_in'])) {
                $map[$currency] = $rail['charged_in'];
            }
        }

        return $map;
    }

    /**
     * Every currency's network labels, for a picker that follows the
     * checkout's live currency client-side.
     *
     * @return array<string, list<string>>
     */
    public static function networksByCurrency(): array
    {
        return array_map(fn (array $rail): array => array_keys($rail['networks']), self::RAILS);
    }

    /**
     * Digits only: customers type "+237 6 70 00 00 00" and "00233 24 ..." and
     * the rails want neither the plus nor the spacing.
     */
    public static function normalisePhone(string $phoneNumber): string
    {
        $digits = preg_replace('/\D+/', '', $phoneNumber) ?? '';

        return str_starts_with($digits, '00') ? substr($digits, 2) : $digits;
    }

    private static function key(string $currency): string
    {
        return strtoupper(trim($currency));
    }
}
