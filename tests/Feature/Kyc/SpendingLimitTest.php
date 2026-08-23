<?php

namespace Tests\Feature\Kyc;

use App\Domain\Kyc\Exceptions\SpendingLimitExceededException;
use App\Domain\Kyc\Services\SpendingLimitService;
use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\Services\RiskGate;
use App\Models\Order;
use App\Models\Setting;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The spending-limit gate: rolling 24h/7d/30d caps of paid order value, per
 * verification level, settings-tunable, shadow until the enforce flag is on,
 * and wired at order placement only (funding is deliberately uncounted).
 */
class SpendingLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'limits.standard' => ['day' => 2000, 'week' => 5000, 'month' => 10000],
            'limits.verified' => ['day' => 5000, 'week' => 10000, 'month' => 15000],
            // Keep the KYC/risk gates quiet so only the limit gate is under test.
            'kyc.tier2_threshold_usd' => 1_000_000,
            'kyc.tier1_trigger_usd' => 0,
        ]);
    }

    private function enforce(bool $on): void
    {
        SiteSetting::put('features.spending_limits_enforce_enabled', $on ? 'on' : 'off', 'features');
        Cache::flush();
    }

    private function paidOrders(User $user, float $totalUsd, ?\DateTimeInterface $createdAt = null): void
    {
        Order::factory()->create([
            'user_id' => $user->id,
            'total_amount' => $totalUsd,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    public function test_enforced_daily_cap_refuses_the_order_that_crosses_it(): void
    {
        $this->enforce(true);
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);
        $this->paidOrders($user, 1900);

        try {
            app(SpendingLimitService::class)->guard($user, 200); // 2100 > 2000
            $this->fail('Expected SpendingLimitExceededException.');
        } catch (SpendingLimitExceededException $e) {
            $this->assertSame('daily', $e->window);
            $this->assertStringContainsString('Verify your identity', $e->customerMessage());
            $this->assertTrue($e->toResponse()['limit_exceeded']);
        }
    }

    public function test_shadow_mode_logs_but_never_refuses(): void
    {
        $this->enforce(false);
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);
        $this->paidOrders($user, 1900);

        app(SpendingLimitService::class)->guard($user, 200);

        $this->assertTrue(true); // no exception in shadow
    }

    public function test_a_verified_customer_gets_the_raised_cap(): void
    {
        $this->enforce(true);
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $this->paidOrders($user, 1900);

        // 2100 would breach the standard cap but is well inside the verified one.
        app(SpendingLimitService::class)->guard($user, 200);

        $this->assertTrue(true);

        // The verified message does not ask them to verify again.
        try {
            app(SpendingLimitService::class)->guard($user, 3200); // 5100 > 5000
            $this->fail('Expected SpendingLimitExceededException.');
        } catch (SpendingLimitExceededException $e) {
            $this->assertStringNotContainsString('Verify your identity', $e->customerMessage());
        }
    }

    public function test_the_weekly_window_catches_spend_the_daily_window_misses(): void
    {
        $this->enforce(true);
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);
        $this->paidOrders($user, 4900, now()->subDays(3)); // outside 24h, inside 7d

        try {
            app(SpendingLimitService::class)->guard($user, 200); // weekly 5100 > 5000
            $this->fail('Expected SpendingLimitExceededException.');
        } catch (SpendingLimitExceededException $e) {
            $this->assertSame('weekly', $e->window);
        }
    }

    public function test_spend_outside_the_month_window_does_not_count(): void
    {
        $this->enforce(true);
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);
        $this->paidOrders($user, 9900, now()->subDays(45)); // outside every window

        app(SpendingLimitService::class)->guard($user, 500);

        $this->assertTrue(true);
    }

    public function test_orders_with_suspect_pricing_do_not_count_toward_the_windows(): void
    {
        $this->enforce(true);
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);

        // Non-USD display currency with no recorded exchange rate: the USD
        // fallback would read the raw 900,000 (naira) as $900,000 and jam
        // every window shut for this customer. It must be ignored.
        Order::factory()->create([
            'user_id' => $user->id,
            'display_currency' => 'NGN',
            'total_amount' => 900000,
            'metadata' => [],
            'created_at' => now(),
        ]);

        app(SpendingLimitService::class)->guard($user, 200);

        $this->assertTrue(true); // not refused
    }

    public function test_limits_are_admin_tunable_through_settings(): void
    {
        $this->enforce(true);
        Setting::set('spend_limit_standard_day_usd', 100, 'integer');
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);
        $this->paidOrders($user, 90);

        $this->expectException(SpendingLimitExceededException::class);
        app(SpendingLimitService::class)->guard($user, 20); // 110 > 100
    }

    public function test_the_risk_gate_applies_limits_to_orders_but_not_funding(): void
    {
        $this->enforce(true);
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);
        $this->paidOrders($user, 1900);

        // Funding context passes untouched...
        app(RiskGate::class)->guard(new RiskContext(user: $user, context: 'funding', amountUsd: 200));

        // ...the same amount at order placement is refused.
        $this->expectException(SpendingLimitExceededException::class);
        app(RiskGate::class)->guard(new RiskContext(user: $user, context: 'order', amountUsd: 200));
    }
}
