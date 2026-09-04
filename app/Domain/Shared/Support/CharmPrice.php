<?php

namespace App\Domain\Shared\Support;

/**
 * Customer-facing price rounding for display currencies, so nobody is asked to
 * pay ₦1,056.80. Every USD-settled price converted into a local currency picks
 * up FX decimals; this rounds the converted figure UP to a step the currency's
 * people actually pay in.
 *
 * Rounding is ALWAYS upward: the charged amount can only ever exceed the
 * USD-derived price, so the platform can gain fractions of margin from
 * rounding but can never, in any code path, lose money to it.
 *
 * The step scales with the currency's magnitude (rate_per_usd):
 *   >= 100/USD (NGN, XAF, KES, TZS...) -> nearest 10   (max ~US$0.10 of round-up)
 *   >= 3/USD   (ZAR, GHS, CNY...)      -> nearest 1    (max ~US$0.33)
 *   below      (USD, EUR, GBP)          -> nearest 0.01 (unchanged in practice)
 *
 * Fiat only - callers must not route crypto display amounts through this.
 */
final class CharmPrice
{
    public static function step(float $ratePerUsd): float
    {
        if ($ratePerUsd >= 100) {
            return 10.0;
        }

        if ($ratePerUsd >= 3) {
            return 1.0;
        }

        return 0.01;
    }

    /**
     * Round a display-currency amount UP to the currency's charm step. The
     * pre-ceil rounding to 6 decimals absorbs float noise so an amount that IS
     * already on the step (₦1,020.0000001 from a float multiply) stays put
     * instead of jumping a whole step.
     */
    public static function roundUp(float $amount, float $ratePerUsd): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        $step = self::step($ratePerUsd);

        // The trailing round() scrubs float dust from the multiply
        // (1020 * 0.01 = 10.200000000000001) - it can never change the value
        // by a step, only clean its representation.
        return round(ceil(round($amount / $step, 6)) * $step, 2);
    }
}
