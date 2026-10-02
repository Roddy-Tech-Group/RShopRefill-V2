<?php

namespace App\Rules;

use App\Models\CurrencyRate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A currency a customer may be charged in.
 *
 * That is the settlement currency itself (USD, which needs no rate: one dollar
 * is one dollar), or a fiat currency with an active row in the rate table. The
 * rate lookup behind checkout and funding used to answer any other code with
 * the USD row, so "5.00 ZZZ" became a real order for 5.00 of a currency we hold
 * no rate for. Every door that takes a charge currency uses this one rule, and
 * CurrencyRateService::resolveRate() applies the same test when it converts.
 */
class ChargeableCurrency implements ValidationRule
{
    public const SETTLEMENT_CURRENCY = 'USD';

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::accepts($value)) {
            $fail('That currency is not available right now. Please choose another currency.');
        }
    }

    /**
     * Whether an order or top-up may be priced and charged in this currency.
     */
    public static function accepts(string $code): bool
    {
        $code = strtoupper(trim($code));

        if ($code === self::SETTLEMENT_CURRENCY) {
            return true;
        }

        return CurrencyRate::query()
            ->where('is_active', true)
            ->where('type', 'fiat')
            ->where('code', $code)
            ->exists();
    }
}
