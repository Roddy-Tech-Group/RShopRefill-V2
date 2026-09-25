<?php

namespace Tests\Feature\Shop;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The eSIM page's location tabs: a segmented pill that has to survive a phone
 * and then ride under the nav while the grid it filters scrolls past it.
 */
class EsimLocationTabsTest extends TestCase
{
    private function tabs(): string
    {
        return File::get(resource_path('views/components/esim/location-tabs.blade.php'));
    }

    private function page(): string
    {
        return File::get(resource_path('views/shop/esims.blade.php'));
    }

    /**
     * w-max hugs the tabs, max-w-full stops the pill exceeding the screen. With
     * w-max alone the five tabs ran to about 500px and pushed the pill past a
     * phone viewport, taking the page's horizontal scroll with it.
     */
    public function test_the_pill_can_never_be_wider_than_the_screen(): void
    {
        $tabs = $this->tabs();

        $this->assertStringContainsString('flex w-max max-w-full items-center gap-1 overflow-x-auto rounded-full', $tabs);

        // Overflow stays auto at every width. It went visible from sm, which was
        // safe only while w-max made the box fit its content by definition; with
        // the cap, a tablet narrower than the tabs would spill them outside the
        // white track.
        $this->assertStringNotContainsString('sm:overflow-visible', $tabs);
    }

    /**
     * The chosen tab is scrolled into view, by moving the track itself.
     * scrollIntoView would scroll every scrollable ancestor, which on a sticky
     * control drags the page with it.
     */
    public function test_the_chosen_tab_is_revealed_without_moving_the_page(): void
    {
        $tabs = $this->tabs();

        $this->assertStringContainsString('this.reveal(track, active);', $tabs);
        $this->assertStringContainsString('track.scrollTo({ left:', $tabs);

        // The call, not the word: the comment above reveal() names
        // scrollIntoView to say why it is not used, and prose must not be able
        // to fail this.
        $this->assertStringNotContainsString('scrollIntoView(', $tabs);
    }

    /**
     * The tabs stick under the nav on a desktop, offset by the bar's real
     * published height rather than a hardcoded number, and the wrapper carries
     * the page's own tone so the grid scrolls behind a surface instead of
     * through the gap either side of the pill.
     *
     * Only the positioning is desktop-scoped. The band is the page's own tone,
     * so on a phone, where nothing is stuck, there is nothing to see.
     */
    public function test_the_tabs_stick_under_the_nav_on_a_band_of_page_tone(): void
    {
        preg_match('/<section class="([^"]*md:sticky[^"]*)">\s*<x-esim\.location-tabs \/>/', $this->page(), $wrapper);

        // A <section>, and that is not cosmetic. In dark mode the page tone is
        // not a colour this element can name: app.css keeps dark:bg-[#0c1a36]
        // at page black only for body, main, section, footer and
        // [data-page-content], and flips it to card grey everywhere else. As a
        // div the band rendered a lifted grey slab across a black page.
        $this->assertNotEmpty($wrapper, 'The tabs wrapper is no longer a desktop-sticky <section>, so its dark tone will read as a card rather than the page.');
        $this->assertStringContainsString('md:top-[var(--nav-h,64px)]', $wrapper[1], 'The offset must follow the nav, which republishes --nav-h as it folds.');
        $this->assertStringContainsString('md:z-30', $wrapper[1]);

        // Nothing pins it on a phone: a bare `sticky`, not the md: variant.
        $this->assertDoesNotMatchRegularExpression('/(^|\s)sticky(\s|$)/', $wrapper[1], 'The tabs are pinned on a phone too.');

        // The same pair <main> wears, so the band follows every remap the page
        // tone does in both themes.
        $main = File::get(resource_path('views/components/layouts/app/header.blade.php'));
        $this->assertStringContainsString('bg-[#eff6ff] dark:bg-[#0c1a36]', $main);
        $this->assertStringContainsString('bg-[#eff6ff]', $wrapper[1]);
        $this->assertStringContainsString('dark:bg-[#0c1a36]', $wrapper[1]);
    }

    /**
     * The detail page's plan-type selector rides under the nav the same way,
     * and it is the CARD that sticks, not the segmented control inside it:
     * the card carries overflow-hidden for its own radius, and an overflow
     * other than visible on an ancestor stops a descendant sticking at all.
     * On the sticking element itself it is harmless.
     */
    public function test_the_plan_selector_card_sticks_under_the_nav(): void
    {
        $detail = File::get(resource_path('views/shop/esim.blade.php'));

        preg_match('/<div id="esim-packages" class="([^"]+)"/', $detail, $card);

        $this->assertNotEmpty($card, 'The selector card is gone or renamed.');
        $this->assertStringContainsString('md:sticky', $card[1]);
        $this->assertStringContainsString('md:top-[var(--nav-h,64px)]', $card[1], 'The offset must follow the nav rather than a hardcoded height.');
        $this->assertStringContainsString('md:z-30', $card[1]);

        // Desktop only: two rows of controls pinned on a phone would hold back
        // a third of a short viewport from the plans they choose between.
        $this->assertDoesNotMatchRegularExpression('/(^|\s)sticky(\s|$)/', $card[1], 'The selector is pinned on a phone too.');
        $this->assertStringContainsString('overflow-hidden', $card[1], 'The card still clips its own children for the radius.');

        // It is opaque, so the plan grid passes behind it rather than through it.
        $this->assertStringContainsString('bg-[#ffffff]', $card[1]);
    }

    /**
     * The country header must outrank the sticky plan selector.
     *
     * The header card carries a z-index, which makes it a stacking context its
     * region dropdown can never escape, and that list hangs far below the
     * card's own bottom edge. So the card's number, not the list's, decides
     * what the list can cover. When the selector gained md:z-30 for stickiness
     * it overtook the header's z-20 and painted straight through the middle of
     * the open country list.
     */
    public function test_the_country_header_outranks_the_sticky_plan_selector(): void
    {
        $detail = File::get(resource_path('views/shop/esim.blade.php'));

        preg_match('/<div class="esim-tile relative z-(\d+) /', $detail, $header);
        preg_match('/<div id="esim-packages" class="[^"]*md:z-(\d+)/', $detail, $selector);

        $this->assertNotEmpty($header, 'The country header card is gone or no longer carries a z-index.');
        $this->assertNotEmpty($selector, 'The sticky selector card is gone or no longer carries a z-index.');

        $this->assertGreaterThan(
            (int) $selector[1],
            (int) $header[1],
            "The country header (z-{$header[1]}) no longer outranks the sticky selector (z-{$selector[1]}), so the open region list will be painted over."
        );

        // And it still passes beneath the nav, which sticks at z-50.
        $this->assertLessThan(50, (int) $header[1], 'The header now covers the nav.');
    }

    /**
     * The destination search is grey until you click into it, and only then
     * wears the interactive edge.
     *
     * The swap is a class swap, not a focus: variant, and that is forced:
     * html:not(.dark) body.storefront pins .border-zinc-200 to the shared edge
     * colour at a specificity no focus: utility can reach, so the field stayed
     * grey however it was focused. Removing the class removes the rule.
     */
    public function test_the_destination_search_turns_blue_when_clicked_into(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('@focus="searchFocused = true"', $page);
        $this->assertStringContainsString('@blur="searchFocused = false"', $page);
        $this->assertStringContainsString('searchFocused: false,', $page, 'The flag must live on the page scope, since $refs.esimSearch is read from there.');

        preg_match('/:class="searchFocused\s*\?\s*\'([^\']+)\'\s*:\s*\'([^\']+)\'"/s', $page, $swap);
        $this->assertNotEmpty($swap, 'The border no longer swaps on focus.');
        $this->assertStringContainsString('border-edge', $swap[1], 'Focused state is not the interactive edge.');
        $this->assertStringContainsString('border-zinc-200', $swap[2], 'Resting state is not the neutral edge.');
        $this->assertStringNotContainsString('border-edge', $swap[2], 'The field is blue at rest again.');

        // A standing blue border would have been the only focus cue; the halo
        // stays either way, and the field sets outline-none.
        $this->assertStringContainsString('focus:ring-2 focus:ring-edge/30', $page);
        $this->assertStringNotContainsString('focus:ring-[#0c1337]/30', $page);
    }

    /**
     * Every search field on the eSIM surfaces and in the locale modal wears the
     * edge at REST, not only while focused, and each keeps a focus halo.
     *
     * The halo is the point: once the border is blue at rest it can no longer
     * be the focus indicator, and these fields all set outline-none, so
     * without it a keyboard user focusing one would see nothing change.
     */
    public function test_every_search_field_wears_the_edge_at_rest_and_still_shows_focus(): void
    {
        $fields = [];

        foreach ([
            resource_path('views/shop/esim.blade.php'),
            resource_path('views/shop/esims.blade.php'),
            resource_path('views/components/nav/locale-modal.blade.php'),
            resource_path('views/components/nav/language-switcher.blade.php'),
        ] as $file) {
            $markup = File::get($file);
            preg_match_all('/class="([^"]*border-2 border-edge[^"]*)"/', $markup, $matches);

            foreach ($matches[1] as $classes) {
                $fields[] = [basename($file), $classes];
            }

            // No field is left blue-on-click-only.
            $this->assertStringNotContainsString('focus:border-blue-500', $markup, basename($file).' still turns blue only on focus.');
        }

        // The destination search is deliberately NOT in this set: it is grey at
        // rest and only takes the edge once clicked into, which
        // test_the_destination_search_turns_blue_when_clicked_into covers.
        $this->assertCount(7, $fields, 'Expected the three eSIM detail searches, the purchase sheet currency picker, two locale panes and the language switcher.');

        foreach ($fields as [$file, $classes]) {
            $this->assertStringContainsString('focus:ring-2 focus:ring-edge/30', $classes, "A field in {$file} has a standing edge but no focus indicator.");
            $this->assertStringContainsString('dark:border-edge', $classes, "A field in {$file} loses the edge in dark mode.");
        }
    }
}
