<?php

namespace Tests\Feature\Dashboard;

use Tests\TestCase;

/**
 * The marks inside the header chips are 24px; the chips are 36px. Search, the
 * bell, the cart and the avatar glyph, on the dashboard's bar, on its phone
 * bar and on the storefront's mirrors of the cart and avatar. The chips did
 * not grow: the marks did, from 18px and 20px, so the row reads at a glance.
 *
 * Source-reading only: the chip tone of the bell and the search button render
 * inside layouts this class does not need to boot.
 */
class HeaderChipGlyphSizesTest extends TestCase
{
    private function dashboard(): string
    {
        return file_get_contents(resource_path('views/components/layouts/dashboard.blade.php'));
    }

    private function bell(): string
    {
        return file_get_contents(resource_path('views/livewire/notifications-menu.blade.php'));
    }

    private function storefront(): string
    {
        return file_get_contents(resource_path('views/components/nav/account-cart.blade.php'));
    }

    public function test_search_and_bell_marks_are_24px(): void
    {
        // The search chip's own inline mark.
        $this->assertStringContainsString('<svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">', $this->dashboard());

        // The bell: the chip tone draws inline, the other tones draw the file.
        $bell = $this->bell();
        $this->assertStringContainsString('<svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"', $bell);
        $this->assertStringContainsString("'h-6 w-6 shrink-0',", $bell);

        // The old sizes are gone from these marks (the chip mark had an inline size too).
        $this->assertStringNotContainsString('<svg class="h-[18px] w-[18px] shrink-0"', $this->dashboard());
        $this->assertStringNotContainsString('<svg class="h-[18px] w-[18px] shrink-0"', $bell);
        $this->assertStringNotContainsString("'h-5 w-5 shrink-0',", $bell);
    }

    public function test_cart_and_avatar_marks_are_24px_on_both_bars(): void
    {
        $dashboard = $this->dashboard();
        $storefront = $this->storefront();

        // Cart: the header chip and the phone bar on the dashboard, and the storefront chip.
        $this->assertSame(2, substr_count($dashboard, 'anim-icon anim-icon--cart inline-flex h-6 w-6 shrink-0'));
        $this->assertSame(1, substr_count($storefront, 'anim-icon anim-icon--cart inline-flex h-6 w-6 shrink-0'));

        // Avatar fallback: the header and the phone bar on the dashboard, and the storefront.
        $this->assertSame(2, substr_count($dashboard, '<flux:icon.user class="h-6 w-6" />'));
        $this->assertSame(1, substr_count($storefront, '<flux:icon.user class="h-6 w-6" />'));

        foreach ([$dashboard, $storefront] as $source) {
            $this->assertStringNotContainsString('anim-icon--cart inline-flex h-5 w-5', $source);
            $this->assertStringNotContainsString('<flux:icon.user class="h-5 w-5" />', $source);
        }
    }

    public function test_the_chips_themselves_stayed_36px(): void
    {
        $dashboard = $this->dashboard();

        // Search, cart and avatar buttons on the dashboard bar.
        $this->assertGreaterThanOrEqual(3, substr_count($dashboard, 'h-9 w-9'));
        $this->assertStringContainsString("'h-9 w-9 rounded-full border-2 border-white/30", $this->bell());

        // Storefront cart and avatar, each named by what follows the size so
        // the guest Sign in control (also h-9 w-9) is not counted with them.
        $storefront = $this->storefront();
        $this->assertSame(1, substr_count($storefront, 'relative flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2'));
        $this->assertSame(1, substr_count($storefront, 'flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full border-2'));
    }

    /** The dropdown's list-item marks are not chips and keep their own size. */
    public function test_the_list_item_marks_were_not_swept_up(): void
    {
        $this->assertStringContainsString('<svg class="h-4.5 w-4.5 text-white" style="height:1.125rem;width:1.125rem;"', $this->bell());
    }
}
