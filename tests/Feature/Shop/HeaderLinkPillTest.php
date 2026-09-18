<?php

namespace Tests\Feature\Shop;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The row-header "See all" link (storefront) and the dashboard "View all" links
 * render as small blue pills, matching the card "See more" button - the dashboard
 * variant a notch smaller.
 *
 * The storefront pill and its carousel arrows share one treatment with the
 * reviews row header (customer-reviews.blade.php): an h-8 rounded-full white
 * control on a zinc ring. Height is asserted because the pill sat at h-10 next
 * to h-8 arrows, which left the row visibly uneven.
 */
class HeaderLinkPillTest extends TestCase
{
    public function test_brand_row_see_more_link_is_a_pill(): void
    {
        $html = Blade::render('<x-home.brand-row title="Cards" view-all-href="/all"><li>card</li></x-home.brand-row>');

        // Same word as the reviews row, so the pill is the same width: 'See all'
        // rendered 20px narrower beside an otherwise identical control.
        $this->assertStringContainsString('See more', $html);
        $this->assertStringNotContainsString('See all', $html);
        // Restyled to the storefront reviews-header control: white rounded-full
        // pill with a zinc ring (the old small blue chip is retired).
        $this->assertStringContainsString('flex h-8 shrink-0 items-center rounded-full bg-white px-4 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-200', $html);
    }

    public function test_row_headers_all_use_the_shared_control_component(): void
    {
        // The treatment lives in one component now. Asserting the rows delegate
        // to it is what stops them drifting apart again - checking each row's
        // class string separately is exactly how they got out of sync before.
        foreach ([
            'components/home/customer-reviews.blade.php',
            'components/home/brand-row.blade.php',
            'components/home/explore-row.blade.php',
        ] as $view) {
            $markup = file_get_contents(resource_path('views/'.$view));

            $this->assertStringContainsString('<x-ui.row-controls', $markup, $view.' should use the shared control cluster.');
            $this->assertStringNotContainsString('h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white', $markup, $view.' still has its own copy of the arrow markup.');
        }
    }

    public function test_shared_controls_render_pill_and_arrows_at_one_height(): void
    {
        $html = Blade::render('<x-ui.row-controls href="/all" label="See more" prev="prev()" next="next()" />');

        // Pill and arrows are the same h-8 rounded-full control, so the header
        // reads as one row rather than a tall pill beside short circles.
        $this->assertStringContainsString('flex h-8 shrink-0 items-center rounded-full bg-white px-4', $html);
        $this->assertSame(2, substr_count($html, 'h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white'));
        $this->assertSame(2, substr_count($html, '<svg class="h-5 w-5"'));
    }

    public function test_shared_controls_pass_through_alpine_expressions_unescaped(): void
    {
        $html = Blade::render('<x-ui.row-controls prev="nudge(-1)" next="nudge(1)" prev-disabled="! canPrev" next-disabled="! canNext" />');

        // Blade's echo would turn a '&&' in these into '&amp;&amp;' and break the
        // binding, so the component emits them raw.
        $this->assertStringContainsString('x-on:click="nudge(-1)"', $html);
        $this->assertStringContainsString(':disabled="! canPrev"', $html);
        $this->assertStringContainsString(':disabled="! canNext"', $html);
    }

    public function test_storefront_arrows_stay_off_the_phone_layout_but_reviews_keep_theirs(): void
    {
        $storefront = Blade::render('<x-ui.row-controls prev="nudge(-1)" next="nudge(1)" />');
        $reviews = Blade::render('<x-ui.row-controls prev="prev()" next="next()" :arrows-on-mobile="true" />');

        // The storefront tracks are swipeable on a phone, so the arrows are
        // desktop-only there; the reviews row shows them at every width.
        $this->assertStringContainsString('class="hidden sm:flex h-8 w-8', $storefront);
        $this->assertStringContainsString('class="flex h-8 w-8', $reviews);
        $this->assertStringNotContainsString('hidden sm:flex', $reviews);
    }

    public function test_dashboard_view_all_links_are_smaller_pills(): void
    {
        $markup = file_get_contents(resource_path('views/livewire/dashboard/overview.blade.php'));

        $this->assertStringContainsString('rounded-md bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold text-blue-600', $markup);
        // The old plain blue text link must be gone.
        $this->assertStringNotContainsString('text-xs font-semibold text-blue-600 hover:text-blue-700">View all', $markup);
    }
}
