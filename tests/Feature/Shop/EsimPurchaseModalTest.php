<?php

namespace Tests\Feature\Shop;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The purchase sheet that "Get eSIM" opens, and the top banner it replaced.
 *
 * The banner was pinned above the plans it described, so it covered them and
 * scrolled with nothing; "Get eSIM" also used to add and leave for checkout in
 * one click. The sheet is that missing pause: coverage, the currency charged,
 * and then the two ways to commit.
 */
class EsimPurchaseModalTest extends TestCase
{
    private function page(): string
    {
        return File::get(resource_path('views/shop/esim.blade.php'));
    }

    /** "Get eSIM" selects the plan and opens the sheet, rather than buying outright. */
    public function test_get_esim_opens_the_sheet_instead_of_buying(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('@click.stop="selectedId = p.id; showBuy = true"', $page);
        $this->assertStringContainsString('@keydown.enter.stop="selectedId = p.id; showBuy = true"', $page);

        // Keyboard and pointer must agree: a plan card reachable by tab that
        // buys on Enter while a tap only opens a sheet is a trap.
        $this->assertSame(
            substr_count($page, 'selectedId = p.id; showBuy = true'),
            2,
            'The click and keydown handlers on the plan button have drifted apart.'
        );

        $this->assertStringContainsString('showBuy: false,', $page, 'The sheet has no state to open.');
    }

    /**
     * It is a dialog, it announces itself, and it follows the page's own modal
     * pattern: display:none in the markup rather than x-cloak, so the sheet
     * cannot flash on the way in before Alpine boots.
     */
    public function test_the_sheet_is_a_labelled_dialog_that_cannot_flash(): void
    {
        preg_match('/<div\s+x-show="showBuy"\s+style="display: none;"(.*?)>/s', $this->page(), $shell);

        $this->assertNotEmpty($shell, 'The sheet lost its markup-level display:none and will flash on load.');
        $this->assertStringContainsString('role="dialog"', $shell[1]);
        $this->assertStringContainsString('aria-modal="true"', $shell[1]);
        $this->assertStringContainsString('aria-labelledby="buy-title"', $shell[1]);
        $this->assertStringContainsString('@keydown.escape.window="showBuy = false"', $shell[1]);

        // The heading that label points at has to exist.
        $this->assertStringContainsString('id="buy-title"', $this->page());
    }

    /** The old top banner is gone; nothing it did is left orphaned on the page. */
    public function test_the_top_banner_is_gone(): void
    {
        $page = $this->page();

        $this->assertStringNotContainsString('fixed inset-x-0 z-[100]', $page, 'The buy toast is still pinned to the top of the page.');
        $this->assertStringNotContainsString('Deselect plan', $page);
        $this->assertStringNotContainsString('Buy toast', $page);

        // Its two remaining jobs moved into the sheet rather than being dropped.
        $this->assertStringContainsString('Package details', $page);
        $this->assertStringContainsString('pointsEarned()', $page);
    }

    /**
     * Coverage is read off the page's own synced data, so the sheet cannot
     * promise reach the plan does not carry, and the long list defers to the
     * modal that already renders it in full.
     */
    public function test_coverage_comes_from_the_pages_own_data(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('@if ($coverageCount > 1)', $page);
        $this->assertStringContainsString('$coverageList->take(12)', $page);
        $this->assertStringContainsString('+{{ $coverageCount - 12 }} more', $page);

        // The two sheets are the same layer, so the one hands over to the other
        // rather than stacking on top of it.
        $this->assertStringContainsString('@click="showBuy = false; showNetworks = true"', $page);
        $this->assertStringContainsString('@click="detailsId = selectedId; showBuy = false; showDetails = true"', $page);
    }

    /**
     * Adding from the sheet steps aside afterwards. The cart panel sits at
     * z-[56] and the sheet at z-[70], so a sheet that stayed up would hide the
     * only confirmation the buyer gets.
     */
    public function test_adding_from_the_sheet_reveals_the_cart_behind_it(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('@click="addFromBuyModal()"', $page);
        $this->assertStringContainsString('this.showBuy = false;', $page);
        $this->assertStringContainsString('z-[70]', $page);

        // The real layers, so a restack of either one fails here rather than in
        // front of a buyer.
        $cart = File::get(resource_path('views/components/nav/account-cart.blade.php'));
        $this->assertStringContainsString('z-[56]', $cart);
    }

    /**
     * The currency list sits in normal flow, never floating above its trigger.
     *
     * Floating, it was clipped out of existence: the sheet's body scrolls, so
     * it clips anything positioned outside it, and the panel opened upward
     * (bottom-full) from the last control in that body. Overflow past a scroll
     * container's top edge is never reachable by scrolling, so the cut rows
     * were gone rather than merely hidden, and the first of the 49 is XAF.
     */
    public function test_the_currency_list_cannot_be_clipped_out_of_view(): void
    {
        $page = $this->page();

        preg_match('/<div x-show="open"[^>]*x-ref="list"[^>]*class="([^"]+)"/', $page, $list);

        $this->assertNotEmpty($list, 'The currency list is gone or lost its ref.');
        $this->assertStringNotContainsString('absolute', $list[1], 'The list floats again, so the scrolling sheet body will clip it.');
        $this->assertStringNotContainsString('bottom-full', $list[1], 'The list opens upward again, into the unreachable side of the overflow.');
        $this->assertStringContainsString('max-h-64', $list[1], 'The list still caps its own height and scrolls internally.');
        $this->assertStringContainsString('overflow-y-auto', $list[1]);

        // Opening it brings it, and the row already chosen, into view.
        $this->assertStringContainsString('@click="open = ! open; if (open) { reveal() }"', $page);
        $this->assertStringContainsString("getAttribute('aria-selected') === 'true'", $page);
    }

    /**
     * The page holds ONE scroll lock across all four of its overlays, via a
     * watcher rather than an effect.
     *
     * x-effect re-runs on every dependency change, so a sheet handing over to
     * another (showBuy = false; showNetworks = true) re-entered the still-true
     * branch and locked a second time against a reference counter. The count
     * never returned to zero, so the page stayed frozen after everything
     * closed and no later open/close could recover it. $watch fires only on a
     * real transition.
     */
    public function test_the_overlays_hold_one_scroll_lock_between_them(): void
    {
        $page = $this->page();

        $this->assertStringContainsString(
            "this.\$watch('showBuy || showNetworks || showCompat || showDetails', (isOpen) => {",
            $page,
            'The combined scroll lock is gone or no longer a watcher.'
        );
        $this->assertStringContainsString('window.rshopScrollLock?.lock();', $page);
        $this->assertStringContainsString('window.rshopScrollLock?.unlock();', $page);

        // The compound condition must never go back on an effect: that is the
        // exact shape that double-locked.
        $this->assertDoesNotMatchRegularExpression(
            '/x-effect="[^"]*showBuy[^"]*rshopScrollLock/s',
            $page,
            'The compound lock is back on x-effect and will leak on every handover.'
        );
    }

    /** The sheet prices in the same currency pair as the rest of the page. */
    public function test_the_sheet_prices_in_the_pages_own_currency_state(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('x-text="totalLabel()"', $page);
        $this->assertStringContainsString('@click="selectedCrypto = code; open = false"', $page);

        // Both ways to commit, and neither can fire on an unbuyable plan.
        $this->assertStringContainsString(':disabled="! selectedId || ! purchasable || cartState === \'loading\'"', $page);
        $this->assertStringContainsString(':disabled="! selectedId || ! purchasable || $store.cart.loading"', $page);
    }
}
