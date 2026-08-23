<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The features card's sub-headings: purely visual labels dividing the same
 * flag list into clusters. No database change - every flag stays in the
 * `features` group and every row still renders.
 */
class SystemSettingsSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_features_card_renders_the_sub_headings(): void
    {
        Volt::test('admin.system-settings')
            ->assertSee('Payments & Gateways')
            ->assertSee('Risk & Fraud')
            ->assertSee('Verification & Limits')
            ->assertSee('Users & Auth')
            ->assertSee('Commerce & Catalog')
            ->assertSee('Content & Marketing');
    }

    public function test_every_flag_still_renders_under_its_section(): void
    {
        // One representative per section, plus an eligibility cell (prefix
        // match) - and no "Other" bucket, proving the map covers every seeded
        // flag.
        Volt::test('admin.system-settings')
            ->assertSee('features.gateway_korapay_enabled')
            ->assertSee('features.eligibility_korapay_esim_enabled')
            ->assertSee('features.risk_engine_enforce_enabled')
            ->assertSee('features.kyc_enforce_enabled')
            ->assertSee('features.kyc_tier2_threshold_usd')
            ->assertSee('features.guest_checkout_enabled')
            ->assertSee('features.gomarket_enabled')
            ->assertSee('features.newsletter_signup_enabled')
            ->assertDontSee('>Other<', false);
    }

    public function test_the_other_cards_are_untouched(): void
    {
        // Non-features groups keep their flat lists and slug headers.
        Volt::test('admin.system-settings')
            ->assertSee('system.maintenance_mode')
            ->assertSee('site.name')
            ->assertSee('contact.email_support');
    }
}
