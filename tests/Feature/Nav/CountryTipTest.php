<?php

namespace Tests\Feature\Nav;

use Tests\TestCase;

/**
 * The first-visit region-tip modal's "how it works" flow: left-aligned, one step
 * per line, a 10px-radius box, and no decorative middot separators between steps.
 */
class CountryTipTest extends TestCase
{
    public function test_flow_steps_are_left_aligned_with_no_dot_separators(): void
    {
        $blade = file_get_contents(resource_path('views/components/nav/country-tip.blade.php'));

        // All four steps still render.
        $this->assertStringContainsString('Buy from any country/region', $blade);
        $this->assertStringContainsString('Pay in your currency', $blade);
        $this->assertStringContainsString('Switch your account to the country you bought from', $blade);
        $this->assertStringContainsString('Redeem it, pay &amp; enjoy', $blade);

        // Left-aligned, 10px radius, and the blue middot separators are gone.
        $this->assertStringContainsString('rounded-[10px]', $blade);
        $this->assertStringContainsString('text-left', $blade);
        $this->assertStringNotContainsString('&middot;', $blade);
    }
}
