<?php

namespace Tests\Feature\Nav;

use Tests\TestCase;

/**
 * The header action buttons are larger tap targets on mobile - 44px, dropping
 * to 40px on desktop - with 10px corners instead of full circles, and they all
 * wear the same page-tone fill as the locale pill beside them.
 *
 * The mobile search trigger lives in main-nav; account and cart are their own
 * partial, so the two files are asserted separately.
 *
 * Source-reading only - no RefreshDatabase here, so nothing in this class may
 * hit a route. Rendered coverage lives in NavRowAlignmentTest.
 */
class MobileActionButtonsTest extends TestCase
{
    private function nav(): string
    {
        return file_get_contents(resource_path('views/components/nav/main-nav.blade.php'));
    }

    private function accountCart(): string
    {
        return file_get_contents(resource_path('views/components/nav/account-cart.blade.php'));
    }

    public function test_action_buttons_are_enlarged_on_mobile_with_10px_corners(): void
    {
        // Mobile-only menu button. Search used to hold this slot as a chip
        // that opened a sheet; it is a field on its own row now, so the menu
        // button is what has to clear 44px here.
        $this->assertStringContainsString('h-11 w-11 shrink-0 items-center justify-center rounded-[10px]', $this->nav());

        $ac = $this->accountCart();

        // Authenticated account avatar button. Same 44px floor as the rest of
        // the row - the point of this test - but a circle rather than a 10px
        // square: it is a face, not a panel. Sized here, shaped in
        // AccountAvatarShapeTest.
        $this->assertStringContainsString('w-11 h-11 md:w-10 md:h-10 overflow-hidden rounded-full', $ac);

        // Sign in and Cart still have to clear 44px on mobile, which padding
        // alone does not give them. min-h-11 is that floor; md:min-h-0 lets the
        // desktop chip be compact again once there is a pointer.
        $this->assertSame(2, substr_count($ac, 'min-h-11 items-center gap-2 rounded-[10px]'));
        $this->assertSame(2, substr_count($ac, 'md:min-h-0'));

        // The old full-circle sizing is gone from these action buttons.
        $this->assertStringNotContainsString('rounded-full px-2.5 py-2', $ac);
        $this->assertStringNotContainsString('w-9 h-9 md:w-10 md:h-10 rounded-full', $ac);
    }

    /**
     * One fill across the whole action row: #f4f6f5 on the light page, white/10
     * in dark.
     *
     * On the nav it is a HOVER fill, not a resting one - the row reads as a set
     * of links until you point at one, and only Invite a Friend is a standing
     * pill. Sign in and Cart keep a resting fill below md, where there is no
     * pointer to reveal a hover with.
     */
    public function test_the_action_chips_share_the_locale_pill_fill(): void
    {
        $links = file_get_contents(resource_path('views/components/nav/utility-links.blade.php'));
        $pill = file_get_contents(resource_path('views/components/nav/region-pill.blade.php'));

        foreach ([$links, $pill] as $source) {
            $this->assertStringContainsString('hover:bg-[#f4f6f5]', $source);
            $this->assertStringContainsString('dark:hover:bg-white/10', $source);
        }

        // The search field wears the same fill at rest, and drops it for a
        // white surface once it has focus.
        $this->assertStringContainsString("'border-transparent bg-[#f4f6f5] dark:bg-white/8'", $this->nav());

        // Sign in: filled on a phone, transparent from md up, and the hover
        // fill on both sides of that breakpoint. Cart no longer shares this
        // kit: it is the dashboard's outlined chip (see CartChipTest), so each
        // of these appears once.
        $ac = $this->accountCart();
        $this->assertSame(1, substr_count($ac, 'bg-[#f4f6f5] md:bg-transparent'));
        $this->assertSame(1, substr_count($ac, 'hover:bg-[#e8ecea] md:hover:bg-[#f4f6f5]'));
        $this->assertSame(1, substr_count($ac, 'dark:bg-white/8 md:dark:bg-transparent'));
        $this->assertSame(1, substr_count($ac, 'dark:hover:bg-white/15 md:dark:hover:bg-white/10'));

        // bg-zinc-200 remaps to a different grey in dark and is what the chips
        // used to use. Nothing here carries it any more: the avatar took the
        // dashboard's outline too (AccountAvatarShapeTest).
        $this->assertSame(0, substr_count($ac, 'bg-zinc-200'));
    }

    public function test_the_guest_control_reads_sign_in(): void
    {
        $ac = $this->accountCart();

        $this->assertStringContainsString("<span class=\"hidden sm:inline\">{{ __('navigation.sign_in') }}</span>", $ac);
        $this->assertStringContainsString("aria-label=\"{{ __('navigation.sign_in') }}\"", $ac);
        $this->assertStringNotContainsString('>Login<', $ac);
    }

    /**
     * Sign in and Cart are inline SVGs on the same stroke weight.
     *
     * Sign in was an <img src="Login.svg">, which cannot take currentColor - it
     * stayed dark while the chip inverted on hover. Cart was a filled 48x48
     * path, which read noticeably heavier than the mark beside it. Both are
     * stroked 24x24 now and follow the button's ink.
     */
    public function test_the_action_marks_are_inline_and_match_each_other(): void
    {
        $ac = $this->accountCart();

        // No <img> mark left on these controls.
        $this->assertStringNotContainsString("rawurlencode('Login.svg')", $ac);
        $this->assertStringNotContainsString('viewBox="0 0 48 48"', $ac);

        // Two stroked marks, same weight, both inheriting the button colour.
        // Sign in is drawn here; Cart comes from x-icons.cart, which the
        // dashboard shares so the two carts cannot drift apart. It sits in the
        // roll-on-hover wrapper now, sized by the wrapper, as on the dashboard.
        $stroke = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"';

        $this->assertSame(1, substr_count($ac, $stroke));
        $this->assertStringContainsString('<span class="anim-icon anim-icon--cart inline-flex h-6 w-6 shrink-0" aria-hidden="true"><x-icons.cart /></span>', $ac);
        $this->assertStringContainsString($stroke, file_get_contents(resource_path('views/components/icons/cart.blade.php')));
    }
}
