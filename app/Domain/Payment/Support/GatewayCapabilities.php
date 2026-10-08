<?php

namespace App\Domain\Payment\Support;

/**
 * What each fiat gateway can charge, per method, currency, country and
 * mobile money network, as its own documentation states it.
 *
 * Everything that offers or routes a payment reads this one table: the router
 * (a gateway is only a candidate when it can take the method, the currency and,
 * for mobile money, the customer's country and network), the checkout and
 * funding screens (the networks shown are the union of what the switched-on
 * gateways on the row can take), and the pay step (a country or network no
 * live gateway serves is refused with a plain message instead of a charge
 * that fails at the gateway). Switching a gateway on therefore adds its
 * methods, countries and networks to the storefront without a deploy.
 *
 * Verified against the gateways' documentation on 2026-10-06 (links in the
 * "Payment Gateway Coverage" document):
 *
 *  - Flutterwave: cards and bank methods in every currency we price in, the
 *    behaviour it always had here. Mobile money per market, with the networks
 *    its francophone rail lists per country; Burkina Faso's Orange needs an
 *    authorisation code we do not collect, so only Mobicash is offered there.
 *  - Kora: cards, bank transfer and pay-with-bank in NGN only; mobile money in
 *    Cameroon, Côte d'Ivoire, Ghana, Kenya, Egypt and Tanzania.
 *  - Paystack (a Nigerian merchant account): cards and Apple Pay in NGN, plus
 *    USD once Paystack approves international collection (config); bank
 *    transfer and USSD in NGN. Mobile money is offered only to businesses
 *    registered in Ghana, Kenya or Côte d'Ivoire, so never to us.
 */
final class GatewayCapabilities
{
    /** Every currency we price in. */
    private const ANY = '*';

    /**
     * Mobile money coverage: currency => country => network labels, as the
     * storefront shows them. A market with one network and no choice to make
     * lists that network alone; an empty list means the rail takes the number
     * only.
     *
     * @var array<string, array<string, array<string, list<string>>>>
     */
    private const MOBILE_MONEY = [
        'flutterwave' => [
            'GHS' => ['GH' => ['MTN', 'Vodafone', 'AirtelTigo']],
            'KES' => ['KE' => ['M-Pesa']],
            'UGX' => ['UG' => ['MTN', 'Airtel']],
            'RWF' => ['RW' => []],
            'XAF' => ['CM' => ['MTN', 'Orange']],
            'XOF' => [
                'CI' => ['MTN', 'Orange', 'Moov', 'Wave'],
                'SN' => ['Orange', 'Wave'],
                'BF' => ['Mobicash'],
            ],
            // type=mobile_money_tanzania; the network is optional on the charge
            // and the docs list no closed set, so the number alone is sent.
            // Zambia is listed as a payment option but not documented as a
            // charge, and is off on our dashboard, so it is not claimed here.
            'TZS' => ['TZ' => []],
        ],
        'korapay' => [
            'XAF' => ['CM' => ['MTN', 'Orange']],
            'XOF' => ['CI' => ['MTN', 'Orange', 'Moov', 'Wave']],
            'GHS' => ['GH' => ['MTN', 'AirtelTigo', 'Vodafone']],
            'KES' => ['KE' => ['M-Pesa', 'Airtel', 'Equitel']],
            'EGP' => ['EG' => ['Vodafone', 'Orange', 'Etisalat']],
            'TZS' => ['TZ' => ['Vodacom', 'Yas', 'Airtel']],
        ],
        'paystack' => [],
    ];

    /**
     * Every other method: currency lists, or ANY.
     *
     * @var array<string, array<string, string|list<string>>>
     */
    private const METHODS = [
        'flutterwave' => [
            'card' => self::ANY,
            'apple_pay' => self::ANY,
            'bank_transfer' => self::ANY,
            'pay_with_bank' => self::ANY,
            'ussd' => self::ANY,
            'bank_qr' => self::ANY,
            'mobile_wallet' => self::ANY,
        ],
        'korapay' => [
            'card' => ['NGN'],
            'bank_transfer' => ['NGN'],
            'pay_with_bank' => ['NGN'],
        ],
        'paystack' => [
            'bank_transfer' => ['NGN'],
            'ussd' => ['NGN'],
        ],
    ];

    /**
     * A USD mobile money order is charged in CFA francs on the francophone
     * rail, so its coverage is the franc countries' coverage.
     *
     * @var array<string, list<string>>
     */
    private const CHARGED_AS = [
        'USD' => ['XAF', 'XOF'],
    ];

    /** @var array<string, string> */
    private const COUNTRY_NAMES = [
        'CM' => 'Cameroon', 'GA' => 'Gabon', 'CG' => 'Congo', 'TD' => 'Chad', 'CF' => 'the Central African Republic',
        'GQ' => 'Equatorial Guinea', 'SN' => 'Senegal', 'CI' => "Côte d'Ivoire", 'BF' => 'Burkina Faso', 'ML' => 'Mali',
        'BJ' => 'Benin', 'TG' => 'Togo', 'NE' => 'Niger', 'GW' => 'Guinea-Bissau', 'GH' => 'Ghana', 'KE' => 'Kenya',
        'UG' => 'Uganda', 'RW' => 'Rwanda', 'EG' => 'Egypt', 'TZ' => 'Tanzania',
    ];

    /**
     * Can this gateway charge this method in this currency, and (for mobile
     * money, when given) in this country on this network? Flags aside.
     */
    public static function supports(string $gateway, string $method, string $currency, ?string $country = null, ?string $network = null): bool
    {
        $currency = strtoupper(trim($currency));

        if ($method === 'mobile_money') {
            $coverage = self::mobileMoneyCoverage($gateway, $currency);

            if ($coverage === []) {
                return false;
            }

            if ($country !== null && ! array_key_exists(strtoupper($country), $coverage)) {
                return false;
            }

            if ($network !== null) {
                $pool = $country !== null ? [$coverage[strtoupper($country)]] : array_values($coverage);

                return self::labelIn($network, array_merge(...$pool)) !== null;
            }

            return true;
        }

        $currencies = self::methodCurrencies($gateway, $method);

        if ($currencies === null) {
            return false;
        }

        return $currencies === self::ANY || in_array($currency, $currencies, true);
    }

    /**
     * This gateway's mobile money coverage for a currency: country => network
     * labels. Empty when it has none.
     *
     * @return array<string, list<string>>
     */
    public static function mobileMoneyCoverage(string $gateway, string $currency): array
    {
        $currency = strtoupper(trim($currency));
        $table = self::MOBILE_MONEY[$gateway] ?? [];
        $coverage = [];

        foreach (self::CHARGED_AS[$currency] ?? [$currency] as $charged) {
            foreach ($table[$charged] ?? [] as $country => $networks) {
                $coverage[$country] = array_values(array_unique([...($coverage[$country] ?? []), ...$networks]));
            }
        }

        return $coverage;
    }

    /**
     * The union of what the given gateways can take for mobile money in a
     * currency: country => network labels, first gateway's order first.
     *
     * @param  list<string>  $gateways
     * @return array<string, list<string>>
     */
    public static function mobileMoneyUnion(array $gateways, string $currency): array
    {
        $union = [];

        foreach ($gateways as $gateway) {
            foreach (self::mobileMoneyCoverage($gateway, $currency) as $country => $networks) {
                $union[$country] = array_values(array_unique([...($union[$country] ?? []), ...$networks]));
            }
        }

        return $union;
    }

    /**
     * The canonical label for a network the customer named, matched without
     * regard to case, or null when it is not one of the given labels.
     *
     * @param  list<string>  $labels
     */
    public static function labelIn(?string $input, array $labels): ?string
    {
        $needle = strtolower(trim((string) $input));

        if ($needle === '') {
            return null;
        }

        foreach ($labels as $label) {
            if (strtolower($label) === $needle) {
                return $label;
            }
        }

        return null;
    }

    public static function countryName(string $country): string
    {
        return self::COUNTRY_NAMES[strtoupper($country)] ?? strtoupper($country);
    }

    /**
     * What this gateway can take, in a sentence for the admin screens, read
     * off the table so the screen can never say more than the router does.
     */
    public static function summary(string $gateway): string
    {
        $parts = [];
        $methods = self::METHODS[$gateway] ?? [];
        $labels = [
            'card' => 'cards', 'apple_pay' => 'Apple Pay', 'bank_transfer' => 'bank transfer',
            'pay_with_bank' => 'pay with bank', 'ussd' => 'USSD', 'bank_qr' => 'bank QR', 'mobile_wallet' => 'mobile wallets',
        ];

        if ($gateway === 'paystack') {
            $methods = ['card' => null, 'apple_pay' => null] + $methods;
        }

        $byCurrency = [];

        foreach ($methods as $method => $currencies) {
            $currencies = self::methodCurrencies($gateway, $method);
            $key = $currencies === self::ANY ? 'every currency we price in' : implode(', ', (array) $currencies);
            $byCurrency[$key][] = $labels[$method] ?? $method;
        }

        foreach ($byCurrency as $where => $what) {
            $parts[] = ucfirst(implode(', ', $what)).' in '.$where;
        }

        $markets = [];

        foreach (self::MOBILE_MONEY[$gateway] ?? [] as $currency => $countries) {
            foreach ($countries as $country => $networks) {
                $markets[] = self::countryName($country).($networks === [] ? '' : ' ('.implode(', ', $networks).')');
            }
        }

        if ($markets !== []) {
            $parts[] = 'mobile money in '.implode(', ', $markets);
        }

        return $parts === [] ? 'Nothing yet' : implode('; ', $parts).'.';
    }

    /**
     * @return string|list<string>|null
     */
    private static function methodCurrencies(string $gateway, string $method): string|array|null
    {
        $currencies = self::METHODS[$gateway][$method] ?? null;

        // Paystack card + Apple Pay currencies follow the merchant account:
        // NGN always, USD only after international USD collection (and its
        // domiciliary settlement account) is approved. Config so the upgrade
        // is an env change, not a deploy.
        if ($gateway === 'paystack' && in_array($method, ['card', 'apple_pay'], true)) {
            return array_values(array_unique(array_map(
                'strtoupper',
                (array) config('services.paystack.charge_currencies', ['NGN']),
            )));
        }

        return $currencies;
    }
}
