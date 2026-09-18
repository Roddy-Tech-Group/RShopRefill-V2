<?php

namespace Tests\Feature\Shop;

use Tests\TestCase;

/**
 * The home eSIM row stacks three deep on a phone, like the rows around it.
 *
 * The gift-card and top-up rows pass mobile-rows="3" to x-home.brand-row, but
 * the eSIM row has its own carousel and stayed a single line, so it was the one
 * row on the page that read differently on mobile.
 */
class EsimRowMobileRowsTest extends TestCase
{
    private function exploreRow(): string
    {
        return file_get_contents(resource_path('views/components/home/explore-row.blade.php'));
    }

    public function test_the_esim_track_is_three_deep_below_sm(): void
    {
        $row = $this->exploreRow();

        $this->assertStringContainsString('grid w-max grid-flow-col grid-rows-[repeat(3,auto)]', $row);
        $this->assertStringNotContainsString('class="flex w-max gap-4', $row);
    }

    /** From `sm` up it is two lines, matching the brand rows' rows="2". */
    public function test_the_esim_track_is_two_deep_from_sm_up(): void
    {
        $row = $this->exploreRow();

        $this->assertStringContainsString('sm:grid-rows-[repeat(2,auto)]', $row);
        $this->assertStringNotContainsString('sm:grid-rows-[repeat(1,auto)]', $row);
    }

    /** The depths it matches: the brand rows' own phone and desktop settings. */
    public function test_the_brand_rows_it_matches_are_three_deep_on_mobile_and_two_on_desktop(): void
    {
        $welcome = file_get_contents(resource_path('views/welcome.blade.php'));

        $this->assertStringContainsString(':mobile-rows="$row[\'mobileRows\'] ?? 3"', $welcome);
        $this->assertStringContainsString(':mobile-rows="3"', $welcome);
        $this->assertStringContainsString(':rows="$row[\'rows\'] ?? 2"', $welcome);
        $this->assertStringContainsString(':rows="2"', $welcome);
    }
}
