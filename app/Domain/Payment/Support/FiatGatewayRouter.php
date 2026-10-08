<?php

namespace App\Domain\Payment\Support;

use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Support\FeatureFlag;

/**
 * Per-method, per-currency routing across the fiat gateways.
 *
 * The order comes from the payment switchboard (Management > Gateway settings):
 * each method in each currency names its gateways first choice first. The
 * router takes the first one that is switched on and able to charge the method
 * in that currency; the rest are its automatic fallbacks. With the seeded
 * order, Flutterwave is first everywhere and the backups take traffic only when
 * it is off, which is how the platform behaved before the switchboard. Making
 * Kora first for a mobile money currency is a row edit, and while Kora's own
 * switch is off the router skips it and Flutterwave keeps the payment.
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
        // Paystack offers mobile money only to businesses registered in Ghana,
        // Kenya or Côte d'Ivoire; ours is Nigerian, so it is not a backup here.
        'mobile_money' => ['flutterwave', 'korapay'],
    ];

    /*
     * Coverage per gateway (method, currency, country, network) lives in
     * GatewayCapabilities, one table for the router and every screen.
     */

    /**
     * The gateway that will charge this method in this currency right now,
     * or null when no enabled gateway covers the pair.
     *
     * This is capability + availability only; it does NOT consider the product's
     * compliance category. Category-aware selection composes on top of
     * candidates() in PaymentEligibilityService::routeGateway, which filters this
     * ordered list down to the gateways approved for the cart.
     */
    public static function resolve(string $method, string $currency, ?string $country = null, ?string $network = null): ?string
    {
        return self::candidates($method, $currency, $country, $network)[0] ?? null;
    }

    /**
     * Every live gateway that can charge this method in this currency, in
     * failover order. The eligibility service walks this list to pick the first
     * gateway also approved for the cart's categories, so a category no gateway
     * approves greys/refuses while a category one backup approves still routes.
     *
     * @return list<string>
     */
    public static function candidates(string $method, string $currency, ?string $country = null, ?string $network = null): array
    {
        $currency = strtoupper(trim($currency));

        return array_values(array_filter(
            self::order($method, $currency),
            fn (string $gateway): bool => self::isLive($gateway) && self::supports($gateway, $method, $currency, $country, $network),
        ));
    }

    /**
     * The switched-on gateways on this row that can take the method in this
     * currency, in row order: what the storefront may offer right now.
     *
     * @return list<string>
     */
    public static function liveGatewaysFor(string $method, string $currency): array
    {
        return self::candidates($method, $currency);
    }

    /**
     * The gateway order the switchboard sets for this method in this currency,
     * whether or not each gateway is switched on or able: the full preference
     * the router then filters. Falls back to the default order for a currency
     * with no row of its own (the card-only fallback currencies).
     *
     * Only fiat gateways survive, so a hand-edited row can never send a fiat
     * method to the crypto or wallet rail.
     *
     * @return list<string>
     */
    public static function order(string $method, string $currency): array
    {
        if (! array_key_exists($method, self::METHOD_PRIORITY)) {
            return [];
        }

        $order = PaymentRouteTable::gatewayOrder($method, $currency) ?? self::METHOD_PRIORITY[$method];

        return array_values(array_unique(array_filter(
            $order,
            fn (string $gateway): bool => in_array($gateway, self::FIAT_GATEWAYS, true),
        )));
    }

    /**
     * The order a method had before the switchboard, for seeding and for the
     * fallback when the table cannot be read. Crypto and wallet each have one
     * rail of their own.
     *
     * @return list<string>
     */
    public static function defaultOrder(string $method): array
    {
        return self::METHOD_PRIORITY[$method] ?? match ($method) {
            'crypto' => ['nowpayments'],
            'wallet' => ['wallet'],
            default => [],
        };
    }

    /**
     * Can this gateway charge this method in this currency, and (for mobile
     * money, when given) in this country on this network? Flags aside.
     */
    public static function supports(string $gateway, string $method, string $currency, ?string $country = null, ?string $network = null): bool
    {
        return GatewayCapabilities::supports($gateway, $method, $currency, $country, $network);
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
     *
     * Capability + availability only; category-aware selection composes on top
     * of liveFiatGateways() in PaymentEligibilityService::defaultGatewayFor.
     */
    public static function defaultGateway(): ?string
    {
        return self::liveFiatGateways()[0] ?? null;
    }

    /**
     * Every live fiat gateway, in preference order.
     *
     * @return list<string>
     */
    public static function liveFiatGateways(): array
    {
        return array_values(array_filter(
            self::FIAT_GATEWAYS,
            fn (string $gateway): bool => self::isLive($gateway),
        ));
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
}
