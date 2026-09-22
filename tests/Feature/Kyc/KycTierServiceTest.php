<?php

namespace Tests\Feature\Kyc;

use App\Domain\Kyc\Enums\KycTier;
use App\Domain\Kyc\Services\KycTierService;
use App\Domain\Shared\Enums\FundingStatus;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The KYC tier engine. The rules pinned here: a user's tier is read from their
 * verified state, an action's required tier is driven by risk exposure on two
 * independent dials (one transaction, and money in over a rolling window), and
 * the whole engine is inert until the enforce flag is switched on (shadow mode
 * demands nothing).
 */
class KycTierServiceTest extends TestCase
{
    use RefreshDatabase;

    private KycTierService $engine;

    protected function setUp(): void
    {
        parent::setUp();

        // Neither dial is pinned here: the migrations seed the admin-editable
        // site_settings rows at $500 (single transaction) and $2,000
        // (cumulative) and those rows outrank config, so the tests below work
        // against the seeded values.
        config([
            'kyc.tier1_trigger_usd' => 0,
            'kyc.tier2_orders_in_24h' => 10,
            'kyc.cumulative_window_days' => 30,
        ]);

        $this->engine = app(KycTierService::class);
    }

    private function enforce(bool $on): void
    {
        SiteSetting::put('features.kyc_enforce_enabled', $on ? 'on' : 'off', 'features');
        Cache::flush();
    }

    /**
     * Money the customer put into their wallet, settled in USD.
     */
    private function fund(User $user, float $usd, ?Carbon $at = null, FundingStatus $status = FundingStatus::Completed): WalletFunding
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
            'status' => $status,
            'completed_at' => $status === FundingStatus::Completed ? ($at ?? now()) : null,
        ]);

        // created_at is not fillable, so a backdated funding is stamped after
        // the insert.
        if ($at !== null) {
            WalletFunding::query()->whereKey($funding->id)->update(['created_at' => $at]);
        }

        return $funding->fresh();
    }

    /**
     * An order the customer paid straight from a card (new money in).
     */
    private function cardOrder(User $user, float $usd, ?Carbon $at = null, array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'user_id' => $user->id,
            'payment_method' => 'flutterwave',
            'total_amount' => $usd,
            'display_currency' => 'USD',
            'created_at' => $at ?? now(),
        ], $overrides));
    }

    /**
     * An order paid from balance the customer already held.
     */
    private function walletOrder(User $user, float $usd): Order
    {
        return Order::factory()->create([
            'user_id' => $user->id,
            'payment_method' => 'wallet',
            'total_amount' => $usd,
            'display_currency' => 'USD',
        ]);
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

    // ------------------------------------------------ single transaction dial

    public function test_a_single_transaction_at_the_threshold_requires_identity_verification(): void
    {
        $user = User::factory()->create();

        $this->assertSame(KycTier::IdentityVerified, $this->engine->requiredTierFor($user, 600, 'order'));
        $this->assertSame(KycTier::IdentityVerified, $this->engine->requiredTierFor($user, 600, 'funding'));
        $this->assertSame(KycTier::Registered, $this->engine->requiredTierFor($user, 499.99, 'order'));
    }

    public function test_a_zero_tier1_trigger_means_unset_not_always(): void
    {
        // 0 = "never triggers", the same semantics as the Tier 2 dials.
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

    // -------------------------------------------------------- cumulative dial

    /**
     * Money in is every completed funding plus every gateway-settled order.
     * The action in hand counts toward the line, so the gate closes on the
     * action that would cross it, not the one after.
     */
    public function test_money_in_across_fundings_and_card_orders_reaches_the_cumulative_dial(): void
    {
        $user = User::factory()->create();
        $this->fund($user, 1200);
        $this->cardOrder($user, 600);

        $this->assertSame(1800.0, $this->engine->moneyInUsd($user));

        // $1,800 in, a $50 card order makes $1,850: still under $2,000.
        $this->assertSame(KycTier::Registered, $this->engine->requiredTierFor($user, 50, 'order'));
        // A $250 card order makes $2,050: over the line.
        $this->assertSame(KycTier::IdentityVerified, $this->engine->requiredTierFor($user, 250, 'order'));
        // A funding is money in exactly the same way.
        $this->assertSame(KycTier::IdentityVerified, $this->engine->requiredTierFor($user, 250, 'funding'));
    }

    /**
     * An order paid from wallet balance is not new money: the funding that
     * balance came from was already counted. Counting the order as well would
     * put a wallet customer at the gate on half the volume of a card customer.
     */
    public function test_an_order_paid_from_wallet_is_neither_money_in_nor_added_to_itself(): void
    {
        $user = User::factory()->create();
        $this->fund($user, 1600);
        $this->walletOrder($user, 1600); // spends the funding

        // Only the funding is money in.
        $this->assertSame(1600.0, $this->engine->moneyInUsd($user));

        // A $300 card order: $1,600 + $300 = $1,900, under the line. (Had the
        // wallet order counted, this would read $3,500 and trip.)
        $this->assertSame(KycTier::Registered, $this->engine->requiredTierFor($user, 300, 'order'));

        // A $450 order paid from wallet adds nothing to the sum: $1,600 stays
        // $1,600. (Added, it would read $2,050 and trip.)
        $this->assertSame(KycTier::Registered, $this->engine->requiredTierFor($user, 450, 'order', bringsNewMoney: false));
        // The same $450 by card is new money and does trip.
        $this->assertSame(KycTier::IdentityVerified, $this->engine->requiredTierFor($user, 450, 'order', bringsNewMoney: true));
    }

    public function test_money_in_before_the_window_opened_does_not_count(): void
    {
        $user = User::factory()->create();
        $this->fund($user, 2000, now()->subDays(45));

        $this->assertSame(0.0, $this->engine->moneyInUsd($user));
        $this->assertSame(KycTier::Registered, $this->engine->requiredTierFor($user, 50, 'order'));

        $this->fund($user, 2000, now()->subDays(20));

        $this->assertSame(2000.0, $this->engine->moneyInUsd($user));
        $this->assertSame(KycTier::IdentityVerified, $this->engine->requiredTierFor($user, 50, 'order'));
    }

    public function test_only_completed_fundings_are_money_in(): void
    {
        $user = User::factory()->create();
        $this->fund($user, 900, status: FundingStatus::Pending);
        $this->fund($user, 900, status: FundingStatus::Failed);
        $this->fund($user, 900, status: FundingStatus::Cancelled);

        $this->assertSame(0.0, $this->engine->moneyInUsd($user));
    }

    /**
     * A non-USD order with no exchange rate recorded would read its display
     * amount as dollars. One such NGN order could put a customer over the line
     * on its own, so it is left out, as the spending limits leave it out.
     */
    public function test_orders_with_suspect_pricing_are_left_out_of_money_in(): void
    {
        $user = User::factory()->create();
        $this->cardOrder($user, 1_500_000, overrides: ['display_currency' => 'NGN', 'metadata' => null]);

        $this->assertSame(0.0, $this->engine->moneyInUsd($user));
    }

    public function test_the_cumulative_dial_is_read_from_settled_usd_not_display_amounts(): void
    {
        $user = User::factory()->create();
        $this->cardOrder($user, 400_000, overrides: [
            'display_currency' => 'NGN',
            'metadata' => ['exchange_rate' => 1600, 'settlement_total_usd' => 250],
        ]);

        $this->assertSame(250.0, $this->engine->moneyInUsd($user));
    }

    // ---------------------------------------------------- settings and config

    public function test_both_dials_are_seeded_where_the_settings_page_can_edit_them(): void
    {
        // RefreshDatabase runs the migrations, so the admin-editable rows must
        // exist with their defaults.
        $this->assertSame('500', SiteSetting::query()->where('key', 'features.kyc_tier2_threshold_usd')->value('value'));
        $this->assertSame('2000', SiteSetting::query()->where('key', 'features.kyc_cumulative_threshold_usd')->value('value'));
    }

    public function test_an_admin_edit_on_the_settings_page_moves_the_single_transaction_gate(): void
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

    public function test_an_admin_edit_on_the_settings_page_moves_the_cumulative_gate(): void
    {
        SiteSetting::put('features.kyc_cumulative_threshold_usd', '500', 'features');
        Cache::flush();

        $user = User::factory()->create();
        $this->fund($user, 300);

        // $300 in + $250 = $550: over the lowered line, under the seeded one.
        $this->assertSame(KycTier::IdentityVerified, $this->engine->requiredTierFor($user, 250, 'order'));
    }

    public function test_a_zero_cumulative_dial_never_triggers_on_volume(): void
    {
        SiteSetting::put('features.kyc_cumulative_threshold_usd', '0', 'features');
        Cache::flush();

        $user = User::factory()->create();
        $this->fund($user, 5000);

        $this->assertSame(KycTier::Registered, $this->engine->requiredTierFor($user, 50, 'order'));
    }

    public function test_the_config_defaults_backstop_missing_rows(): void
    {
        SiteSetting::query()->whereIn('key', ['features.kyc_tier2_threshold_usd', 'features.kyc_cumulative_threshold_usd'])->delete();
        Cache::flush();
        config(['kyc.tier2_threshold_usd' => 200, 'kyc.cumulative_threshold_usd' => 750]);

        $this->assertSame(200.0, $this->engine->singleThresholdUsd());
        $this->assertSame(750.0, $this->engine->cumulativeThresholdUsd());
    }

    // ------------------------------------------------------- shadow / enforce

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

    public function test_enforcing_demands_the_step_up_on_the_cumulative_dial_too(): void
    {
        $this->enforce(true);
        $user = User::factory()->create();
        $this->fund($user, 1950);

        $this->assertSame(KycTier::IdentityVerified, $this->engine->requiredStepUp($user, 60, 'order'));
        $this->assertNull($this->engine->requiredStepUp($user, 60, 'order', bringsNewMoney: false));
    }

    public function test_a_satisfied_user_needs_no_step_up_even_when_enforcing(): void
    {
        $this->enforce(true);
        $user = User::factory()->create(['kyc_status' => 'verified']); // IdentityVerified
        $this->fund($user, 5000);

        $this->assertNull($this->engine->requiredStepUp($user, 5000, 'order'));
    }
}
