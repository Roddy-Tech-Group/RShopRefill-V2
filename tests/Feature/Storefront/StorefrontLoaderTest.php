<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The branded reload loader renders on the storefront (not just the dashboard)
 * and follows the active theme, so a hard reload of the website matches the
 * theme in force instead of flashing unbranded.
 */
class StorefrontLoaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_shows_theme_aware_reload_loader(): void
    {
        $this->withoutVite();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('id="app-loader"', $html);
        // Light + dark backgrounds are defined. Extra Dark rides on the same
        // .dark rule - the theme engine applies .pure-dark alongside .dark, so
        // a rule of its own could only restate it, which is how the two drifted
        // apart before. See Tests\Feature\Nav\AppLoaderGroundTest.
        $this->assertStringContainsString('.dark .app-loader', $html);
        $this->assertStringNotContainsString('.dark.pure-dark .app-loader', $html);
        // The spinner spins (rotate) and glows (halo).
        $this->assertStringContainsString('app-loader-rotate', $html);
        $this->assertStringContainsString('app-loader-glow', $html);
    }
}
