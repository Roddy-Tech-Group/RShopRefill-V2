<?php

namespace Tests\Feature\Admin;

use App\Models\SiteSetting;
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
            ->assertSee('features.kyc_cumulative_threshold_usd')
            ->assertSee('features.gomarket_enabled')
            ->assertSee('features.newsletter_signup_enabled')
            ->assertDontSee('>Other<', false);
    }

    public function test_the_transaction_caps_render_under_verification_and_limits(): void
    {
        Volt::test('admin.system-settings')
            ->assertSee('features.spend_limit_standard_day_usd')
            ->assertSee('features.spend_limit_standard_week_usd')
            ->assertSee('features.spend_limit_verified_day_usd')
            ->assertSee('features.spend_limit_verified_week_usd')
            ->assertSee('features.spend_limit_verified_month_usd')
            // The standard monthly cap is the cumulative KYC dial, not a row.
            ->assertDontSee('features.spend_limit_standard_month_usd')
            ->assertDontSee('>Other<', false);
    }

    /**
     * site_settings.description is VARCHAR(255) on production MariaDB. The
     * suite runs on sqlite, which does not enforce the length, so a seed
     * migration with a longer description passes every test and then fails the
     * production deploy with "Data too long for column". Caught on a local
     * MariaDB run on 2026-09-17; this is the guard.
     */
    public function test_every_seeded_setting_description_fits_the_production_column(): void
    {
        $tooLong = SiteSetting::query()
            ->whereNotNull('description')
            ->get(['key', 'description'])
            ->filter(fn (SiteSetting $row) => mb_strlen((string) $row->description) > 255)
            ->map(fn (SiteSetting $row) => $row->key.' ('.mb_strlen((string) $row->description).')')
            ->values()
            ->all();

        $this->assertSame([], $tooLong, 'These seeded descriptions will not fit site_settings.description (255) on MariaDB.');
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
