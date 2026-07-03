<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The branded reload loader renders on the storefront (not just the dashboard)
 * and follows all three themes, so a hard reload of the website matches the
 * active theme instead of flashing unbranded.
 */
class StorefrontLoaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_shows_theme_aware_reload_loader(): void
    {
        $this->withoutVite();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('id="app-loader"', $html);
        // Light + dark + Extra Dark (pure-dark) backgrounds are all defined.
        $this->assertStringContainsString('.dark .app-loader', $html);
        $this->assertStringContainsString('.dark.pure-dark .app-loader', $html);
        // The spinner spins (rotate) and glows (halo).
        $this->assertStringContainsString('app-loader-rotate', $html);
        $this->assertStringContainsString('app-loader-glow', $html);
    }
}
