<?php

namespace App\Domain\Payment\Support;

use App\Models\CurrencyRate;
use Illuminate\Support\Facades\Cache;

/**
 * The payment-method badges shown on the storefront "Pay your way" strip.
 *
 * Lives here rather than in the Blade component because every badge is a
 * promise that checkout accepts that rail, and a promise has to be checkable.
 * Each entry names the SupportedPaymentMethods key it stands for, so a test can
 * prove the strip never advertises something checkout would reject: a customer
 * who picks a method off this strip and then cannot use it is worse off than
 * one who never saw it.
 *
 * What each badge's claim rests on:
 *   - card brands ride the gateway's acceptance, not ours. The live card path
 *     is the provider's inline modal (direct charge is off by default) and our
 *     code never inspects the brand, so Visa through Diners are all genuinely
 *     chargeable today. PaymentSession's supported_brands array is display
 *     copy, not a gate.
 *   - mobile-money networks are per the owner's confirmation of the gateway
 *     account (2026-07-30). Each market charges on its own Flutterwave rail
 *     (see MobileMoneyRail); the charge used to be hardwired to the
 *     francophone rail, which refused every non-XAF/XOF attempt.
 *   - the bank / USSD / QR / wallet rails are drawn generically, because the
 *     customer picks their own bank, short code or wallet on the gateway's
 *     page and we cannot know which one they will use.
 *
 * Brand marks only, never gateway or provider names: the customer chooses
 * "Visa" or "Bank transfer", not whoever settles it behind the scenes.
 */
final class PaymentStripBadges
{
    /** Coin lineup changes with every crypto sync, so cache it with the chrome. */
    public const CRYPTO_CACHE_KEY = 'storefront-payment-coins-v1';

    private const CRYPTO_CACHE_MINUTES = 30;

    /**
     * Card networks, wallets and bank rails.
     *
     * `img` names an owned logo asset; `svg` names an inline mark drawn by the
     * payment-mark component. `mono` marks a single-colour black glyph, which
     * has to be inverted for dark mode rather than opted out of the invert.
     *
     * @var list<array{label: string, method: string, img?: string, svg?: string, mono?: bool}>
     */
    private const FIXED = [
        // Mobile money leads the strip. These are the rails most customers on
        // this catalogue actually pay with, so they are the first thing the row
        // shows rather than the eighth.
        //
        // Both are drawn inline rather than loaded as files: the global
        // dark-mode rule inverts SVG images, which would turn MTN's yellow
        // purple and Orange's orange blue.
        ['label' => 'MoMo', 'method' => 'mobile_money', 'svg' => 'mtn_momo'],
        ['label' => 'Orange Money', 'method' => 'mobile_money', 'svg' => 'orange_money'],
        ['label' => 'Visa', 'method' => 'card', 'img' => 'visa.svg'],
        ['label' => 'Mastercard', 'method' => 'card', 'img' => 'mastercard.svg'],
        ['label' => 'Verve', 'method' => 'card', 'svg' => 'verve'],
        ['label' => 'American Express', 'method' => 'card', 'svg' => 'amex'],
        ['label' => 'Discover', 'method' => 'card', 'svg' => 'discover'],
        ['label' => 'Diners Club', 'method' => 'card', 'svg' => 'diners'],
        ['label' => 'Apple Pay', 'method' => 'apple_pay', 'svg' => 'apple_pay'],
        ['label' => 'M-PESA', 'method' => 'mobile_money', 'svg' => 'mpesa'],
        ['label' => 'Airtel Money', 'method' => 'mobile_money', 'svg' => 'airtel_money'],
        ['label' => 'Bank transfer', 'method' => 'bank_transfer', 'svg' => 'bank'],
        ['label' => 'Pay with bank', 'method' => 'pay_with_bank', 'svg' => 'bank_online'],
        ['label' => 'USSD', 'method' => 'ussd', 'svg' => 'ussd'],
        ['label' => 'Bank QR', 'method' => 'bank_qr', 'svg' => 'bank_qr'],
        ['label' => 'Mobile wallet', 'method' => 'mobile_wallet', 'svg' => 'mobile_wallet'],
    ];

    /**
     * Every badge, fixed rails first and the live crypto lineup after.
     *
     * @return list<array{label: string, method: string, img?: string, svg?: string, mono?: bool}>
     */
    public static function all(): array
    {
        // A badge is a promise that checkout takes the method. A method held
        // back from checkout (SupportedPaymentMethods::withheld) loses its
        // badge here too, with no second list to keep in step.
        $offered = SupportedPaymentMethods::allMethods();

        $fixed = array_values(array_filter(
            self::FIXED,
            fn (array $badge) => in_array($badge['method'], $offered, true),
        ));

        return [...$fixed, ...self::cryptoBadges()];
    }

    /**
     * Coins read from currency_rates, NOT a literal list: the accepted lineup
     * moves (BNB was retired, TON and USDC added) and a hard-coded strip goes
     * on advertising coins the gateway no longer takes.
     *
     * Gated on the SAME scope checkout uses - payable() = is_active AND
     * is_payable. A coin with is_payable=false is absent from the checkout coin
     * picker and rejected outright by PaymentSessionController, so advertising
     * it here would be a permanent lie rather than a stale cache. is_payable
     * defaults to true in the migration that adds it, so gating on it costs
     * nothing in an environment where crypto:sync-currencies has never run.
     *
     * @return list<array{label: string, method: string, img: string}>
     */
    public static function cryptoBadges(): array
    {
        return Cache::remember(
            self::CRYPTO_CACHE_KEY,
            now()->addMinutes(self::CRYPTO_CACHE_MINUTES),
            fn () => CurrencyRate::query()
                ->payable()
                ->crypto()
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get(['code', 'name', 'icon_path'])
                ->filter(fn (CurrencyRate $coin) => filled($coin->icon_path))
                // One badge per coin ARTWORK: USDT on BSC / TRC20 / SOL are
                // three payable currencies sharing one logo, and three
                // identical badges read as a rendering bug, not a choice of
                // network.
                ->unique('icon_path')
                ->map(fn (CurrencyRate $coin) => [
                    'label' => $coin->name ?: $coin->code,
                    'method' => 'crypto',
                    'img' => $coin->icon_path,
                ])
                ->values()
                ->all()
        );
    }

    /**
     * Drop the cached lineup.
     *
     * Called by crypto:sync-currencies after it rewrites is_payable, so a coin
     * the gateway just stopped accepting leaves the homepage immediately rather
     * than lingering for the rest of the cache window.
     */
    public static function forgetCryptoCache(): void
    {
        Cache::forget(self::CRYPTO_CACHE_KEY);
    }
}
