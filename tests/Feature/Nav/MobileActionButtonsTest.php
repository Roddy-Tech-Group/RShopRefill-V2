<?php

namespace Tests\Feature\Nav;

use Tests\TestCase;

/**
 * The header action buttons (search, account, cart) are larger tap targets on
 * mobile - h-11/w-11 (44px), dropping to 40px on desktop - with 10px corners
 * instead of full circles.
 */
class MobileActionButtonsTest extends TestCase
{
    public function test_action_buttons_are_enlarged_on_mobile_with_10px_corners(): void
    {
        $blade = file_get_contents(resource_path('views/components/nav/main-nav.blade.php'));

        // Mobile-only search button.
        $this->assertStringContainsString('h-11 w-11 items-center justify-center rounded-[10px]', $blade);

        // Authenticated account avatar button.
        $this->assertStringContainsString('w-11 h-11 md:w-10 md:h-10 overflow-hidden rounded-[10px]', $blade);

        // Guest account button + cart button share this size/shape.
        $this->assertStringContainsString('w-11 h-11 md:w-10 md:h-10 rounded-[10px]', $blade);

        // The old full-circle sizing is gone from these action buttons.
        $this->assertStringNotContainsString('w-9 h-9 md:w-10 md:h-10 rounded-full', $blade);
    }

    public function test_action_buttons_are_grey_not_black_in_dark_mode(): void
    {
        $blade = file_get_contents(resource_path('views/components/nav/main-nav.blade.php'));

        // bg-zinc-100 remaps to near-black in dark mode; bg-zinc-200 is remapped by
        // the theme to a legible grey chip (#303030 / #2a2a2a) in both dark themes,
        // and dark:text-zinc-200 keeps the icons/text light on it.
        // Wallet pill (authed + guest).
        $this->assertStringContainsString('bg-zinc-200 px-4 text-sm font-semibold text-zinc-900 dark:text-zinc-200', $blade);
        // Guest account + cart buttons.
        $this->assertStringContainsString('bg-zinc-200 text-zinc-900 hover:bg-blue-600 hover:text-white/70 dark:text-zinc-200', $blade);
        // Mobile search button.
        $this->assertStringContainsString('rounded-[10px] bg-zinc-200 text-zinc-900 dark:text-zinc-200', $blade);
        // Authed account avatar button.
        $this->assertStringContainsString('rounded-[10px] bg-zinc-200 transition-all duration-150 ring-1 ring-zinc-200', $blade);
    }
}
