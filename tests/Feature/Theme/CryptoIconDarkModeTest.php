<?php

namespace Tests\Feature\Theme;

use Tests\TestCase;

/**
 * Dark mode applies a blanket invert to .svg <img> icons; coloured crypto coins
 * must be exempted or they flatten to white discs. BNB and LTC were missing from
 * that exception list, so they rendered as blank grey circles in dark mode.
 */
class CryptoIconDarkModeTest extends TestCase
{
    public function test_bnb_and_ltc_svgs_are_exempt_from_the_dark_invert(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // The exception block keeps brand colours on coloured coin SVGs.
        $this->assertStringContainsString('.dark img[src$=".svg"][src*="BNB"]', $css);
        $this->assertStringContainsString('.dark img[src$=".svg"][src*="LTC"]', $css);
    }
}
