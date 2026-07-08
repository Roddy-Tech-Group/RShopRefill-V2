<?php

namespace Tests\Feature\Shop;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The row-header "See all" link (storefront) and the dashboard "View all" links
 * render as small blue pills, matching the card "See more" button - the dashboard
 * variant a notch smaller.
 */
class HeaderLinkPillTest extends TestCase
{
    public function test_brand_row_see_all_link_is_a_pill(): void
    {
        $html = Blade::render('<x-home.brand-row title="Cards" view-all-href="/all"><li>card</li></x-home.brand-row>');

        $this->assertStringContainsString('See all', $html);
        // Restyled to the storefront reviews-header control: white rounded-[12px]
        // pill with a zinc ring (the old small blue chip is retired).
        $this->assertStringContainsString('rounded-[12px] bg-white px-4 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-200', $html);
    }

    public function test_dashboard_view_all_links_are_smaller_pills(): void
    {
        $markup = file_get_contents(resource_path('views/livewire/dashboard/overview.blade.php'));

        $this->assertStringContainsString('rounded-md bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold text-blue-600', $markup);
        // The old plain blue text link must be gone.
        $this->assertStringNotContainsString('text-xs font-semibold text-blue-600 hover:text-blue-700">View all', $markup);
    }
}
