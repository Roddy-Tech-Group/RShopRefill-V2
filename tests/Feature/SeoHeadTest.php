<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sitewide SEO head: a single canonical host (no www/non-www split), rich
 * robots directive, and the env-gated Google Analytics tag.
 */
class SeoHeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_anchors_to_configured_host_not_request_host(): void
    {
        config(['app.url' => 'https://rshoprefill.com']);
        $this->withoutVite();

        // Request arrives on the www host; canonical must still be non-www.
        $html = $this->get('http://www.rshoprefill.com/gift-cards')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="https://rshoprefill.com/gift-cards">', $html);
    }

    public function test_homepage_canonical_has_no_trailing_path(): void
    {
        config(['app.url' => 'https://rshoprefill.com']);
        $this->withoutVite();

        $html = $this->get('https://rshoprefill.com/')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="https://rshoprefill.com">', $html);
    }

    public function test_robots_directive_allows_rich_previews(): void
    {
        $this->withoutVite();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('index, follow, max-image-preview:large', $html);
    }

    public function test_analytics_tag_renders_from_env_fallback(): void
    {
        config(['services.google.analytics_id' => 'G-TEST123']);
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertSee('googletagmanager.com/gtag/js?id=G-TEST123', false);
    }

    public function test_admin_setting_overrides_env_for_analytics(): void
    {
        config(['services.google.analytics_id' => 'G-ENVFALLBACK']);
        SiteSetting::put('seo.google_analytics_id', 'G-ADMINSET', 'seo');
        $this->withoutVite();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('gtag/js?id=G-ADMINSET', $html);
        $this->assertStringNotContainsString('G-ENVFALLBACK', $html);
    }

    public function test_analytics_tag_absent_when_not_configured(): void
    {
        config(['services.google.analytics_id' => null]);
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertDontSee('googletagmanager.com/gtag/js', false);
    }

    public function test_admin_can_delist_site_via_robots_setting(): void
    {
        SiteSetting::put('seo.robots_default', 'noindex, nofollow', 'seo');
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_google_verification_meta_renders_when_set(): void
    {
        SiteSetting::put('seo.google_verification', 'verify-token-xyz', 'seo');
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertSee('<meta name="google-site-verification" content="verify-token-xyz">', false);
    }

    public function test_site_name_setting_drives_brand_in_head(): void
    {
        SiteSetting::put('site.name', 'BrandX Market', 'site');
        $this->withoutVite();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:site_name" content="BrandX Market">', $html);
        $this->assertStringContainsString('<meta name="application-name" content="BrandX Market">', $html);
    }
}
