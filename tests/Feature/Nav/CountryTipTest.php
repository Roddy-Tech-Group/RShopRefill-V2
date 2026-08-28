<?php

namespace Tests\Feature\Nav;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The first-visit "Shopping from your country?" modal was removed outright.
 *
 * This file used to assert its internals. It is kept as a regression guard
 * instead of deleted: the component was self-gating via sessionStorage, so a
 * reintroduction would be invisible to anyone who had already dismissed it once
 * and could sit unnoticed in the layout for a long time.
 */
class CountryTipTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_country_tip_component_no_longer_exists(): void
    {
        $this->assertFalse(
            File::exists(resource_path('views/components/nav/country-tip.blade.php'))
        );
    }

    public function test_the_layout_does_not_render_it(): void
    {
        $header = File::get(resource_path('views/components/layouts/app/header.blade.php'));
        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $header);

        $this->assertStringNotContainsString('<x-nav.country-tip', $markup);
    }

    public function test_no_storefront_page_shows_the_nudge(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('Shopping from your country?', $html);
        $this->assertStringNotContainsString('Switch your region to see the gift', $html);

        // The locale modal it pointed at is a separate control and stays.
        $this->assertStringContainsString('Region and currency', $html);
    }
}
