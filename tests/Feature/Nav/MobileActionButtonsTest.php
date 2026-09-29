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

    public function test_the_menu_button_clears_44px_and_the_account_controls_are_outlined_circles(): void
    {
        // Mobile-only menu button. Search used to hold this slot as a chip
        // that opened a sheet; it is a field on its own row now, so the menu
        // button is what has to clear 44px here. A circle: every control on
        // the row is round now.
        $this->assertStringContainsString('flex h-11 w-11 shrink-0 items-center justify-center rounded-full', $this->nav());

        $ac = $this->accountCart();

        // Every account control is a 36px circle: cart, the avatar, and the
        // sign-in control a guest sees instead of it. Cart and avatar are the
        // dashboard's chips adapted to this bar, with a 2px outline at 30% of
        // the ink and a 10% wash on hover, no fill, no label. The avatar's
        // crop is AccountAvatarShapeTest's.
        $this->assertSame(3, substr_count($ac, 'h-9 w-9 shrink-0 items-center justify-center'));
        $this->assertSame(2, substr_count($ac, 'rounded-full border-2 border-[#0c1337]/30 text-zinc-700 transition-colors hover:border-edge hover:bg-[#0c1337]/10'));

        // The old sizing kit is gone from these action buttons.
        foreach (['rounded-full px-2.5 py-2', 'w-9 h-9 md:w-10 md:h-10 rounded-full', 'min-h-11 items-center gap-2 rounded-[10px]'] as $old) {
            $this->assertStringNotContainsString($old, $ac, $old.' is still in account-cart');
        }
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
    public function test_the_utility_links_and_the_search_field_share_the_page_tone_fill(): void
    {
        // The utility links keep the page-tone hover fill. The region pill no
        // longer sets that tone: it is the dashboard's outlined pill now, and
        // RegionPillChipTest pins it against the dashboard's own markup.
        $links = file_get_contents(resource_path('views/components/nav/utility-links.blade.php'));

        $this->assertStringContainsString('hover:bg-[#f4f6f5]', $links);
        $this->assertStringContainsString('dark:hover:bg-white/10', $links);

        // The phone search field wears the same fill at rest, and drops it for
        // a white surface with the edge-blue border once it has focus.
        $this->assertStringContainsString("'border-edge bg-[#ffffff] dark:border-edge dark:bg-[#0c1a36]' : 'border-zinc-200 bg-[#f4f6f5] dark:border-white dark:bg-white/8'", $this->nav());

        // Cart and account no longer share this kit: both are the dashboard's
        // outlined chips (CartChipTest, AccountAvatarShapeTest), so none of
        // the filled-chip classes are left in the file. bg-zinc-200 remaps to
        // a different grey in dark and is what the chips used to use.
        $ac = $this->accountCart();
        foreach (['bg-[#f4f6f5] md:bg-transparent', 'hover:bg-[#e8ecea] md:hover:bg-[#f4f6f5]', 'bg-zinc-200'] as $old) {
            $this->assertSame(0, substr_count($ac, $old), $old.' is still in account-cart');
        }
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
