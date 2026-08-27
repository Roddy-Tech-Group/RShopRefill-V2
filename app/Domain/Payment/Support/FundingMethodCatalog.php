<?php

namespace App\Domain\Payment\Support;

use App\Support\FeatureFlag;

/**
 * The payment-method tiles for the wallet-funding sheet, WITHOUT a session.
 *
 * The sheet used to read its tiles off a PaymentSession, which forced a
 * funding row into existence before the customer had chosen anything, and
 * which collapsed to a lone crypto tile the moment fiat was dark (the session
 * was stamped crypto, so the fiat tiles did not grey out - they vanished).
 *
 * This builds the same list from the same primitives checkout uses: the
 * per-currency allow-list the pay endpoint enforces, and FeatureFlag's
 * method-level availability (which already folds in the fiat failover
 * router). Every applicable method is returned, live or not, with an
 * `enabled` flag, so a dark rail shows as "temporarily unavailable" exactly
 * as it does at checkout, and lights back up on its own when the flag flips.
 */
final class FundingMethodCatalog
{
    /**
     * Copy per method. Wallet balance is excluded: a wallet cannot fund itself.
     *
     * @var array<string, array{label: string, description: string}>
     */
    private const METHODS = [
        'card' => ['label' => 'Card Payment', 'description' => 'Visa, Mastercard, Verve'],
        'apple_pay' => ['label' => 'Apple Pay', 'description' => 'Pay securely with Apple Wallet'],
        'bank_transfer' => ['label' => 'Bank Transfer', 'description' => 'Transfer directly to a dynamic virtual account'],
        'mobile_money' => ['label' => 'Mobile Money', 'description' => 'MTN Mobile Money or Orange Money'],
        'ussd' => ['label' => 'USSD Code', 'description' => 'Dial a code to pay from your bank account'],
        'pay_with_bank' => ['label' => 'Pay With Bank', 'description' => 'Internet and mobile banking'],
        'bank_qr' => ['label' => 'Bank QR', 'description' => 'Scan to pay from your banking app'],
        'mobile_wallet' => ['label' => 'Opay / Pocket', 'description' => 'Pay instantly with your Opay wallet'],
        'crypto' => ['label' => 'Crypto Transfer', 'description' => 'USDT, BTC, ETH and more'],
    ];

    /**
     * Every method the currency accepts, in allow-list order, each flagged
     * with whether it can take money right now.
     *
     * @return list<array{type: string, provider: string, label: string, description: string, enabled: bool, supported_networks?: list<string>}>
     */
    public static function forCurrency(string $currency, ?string $cryptoMinimumLabel = null): array
    {
        $currency = strtoupper(trim($currency));
        $methods = [];

        foreach (SupportedPaymentMethods::forCurrency($currency) as $type) {
            if ($type === 'wallet' || ! isset(self::METHODS[$type])) {
                continue;
            }

            $entry = [
                'type' => $type,
                'provider' => self::providerFor($type, $currency),
                'label' => self::METHODS[$type]['label'],
                'description' => self::METHODS[$type]['description'],
                'enabled' => FeatureFlag::methodEnabled($type, $currency),
            ];

            if ($type === 'mobile_money') {
                $entry['description'] = $currency === 'GHS' ? 'MTN, Vodafone, AirtelTigo Mobile Money' : 'MTN Mobile Money or Orange Money';
                $entry['supported_networks'] = $currency === 'GHS' ? ['MTN', 'Vodafone', 'AirtelTigo'] : ['MTN', 'Orange'];
            }

            // The crypto floor belongs on the tile, so a customer whose amount is
            // fine for card learns that crypto wants more BEFORE choosing it.
            if ($type === 'crypto' && $cryptoMinimumLabel !== null) {
                $entry['description'] = "From {$cryptoMinimumLabel} in USDT, BTC, ETH and more";
            }

            $methods[] = $entry;
        }

        return $methods;
    }

    /**
     * Whether any fiat method can take money in this currency right now. When
     * none can, crypto is the only rail and its minimum is the real minimum.
     */
    public static function hasLiveFiat(string $currency): bool
    {
        foreach (self::forCurrency($currency) as $method) {
            if ($method['type'] !== 'crypto' && $method['enabled']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Human labels for the rails that are down, for the "temporarily
     * unavailable" notice. Fiat collapses to one label because customers see
     * rails, not vendors.
     *
     * @return list<string>
     */
    public static function downRails(string $currency): array
    {
        $rails = [];

        foreach (self::forCurrency($currency) as $method) {
            if ($method['enabled']) {
                continue;
            }

            $rails[] = $method['type'] === 'crypto' ? 'Crypto payments' : 'Card and bank payments';
        }

        return array_values(array_unique($rails));
    }

    private static function providerFor(string $type, string $currency): string
    {
        if ($type === 'crypto') {
            return 'nowpayments';
        }

        return FiatGatewayRouter::resolve($type, $currency) ?? 'flutterwave';
    }
}
