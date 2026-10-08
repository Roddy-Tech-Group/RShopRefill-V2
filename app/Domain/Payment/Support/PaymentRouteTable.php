<?php

namespace App\Domain\Payment\Support;

use App\Models\PaymentRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The payment switchboard, read.
 *
 * Every surface that offers or charges a payment method (the checkout tiles,
 * the wallet sheet, the pay step, the mobile API, the storefront strip) asks
 * this class, so they cannot disagree. Rows come from the payment_routes table,
 * cached for a minute or until a row changes, and memoised for the life of one
 * request or job.
 *
 * If the table cannot be read (the minutes between a code deploy and its
 * migration, or the database failing), the answer is the code's own defaults:
 * the behaviour the platform had before the switchboard existed. A switchboard
 * that is down must never take checkout down with it.
 */
final class PaymentRouteTable
{
    private const CACHE_KEY = 'payment_routes:v1';

    /**
     * Rows are dropped from the cache the moment one changes, but a request
     * that read the old rows just before an admin's commit can write them
     * back afterwards. With no expiry that stale copy would stand until the
     * next edit; with this one, an admin's change is live within a minute at
     * worst.
     */
    private const CACHE_SECONDS = 60;

    /**
     * Currency => offered and withheld methods in tile order.
     *
     * @return array<string, list<array{method: string, offered: bool, gateways: list<string>, note: ?string}>>
     */
    public static function all(): array
    {
        try {
            return Cache::memo()->remember(self::CACHE_KEY, self::CACHE_SECONDS, self::load(...));
        } catch (\Throwable $e) {
            // The cache store may be what failed; the table itself may still answer.
            try {
                return self::load();
            } catch (\Throwable $e) {
                Log::warning('payment_routes.unreadable_using_defaults', ['error' => $e->getMessage()]);

                return self::defaults();
            }
        }
    }

    /**
     * The rows for one currency, or null when the currency has none.
     *
     * @return list<array{method: string, offered: bool, gateways: list<string>, note: ?string}>|null
     */
    public static function forCurrency(string $currency): ?array
    {
        return self::all()[strtoupper(trim($currency))] ?? null;
    }

    /**
     * The gateway order for a method in a currency, or null when there is no
     * row for it.
     *
     * @return list<string>|null
     */
    public static function gatewayOrder(string $method, string $currency): ?array
    {
        foreach (self::forCurrency($currency) ?? [] as $row) {
            if ($row['method'] === $method) {
                return $row['gateways'];
            }
        }

        return null;
    }

    /**
     * Drop the cached copy so the next payment reads the table afresh.
     */
    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
            Cache::memo()->forget(self::CACHE_KEY);
        } catch (\Throwable $e) {
            Log::warning('payment_routes.cache_forget_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, list<array{method: string, offered: bool, gateways: list<string>, note: ?string}>>
     */
    private static function load(): array
    {
        $table = [];

        $rows = PaymentRoute::query()
            ->orderBy('currency')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['currency', 'method', 'is_offered', 'gateways', 'note']);

        if ($rows->isEmpty()) {
            return self::defaults();
        }

        foreach ($rows as $row) {
            $table[strtoupper($row->currency)][] = [
                'method' => $row->method,
                'offered' => (bool) $row->is_offered,
                'gateways' => array_values(array_map('strval', (array) $row->gateways)),
                'note' => $row->note,
            ];
        }

        return $table;
    }

    /**
     * What the platform did before the switchboard: every method a currency
     * listed, offered, in that order, on the default gateway order.
     *
     * @return array<string, list<array{method: string, offered: bool, gateways: list<string>, note: ?string}>>
     */
    public static function defaults(): array
    {
        $table = [];

        foreach (SupportedPaymentMethods::DEFAULTS as $currency => $methods) {
            foreach ($methods as $method) {
                $table[$currency][] = [
                    'method' => $method,
                    'offered' => ! isset(SupportedPaymentMethods::DEFAULT_WITHHELD[$method])
                        && ! in_array($method, SupportedPaymentMethods::DEFAULT_OFF_UNTIL_TESTED[$currency] ?? [], true),
                    'gateways' => FiatGatewayRouter::defaultOrder($method),
                    'note' => SupportedPaymentMethods::DEFAULT_WITHHELD[$method]
                        ?? (in_array($method, SupportedPaymentMethods::DEFAULT_OFF_UNTIL_TESTED[$currency] ?? [], true) ? SupportedPaymentMethods::OFF_UNTIL_TESTED_NOTE : null),
                ];
            }
        }

        return $table;
    }
}
