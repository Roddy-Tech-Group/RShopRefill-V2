<?php

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Organization / WebSite structured data carries the brand-entity signals
 * that let Google rank us for brand searches: alternateName ties every spelling
 * of the name (singular/plural + .com) to one entity, and sameAs points at the
 * official profiles. This is how "rshoprefills" searches resolve to this site.
 */
class BrandEntitySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_declares_brand_aliases_and_profiles(): void
    {
        $this->withoutVite();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('"@type":"Organization"', $html);

        // alternateName ties the plural brand + domain variants to this entity.
        $this->assertStringContainsString('"alternateName"', $html);
        $this->assertStringContainsString('rshoprefills', $html);
        $this->assertStringContainsString('rshoprefills.com', $html);

        // sameAs points at the official profiles (the strongest brand signal).
        $this->assertStringContainsString('"sameAs"', $html);
        $this->assertStringContainsString('facebook.com/rshoprefills', $html);
    }
}
