<?php

namespace Tests\Feature\Shop;

use App\Domain\Payment\Support\PaymentStripBadges;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Models\CurrencyRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The homepage "Pay your way" strip.
 *
 * Every badge is a promise that checkout accepts that rail, so the badge list is
 * asserted against SupportedPaymentMethods rather than eyeballed, and the crypto
 * coins are gated on the exact scope checkout uses. Both rules exist because the
 * strip got them wrong: it hard-coded BNB (retired by the crypto launch lineup),
 * omitted TON and USDC (added by it), and gated coins on `is_active` while
 * checkout gates on `is_active AND is_payable` - so a coin the gateway had
 * stopped accepting was advertised on the homepage and rejected at payment.
 */
class PaymentStripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // currency_rates is NOT empty at the start of a test: the crypto launch
        // migrations seed the coin lineup and RefreshDatabase runs migrations
        // for every case. These tests own their coin set, so clear it - and the
        // badge cache with it, or the first test's lineup leaks into the rest.
        CurrencyRate::query()->delete();
        PaymentStripBadges::forgetCryptoCache();
    }

    private function coin(
        string $code,
        string $name,
        ?string $icon,
        bool $active = true,
        bool $payable = true,
        int $sort = 100,
    ): CurrencyRate {
        return CurrencyRate::create([
            'code' => $code,
            'name' => $name,
            'type' => 'crypto',
            'rate_per_usd' => 1,
            'icon_path' => $icon,
            'sort_order' => $sort,
            'is_active' => $active,
            'is_payable' => $payable,
        ]);
    }

    public function test_every_badge_names_a_method_checkout_actually_accepts(): void
    {
        $this->coin('BTC', 'Bitcoin', 'BTC.svg');

        $supported = SupportedPaymentMethods::allMethods();

        foreach (PaymentStripBadges::all() as $badge) {
            $this->assertContains(
                $badge['method'],
                $supported,
                "The strip advertises '{$badge['label']}' as method '{$badge['method']}', which no currency accepts.",
            );
        }
    }

    public function test_every_badge_carries_exactly_one_mark(): void
    {
        $this->coin('BTC', 'Bitcoin', 'BTC.svg');

        foreach (PaymentStripBadges::all() as $badge) {
            $this->assertTrue(
                isset($badge['img']) xor isset($badge['svg']),
                "Badge '{$badge['label']}' must have either an img asset or an inline svg mark, not both or neither.",
            );
        }
    }

    /**
     * A badge whose `svg` key has no matching @case renders the component's
     * neutral fallback - no blank hole in the strip, but also not the intended
     * mark. Catch the mismatch here instead.
     */
    public function test_every_inline_mark_has_a_case_in_the_component(): void
    {
        $component = File::get(resource_path('views/components/home/payment-mark.blade.php'));

        foreach (PaymentStripBadges::all() as $badge) {
            if (! isset($badge['svg'])) {
                continue;
            }

            $this->assertStringContainsString(
                "@case('{$badge['svg']}')",
                $component,
                "Badge '{$badge['label']}' asks for inline mark '{$badge['svg']}', which payment-mark.blade.php does not draw.",
            );
        }
    }

    /** Every img badge must point at an asset that exists on disk. */
    public function test_every_logo_asset_exists(): void
    {
        $this->coin('BTC', 'Bitcoin', 'BTC.svg');

        foreach (PaymentStripBadges::all() as $badge) {
            if (! isset($badge['img'])) {
                continue;
            }

            $this->assertFileExists(
                public_path('assets/'.$badge['img']),
                "Badge '{$badge['label']}' points at a missing asset: {$badge['img']}",
            );
        }
    }

    public function test_retired_coins_are_not_advertised(): void
    {
        $this->coin('BTC', 'Bitcoin', 'BTC.svg', sort: 10);
        // BNB was dropped from the launch lineup: deactivated, not deleted,
        // because historical orders still reference the code.
        $this->coin('BNB', 'BNB', 'BNB.webp', active: false, sort: 20);

        $labels = array_column(PaymentStripBadges::cryptoBadges(), 'label');

        $this->assertContains('Bitcoin', $labels);
        $this->assertNotContains('BNB', $labels);
    }

    /**
     * The gate that matters most: checkout hides and the pay endpoint rejects
     * any coin with is_payable=false, so advertising one is a permanent lie
     * rather than a stale cache.
     */
    public function test_coins_the_gateway_will_not_accept_are_not_advertised(): void
    {
        $this->coin('BTC', 'Bitcoin', 'BTC.svg', sort: 10);
        $this->coin('TON', 'Toncoin', 'TON.svg', payable: false, sort: 20);

        $this->assertSame(['Bitcoin'], array_column(PaymentStripBadges::cryptoBadges(), 'label'));
    }

    public function test_coins_sharing_one_logo_render_a_single_badge(): void
    {
        // USDT on three chains: three payable currencies, one piece of artwork.
        $this->coin('USDTBSC', 'Tether (BSC)', 'USDT.svg', sort: 10);
        $this->coin('USDTSOL', 'Tether (Solana)', 'USDT.svg', sort: 11);
        $this->coin('USDTTRC20', 'Tether (TRON)', 'USDT.svg', sort: 12);

        $this->assertCount(1, PaymentStripBadges::cryptoBadges());
    }

    public function test_coins_without_artwork_are_skipped(): void
    {
        $this->coin('BTC', 'Bitcoin', 'BTC.svg', sort: 10);
        $this->coin('XMR', 'Monero', null, sort: 20);

        $this->assertSame(['Bitcoin'], array_column(PaymentStripBadges::cryptoBadges(), 'label'));
    }

    public function test_fiat_rates_are_never_treated_as_coins(): void
    {
        CurrencyRate::create([
            'code' => 'NGN',
            'name' => 'Nigerian Naira',
            'type' => 'fiat',
            'rate_per_usd' => 1600,
            'icon_path' => 'NGN.svg',
            'is_active' => true,
            'is_payable' => true,
        ]);

        $this->assertSame([], PaymentStripBadges::cryptoBadges());
    }

    /**
     * Card brand acceptance is the gateway's, not ours: the live card path is
     * the provider's inline modal and our code never inspects the brand, so
     * every network the account takes is chargeable. All six ride the strip.
     */
    public function test_gateway_card_brands_are_advertised(): void
    {
        $labels = array_column(PaymentStripBadges::all(), 'label');

        foreach (['Visa', 'Mastercard', 'Verve', 'American Express', 'Discover', 'Diners Club'] as $brand) {
            $this->assertContains($brand, $labels);
        }
    }

    /**
     * Mobile-money networks per the gateway account (owner-confirmed,
     * 2026-07-30). The per-market charge endpoints are queued work - see
     * PaymentStripBadges' docblock.
     */
    public function test_mobile_money_networks_cover_the_accepted_markets(): void
    {
        $labels = array_column(PaymentStripBadges::all(), 'label');

        foreach (['MTN Mobile Money', 'Orange Money', 'M-PESA', 'Airtel Money'] as $network) {
            $this->assertContains($network, $labels);
        }
    }

    /**
     * USSD short codes are bank-specific (*737# is one bank's, *894# another's)
     * and Flutterwave's hosted page lets the customer pick their own bank, so
     * the mark must stay generic. Asserted against the RENDERED page rather than
     * the source: the source legitimately names those codes in a comment
     * explaining why they must not be used, and comments never reach a customer.
     */
    public function test_the_ussd_mark_names_no_specific_bank(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        foreach (['*737#', '*894#', '*966#', '*901#'] as $bankCode) {
            $this->assertStringNotContainsString($bankCode, $html);
        }

        // The generic short-code chip is what should be there instead.
        $this->assertStringContainsString('*###', $html);
    }

    public function test_homepage_renders_the_marquee_with_each_badge_twice(): void
    {
        $this->coin('BTC', 'Bitcoin', 'BTC.svg');

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('pay-strip-track', $html);
        $this->assertStringContainsString('Pay your way', $html);

        // Every badge is rendered twice so the -50% translate loops seamlessly;
        // one copy is aria-hidden so screen readers hear one set of methods. The
        // accessible name is the img alt / sr-only span - never a title
        // attribute, which ARIA does not expose on a generic div.
        // Both copies of the artwork are present...
        $this->assertSame(2, substr_count($html, 'assets/visa.svg'));
        $this->assertSame(2, substr_count($html, 'assets/BTC.svg'));
        // ...while the accessible name appears exactly once per method: the
        // clone's img carries alt="" and its inline marks get no sr-only span.
        $this->assertSame(1, substr_count($html, 'alt="Visa"'));
        $this->assertSame(1, substr_count($html, 'alt="Bitcoin"'));
        $this->assertSame(1, substr_count($html, 'sr-only">Verve</span>'));
        $this->assertStringContainsString('alt=""', $html);

        // Spacing lives on the badges, not as a flex gap on the track: a gap
        // makes 2N-1 gaps for 2N items and -50% then misses a full copy.
        $this->assertStringContainsString('mr-7 sm:mr-9', $html);
        $this->assertStringNotContainsString('pay-strip-track flex w-max items-center gap-', $html);

        // A pause control exists (WCAG 2.2.2) - hover alone excludes keyboard
        // and touch users.
        $this->assertStringContainsString('aria-pressed', $html);
    }

    public function test_the_strip_surface_does_not_rely_on_remapped_dark_utilities(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        // app.css remaps `.dark .bg-white`, which outranks Tailwind's dark:
        // variant - so `dark:bg-black` silently never applied and the notch
        // (which has no remap) ended up pure black on a grey panel.
        $this->assertStringContainsString('pay-strip-card', $html);
        $this->assertStringContainsString('pay-strip-notch', $html);
    }

    public function test_an_empty_coin_table_still_renders_the_strip(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        // No coins configured is not a broken page: the fixed rails stand alone.
        $this->assertStringContainsString('Pay your way', $html);
        $this->assertStringContainsString('alt="Visa"', $html);
    }
}
