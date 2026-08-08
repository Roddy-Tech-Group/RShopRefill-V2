<?php

namespace App\Domain\Payment\Support;

use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Support\FeatureFlag;

/**
 * Per-method failover routing across the fiat gateways.
 *
 * Flutterwave is the primary for every fiat rail. The backups (Paystack for
 * cards / Apple Pay / USSD / NGN transfers, Kora for francophone mobile money
 * and NGN pay-with-bank) receive traffic ONLY while the Flutterwave flag is
 * off: while it is on, resolve() short-circuits on the first priority entry
 * and the backups are unreachable no matter what their flags say.
 *
 * Routing is decided per method AND currency, because the backups cover
 * different slices of the matrix (Paystack cannot charge XAF, Kora cannot
 * charge a US card). A method no enabled gateway can serve resolves to null,
 * which the checkout renders as a greyed tile and the pay endpoint refuses
 * with the standard gateway_disabled response, the same contract as the
 * single-gateway kill switches.
 *
 * The three surfaces that MUST agree - the checkout tiles, the pre-flight
 * guard in CheckoutService and the pay endpoint - all resolve through here,
 * mirroring the FeatureFlag::GATEWAY_FLAGS "one canonical map" rule.
 */
final class FiatGatewayRouter
{
    /**
     * Every gateway that can front a fiat method, in default preference order.
     * Order matters: defaultGateway() stamps the first enabled entry onto a
     * fresh checkout before the customer has committed to a method.
     *
     * @var list<string>
     */
    public const FIAT_GATEWAYS = ['flutterwave', 'paystack', 'korapay'];

    /**
     * Method key => gateways able to charge it, in failover order.
     * Methods listed with Flutterwave only have no backup today and grey out
     * whenever it is off (bank QR and the NGN e-wallets exist on no backup;
     * see the capability notes below for why).
     *
     * @var array<string, list<string>>
     */
    public const METHOD_PRIORITY = [
        'card' => ['flutterwave', 'paystack', 'korapay'],
        'apple_pay' => ['flutterwave', 'paystack'],
        'bank_transfer' => ['flutterwave', 'paystack', 'korapay'],
        'pay_with_bank' => ['flutterwave', 'korapay'],
        'ussd' => ['flutterwave', 'paystack'],
        'bank_qr' => ['flutterwave'],
        'mobile_wallet' => ['flutterwave'],
        'mobile_money' => ['flutterwave', 'korapay', 'paystack'],
    ];

    /**
     * Currency coverage per backup gateway and method, from each provider's
     * documentation (verified 2026-08-07):
     *
     *  - Paystack (Nigerian merchant account): cards NGN, plus USD only once
     *    international USD collection is enabled on the account, which is why
     *    the card/apple_pay list is config-driven rather than constant.
     *    Mobile money GHS (MTN/Telecel/AirtelTigo), KES (M-PESA), XOF
     *    (MTN/Wave/Orange). Bank transfer + USSD are NGN rails.
     *  - Kora: cards NGN only; mobile money XAF/XOF/GHS/KES/EGP/TZS;
     *    bank transfer + pay-with-bank NGN.
     *
     * Flutterwave is deliberately absent: it keeps today's behavior of
     * accepting every method/currency pair SupportedPaymentMethods offers.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const BACKUP_CAPABILITIES = [
        'paystack' => [
            'bank_transfer' => ['NGN'],
            'ussd' => ['NGN'],
            'mobile_money' => ['GHS', 'KES', 'XOF'],
        ],
        'korapay' => [
            'card' => ['NGN'],
            'bank_transfer' => ['NGN'],
            'pay_with_bank' => ['NGN'],
            'mobile_money' => ['XAF', 'XOF', 'GHS', 'KES', 'EGP', 'TZS'],
        ],
    ];

    /**
     * The gateway that will charge this method in this currency right now,
     * or null when no enabled gateway covers the pair.
     */
    public static function resolve(string $method, string $currency): ?string
    {
        $currency = strtoupper(trim($currency));

        foreach (self::METHOD_PRIORITY[$method] ?? [] as $gateway) {
            if (! self::isLive($gateway)) {
                continue;
            }

            if (self::supports($gateway, $method, $currency)) {
                return $gateway;
            }
        }

        return null;
    }

    /**
     * Can this gateway charge this method in this currency, flags aside?
     */
    public static function supports(string $gateway, string $method, string $currency): bool
    {
        if ($gateway === 'flutterwave') {
            return true;
        }

        $currencies = self::capabilities($gateway)[$method] ?? null;

        return $currencies !== null && in_array(strtoupper($currency), $currencies, true);
    }

    /**
     * Is any fiat gateway accepting new money? The order-placement guard asks
     * this, because the concrete gateway cannot be picked until the customer
     * commits to a method in the payment wizard.
     */
    public static function anyFiatGatewayEnabled(): bool
    {
        foreach (self::FIAT_GATEWAYS as $gateway) {
            if (self::isLive($gateway)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The gateway stamped onto a fresh fiat checkout before a method is
     * chosen. The pay endpoint re-resolves per method at commitment and
     * re-stamps while the session is still pre-charge ('awaiting_method').
     */
    public static function defaultGateway(): ?string
    {
        foreach (self::FIAT_GATEWAYS as $gateway) {
            if (self::isLive($gateway)) {
                return $gateway;
            }
        }

        return null;
    }

    /**
     * Enabled by flag AND backed by a real provider class. The second half
     * makes an early flag flip (backup enabled before its provider ships)
     * inert instead of a crash at payment initialization.
     */
    private static function isLive(string $gateway): bool
    {
        return PaymentGatewayFactory::isImplemented($gateway)
            && FeatureFlag::gatewayEnabled($gateway);
    }

    /**
     * Fiat methods with no serving gateway, per currency, for the checkout
     * tiles. Keys are every currency SupportedPaymentMethods knows plus
     * '__fallback' for the rest; values list the method keys to grey.
     *
     * @return array<string, list<string>>
     */
    public static function unavailableFiatMethodsByCurrency(): array
    {
        $map = [];

        foreach ([...SupportedPaymentMethods::knownCurrencies(), '__fallback'] as $currency) {
            // '__fallback' stands for any currency without an explicit entry.
            // Those exotic currencies are served by Flutterwave alone, so the
            // sentinel deliberately matches no backup capability list.
            $probe = $currency === '__fallback' ? '???' : $currency;

            $map[$currency] = array_values(array_filter(
                array_intersect(SupportedPaymentMethods::forCurrency($probe), array_keys(self::METHOD_PRIORITY)),
                fn (string $method) => self::resolve($method, $probe) === null,
            ));
        }

        return $map;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function capabilities(string $gateway): array
    {
        $capabilities = self::BACKUP_CAPABILITIES[$gateway] ?? [];

        // Paystack card + Apple Pay currencies follow the merchant account:
        // NGN always, USD only after international USD collection (and its
        // domiciliary settlement account) is approved. Config so the upgrade
        // is an env change, not a deploy.
        if ($gateway === 'paystack') {
            $chargeCurrencies = array_map(
                'strtoupper',
                (array) config('services.paystack.charge_currencies', ['NGN']),
            );
            $capabilities['card'] = $chargeCurrencies;
            $capabilities['apple_pay'] = $chargeCurrencies;
        }

        return $capabilities;
    }
}
