<?php

namespace Tests\Feature\Kyc;

use App\Domain\Kyc\Enums\KycTier;
use App\Domain\Kyc\Services\KycTierService;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The KYC tier engine. The rules pinned here: a user's tier is read from their
 * verified state, an action's required tier is driven by risk exposure, and the
 * whole engine is inert until the enforce flag is switched on (shadow mode
 * demands nothing).
 */
class KycTierServiceTest extends TestCase
{
    use RefreshDatabase;

    private KycTierService $engine;

    protected function setUp(): void
    {
        parent::setUp();

        // The Tier 2 threshold is NOT pinned here: the migrations seed the
        // admin-editable site_settings row at $500 and that row outranks
        // config, so the tests below work against the seeded $500.
        config([
            'kyc.tier1_trigger_usd' => 0,
            'kyc.tier2_orders_in_24h' => 10,
        ]);

        $this->engine = app(KycTierService::class);
    }

    private function enforce(bool $on): void
    {
        SiteSetting::put('features.kyc_enforce_enabled', $on ? 'on' : 'off', 'features');
        Cache::flush();
    }

    public function test_current_tier_reads_from_verified_state(): void
    {
        $registered = User::factory()->create();
        $this->assertSame(KycTier::Registered, $this->engine->currentTier($registered));

        $phone = User::factory()->create(['phone_verified_at' => now()]);
        $this->assertSame(KycTier::PhoneVerified, $this->engine->currentTier($phone));

        $identity = User::factory()->create(['kyc_status' => 'verified']);
        $this->assertSame(KycTier::IdentityVerified, $this->engine->currentTier($identity));
    }

    public function test_high_value_transaction_requires_identity_verification(): void
    {
        $user = User::factory()->create();

        $this->assertSame(KycTier::IdentityVerified, $this->engine->requiredTierFor($user, 600, 'order'));
        $this->assertSame(KycTier::IdentityVerified, $this->engine->requiredTierFor($user, 600, 'funding'));
    }

    public function test_a_zero_tier1_trigger_means_unset_not_always(): void
    {
        // 0 = "never triggers", the same semantics as the Tier 2 threshold.
        // (The old behaviour required the phone tier on EVERY amount, which is
        // unattainable while no phone-OTP flow exists: a $100 funding was
        // stepped up the moment enforcement came on.)
        $user = User::factory()->create();

        $this->assertSame(KycTier::Registered, $this->engine->requiredTierFor($user, 50, 'order'));
        $this->assertSame(KycTier::Registered, $this->engine->requiredTierFor($user, 100, 'funding'));
    }

    public function test_a_positive_tier1_trigger_requires_the_phone_tier(): void
    {
        config(['kyc.tier1_trigger_usd' => 25]);
        $user = User::factory()->create();

        $this->assertSame(KycTier::PhoneVerified, $this->engine->requiredTierFor($user, 50, 'order'));
        $this->assertSame(KycTier::Registered, $this->engine->requiredTierFor($user, 10, 'order'));
    }

    public function test_the_threshold_is_seeded_where_the_settings_page_can_edit_it(): void
    {
        // RefreshDatabase runs the migrations, so the admin-editable row must
        // exist with the $500 default.
        $this->assertSame(
            '500',
            SiteSetting::query()->where('key', 'features.kyc_tier2_threshold_usd')->value('value'),
        );
    }

    public function test_an_admin_edit_on_the_settings_page_moves_the_gate(): void
    {
        // Seeded default is $500; an admin raises the bar to $1000 on the
        // Settings page (site_settings row, Verification & Limits).
        SiteSetting::put('features.kyc_tier2_threshold_usd', '1000', 'features');
        Cache::flush();

        $user = User::factory()->create();

        // $600 tripped the seeded bar but no longer trips the raised one
        // (and with tier 1 unset it needs no tier at all)...
        $this->assertSame(KycTier::Registered, $this->engine->requiredTierFor($user, 600, 'order'));
        // ...but $1000 does.
        $this->assertSame(KycTier::IdentityVerified, $this->engine->requiredTierFor($user, 1000, 'order'));
    }

    public function test_the_config_default_backstops_a_missing_row(): void
    {
        SiteSetting::query()->where('key', 'features.kyc_tier2_threshold_usd')->delete();
        Cache::flush();
        config(['kyc.tier2_threshold_usd' => 200]);

        $this->assertSame(200.0, $this->engine->tier2ThresholdUsd());
    }

    public function test_shadow_mode_demands_no_step_up(): void
    {
        $this->enforce(false);
        $user = User::factory()->create(); // Registered

        // Over the $500 seed, so identity verification would be required, but
        // shadow mode returns null.
        $this->assertNull($this->engine->requiredStepUp($user, 600, 'order'));
    }

    public function test_enforcing_demands_the_step_up(): void
    {
        $this->enforce(true);
        $user = User::factory()->create(); // Registered

        $this->assertSame(KycTier::IdentityVerified, $this->engine->requiredStepUp($user, 600, 'order'));
    }

    public function test_a_satisfied_user_needs_no_step_up_even_when_enforcing(): void
    {
        $this->enforce(true);
        $user = User::factory()->create(['kyc_status' => 'verified']); // IdentityVerified

        $this->assertNull($this->engine->requiredStepUp($user, 5000, 'order'));
    }
}
