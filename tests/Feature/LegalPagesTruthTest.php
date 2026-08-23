<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The legal pages must describe the business we actually run: gift cards
 * (buy only), eSIMs, top-ups, bills and the wallet - never the travel and
 * digital-real-estate verticals the pages were once drafted around, and
 * never controls or vendor names we chose not to publish. These tests pin
 * the corrected copy so a future edit cannot quietly reintroduce fiction.
 */
class LegalPagesTruthTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_terms_name_the_entity_law_and_real_catalog(): void
    {
        $this->withoutVite();

        $this->get(route('shop.terms'))
            ->assertOk()
            ->assertSee('Roddy Technologies LTD')
            ->assertSee('RC 9385422')
            ->assertSee('5 Tetlow Road, Owerri, Imo State, Nigeria')
            ->assertSee('Federal Republic of Nigeria')
            ->assertSee('cannot be withdrawn as cash')
            ->assertSee('chargeback')
            ->assertDontSee('flights and stays')
            ->assertDontSee('travel such as');
    }

    public function test_the_refund_policy_covers_only_real_products(): void
    {
        $this->withoutVite();

        $this->get(route('shop.refund-policy'))
            ->assertOk()
            ->assertSee('2 to 10 business days')
            ->assertSee('Crypto payments')
            ->assertSee('Before you dispute a charge with your bank')
            ->assertDontSee('Digital real estate')
            ->assertDontSee('Flights and hotel stays')
            ->assertDontSee('flat processing fee')
            ->assertDontSee('You never lose money');
    }

    public function test_the_privacy_policy_discloses_the_real_kyc_without_naming_vendors(): void
    {
        $this->withoutVite();

        $this->get(route('shop.privacy'))
            ->assertOk()
            ->assertSee('biometric information')
            ->assertSee('Device fingerprints')
            ->assertSee('five to')
            ->assertSee('Nigeria Data Protection Commission')
            ->assertDontSee('Smile ID')
            ->assertDontSee('USD 500')
            ->assertDontSee('flight bookings')
            ->assertDontSee('real estate')
            ->assertDontSee('Airline and hotel partners');
    }

    public function test_the_compliance_page_claims_only_controls_that_exist(): void
    {
        $this->withoutVite();

        $this->get(route('shop.compliance'))
            ->assertOk()
            ->assertSee('identity-verification partner')
            ->assertSee('risk engine')
            ->assertDontSee('Digital real estate')
            ->assertDontSee('OFAC')
            ->assertDontSee('independently reviewed')
            ->assertDontSee('Smile ID')
            ->assertDontSee('USD 500')
            ->assertDontSee('IPRS');
    }

    public function test_the_cookie_policy_names_the_actual_trackers(): void
    {
        $this->withoutVite();

        $this->get(route('shop.cookie-policy'))
            ->assertOk()
            ->assertSee('Google Analytics')
            ->assertSee('Meta Pixel')
            ->assertSee('reopen the consent banner');
    }

    public function test_tracking_tags_are_consent_gated_not_loaded_directly(): void
    {
        SiteSetting::put('seo.google_analytics_id', 'G-CONSENT1', 'seo');
        SiteSetting::put('seo.facebook_pixel_id', '1234567890', 'seo');
        $this->withoutVite();

        $html = $this->get('/')->assertOk()->getContent();

        // The IDs ride inside the consent-gated loader; no tag is injected as
        // a direct script element, so nothing loads before the banner is
        // accepted.
        $this->assertStringContainsString('"G-CONSENT1"', $html);
        $this->assertStringContainsString('cookie-consent-accepted', $html);
        $this->assertStringNotContainsString('<script async src="https://www.googletagmanager.com', $html);
        $this->assertStringNotContainsString("<script async src='https://www.googletagmanager.com", $html);
    }
}
