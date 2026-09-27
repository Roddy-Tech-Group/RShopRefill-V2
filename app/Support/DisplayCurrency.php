<?php

namespace App\Support;

use App\Models\CurrencyRate;
use App\Models\ExchangeRate;

/**
 * The customer's chosen DISPLAY currency - the locale modal's currency pane.
 *
 * Chosen once, it converts every browse-surface price into that currency:
 * tile price ranges, the product page's pay prices, denomination rows. It
 * is display only - checkout still settles through its own payment-currency
 * flow, and a card's face value stays in the card's own currency (a UK card
 * is GBP however the customer browses).
 *
 * Resolution happens in ResolveDisplayCurrency: ?display_currency= for a
 * fresh switch, then the display_currency cookie, then null - native
 * prices. Rates come from the display-safe registry chain
 * (CurrencyRateService::resolveRateForDisplay) and are memoized per request
 * on the request instance - a listing page formats hundreds of labels, and
 * each one must not be a query. A pair the registry cannot price falls back
 * to the native label: a right price in the wrong currency beats a wrong
 * price in the right one.
 */
class DisplayCurrency
{
    public const COOKIE = 'display_currency';

    /** The chosen display currency, or null to show native prices. */
    public static function code(): ?string
    {
        $code = request()->attributes->get(self::COOKIE);

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * An amount in its display form: converted when a display currency is
     * chosen and the pair is priceable, native otherwise.
     *
     * @return array{0: float, 1: string} [amount, ISO currency code]
     */
    public static function convert(float $amount, ?string $native): array
    {
        $from = strtoupper(trim((string) ($native ?: 'USD')));
        $to = self::code();

        if ($to === null || $to === $from) {
            return [$amount, $from];
        }

        $rate = self::rate($from, $to);

        if ($rate === null || $rate <= 0) {
            return [$amount, $from];
        }

        return [round($amount * $rate, 2), $to];
    }

    /** Display rate for a pair, memoized per request; null when unpriceable. */
    public static function rate(string $from, string $to): ?float
    {
        $memoKey = 'memo:display_rate:'.$from.':'.$to;
        $attributes = request()->attributes;

        if (! $attributes->has($memoKey)) {
            try {
                $attributes->set($memoKey, self::resolveStrictRate($from, $to));
            } catch (\Throwable $e) {
                $attributes->set($memoKey, null);
            }
        }

        return $attributes->get($memoKey);
    }

    /**
     * A rate only when one genuinely exists - null otherwise.
     *
     * Deliberately NOT CurrencyRateService::resolveRateForDisplay(): its
     * currency_rates fallback resolves an unknown code to the USD row, and
     * failing that to a synthetic 1.0, so an unpriceable pair comes back as
     * rate 1.0 rather than as an error. On a money surface that is the worst
     * possible answer - a $10 card would read "EUR 10" - so this asks for
     * both legs explicitly and gives up when either is missing. Giving up
     * means showing the native price, which is always true.
     */
    private static function resolveStrictRate(string $from, string $to): ?float
    {
        $direct = ExchangeRate::active()
            ->where('base_currency', $from)
            ->where('target_currency', $to)
            ->orderByDesc('fetched_at')
            ->value('rate');

        if ($direct !== null && (float) $direct > 0) {
            return (float) $direct;
        }

        $inverse = ExchangeRate::active()
            ->where('base_currency', $to)
            ->where('target_currency', $from)
            ->orderByDesc('fetched_at')
            ->value('rate');

        if ($inverse !== null && (float) $inverse > 0) {
            return 1.0 / (float) $inverse;
        }

        // Cross-rate through the admin-managed table, but only when BOTH
        // currencies really carry a row of their own.
        $fromPerUsd = CurrencyRate::query()->where('is_active', true)->where('code', $from)->value('rate_per_usd');
        $toPerUsd = CurrencyRate::query()->where('is_active', true)->where('code', $to)->value('rate_per_usd');

        if ($fromPerUsd !== null && $toPerUsd !== null && (float) $fromPerUsd > 0 && (float) $toPerUsd > 0) {
            return (float) $toPerUsd / (float) $fromPerUsd;
        }

        return null;
    }
}
