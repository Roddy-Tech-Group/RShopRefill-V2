<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Footer "Get the app" badges: App Store + Google Play render as coming-soon
 * announcements (static pills, not links) until the store listings ship.
 */
class FooterAppBadgesTest extends TestCase
{
    use RefreshDatabase;

    public function test_footer_shows_both_store_badges_as_coming_soon(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Get the app', $html);
        $this->assertStringContainsString('App Store', $html);
        $this->assertStringContainsString('Google Play', $html);
        $this->assertSame(2, substr_count($html, 'Coming soon on'));

        // Until the store listings ship, both badges route to the Web App
        // page (the installable PWA).
        $this->assertSame(2, substr_count($html, 'href="'.route('shop.mobile-app').'" wire:navigate title="Coming soon - use our Web App today"'));
    }
}
