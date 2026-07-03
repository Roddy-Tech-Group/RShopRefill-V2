<?php

namespace Tests\Feature\Shop;

use Tests\TestCase;

/**
 * eSIM data plan cards must all be the same height. The variable-length tier chip
 * ("TRIP"/"EXPLORER"/"ADVENTURER"/"NOMAD") used to wrap onto a 2nd line on long
 * names and make those data cards taller, so the tier chip now renders only on
 * voice plans - every data plan card carries the single "Data only" badge. This
 * applies everywhere data plans are listed: the eSIM detail page and the
 * Discover Global home section.
 *
 * Those pages lean on MySQL-only JSON functions and render the cards client-side,
 * so assert against the card template markup directly.
 */
class EsimPlanCardTest extends TestCase
{
    public function test_esim_detail_page_data_plans_show_only_the_data_only_badge(): void
    {
        $markup = file_get_contents(resource_path('views/shop/esim.blade.php'));

        // Alpine: tier chip gated on p.is_voice.
        $this->assertStringContainsString('x-show="p.is_voice && tiers[idx % 4]', $markup);
        // Tiny See-more button sharing a bottom row with the price (button left, price right),
        // and a smaller variant inside the dashboard.
        $this->assertStringContainsString('mt-auto flex items-center justify-between gap-2 pt-3', $markup);
        $this->assertStringContainsString("\$inDash ? 'rounded-md px-1.5 text-[10px]' : 'rounded-[8px] px-2 text-[11px]'", $markup);
    }

    public function test_discover_global_data_plans_show_only_the_data_only_badge(): void
    {
        $markup = file_get_contents(resource_path('views/components/home/discover-global.blade.php'));

        // Blade: tier chip gated on $plan['is_voice'].
        $this->assertStringContainsString("\$plan['is_voice'] && \$tiers[\$idx % 4]", $markup);
        // Tiny See-more button sharing a bottom row with the price (button left, price right),
        // and a smaller variant inside the dashboard.
        $this->assertStringContainsString('mt-auto flex items-center justify-between gap-2 pt-3', $markup);
        $this->assertStringContainsString("\$onDashboard ? 'rounded-md px-1.5 text-[10px]' : 'rounded-[8px] px-2 text-[11px]'", $markup);
    }
}
