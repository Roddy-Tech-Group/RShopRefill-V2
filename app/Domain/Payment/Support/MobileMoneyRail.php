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
        'TZS' => [
            'type' => 'mobile_money_tanzania',
            'networks' => [],
            'country' => null,
            'description' => 'Mobile Money',
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
            'networks' => ['MTN' => 'MTN', 'Orange' => 'ORANGE', 'Moov' => 'MOOV', 'Wave' => 'WAVE', 'Mobicash' => 'MOBICASH'],
            'country' => 'CI',
            'description' => 'MTN, Orange, Moov or Wave Mobile Money',
            'precision' => 0,
        ],
        // No rail takes dollars, so a USD order is charged as CFA francs on
        // the Francophone rail: XAF by default (Cameroon), or XOF when the
        // number's dialling prefix puts the customer in a West African franc
        // country. The franc figure is recorded on the attempt so verification
        // and refunds read what the rail was actually asked for.
        'USD' => [
            'type' => 'mobile_money_franco',
            'networks' => ['MTN' => 'MTN', 'Orange' => 'ORANGE', 'Moov' => 'MOOV', 'Wave' => 'WAVE', 'Mobicash' => 'MOBICASH'],
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

    /**
     * Whether the customer has a network to choose: more than one network is
     * offered across the switched-on gateways on this currency's row. A
     * market with one network (M-Pesa in Kenya on Flutterwave alone) takes
     * the number only.
     */
    public static function requiresNetwork(string $currency): bool
    {
        return self::networkOptions($currency) !== [];
    }

    /**
     * The network labels to offer for this currency: the union of what the
     * switched-on gateways on its row can take, in row order, across every
     * country the currency covers. Empty when there is nothing to choose.
     *
     * @return list<string>
     */
    public static function networkOptions(string $currency): array
    {
        $labels = [];

        foreach (self::coverageOffered($currency) as $networks) {
            foreach ($networks as $label) {
                $labels[$label] = true;
            }
        }

        $labels = array_keys($labels);

        return count($labels) > 1 ? $labels : [];
    }

    /**
     * Country => network labels offered for this currency right now.
     *
     * @return array<string, list<string>>
     */
    public static function coverageOffered(string $currency): array
    {
        return GatewayCapabilities::mobileMoneyUnion(
            FiatGatewayRouter::liveGatewaysFor('mobile_money', self::key($currency)),
            self::key($currency),
        );
    }

    /**
     * Whether Flutterwave's rail for this currency wants a network code on
     * the charge (Ghana, Uganda and the francophone rails do; M-Pesa and
     * Rwanda take the number alone).
     */
    public static function codeRequired(string $currency): bool
    {
        return (self::RAILS[self::key($currency)]['networks'] ?? []) !== [];
    }

    /**
     * Flutterwave's code for the network the customer picked, or null when
     * its rail for this currency has no code for it.
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
     * The label the screens show for what the customer picked, matched
     * without regard to case against every network any gateway offers for
     * this currency. Null when the value is not one of them.
     */
    public static function networkLabel(string $currency, ?string $input): ?string
    {
        $labels = [];

        foreach (self::coverageOffered($currency) as $networks) {
            array_push($labels, ...$networks);
        }

        // A Flutterwave code typed in place of its label still resolves.
        foreach (self::RAILS[self::key($currency)]['networks'] ?? [] as $label => $code) {
            if (strtolower($code) === strtolower(trim((string) $input))) {
                $input = $label;
            }
        }

        return GatewayCapabilities::labelIn($input, $labels);
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

    /**
     * Rails whose API charge answers with the operator's own authorisation
     * page (Ghana, Uganda, Rwanda, Tanzania). Rather than send the customer
     * away to it, these open in Flutterwave's popup on our page, which
     * renders the same step inline. The handset rails never need this: their
     * prompt is on the phone before the API answers.
     */
    public static function opensInPopup(string $currency): bool
    {
        return self::supports($currency) && ! self::promptsOnHandset($currency);
    }

    /**
     * Flutterwave's popup option for a rail that opens there.
     */
    public static function popupOption(string $currency): ?string
    {
        return match (self::chargeType($currency)) {
            'mobile_money_ghana' => 'mobilemoneyghana',
            'mobile_money_uganda' => 'mobilemoneyuganda',
            'mobile_money_rwanda' => 'mobilemoneyrwanda',
            'mobile_money_tanzania' => 'mobilemoneytanzania',
            default => null,
        };
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

    /**
     * Dialling codes for the countries our mobile money rails serve.
     *
     * @var array<string, string>
     */
    private const DIAL_CODES = [
        'CM' => '237', 'GA' => '241', 'CG' => '242', 'TD' => '235', 'CF' => '236', 'GQ' => '240',
        'SN' => '221', 'CI' => '225', 'BF' => '226', 'ML' => '223', 'BJ' => '229', 'TG' => '228', 'NE' => '227', 'GW' => '245',
        'GH' => '233', 'KE' => '254', 'UG' => '256', 'RW' => '250', 'TZ' => '255', 'EG' => '20',
    ];

    /**
     * The country a number in this currency belongs to when it carries no
     * dialling code: the francophone rail's default, or the currency's one
     * country.
     */
    public static function homeCountry(string $currency): ?string
    {
        return self::countryFor($currency, '') ?? match (self::key($currency)) {
            'GHS' => 'GH', 'KES' => 'KE', 'UGX' => 'UG', 'RWF' => 'RW', 'TZS' => 'TZ', 'EGP' => 'EG',
            default => null,
        };
    }

    /**
     * The number as a gateway that wants it international expects it: digits
     * only, with the country's dialling code in front. "0670000000" typed in
     * Cameroon becomes "237670000000"; a number already carrying a known code
     * is left alone.
     */
    public static function internationalNumber(string $currency, string $phoneNumber, ?string $country = null): string
    {
        $digits = self::normalisePhone($phoneNumber);

        foreach (self::DIAL_CODES as $code) {
            if (strlen($digits) >= 11 && str_starts_with($digits, $code)) {
                return $digits;
            }
        }

        // The country the customer picked beside the number wins over the
        // currency's home country: a Senegalese number on an XOF order is
        // dialled as 221, not 225.
        $home = $country !== null && isset(self::DIAL_CODES[strtoupper($country)])
            ? strtoupper($country)
            : self::homeCountry($currency);
        $code = $home !== null ? (self::DIAL_CODES[$home] ?? '') : '';

        // Only markets with a trunk zero drop it (0244 in Ghana becomes 233 244);
        // a Côte d'Ivoire number keeps its leading 0, it is part of the number.
        if (in_array($home, ['GH', 'KE', 'UG', 'RW', 'TZ', 'EG'], true)) {
            $digits = ltrim($digits, '0');
        }

        return $code.$digits;
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
     * Country => network labels offered per currency, for the network chips
     * to follow the country picked beside the number.
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function coverageByCurrency(): array
    {
        $map = [];

        foreach (array_keys(self::RAILS) as $currency) {
            $map[$currency] = self::coverageOffered($currency);
        }

        return $map;
    }

    /**
     * The dialling codes the number field shows for each currency: the
     * countries the switched-on gateways serve right now, home country first,
     * or the home country alone when nothing is live, so the chip still
     * reads right while the method is being refused.
     *
     * @return array<string, list<array{country: string, name: string, dial: string}>>
     */
    public static function dialCodesByCurrency(): array
    {
        $map = [];

        foreach (array_keys(self::RAILS) as $currency) {
            $home = self::homeCountry($currency);
            $countries = array_keys(self::coverageOffered($currency));

            if ($countries === [] && $home !== null) {
                $countries = [$home];
            }

            usort($countries, fn (string $a, string $b) => ($a === $home ? 0 : 1) <=> ($b === $home ? 0 : 1) ?: strcmp($a, $b));

            $map[$currency] = array_values(array_map(fn (string $country) => [
                'country' => $country,
                'name' => GatewayCapabilities::countryName($country),
                'dial' => self::DIAL_CODES[$country] ?? '',
            ], array_filter($countries, fn (string $country) => isset(self::DIAL_CODES[$country]))));
        }

        return $map;
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
