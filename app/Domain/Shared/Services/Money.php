<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Enums\Currency;

/**
 * Currency-aware money formatter. Centralises every "how do I render an amount
 * to a customer" decision so views never reach for `'$'` or `'USD'` directly.
 *
 * Two formats:
 *   - format($amount, $code)   => "₦25,000.00"      (symbol + amount)
 *   - codeAmount($amount, $code) => "NGN 25,000.00" (code + amount)
 *
 * Callers can pass the currency as a string code ("NGN"), a Currency enum
 * instance, or null — the helper normalises all three. Unknown codes degrade
 * safely: the raw code is used as the prefix and 2 decimals are applied, so
 * we never crash on a legacy/exotic currency.
 */
class Money
{
    /** Scale of wallets.balance and every amount the wallet ledger records. */
    private const LEDGER_DECIMALS = 4;

    public static function format(float $amount, Currency|string|null $code): string
    {
        $currency = self::resolve($code);

        if ($currency instanceof Currency) {
            return $currency->symbol().number_format($amount, $currency->decimalPrecision());
        }

        // Unknown code — render as "XXX 1,234.56" so it stays readable.
        $raw = self::raw($code);

        return ($raw !== '' ? $raw.' ' : '').number_format($amount, 2);
    }

    public static function codeAmount(float $amount, Currency|string|null $code): string
    {
        $currency = self::resolve($code);
        $upper = $currency instanceof Currency
            ? $currency->value
            : (self::raw($code) !== '' ? self::raw($code) : 'USD');
        $precision = $currency instanceof Currency ? $currency->decimalPrecision() : 2;

        return $upper.' '.number_format($amount, $precision);
    }

    /**
     * The amount as the wallet ledger stores it, for admin screens where the
     * figure has to be acted on exactly: the currency's usual decimals,
     * widened up to the ledger's four only when the value carries them, so a
     * 0.0099 balance never reads as 0.01 and can be typed back to clear it.
     */
    public static function exact(float $amount, Currency|string|null $code): string
    {
        $currency = self::resolve($code);
        $decimals = $currency instanceof Currency ? $currency->decimalPrecision() : 2;

        while ($decimals < self::LEDGER_DECIMALS && round($amount, $decimals) !== round($amount, self::LEDGER_DECIMALS)) {
            $decimals++;
        }

        return number_format($amount, $decimals);
    }

    public static function symbol(Currency|string|null $code): string
    {
        $currency = self::resolve($code);

        return $currency instanceof Currency ? $currency->symbol() : self::raw($code);
    }

    private static function resolve(Currency|string|null $code): ?Currency
    {
        if ($code instanceof Currency) {
            return $code;
        }

        if ($code === null || $code === '') {
            return null;
        }

        return Currency::tryFrom(strtoupper($code));
    }

    /**
     * Best-effort uppercase string form of whatever was passed — used only for
     * the fallback render of unknown codes.
     */
    private static function raw(Currency|string|null $code): string
    {
        if ($code instanceof Currency) {
            return $code->value;
        }

        return strtoupper((string) ($code ?? ''));
    }
}
