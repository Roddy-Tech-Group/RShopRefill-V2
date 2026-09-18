<?php

namespace Tests\Feature\Kyc;

use App\Domain\Kyc\Exceptions\SpendingLimitExceededException;
use App\Domain\Kyc\Services\KycTierService;
use App\Domain\Kyc\Services\SpendingLimitService;
use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\Services\RiskGate;
use App\Domain\Shared\Enums\FundingStatus;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The transaction-limit gate: rolling daily / weekly / monthly caps on MONEY IN
 * per verification level, on the same meter as the cumulative KYC dial. Wallet
 * fundings and gateway-paid orders count; orders paid from wallet balance do
 * not count and are never refused. Admin-tunable on the Settings page, shadow
 * until the enforce flag is on.
 *
 * Nothing is pinned here: the tests run against the rows the migrations seed
 * (standard $500 / $1,000 / the $2,000 cumulative dial; verified $5,000 /
 * $10,000 / $15,000), because those rows are what production will read.
 */
class SpendingLimitTest extends TestCase
{
    use RefreshDatabase;

    private function enforce(bool $on, bool $verificationAvailable = true): void
    {
        SiteSetting::put('features.spending_limits_enforce_enabled', $on ? 'on' : 'off', 'features');
        SiteSetting::put('features.kyc_verification_enabled', $verificationAvailable ? 'on' : 'off', 'features');
        Cache::flush();
    }

    private function limits(): SpendingLimitService
    {
        return app(SpendingLimitService::class);
    }

    /**
     * A completed wallet funding: money in.
     */
    private function fund(User $user, float $usd, ?\DateTimeInterface $at = null): void
    {
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id, 'currency' => 'USD'], ['balance' => 0, 'is_active' => true]);

        $funding = WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'FUND-'.Str::upper(Str::random(10)),
            'currency' => 'USD',
            'amount' => $usd,
            'settled_amount_usd' => $usd,
            'gateway' => 'flutterwave',
            'status' => FundingStatus::Completed,
            'completed_at' => $at ?? now(),
        ]);

        if ($at !== null) {
            $funding->forceFill(['created_at' => $at])->save();
        }
    }

    /**
     * A paid order. 'flutterwave' is money in; 'wallet' spends a held balance.
     */
    private function order(User $user, float $usd, string $paymentMethod, ?\DateTimeInterface $at = null): void
    {
        Order::factory()->create([
            'user_id' => $user->id,
            'payment_method' => $paymentMethod,
            'total_amount' => $usd,
            'display_currency' => 'USD',
            'created_at' => $at ?? now(),
        ]);
    }

    // ------------------------------------------------------------ the meter

    public function test_the_daily_cap_refuses_the_gateway_order_that_crosses_it(): void
    {
        $this->enforce(true);
        $user = User::factory()->create();
        $this->order($user, 450, 'flutterwave');

        try {
            $this->limits()->guard($user, 60); // 510 > 500
            $this->fail('Expected SpendingLimitExceededException.');
        } catch (SpendingLimitExceededException $e) {
            $this->assertSame('daily', $e->window);
            $this->assertSame(500.0, $e->limitUsd);
            $this->assertStringContainsString('Verify your identity', $e->customerMessage());
            $this->assertTrue($e->toResponse()['limit_exceeded']);
        }

        // Exactly at the cap is allowed: the cap is the most that may come in.
        $this->limits()->guard($user, 50);
    }

    /**
     * The hole this closes: an unverified customer loading the wallet in
     * sub-$500 pieces went straight past a $500 daily limit, because only
     * orders were metered.
     */
    public function test_wallet_fundings_count_so_the_daily_cap_cannot_be_walked_around(): void
    {
        $this->enforce(true);
        $user = User::factory()->create();
        $this->fund($user, 480);

        try {
            $this->limits()->guard($user, 480, 'funding'); // 960 > 500
            $this->fail('Expected SpendingLimitExceededException.');
        } catch (SpendingLimitExceededException $e) {
            $this->assertSame('daily', $e->window);
        }
    }

    /**
     * An order paid from wallet balance was counted when it was funded.
     * Counting it again would put a wallet customer at the cap on half the
     * money of a card customer.
     */
    public function test_an_order_paid_from_wallet_is_not_counted(): void
    {
        $this->enforce(true);
        $user = User::factory()->create();
        $this->fund($user, 300);
        $this->order($user, 300, 'wallet'); // spends the funding

        // $300 in, not $600: another $150 by card is $450, inside $500.
        $this->limits()->guard($user, 150);

        $this->assertSame(300.0, app(KycTierService::class)->moneyInUsd($user, now()->subDay()));
    }

    /**
     * Spending a balance already held brings nothing in, so it is never
     * capped, even for a customer who is at the cap. Freezing funds a customer
     * holds is a human decision with its own tool (the admin wallet hold).
     */
    public function test_an_action_that_spends_held_balance_is_never_refused(): void
    {
        $this->enforce(true);
        $user = User::factory()->create();
        $this->fund($user, 500); // at the daily cap

        $this->limits()->guard($user, 400, 'order', bringsNewMoney: false);

        // The same $400 as new money is refused.
        $this->expectException(SpendingLimitExceededException::class);
        $this->limits()->guard($user, 400, 'order', bringsNewMoney: true);
    }

    public function test_only_completed_fundings_count(): void
    {
        $this->enforce(true);
        $user = User::factory()->create();
        $this->fund($user, 450);
        WalletFunding::query()->where('user_id', $user->id)->update(['status' => FundingStatus::Failed]);

        $this->limits()->guard($user, 450, 'funding');

        $this->assertTrue(true); // a failed funding brought nothing in
    }

    public function test_orders_with_suspect_pricing_do_not_count_toward_the_windows(): void
    {
        $this->enforce(true);
        $user = User::factory()->create();

        // Non-USD display currency with no recorded exchange rate: the USD
        // fallback would read the raw 900,000 (naira) as $900,000 and jam
        // every window shut for this customer. It must be ignored.
        Order::factory()->create([
            'user_id' => $user->id,
            'payment_method' => 'flutterwave',
            'display_currency' => 'NGN',
            'total_amount' => 900000,
            'metadata' => [],
            'created_at' => now(),
        ]);

        $this->limits()->guard($user, 200);

        $this->assertTrue(true); // not refused
    }

    // ---------------------------------------------------------- the windows

    public function test_the_weekly_window_catches_money_in_the_daily_window_misses(): void
    {
        $this->enforce(true);
        $user = User::factory()->create();
        $this->fund($user, 450, now()->subDays(2));
        $this->fund($user, 450, now()->subDays(4)); // $900 this week, $0 today

        try {
            $this->limits()->guard($user, 200, 'funding'); // weekly 1100 > 1000
            $this->fail('Expected SpendingLimitExceededException.');
        } catch (SpendingLimitExceededException $e) {
            $this->assertSame('weekly', $e->window);
        }
    }

    public function test_the_monthly_window_catches_money_in_the_weekly_window_misses(): void
    {
        $this->enforce(true);
        $user = User::factory()->create();
        $this->fund($user, 950, now()->subDays(10));
        $this->fund($user, 950, now()->subDays(20)); // $1,900 this month, $0 this week

        try {
            $this->limits()->guard($user, 200, 'funding'); // monthly 2100 > 2000
            $this->fail('Expected SpendingLimitExceededException.');
        } catch (SpendingLimitExceededException $e) {
            $this->assertSame('monthly', $e->window);
            $this->assertSame(2000.0, $e->limitUsd);
        }
    }

    public function test_money_in_before_the_month_window_does_not_count(): void
    {
        $this->enforce(true);
        $user = User::factory()->create();
        $this->fund($user, 9900, now()->subDays(45)); // outside every window

        $this->limits()->guard($user, 500, 'funding');

        $this->assertTrue(true);
    }

    // ------------------------------------------------------- shadow, tiers

    public function test_shadow_mode_logs_but_never_refuses(): void
    {
        $this->enforce(false);
        $user = User::factory()->create();
        $this->fund($user, 480);

        $this->limits()->guard($user, 480, 'funding');

        $this->assertTrue(true); // no exception in shadow
    }

    public function test_a_verified_customer_gets_the_raised_caps(): void
    {
        $this->enforce(true);
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $this->fund($user, 1900);

        // $2,100 today would breach every standard window but is well inside
        // the verified ones.
        $this->limits()->guard($user, 200, 'funding');

        // The verified message does not ask them to verify again.
        try {
            $this->limits()->guard($user, 3200, 'funding'); // 5100 > 5000
            $this->fail('Expected SpendingLimitExceededException.');
        } catch (SpendingLimitExceededException $e) {
            $this->assertSame('daily', $e->window);
            $this->assertStringNotContainsString('Verify your identity', $e->customerMessage());
            $this->assertStringContainsString('resets automatically', $e->customerMessage());
        }
    }

    /**
     * "Verify your identity to raise your limits" sends the customer to a page
     * that cannot help while verification is switched off, so they are told
     * when the limit resets instead.
     */
    public function test_an_unverified_customer_is_not_told_to_verify_while_verification_is_off(): void
    {
        $this->enforce(true, verificationAvailable: false);
        $user = User::factory()->create();
        $this->fund($user, 480);

        try {
            $this->limits()->guard($user, 480, 'funding');
            $this->fail('Expected SpendingLimitExceededException.');
        } catch (SpendingLimitExceededException $e) {
            $this->assertStringNotContainsString('Verify your identity', $e->customerMessage());
            $this->assertStringContainsString('resets automatically', $e->customerMessage());
        }
    }

    // ------------------------------------------------- one ladder, settings

    /**
     * The standard monthly cap is not a number of its own: it IS the
     * cumulative KYC dial, so the ceiling an unverified customer is shown is
     * the amount at which they are asked to verify, and an admin moving one
     * cannot leave the other behind.
     */
    public function test_the_standard_monthly_cap_is_the_cumulative_kyc_dial(): void
    {
        $this->assertSame(2000.0, $this->limits()->sets()['standard']['month']);

        SiteSetting::put('features.kyc_cumulative_threshold_usd', '3500', 'features');
        Cache::flush();

        $this->assertSame(3500.0, $this->limits()->sets()['standard']['month']);
        $this->assertSame(3500.0, app(KycTierService::class)->cumulativeThresholdUsd());
        $this->assertNull(SiteSetting::query()->where('key', 'features.spend_limit_standard_month_usd')->first());
    }

    public function test_the_seeded_ladder_is_the_one_the_kyc_page_shows(): void
    {
        $this->assertSame([
            'standard' => ['day' => 500.0, 'week' => 1000.0, 'month' => 2000.0],
            'verified' => ['day' => 5000.0, 'week' => 10000.0, 'month' => 15000.0],
        ], $this->limits()->sets());

        // One day's standard cap is the single-transaction KYC dial.
        $this->assertSame(app(KycTierService::class)->singleThresholdUsd(), $this->limits()->sets()['standard']['day']);

        // Verifying has to raise every window, or "verify to raise your
        // limits" is not true.
        $sets = $this->limits()->sets();
        foreach (['day', 'week', 'month'] as $window) {
            $this->assertGreaterThan($sets['standard'][$window], $sets['verified'][$window]);
        }
    }

    public function test_the_shipped_config_backstops_missing_rows_with_the_same_ladder(): void
    {
        SiteSetting::query()->where('key', 'like', 'features.spend_limit_%')->delete();
        SiteSetting::query()->where('key', 'features.kyc_cumulative_threshold_usd')->delete();
        Cache::flush();

        $this->assertSame([
            'standard' => ['day' => 500.0, 'week' => 1000.0, 'month' => 2000.0],
            'verified' => ['day' => 5000.0, 'week' => 10000.0, 'month' => 15000.0],
        ], $this->limits()->sets());
    }

    public function test_caps_are_admin_tunable_on_the_settings_page_rows(): void
    {
        $this->enforce(true);
        SiteSetting::put('features.spend_limit_standard_day_usd', '100', 'features');
        Cache::flush();

        $user = User::factory()->create();
        $this->fund($user, 90);

        $this->expectException(SpendingLimitExceededException::class);
        $this->limits()->guard($user, 20, 'funding'); // 110 > 100
    }

    public function test_a_zero_cap_means_no_limit_on_that_window(): void
    {
        $this->enforce(true);
        SiteSetting::put('features.spend_limit_standard_day_usd', '0', 'features');
        Cache::flush();

        $user = User::factory()->create();
        $this->fund($user, 480);

        $this->limits()->guard($user, 480, 'funding'); // $960 today, under the $1,000 week

        $this->assertTrue(true);
    }

    // -------------------------------------------------------- the risk gate

    public function test_the_risk_gate_applies_the_caps_to_fundings_and_gateway_orders(): void
    {
        $this->enforce(true);
        $user = User::factory()->create();
        $this->fund($user, 450);

        try {
            app(RiskGate::class)->guard(new RiskContext(user: $user, context: 'funding', amountUsd: 100));
            $this->fail('Expected the funding to be refused.');
        } catch (SpendingLimitExceededException $e) {
            $this->assertSame('daily', $e->window);
        }

        $this->expectException(SpendingLimitExceededException::class);
        app(RiskGate::class)->guard(new RiskContext(user: $user, context: 'order', amountUsd: 100));
    }

    public function test_the_risk_gate_never_caps_an_order_paid_from_wallet(): void
    {
        $this->enforce(true);
        $user = User::factory()->create();
        $this->fund($user, 500); // at the daily cap

        app(RiskGate::class)->guard(new RiskContext(user: $user, context: 'order', amountUsd: 400, bringsNewMoney: false));

        $this->assertTrue(true); // spending held balance is never refused
    }
}
