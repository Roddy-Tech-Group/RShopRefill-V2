<?php

namespace Tests\Feature\Nav;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Header geometry. On desktop the logo row reads logo then search from the
 * left gutter, with the utility cluster (Help, region/currency, account, cart,
 * theme) right-aligned on the same line, and Invite a Friend riding the
 * category line below.
 *
 * On a phone the row is menu, logo, then the actions; search takes a full-width
 * row of its own beneath it; and the utilities move into the menu sheet. The
 * category rail lines up under the logo at both widths rather than centred.
 */
class NavRowAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private function nav(): string
    {
        return File::get(resource_path('views/components/nav/main-nav.blade.php'));
    }

    private function links(): string
    {
        return File::get(resource_path('views/components/nav/utility-links.blade.php'));
    }

    private function accountCart(): string
    {
        return File::get(resource_path('views/components/nav/account-cart.blade.php'));
    }

    public function test_the_search_bar_sits_beside_the_logo_not_page_centred(): void
    {
        $nav = $this->nav();

        // Both leading columns size to content, so the logo and the search bar
        // hug the left edge; only the trailing column takes the slack.
        $this->assertStringContainsString('grid grid-cols-[auto_auto_1fr] items-center', $nav);
        $this->assertStringNotContainsString('grid-cols-[1fr_auto_1fr]', $nav);

        // The search cell itself has to stop centring inside its column.
        $this->assertStringContainsString('col-start-2 justify-self-start hidden md:block', $nav);
    }

    /**
     * The third grid column is mobile-search only now. It still has to pin to
     * the right gutter, or that button drifts inward on a phone.
     */
    public function test_the_trailing_column_still_pins_to_the_right_gutter(): void
    {
        $this->assertStringContainsString('col-start-3 justify-self-end', $this->nav());
    }

    public function test_the_category_rail_is_left_aligned_at_every_breakpoint(): void
    {
        $nav = $this->nav();

        // The scroll track is nested inside a row that also carries the invite
        // pill, so the vertical padding moved out to that outer row.
        $this->assertStringContainsString('flex items-center gap-3 py-2.5', $nav);
        // gap-5 below md: the tabs have no padding there, so the gap is all
        // that separates them.
        $this->assertStringContainsString('flex min-w-0 flex-1 md:flex-initial gap-5 md:gap-2 overflow-x-auto justify-start', $nav);
        $this->assertStringNotContainsString('md:justify-center', $nav);
    }

    public function test_the_home_page_renders_the_left_aligned_nav(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('grid grid-cols-[auto_auto_1fr] items-center', $html);
        $this->assertStringContainsString('col-start-2 justify-self-start hidden md:block', $html);

        // Scoped to the rail itself: an unrelated centred component elsewhere
        // on the page must not be what makes or breaks this.
        $this->assertStringContainsString(
            'flex min-w-0 flex-1 md:flex-initial gap-5 md:gap-2 overflow-x-auto justify-start [scrollbar-width:none]',
            $html
        );
    }

    /**
     * Desktop: Help, region/currency, theme, account and cart all share the
     * LOGO's line, right-aligned. Mobile: the utility links break out to a row
     * of their own above the logo, and only account/cart stay on the logo line.
     */
    public function test_the_utility_cluster_rides_the_logo_line(): void
    {
        $nav = $this->nav();

        $this->assertStringContainsString('col-start-3 justify-self-end flex items-center gap-1', $nav);
        $this->assertStringContainsString('<x-nav.account-cart />', $nav);

        // Two copies of the links: the menu sheet below md, the logo line at
        // md and up. They are not identical mounts - only the desktop one
        // carries the region suggestion popup, which is anchored to the region
        // pill and would otherwise render twice and have to be dismissed
        // twice. So this counts the tag, not the tag plus its props.
        $this->assertSame(2, substr_count($nav, '<x-nav.utility-links'));
        $this->assertSame(1, substr_count($nav, '<x-nav.utility-links :suggestion="true" />'));
        $this->assertStringContainsString('x-data="{ menuOpen: false }"', $nav);
        $this->assertStringContainsString('hidden items-center gap-1 md:flex', $nav);

        // Neither partial wraps itself, or it would fight the parent flex row.
        foreach ([$this->links(), $this->accountCart()] as $partial) {
            $this->assertStringNotContainsString('max-w-[1550px]', $partial);
            $this->assertStringNotContainsString('justify-end', $partial);
        }
    }

    /**
     * The phone nav is three rows - menu + logo + actions, search, categories -
     * and the utilities that used to sit in a fourth row above the logo are
     * behind the menu button now.
     *
     * The sheet has to be declared OUTSIDE anything that folds on scroll: the
     * button that opens it is in the pinned row, and a sheet that folded with
     * the category strip would close itself the moment the page scrolled
     * under it. The strip's fold is the grid-row collapse (NavFoldTest).
     */
    public function test_the_menu_sheet_sits_outside_the_collapsing_row(): void
    {
        $nav = $this->nav();

        $sheetAt = strpos($nav, 'x-data="{ menuOpen: false }"');
        $collapseAt = strpos($nav, "[grid-template-rows:0fr] opacity-0'");

        $this->assertNotFalse($collapseAt, 'The category strip no longer folds by grid row.');

        $this->assertNotFalse($sheetAt);
        $this->assertLessThan($collapseAt, $sheetAt);

        // The button lives in the logo row and reaches the sheet by event.
        $this->assertStringContainsString('$dispatch(\'open-mobile-menu\')', $nav);
        // Opening just opens it. There is no page to reset: the sheet used to
        // carry a second page of sub-categories and had to rewind to the root,
        // and that drill-down is gone.
        $this->assertStringContainsString('@open-mobile-menu.window="menuOpen = true"', $nav);

        // The strip above the logo is gone, not merely hidden.
        $this->assertStringNotContainsString('flex w-max min-w-full items-center justify-end gap-1 py-1', $nav);

        // Account and cart are NOT duplicated into that row - one copy only.
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'aria-label="Cart"'));
    }

    /** The standalone utility bar is gone from the header entirely. */
    public function test_there_is_no_separate_top_bar_component(): void
    {
        $this->assertFalse(File::exists(resource_path('views/components/nav/top-bar.blade.php')));

        $header = File::get(resource_path('views/components/layouts/app/header.blade.php'));
        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $header);

        $this->assertStringNotContainsString('<x-nav.top-bar', $markup);
        $this->assertStringContainsString('<x-nav.main-nav />', $markup);
    }

    /**
     * Account and cart were pulled out of the logo row's own markup into the
     * shared cluster. Both halves of that move have to hold, or the controls
     * end up rendered twice.
     */
    public function test_account_and_cart_live_in_one_partial_only(): void
    {
        $cluster = $this->accountCart();

        $this->assertStringContainsString("aria-label=\"{{ __('navigation.cart') }}\"", $cluster);
        $this->assertStringContainsString("aria-label=\"{{ __('navigation.your_account') }}\"", $cluster);

        $navMarkup = preg_replace('/\{\{--.*?--\}\}/s', '', $this->nav());
        $this->assertStringNotContainsString("__('navigation.cart')", $navMarkup);
        $this->assertStringNotContainsString("__('navigation.your_account')", $navMarkup);

        // Rendered once on the page, not twice.
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'aria-label="Cart"'));
    }

    /**
     * Invite a Friend rides the CATEGORY line from md up, and moves into the
     * menu sheet below it.
     *
     * It used to render a second time in the mobile utility row above the logo,
     * putting a promotion in a phone nav that was four rows deep before the
     * page started. In the sheet it costs the nav nothing.
     */
    public function test_invite_a_friend_rides_the_category_line_on_desktop(): void
    {
        $nav = $this->nav();

        // One on the category line, one at the foot of the sheet. Matched
        // without the closing slash: the category-line copy takes props now
        // (it opens the referral panel) so it is no longer self-closing on one
        // line, and counting '<x-nav.invite-link />' counted only the sheet's.
        $this->assertSame(2, substr_count($nav, '<x-nav.invite-link'));

        // md+: on the category line, pinned right and OUTSIDE the scroll track,
        // or it scrolls away with the pills on a narrow screen. The span became
        // a relative wrapper when the pill grew a dropdown - the panel anchors
        // to it - but the display and shrink behaviour it is asserting here are
        // unchanged.
        // The box holds two pills now, the Certified reseller badge and the
        // invite (NavGlassRailTest), so it is a flex row with a gap.
        // ml-auto holds it at the right end: the rail beside it stops at its
        // last category now rather than filling the row (NavGlassRailTest).
        $this->assertStringContainsString('class="relative ml-auto hidden shrink-0 items-center gap-2 md:flex"', $nav);

        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $nav);
        $this->assertGreaterThan(
            strpos($markup, 'overflow-x-auto justify-start'),
            strrpos($markup, '<x-nav.invite-link')
        );

        // The category-line copy comes after the logo row; the sheet's copy
        // is declared before it, above the collapsing wrapper.
        $this->assertGreaterThan(
            strpos($markup, 'grid grid-cols-[auto_auto_1fr]'),
            strrpos($markup, '<x-nav.invite-link')
        );
        $this->assertLessThan(
            strpos($markup, 'grid grid-cols-[auto_auto_1fr]'),
            strpos($markup, '<x-nav.invite-link')
        );

        // The partial carries the link and no wrapper of its own.
        $link = File::get(resource_path('views/components/nav/invite-link.blade.php'));
        $this->assertStringContainsString('Invite a Friend', $link);
        $this->assertStringContainsString("route('shop.earn-points')", $link);
        $this->assertStringNotContainsString('max-w-[1550px]', $link);

        // Twice on the page: the category line and the sheet, each hidden at
        // the other's breakpoint.
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        // The two nav pills label themselves in a <span>; the footer's company
        // column carries a third "Invite a Friend" as a plain link, which is
        // not this row's concern.
        $this->assertSame(2, substr_count($html, '>Invite a Friend</span>'));

        // The reference row also carried a "Download the app" link. It was
        // explicitly excluded, so it must not creep back in.
        $this->assertStringNotContainsString('Download the app', $nav);
    }

    /**
     * The logo row and the category rail only line up because they share one
     * container. If either drifts to a different max-width or gutter, the edges
     * separate.
     */
    public function test_both_nav_strips_share_one_container(): void
    {
        $container = 'max-w-[1550px] mx-auto px-4 sm:px-6 lg:px-8';

        // Two: the logo row (which now also holds the phone search row) and
        // the category rail. The mobile utility row that made a third is gone.
        $this->assertSame(2, substr_count($this->nav(), $container));
    }

    /**
     * The logo's -ml-1 cancels the wordmark's own transparent left margin -
     * 20px in a 701x165 source, so 3.9px at h-8 and 4.4px at h-9 - putting the
     * mark ON the gutter rather than proud of it. -ml-3 overshot by ~8px and
     * made the left gutter read narrower than the right.
     *
     * It must not spread to the controls beside it either: they have no such
     * margin to cancel, so the same pull would push THEM off the gutter.
     */
    public function test_the_logo_offset_does_not_leak_into_the_controls(): void
    {
        $nav = $this->nav();

        // The pull moved with the column: on a phone the menu button is what
        // sits on the gutter, and the logo shifts to column two.
        $this->assertStringContainsString('class="col-start-1 -ml-1 flex h-11 w-11', $nav);
        $this->assertStringContainsString('col-start-2 md:col-start-1 justify-self-start', $nav);

        // Blade comments stripped first - prose mentioning the offset is not markup.
        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $this->accountCart());

        $this->assertStringNotContainsString('-ml-', $markup);
    }

    /**
     * The theme picker closes the row on the right at every width - past Sign in
     * and Cart on the logo line, past Invite a Friend on the mobile row.
     *
     * It is placed by main-nav rather than living inside x-nav.utility-links,
     * because that partial sits mid-row in both layouts: anything inside it
     * lands before the controls that follow, never after them.
     */
    public function test_the_theme_picker_closes_both_rows(): void
    {
        $nav = $this->nav();
        $links = $this->links();

        // One round icon trigger, on the logo line. shrink-0 because that row
        // is a single scrolling line and a flex child without it compresses
        // instead of scrolling.
        $this->assertSame(1, substr_count($nav, '<x-theme-toggle class="h-9 w-9 shrink-0 rounded-full'));

        // The sheet carries the footer's arrangement instead - the THEME label
        // beside the segmented chip - because it stands alone at the foot of a
        // panel with nothing beside it to say what three marks would mean.
        $this->assertStringContainsString('<x-theme-toggle variant="segmented" />', $nav);
        $this->assertStringContainsString("__('footer.theme')", $nav);

        // Still one visible at a time: the logo-row one is md+ only. Without
        // that guard both rendered on a phone and the picker appeared twice.
        $this->assertStringContainsString('<span class="hidden md:inline-flex">', $nav);
        $this->assertStringContainsString('x-data="{ menuOpen: false }"', $nav);
        $this->assertStringNotContainsString('<x-theme-toggle', $links);

        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $nav);

        // Sheet: last of the three controls at its foot - invite, then the
        // utility links behind a rule, then the picker.
        $sheetTheme = strpos($markup, '<x-theme-toggle');
        $this->assertGreaterThan(strpos($markup, '<x-nav.invite-link'), $sheetTheme);
        $this->assertGreaterThan(strpos($markup, '<x-nav.utility-links />'), $sheetTheme);

        // Logo row: after account-cart, which is itself after the utility links.
        $accountCart = strpos($markup, '<x-nav.account-cart />');
        $this->assertGreaterThan($accountCart, strrpos($markup, '<x-theme-toggle'));
        $this->assertGreaterThan(strrpos($markup, '<x-nav.utility-links />'), $accountCart);
    }
}
