<?php

namespace App\Support;

use App\Domain\Shared\Support\CharmPrice;
use App\Models\CurrencyRate;

/**
 * One shape for every amount /v2 ever sends.
 *
 * Money is the thing an API must be least ambiguous about. A bare number is a
 * question waiting to be answered wrongly: is it dollars or naira, is it cents or
 * units, is it before or after conversion? So every amount goes out as the same
 * object, and the app never has to guess:
 *
 *   {
 *     "amount": "1620.50",   what to show
 *     "currency": "NGN",     what to show it in
 *     "usd": "1.0500"        the settlement figure, always present
 *   }
 *
 * `usd` is always there because USD is what we actually settle in, and because it
 * is the figure that will still reconcile against an order six months later when
 * the rate has moved. `amount` is presentational and may differ between two
 * devices looking at the same product in different currencies.
 *
 * AMOUNTS ARE STRINGS, and that is deliberate.
 *
 * PHP's json_encode drops a zero fraction, so a price of exactly 20 goes out as
 * `20` while 20.50 goes out as `20.5`. On the wire JSON has one number type, so
 * that is legal, but a typed client generated from this contract does not: a
 * Kotlin field declared Int parses `20` happily and then crashes on the first
 * price with kobo in it. A string is one type, always, and carries its precision
 * visibly. It also sidesteps the older argument about float64 and money.
 *
 * The cost is that the app parses before doing arithmetic, which is the right
 * habit for money anyway. PayPal, Braintree and Adyen all take this shape; Stripe
 * takes the other common one, integer minor units, which does not fit here because
 * our settlement figures carry four decimal places and cents would lose them.
 *
 * Deliberately a plain array rather than a JsonResource: a Resource wraps a
 * MODEL, and this wraps a number.
 */
final class ApiMoney
{
    /** Decimal places on a displayed price. Two, because that is what a price is. */
    private const DISPLAY_SCALE = 2;

    /**
     * Decimal places on the settlement figure. Four, matching the decimal:4 casts
     * on the columns these come from, so nothing is lost for reconciliation.
     */
    private const SETTLEMENT_SCALE = 4;

    /**
     * A price, converted into the display currency the way CHECKOUT converts it.
     *
     * Two steps, and both have to match CheckoutService or the basket screen and
     * the charge disagree. The USD figure is multiplied by the display rate and
     * rounded to four places, exactly as the order total is. Then, for a fiat
     * currency, it is charm-rounded UP to the step that currency is actually paid
     * in (₦1,056.80 becomes ₦1,060), which is what the order total is charged at
     * and what the website's own cart shows. Skipping that step showed the
     * customer ₦1,056.80 and billed them ₦1,060. Crypto display amounts are never
     * charm-rounded, matching checkout again.
     *
     * @return array{amount: string, currency: string, usd: string}
     */
    public static function fromUsd(float $usd): array
    {
        $to = DisplayCurrency::code();
        $rate = $to !== null && $to !== 'USD' ? DisplayCurrency::rate('USD', $to) : null;

        if ($to === null || $to === 'USD' || $rate === null || $rate <= 0) {
            return [
                'amount' => self::decimal($usd, self::DISPLAY_SCALE),
                'currency' => 'USD',
                'usd' => self::decimal($usd, self::SETTLEMENT_SCALE),
            ];
        }

        $converted = round($usd * $rate, 4);

        $amount = self::isFiat($to)
            ? CharmPrice::roundUp($converted, $rate)
            : round($converted, 2);

        return [
            'amount' => self::decimal($amount, self::DISPLAY_SCALE),
            'currency' => $to,
            'usd' => self::decimal($usd, self::SETTLEMENT_SCALE),
        ];
    }

    /**
     * Whether a display currency is fiat, memoised per request: a listing prices
     * hundreds of amounts and this must not be a query for each.
     */
    private static function isFiat(string $currency): bool
    {
        $memoKey = 'memo:currency_is_fiat:'.$currency;
        $attributes = request()->attributes;

        if (! $attributes->has($memoKey)) {
            $attributes->set(
                $memoKey,
                CurrencyRate::query()->where('code', $currency)->value('type') === 'fiat',
            );
        }

        return (bool) $attributes->get($memoKey);
    }

    /**
     * An amount already denominated in some other currency, such as a gift card's
     * face value. Not converted: a UK card is worth GBP 50 however the customer
     * is browsing, and pretending otherwise would misrepresent the product.
     *
     * @return array{amount: string, currency: string}
     */
    public static function native(float $amount, string $currency): array
    {
        return [
            'amount' => self::decimal($amount, self::DISPLAY_SCALE),
            'currency' => strtoupper($currency),
        ];
    }

    /**
     * An amount that was already settled, in the currency it was settled in.
     *
     * For orders and wallet movements, NOT for prices. A price is converted at
     * today's rate because the customer is about to pay it. An order from three
     * months ago is a historical fact: it was placed in naira, at that day's rate,
     * and the customer's bank statement says so. Running it back through
     * DisplayCurrency would re-convert at today's rate and quietly disagree with
     * their statement, their receipt and our own ledger.
     *
     * So nothing is converted here. Both figures are given as recorded.
     *
     * @return array{amount: string, currency: string, usd: string}
     */
    public static function historic(float $amount, string $currency, float $usd): array
    {
        return [
            'amount' => self::decimal($amount, self::DISPLAY_SCALE),
            'currency' => strtoupper($currency ?: 'USD'),
            'usd' => self::decimal($usd, self::SETTLEMENT_SCALE),
        ];
    }

    /**
     * A fixed-scale decimal string with no thousands separator, so it parses the
     * same in every locale. number_format rather than sprintf because it rounds
     * half-up, which is what a customer expects of a price.
     */
    private static function decimal(float $amount, int $scale): string
    {
        return number_format($amount, $scale, '.', '');
    }
}
