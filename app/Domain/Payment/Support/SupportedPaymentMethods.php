<?php

namespace App\Domain\Payment\Support;

/**
 * Single source of truth for which payment methods a display currency accepts.
 *
 * Three layers have to agree on this list: the checkout page's method tabs,
 * CheckoutController (which creates the order + PaymentAttempt) and
 * PaymentSessionController::pay (which actually charges it). When the pay step
 * held a shorter list than checkout, a customer could pick USSD / Pay With Bank
 * / Bank QR / Mobile Wallet, get a real order, and then be told the method was
 * "not available for currency" - leaving an order that could not be paid by any
 * means. Both controllers now read this list so they cannot drift again.
 *
 * The mapping mirrors what is enabled on the gateway dashboard, so we never
 * offer a method the provider would then reject.
 */
final class SupportedPaymentMethods
{
    /**
     * Display currency => allowed method keys.
     *
     * @var array<string, list<string>>
     */
    private const BY_CURRENCY = [
        'USD' => ['card', 'apple_pay', 'crypto', 'wallet'],
        'EUR' => ['card', 'apple_pay', 'crypto', 'wallet', 'pay_with_bank'],
        'GBP' => ['card', 'apple_pay', 'crypto', 'wallet', 'pay_with_bank'],
        'NGN' => ['card', 'apple_pay', 'bank_transfer', 'crypto', 'wallet', 'ussd', 'pay_with_bank', 'bank_qr', 'mobile_wallet'],
        'GHS' => ['card', 'apple_pay', 'mobile_money', 'crypto', 'wallet'],
        'XAF' => ['card', 'apple_pay', 'mobile_money', 'crypto', 'wallet'],
        'XOF' => ['card', 'apple_pay', 'mobile_money', 'crypto', 'wallet'],
        'KES' => ['card', 'apple_pay', 'mobile_money', 'crypto', 'wallet'],
        'UGX' => ['card', 'apple_pay', 'mobile_money', 'crypto', 'wallet'],
        'RWF' => ['card', 'apple_pay', 'mobile_money', 'crypto', 'wallet'],
    ];

    /**
     * Currencies without an explicit entry only get the globally available
     * methods.
     */
    private const FALLBACK = ['card', 'apple_pay'];

    /**
     * @return list<string>
     */
    public static function forCurrency(string $currency): array
    {
        return self::BY_CURRENCY[strtoupper(trim($currency))] ?? self::FALLBACK;
    }

    /**
     * @return bool True when the currency accepts the method.
     */
    public static function allows(string $currency, ?string $method): bool
    {
        return $method !== null && in_array($method, self::forCurrency($currency), true);
    }
}
