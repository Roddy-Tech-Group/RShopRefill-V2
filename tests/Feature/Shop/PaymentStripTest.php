<?php

namespace Tests\Feature\Shop;

use App\Domain\Payment\Support\PaymentStripBadges;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Models\CurrencyRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The "Payment Methods We Accept" row.
 *
 * It used to sit on the homepage and was removed from it; the component itself
 * is unchanged, so these render it in isolation rather than through a page.
 * HomeTrustPanelTest is what guards it staying off the homepage.
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

    /** The strip has no page to render through any more, so render it directly. */
    private function strip(): string
    {
        return Blade::render('<x-home.payment-methods />');
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
     *
     * Coverage is unchanged and still backed by the catalogue: MobileMoneyRail
     * carries MTN for GHS, UGX, RWF, XAF and XOF, Orange for XAF and XOF, M-PESA
     * for KES and Airtel for UGX and RWF, and SupportedPaymentMethods lets every
     * one of those currencies pay by mobile_money. What moved is the MTN badge.
     * It used to be a generic MOMO.svg file labelled "MTN Mobile Money"; the
     * redesign dropped that asset for an inline MTN MoMo mark, because the global
     * dark-mode rule inverts SVG images and would have turned MTN's yellow
     * purple. The mark draws the wordmark as MoMo, so the badge is labelled to
     * match what a customer actually sees on it.
     *
     * The rail is therefore asserted by its MARK as well as its label. A label is
     * a string somebody can retitle; the mark is what proves the badge on the
     * strip is still MTN's and not a second generic mobile-money tile.
     */
    public function test_mobile_money_networks_cover_the_accepted_markets(): void
    {
        $badges = PaymentStripBadges::all();
        $labels = array_column($badges, 'label');

        foreach (['MoMo', 'Orange Money', 'M-PESA', 'Airtel Money'] as $network) {
            $this->assertContains($network, $labels);
        }

        $byLabel = array_column($badges, null, 'label');

        $this->assertSame(
            'mtn_momo',
            $byLabel['MoMo']['svg'] ?? null,
            'The MoMo badge must carry the MTN mark, or the strip advertises a rail it cannot name.'
        );

        // All four are the one method key checkout gates on, so a badge that
        // drifted onto another rail's key fails here rather than at payment.
        foreach (['MoMo', 'Orange Money', 'M-PESA', 'Airtel Money'] as $network) {
            $this->assertSame('mobile_money', $byLabel[$network]['method']);
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
        $html = $this->strip();

        foreach (['*737#', '*894#', '*966#', '*901#'] as $bankCode) {
            $this->assertStringNotContainsString($bankCode, $html);
        }

        // The mark is now a keypad glyph rather than a "*###" chip, so it names
        // no code at all - which satisfies the rule above more strictly than the
        // placeholder did. The label is what identifies the rail.
        $this->assertStringContainsString('>USSD</span>', $html);
    }

    /**
     * The row used to be a marquee that rendered every badge twice - once real,
     * once as an aria-hidden clone - so the loop could seam. It is a static
     * pyramid now, so each badge must appear exactly ONCE. A second copy would
     * be a duplicate logo on the page, not a seam.
     */
    public function test_homepage_renders_each_badge_exactly_once(): void
    {
        $this->coin('BTC', 'Bitcoin', 'BTC.svg');

        $html = $this->strip();

        $this->assertStringContainsString('Payment Methods We Accept', $html);

        $this->assertSame(1, substr_count($html, 'assets/visa.svg'));
        $this->assertSame(1, substr_count($html, 'assets/BTC.svg'));

        // Alt text and the inline marks' accessible names survive the rewrite.
        $this->assertSame(1, substr_count($html, 'alt="Visa"'));
        $this->assertSame(1, substr_count($html, 'alt="Bitcoin"'));
        $this->assertSame(1, substr_count($html, 'sr-only">Verve</span>'));

        // No clone pass, and nothing to pause: both the aria-hidden duplicates
        // and the WCAG 2.2.2 pause control belonged to the animation. Checked
        // against the COMPONENT, not the page - other carousels on the homepage
        // legitimately keep their own pause controls.
        $this->assertStringNotContainsString('pay-strip-track', $html);

        $component = File::get(resource_path('views/components/home/payment-methods.blade.php'));
        $this->assertStringNotContainsString('aria-pressed', $component);
    }

    /**
     * The marquee bled to the viewport edge so marks could enter off-screen.
     * Nothing moves now, so the block is plainly centred inside its panel - any
     * surviving bleed would push it outside the panel's padding.
     */
    public function test_the_payment_row_no_longer_bleeds_to_the_viewport(): void
    {
        $component = File::get(resource_path('views/components/home/payment-methods.blade.php'));

        foreach (['mr-[calc(50%-50vw)]', 'mx-[calc(50%-50vw)]', 'ml-[calc(50%-50vw)]'] as $bleed) {
            $this->assertStringNotContainsString($bleed, $component);
        }

        $this->assertStringNotContainsString('pay-strip', $component);
    }

    /** Bare marks, no card plate: the gift-card tiles below own that treatment. */
    public function test_the_marks_carry_no_card_plate(): void
    {
        $component = File::get(resource_path('views/components/home/payment-methods.blade.php'));

        foreach (['aspect-[16/10]', 'rounded-[15px]'] as $plateClass) {
            $this->assertStringNotContainsString($plateClass, $component);
        }
    }

    /**
     * The rows are computed, not hardcoded, because the crypto sync adds coins
     * to this list - a fixed 3/5/7/8 would break the shape the first time a
     * badge joined. Two things have to hold whatever the count: every mark is
     * placed exactly once, and no row is narrower than the row above it.
     */
    public function test_the_marks_form_a_pyramid(): void
    {
        $this->coin('BTC', 'Bitcoin', 'BTC.svg');

        $expected = count(PaymentStripBadges::all());

        $html = $this->strip();

        // Each row opens with this exact class string; splitting on it gives one
        // chunk per row (the first chunk is everything before row one).
        $chunks = explode('flex flex-wrap items-center justify-center gap-x-7', $html);
        array_shift($chunks);

        $this->assertNotEmpty($chunks, 'No pyramid rows rendered.');

        $counts = array_map(
            fn (string $chunk): int => substr_count($chunk, 'h-[52px] w-[104px]'),
            $chunks
        );

        // explode() already yields one disjoint chunk per row, so these counts
        // stand on their own - no overlap to undo.
        $perRow = $counts;

        $this->assertSame($expected, array_sum($perRow), 'Every mark should be placed exactly once.');

        foreach ($perRow as $i => $count) {
            $this->assertGreaterThan(0, $count, "Row {$i} is empty.");

            if ($i > 0) {
                $this->assertGreaterThanOrEqual(
                    $perRow[$i - 1],
                    $count,
                    "Row {$i} is narrower than the row above it - that is not a pyramid."
                );
            }
        }
    }

    public function test_an_empty_coin_table_still_renders_the_strip(): void
    {
        $html = $this->strip();

        // No coins configured is not a broken page: the fixed rails stand alone.
        $this->assertStringContainsString('Payment Methods We Accept', $html);
        $this->assertStringContainsString('alt="Visa"', $html);
    }
}
