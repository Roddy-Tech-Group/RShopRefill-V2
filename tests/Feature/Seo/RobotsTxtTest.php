<?php

namespace Tests\Feature\Seo;

use Tests\TestCase;

/**
 * robots.txt blocks private surfaces and the faceted-navigation params that
 * generate duplicate listing URLs, while keeping the storefront crawlable and
 * pointing crawlers at the sitemap.
 */
class RobotsTxtTest extends TestCase
{
    public function test_robots_txt_blocks_facet_params_but_keeps_store_crawlable(): void
    {
        $body = $this->get('/robots.txt')->assertOk()->getContent();

        // Faceted filters that only produce canonicalised duplicates are blocked.
        $this->assertStringContainsString('Disallow: /*sort=', $body);
        $this->assertStringContainsString('Disallow: /*subcategory=', $body);

        // Internal endpoints (ajax search backend, region switch) are blocked.
        $this->assertStringContainsString('Disallow: /ajax', $body);
        $this->assertStringContainsString('Disallow: /country', $body);

        // The storefront stays open and the sitemap is advertised.
        $this->assertStringContainsString('Allow: /', $body);
        $this->assertStringContainsString('Sitemap: '.url('/sitemap.xml'), $body);

        // ?country= (the region switch) is deliberately NOT blocked.
        $this->assertStringNotContainsString('Disallow: /*country=', $body);
    }
}
