<?php

namespace Tests\Feature\Risk;

use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Domain\Risk\Contracts\RiskSignal;
use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\Signals\AccountBannedSignal;
use App\Domain\Risk\Signals\AccountNewSignal;
use App\Domain\Risk\Signals\AccountYoungSignal;
use App\Domain\Risk\Signals\ConfirmedFraudLabelSignal;
use App\Domain\Risk\Signals\DeviceUntrustedSignal;
use App\Domain\Risk\Signals\EmailUnverifiedSignal;
use App\Domain\Risk\Signals\HighRiskCategorySignal;
use App\Domain\Risk\Signals\HighValueSignal;
use App\Domain\Risk\Signals\KycTierBelowRequiredSignal;
use App\Domain\Risk\Signals\PhoneUnverifiedSignal;
use App\Domain\Risk\Signals\RepeatedDeclinesSignal;
use App\Domain\Risk\Signals\TurnstileFailuresSignal;
use App\Domain\Risk\Signals\UnverifiedHighValueSignal;
use App\Domain\Risk\Signals\VelocityAmount24hSignal;
use App\Domain\Risk\Signals\VelocityDevice1hSignal;
use App\Domain\Risk\Signals\VelocityIp1hSignal;
use App\Domain\Risk\Signals\VelocityUser1hSignal;
use App\Domain\Risk\Signals\VelocityUser24hSignal;
use App\Models\Order;
use App\Models\PaymentBackoffEvent;
use App\Models\RiskAssessment;
use App\Models\RiskLabel;
use App\Models\SiteSetting;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\TaggedCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Every batch-1 signal in isolation: it fires on the state it is meant to catch
 * and stays silent otherwise. Signals are pure functions of (context + their data
 * source), so each is exercised by crafting exactly that state.
 */
class RiskSignalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kyc.tier2_threshold_usd' => 200,
            'kyc.tier1_trigger_usd' => 0,
            'risk.thresholds.high_value_usd' => 100,
            'risk.thresholds.velocity_user_1h_max' => 2,
            'risk.thresholds.velocity_user_24h_max' => 2,
            'risk.thresholds.velocity_ip_1h_max' => 1,
            'risk.thresholds.velocity_device_1h_max' => 1,
            'risk.thresholds.velocity_amount_24h_usd' => 20,
            'risk.thresholds.repeated_declines_min' => 3,
            'risk.thresholds.repeated_declines_window_hours' => 2,
            'risk.high_risk_categories' => ['gift-cards'],
        ]);
    }

    private function ctx(array $overrides = []): RiskContext
    {
        return new RiskContext(
            user: $overrides['user'] ?? null,
            context: $overrides['context'] ?? 'order',
            amountUsd: $overrides['amountUsd'] ?? 0.0,
            currency: 'USD',
            ip: $overrides['ip'] ?? null,
            deviceUuid: $overrides['deviceUuid'] ?? null,
            categories: $overrides['categories'] ?? [],
        );
    }

    private function assertFires(RiskSignal $signal, RiskContext $context): void
    {
        $hit = $signal->evaluate($context);
        $this->assertNotNull($hit, $signal->code().' should have fired');
        $this->assertSame($signal->code(), $hit->code);
    }

    private function assertSilent(RiskSignal $signal, RiskContext $context): void
    {
        $this->assertNull($signal->evaluate($context), $signal->code().' should have stayed silent');
    }

    // --- Identity & account -------------------------------------------------

    public function test_account_new(): void
    {
        $signal = app(AccountNewSignal::class);
        $fresh = User::factory()->create(['created_at' => now()->subHours(2)]);
        $old = User::factory()->create(['created_at' => now()->subDays(30)]);

        $this->assertFires($signal, $this->ctx(['user' => $fresh]));
        $this->assertSilent($signal, $this->ctx(['user' => $old]));
        $this->assertSilent($signal, $this->ctx()); // no user
    }

    public function test_account_young_excludes_brand_new(): void
    {
        $signal = app(AccountYoungSignal::class);
        $young = User::factory()->create(['created_at' => now()->subDays(3)]);
        $brandNew = User::factory()->create(['created_at' => now()->subHours(1)]);
        $old = User::factory()->create(['created_at' => now()->subDays(30)]);

        $this->assertFires($signal, $this->ctx(['user' => $young]));
        $this->assertSilent($signal, $this->ctx(['user' => $brandNew])); // caught by ACCOUNT_NEW instead
        $this->assertSilent($signal, $this->ctx(['user' => $old]));
    }

    public function test_email_unverified(): void
    {
        $signal = app(EmailUnverifiedSignal::class);
        $unverified = User::factory()->unverified()->create();
        $verified = User::factory()->create(['email_verified_at' => now()]);

        $this->assertFires($signal, $this->ctx(['user' => $unverified]));
        $this->assertSilent($signal, $this->ctx(['user' => $verified]));
    }

    public function test_phone_unverified(): void
    {
        $signal = app(PhoneUnverifiedSignal::class);
        $unverified = User::factory()->create(['phone_verified_at' => null]);
        $verified = User::factory()->create(['phone_verified_at' => now()]);

        $this->assertFires($signal, $this->ctx(['user' => $unverified]));
        $this->assertSilent($signal, $this->ctx(['user' => $verified]));
    }

    public function test_kyc_tier_below_required(): void
    {
        $signal = app(KycTierBelowRequiredSignal::class);
        $unverified = User::factory()->create(['kyc_status' => 'pending']);
        $verified = User::factory()->create(['kyc_status' => 'verified']);

        // The threshold lives in the admin-editable site_settings row (seeded
        // at $500), which outranks any config pin - so move the row itself.
        SiteSetting::put('features.kyc_tier2_threshold_usd', '200', 'features');
        Cache::flush();

        // amount above the tier-2 threshold requires identity verification
        $this->assertFires($signal, $this->ctx(['user' => $unverified, 'amountUsd' => 250]));
        $this->assertSilent($signal, $this->ctx(['user' => $verified, 'amountUsd' => 250]));
    }

    // --- Value & category ---------------------------------------------------

    public function test_high_value(): void
    {
        $signal = app(HighValueSignal::class);
        $user = User::factory()->create();

        $this->assertFires($signal, $this->ctx(['user' => $user, 'amountUsd' => 100]));
        $this->assertSilent($signal, $this->ctx(['user' => $user, 'amountUsd' => 99.99]));
    }

    public function test_unverified_high_value(): void
    {
        $signal = app(UnverifiedHighValueSignal::class);
        $unverified = User::factory()->create(['kyc_status' => 'pending']);
        $verified = User::factory()->create(['kyc_status' => 'verified']);

        $this->assertFires($signal, $this->ctx(['user' => $unverified, 'amountUsd' => 150]));
        $this->assertSilent($signal, $this->ctx(['user' => $verified, 'amountUsd' => 150]));
        $this->assertSilent($signal, $this->ctx(['user' => $unverified, 'amountUsd' => 50]));
    }

    public function test_high_risk_category(): void
    {
        $signal = app(HighRiskCategorySignal::class);

        $this->assertFires($signal, $this->ctx(['categories' => ['gift-cards', 'esims']]));
        $this->assertSilent($signal, $this->ctx(['categories' => ['esims']]));
        $this->assertSilent($signal, $this->ctx(['categories' => []]));
    }

    // --- Velocity -----------------------------------------------------------

    public function test_velocity_user_1h(): void
    {
        $signal = app(VelocityUser1hSignal::class);
        $user = User::factory()->create();
        Order::factory()->count(3)->create(['user_id' => $user->id, 'created_at' => now()]);

        $this->assertFires($signal, $this->ctx(['user' => $user]));

        $quiet = User::factory()->create();
        Order::factory()->create(['user_id' => $quiet->id, 'created_at' => now()]);
        $this->assertSilent($signal, $this->ctx(['user' => $quiet]));
    }

    public function test_velocity_user_1h_ignores_old_orders(): void
    {
        $signal = app(VelocityUser1hSignal::class);
        $user = User::factory()->create();
        Order::factory()->count(3)->create(['user_id' => $user->id, 'created_at' => now()->subHours(2)]);

        $this->assertSilent($signal, $this->ctx(['user' => $user]));
    }

    public function test_velocity_user_24h(): void
    {
        $signal = app(VelocityUser24hSignal::class);
        $user = User::factory()->create();
        Order::factory()->count(3)->create(['user_id' => $user->id, 'created_at' => now()->subHours(5)]);

        $this->assertFires($signal, $this->ctx(['user' => $user]));
    }

    public function test_velocity_ip_1h(): void
    {
        $signal = app(VelocityIp1hSignal::class);
        $this->seedAssessment(['ip_address' => '198.51.100.7']);
        $this->seedAssessment(['ip_address' => '198.51.100.7']);

        $this->assertFires($signal, $this->ctx(['ip' => '198.51.100.7']));
        $this->assertSilent($signal, $this->ctx(['ip' => '198.51.100.9']));
        $this->assertSilent($signal, $this->ctx()); // no ip
    }

    public function test_velocity_device_1h(): void
    {
        $signal = app(VelocityDevice1hSignal::class);
        $this->seedAssessment(['device_uuid' => 'dev-x']);
        $this->seedAssessment(['device_uuid' => 'dev-x']);

        $this->assertFires($signal, $this->ctx(['deviceUuid' => 'dev-x']));
        $this->assertSilent($signal, $this->ctx(['deviceUuid' => 'dev-y']));
    }

    public function test_velocity_amount_24h(): void
    {
        $signal = app(VelocityAmount24hSignal::class);
        $user = User::factory()->create();
        // One paid order worth $12 in the window; a $10 attempt projects to $22 >= $20.
        Order::factory()->create([
            'user_id' => $user->id,
            'total_amount' => 12.00,
            'created_at' => now(),
        ]);

        $this->assertFires($signal, $this->ctx(['user' => $user, 'amountUsd' => 10]));

        $quiet = User::factory()->create();
        $this->assertSilent($signal, $this->ctx(['user' => $quiet, 'amountUsd' => 5]));
    }

    public function test_velocity_amount_24h_ignores_orders_with_suspect_pricing(): void
    {
        $signal = app(VelocityAmount24hSignal::class);
        $user = User::factory()->create();

        // A recent paid order with a non-USD display currency and no recorded
        // exchange rate: the USD fallback would read the raw 90,000 (naira) as
        // $90,000 and instantly trip the spend-spike bar. It must not count.
        Order::factory()->create([
            'user_id' => $user->id,
            'display_currency' => 'NGN',
            'total_amount' => 90000,
            'metadata' => [],
            'created_at' => now(),
        ]);

        $this->assertSilent($signal, $this->ctx(['user' => $user, 'amountUsd' => 5]));
    }

    // --- Payment behaviour --------------------------------------------------

    public function test_repeated_declines(): void
    {
        $signal = app(RepeatedDeclinesSignal::class);
        $user = User::factory()->create();
        PaymentBackoffEvent::create([
            'identifier' => 'u:'.$user->id,
            'user_id' => $user->id,
            'product_line' => 'checkout',
            'signal' => PaymentBackoffManager::SIGNAL_DECLINE,
            'tier' => 'known',
            'decline_count' => 3,
            'no_show_count' => 0,
            'wait_seconds' => 30,
            'enforced' => false,
            'customer_ip' => '203.0.113.1',
        ]);

        $this->assertFires($signal, $this->ctx(['user' => $user]));

        $quiet = User::factory()->create();
        $this->assertSilent($signal, $this->ctx(['user' => $quiet]));
    }

    public function test_turnstile_failures(): void
    {
        $signal = app(TurnstileFailuresSignal::class);
        TaggedCache::for(['security'])->put('turnstile_failures_203.0.113.2', 5, now()->addMinutes(15));

        $this->assertFires($signal, $this->ctx(['ip' => '203.0.113.2']));
        $this->assertSilent($signal, $this->ctx(['ip' => '203.0.113.3']));
    }

    // --- Device -------------------------------------------------------------

    public function test_device_untrusted(): void
    {
        $signal = app(DeviceUntrustedSignal::class);
        $user = User::factory()->create();

        // Unknown device
        $this->assertFires($signal, $this->ctx(['user' => $user, 'deviceUuid' => 'never-seen']));

        // Known but not yet trusted
        TrustedDevice::create(['user_id' => $user->id, 'device_uuid' => 'seen', 'fingerprint' => 'fp-seen', 'trusted_since' => null]);
        $this->assertFires($signal, $this->ctx(['user' => $user, 'deviceUuid' => 'seen']));

        // Trusted
        TrustedDevice::create(['user_id' => $user->id, 'device_uuid' => 'trusted', 'fingerprint' => 'fp-trusted', 'trusted_since' => now()]);
        $this->assertSilent($signal, $this->ctx(['user' => $user, 'deviceUuid' => 'trusted']));

        // No fingerprint -> abstains
        $this->assertSilent($signal, $this->ctx(['user' => $user]));
    }

    // --- Overrides ----------------------------------------------------------

    public function test_confirmed_fraud_label(): void
    {
        $signal = app(ConfirmedFraudLabelSignal::class);
        $flagged = User::factory()->create();
        RiskLabel::create([
            'user_id' => $flagged->id,
            'label' => RiskLabel::LABEL_CONFIRMED_FRAUD,
            'source' => 'admin',
        ]);

        $this->assertFires($signal, $this->ctx(['user' => $flagged]));
        $this->assertSilent($signal, $this->ctx(['user' => User::factory()->create()]));
    }

    public function test_account_banned(): void
    {
        $signal = app(AccountBannedSignal::class);
        $banned = User::factory()->create(['banned_at' => now()]);
        $active = User::factory()->create(['banned_at' => null]);

        $this->assertFires($signal, $this->ctx(['user' => $banned]));
        $this->assertSilent($signal, $this->ctx(['user' => $active]));
    }

    private function seedAssessment(array $overrides): void
    {
        RiskAssessment::create(array_merge([
            'context' => 'order',
            'score' => 0,
            'band' => 'LOW',
            'outcome' => 'allow',
            'enforced' => false,
            'amount_usd' => 0,
        ], $overrides));
    }
}
