<?php

namespace App\Support;

use App\Domain\Payment\Support\FiatGatewayRouter;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Log;

/**
 * Tiny facade around SiteSetting for feature flags. Every features.* key
 * stored in the database is on/off and rides through this class so views
 * and controllers can do FeatureFlag::on('checkout') without thinking about
 * the storage layer or the on/off/true/1 normalisation.
 *
 * Reads are cached by SiteSetting::get() (1h), so this is cheap.
 */
class FeatureFlag
{
    /** Currencies considered "truthy" for the on/off toggles. */
    private const TRUTHY = ['on', 'true', '1'];

    /**
     * The ONE canonical map from a category's DB slug to its feature-flag key.
     * The slugs and flag keys diverge (mobile-airtime -> topups, bill-payments ->
     * bills), so every surface - nav, listings, detail pages, and the server-side
     * purchase guard - must resolve the flag through here so "greyed out" in the UI
     * and "blocked" on the server can never disagree.
     *
     * @var array<string, string>
     */
    public const CATEGORY_FLAGS = [
        'gift-cards' => 'gift_cards',
        'esims' => 'esims',
        'mobile-airtime' => 'topups',
        'bill-payments' => 'bills',
        // Coming-soon categories: no products behind them yet, but the flags
        // gate the surfaces that advertise them (homepage cards, admin menu).
        'flights' => 'flights',
        'stays' => 'stays',
    ];

    /**
     * The ONE canonical map from a payment gateway to its kill-switch flag.
     * Same contract as CATEGORY_FLAGS: the checkout tiles, the pre-flight
     * guard in CheckoutService/WalletFundingService and the pay endpoint all
     * resolve through here, so "greyed out" in the UI and "refused" on the
     * server can never disagree.
     *
     * A disabled gateway stops NEW money entering the pipe only. Webhooks,
     * verification, the sweeper's last-chance check and refunds are never
     * gated: money already in flight must always land.
     *
     * @var array<string, string>
     */
    public const GATEWAY_FLAGS = [
        'flutterwave' => 'gateway_flutterwave',
        'nowpayments' => 'gateway_nowpayments',
        'wallet' => 'gateway_wallet',
        // Fiat backups. Seeded OFF; they receive traffic only while the
        // Flutterwave flag is off, and only for the method/currency pairs
        // FiatGatewayRouter says they cover.
        'paystack' => 'gateway_paystack',
        'korapay' => 'gateway_korapay',
    ];

    /**
     * Checkout method key => the gateway FAMILY it belongs to. For the fiat
     * methods this names the primary only; actual routing (including failover
     * to Paystack / Kora when Flutterwave is off) lives in FiatGatewayRouter.
     * Kept for telemetry and for surfaces that need the family, never for
     * availability decisions - those go through methodEnabled().
     *
     * @var array<string, string>
     */
    public const METHOD_GATEWAYS = [
        'card' => 'flutterwave',
        'apple_pay' => 'flutterwave',
        'bank_transfer' => 'flutterwave',
        'pay_with_bank' => 'flutterwave',
        'ussd' => 'flutterwave',
        'bank_qr' => 'flutterwave',
        'mobile_wallet' => 'flutterwave',
        'mobile_money' => 'flutterwave',
        'crypto' => 'nowpayments',
        'wallet' => 'wallet',
    ];

    /**
     * Is the gateway accepting NEW payments? Unknown gateways default to on
     * so a future integration without a flag row behaves like every other
     * missing flag.
     */
    public static function gatewayEnabled(string $gateway): bool
    {
        $key = self::GATEWAY_FLAGS[$gateway] ?? null;

        return $key === null ? true : self::moneySwitch($key);
    }

    /**
     * A kill switch that decides whether money may move fails CLOSED.
     *
     * on() falls back to the flag's default when the setting store is down,
     * and gateway flags default to on, so a cache outage used to turn a
     * switch an admin had deliberately flipped off back on. Here the read
     * goes cache, then database; only when neither can answer is the gateway
     * treated as off, and someone is told why the tiles went grey. A row that
     * simply does not exist is still on by default, as before.
     */
    private static function moneySwitch(string $name): bool
    {
        $key = 'features.'.rtrim($name, '_').'_enabled';

        try {
            $value = SiteSetting::readOrFail($key);
        } catch (\Throwable $e) {
            Log::critical('Gateway kill switch unreadable, failing closed', ['flag' => $key, 'error' => $e->getMessage()]);

            try {
                app(OperationalAlerter::class)->send(
                    severity: AlertSeverity::Sev2,
                    key: 'feature-flag:unreadable:gateway',
                    title: 'Gateway switches unreadable',
                    message: 'Neither the cache nor the database could answer a gateway kill switch. New payments are refused until one of them recovers.',
                    context: ['flag' => $key, 'error' => $e->getMessage()],
                    runbook: 'docs/runbooks/payment-provider-failure.md',
                );
            } catch (\Throwable) {
                // Alerting is best effort; the refusal is what matters.
            }

            return false;
        }

        if ($value === null) {
            return true;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), self::TRUTHY, true);
    }

    /**
     * Is the checkout method key payable right now? Fiat methods route
     * through FiatGatewayRouter so a live backup keeps them payable while
     * Flutterwave is off; pass the checkout currency for the exact answer
     * (without it, "some currency could pay this" is enough to say yes).
     */
    public static function methodEnabled(string $method, ?string $currency = null): bool
    {
        if (array_key_exists($method, FiatGatewayRouter::METHOD_PRIORITY)) {
            if ($currency !== null) {
                return FiatGatewayRouter::resolve($method, $currency) !== null;
            }

            foreach (FiatGatewayRouter::METHOD_PRIORITY[$method] as $gateway) {
                if (self::gatewayEnabled($gateway)) {
                    return true;
                }
            }

            return false;
        }

        $gateway = self::METHOD_GATEWAYS[$method] ?? null;

        return $gateway === null ? true : self::gatewayEnabled($gateway);
    }

    /**
     * Gateways currently switched off - what the checkout page greys out.
     *
     * @return list<string>
     */
    public static function disabledGateways(): array
    {
        return array_values(array_filter(
            array_keys(self::GATEWAY_FLAGS),
            fn (string $gateway) => ! self::gatewayEnabled($gateway),
        ));
    }

    /**
     * Categories that DISAPPEAR when switched off, rather than greying out.
     *
     * The grey-out treatment says "we sell this, it is briefly unavailable" -
     * right for Gift Cards or eSIMs during an outage. Flights and Stays have no
     * category row, no products and no fulfillment path: they are unbuilt, not
     * unavailable. Greying them out advertises a service that does not exist,
     * so switching them off removes them from the nav, the pickers, the
     * sitemap, the marketing copy and their own routes entirely.
     *
     * @var list<string>
     */
    public const HIDDEN_WHEN_OFF = ['flights', 'stays'];

    /**
     * Should this category appear on the storefront at all?
     *
     * Only the unbuilt categories can answer false. Everything else stays
     * visible whatever its flag says and relies on categoryEnabled() to grey
     * itself out, which is the behaviour those surfaces already assume.
     */
    public static function categoryVisible(?string $slug): bool
    {
        if ($slug === null || ! in_array($slug, self::HIDDEN_WHEN_OFF, true)) {
            return true;
        }

        return self::categoryEnabled($slug);
    }

    /**
     * Feature-flag key for a category slug, or null when the category has no gate
     * (a future category without a flag - treated as on).
     */
    public static function forCategory(?string $slug): ?string
    {
        return $slug ? (self::CATEGORY_FLAGS[$slug] ?? null) : null;
    }

    /**
     * Is the category (identified by its DB slug) currently enabled? Ungated
     * categories are always considered on, matching on()'s default-true behaviour.
     */
    public static function categoryEnabled(?string $slug): bool
    {
        $key = self::forCategory($slug);

        return $key === null ? true : self::on($key);
    }

    /**
     * Is the named feature flag turned on? Pass the suffix only:
     *   FeatureFlag::on('checkout')              // reads features.checkout_enabled
     *   FeatureFlag::on('feedback_widget')       // reads features.feedback_widget_enabled
     *
     * The `_enabled` suffix is added automatically. Pass `$default` to control
     * what happens when the row is missing entirely (defaults to true so new
     * features ship as enabled until an admin explicitly turns them off).
     */
    public static function on(string $name, bool $default = true): bool
    {
        $key = str_starts_with($name, 'features.') ? $name : 'features.'.rtrim($name, '_').'_enabled';
        $value = SiteSetting::get($key, $default ? 'on' : 'off');

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), self::TRUTHY, true);
    }

    /** Convenience inverse. */
    public static function off(string $name, bool $default = true): bool
    {
        return ! self::on($name, $default);
    }
}
