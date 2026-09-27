<?php

namespace Tests\Feature\Kyc;

use App\Domain\Kyc\Contracts\KycProvider;
use App\Domain\Kyc\DTOs\KycSession;
use App\Domain\Kyc\DTOs\KycVerdict;
use App\Domain\Kyc\Exceptions\KycStepUpRequiredException;
use App\Domain\Kyc\Services\KycGate;
use App\Domain\Kyc\Services\KycProviderFactory;
use App\Domain\Kyc\Services\KycSelfServeEligibility;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shared\Enums\Currency;
use App\Models\KycVerification;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The CEO's transact-first rule (2026-09-22): "Verify now" starts a paid
 * identity check only for a customer who has already bought something or
 * funded their wallet, at least $1 worth. Only that door: the checkout and
 * funding step-ups never ask, or a first big order would be deadlocked.
 */
class KycSelfServeEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SiteSetting::put('features.kyc_verification_enabled', 'on', 'features');
        Cache::flush();
    }

    private function customer(): User
    {
        return User::factory()->create(['kyc_status' => 'unsubmitted']);
    }

    private function paidOrder(User $user, float $usd, string $method = 'wallet', PaymentStatus $status = PaymentStatus::Paid): Order
    {
        return Order::factory()->create([
            'user_id' => $user->id,
            'payment_method' => $method,
            'payment_status' => $status,
            'display_currency' => 'USD',
            'subtotal_amount' => $usd,
            'total_amount' => $usd,
            'metadata' => ['exchange_rate' => 1.0, 'settlement_total_usd' => $usd, 'settlement_subtotal_usd' => $usd],
        ]);
    }

    private function funding(User $user, float $usd, string $status = 'completed'): WalletFunding
    {
        $wallet = Wallet::firstOrCreate(['user_id' => $user->id, 'currency' => Currency::USD], ['balance' => 0, 'locked_balance' => 0, 'is_active' => true]);

        return WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'FUND-'.Str::random(8),
            'currency' => 'USD',
            'amount' => $usd,
            'settled_amount_usd' => $status === 'completed' ? $usd : null,
            'gateway' => 'flutterwave',
            'status' => $status,
        ]);
    }

    /** The provider must not be reached when the gate refuses. */
    private function providerNeverCalled(): void
    {
        $this->mock(KycProviderFactory::class, fn ($mock) => $mock->shouldReceive('forUser')->never());
    }

    /**
     * A gate pass is proven by the request reaching the provider factory.
     * It answers with a failure so the test never mints a real session.
     */
    private function providerReached(): void
    {
        $this->mock(KycProviderFactory::class, fn ($mock) => $mock->shouldReceive('forUser')->once()->andThrow(new \RuntimeException('stubbed provider')));
    }

    public function test_a_customer_who_has_never_transacted_cannot_start_verification(): void
    {
        $this->providerNeverCalled();
        $user = $this->customer();

        $this->actingAs($user)->postJson('/api/kyc/start')
            ->assertStatus(403)
            ->assertJsonPath('kyc_ineligible', true)
            ->assertJsonPath('message', 'Verification opens after your first purchase or wallet top-up of at least $1. Shop or fund your wallet, then come back.');

        $this->assertSame(0, KycVerification::count());
    }

    public function test_one_paid_order_of_a_dollar_opens_verification(): void
    {
        $this->providerReached();
        $user = $this->customer();
        $this->paidOrder($user, 1.00);

        // 502 = the stubbed provider failed, which means the gate let it through.
        $this->actingAs($user)->postJson('/api/kyc/start')->assertStatus(502);
    }

    public function test_a_wallet_paid_purchase_counts_as_a_transaction(): void
    {
        $user = $this->customer();
        $this->paidOrder($user, 4.50, method: 'wallet');

        $this->assertTrue(app(KycSelfServeEligibility::class)->isEligible($user));
    }

    public function test_a_purchase_under_the_floor_does_not_count(): void
    {
        $this->providerNeverCalled();
        $user = $this->customer();
        $this->paidOrder($user, 0.50);

        $this->actingAs($user)->postJson('/api/kyc/start')->assertStatus(403);
    }

    public function test_a_refunded_order_does_not_count(): void
    {
        $this->providerNeverCalled();
        $user = $this->customer();
        $this->paidOrder($user, 20.00, status: PaymentStatus::Refunded);
        $this->paidOrder($user, 20.00, status: PaymentStatus::Pending);
        $this->paidOrder($user, 20.00, status: PaymentStatus::Failed);

        $this->actingAs($user)->postJson('/api/kyc/start')->assertStatus(403);
    }

    public function test_a_completed_wallet_top_up_counts_and_a_pending_one_does_not(): void
    {
        $user = $this->customer();
        $eligibility = app(KycSelfServeEligibility::class);

        $this->funding($user, 5.00, status: 'pending');
        $this->assertFalse($eligibility->isEligible($user));

        $this->funding($user, 5.00, status: 'completed');
        $this->assertTrue($eligibility->isEligible($user));
    }

    public function test_the_count_and_the_floor_are_admin_settings(): void
    {
        $user = $this->customer();
        $eligibility = app(KycSelfServeEligibility::class);
        $this->paidOrder($user, 3.00);
        $this->assertTrue($eligibility->isEligible($user));

        // Two transactions required: one is no longer enough.
        SiteSetting::put(KycSelfServeEligibility::MIN_TRANSACTIONS_KEY, '2', 'features');
        Cache::flush();
        $this->assertFalse($eligibility->isEligible($user));
        $this->assertSame('Verification opens after your first 2 purchases or wallet top-ups of at least $1 each. Shop or fund your wallet, then come back.', $eligibility->refusalMessage());

        $this->paidOrder($user, 3.00);
        $this->assertTrue($eligibility->isEligible($user));

        // A $5 floor: the two $3 orders no longer qualify.
        SiteSetting::put(KycSelfServeEligibility::MIN_TRANSACTION_USD_KEY, '5', 'features');
        Cache::flush();
        $this->assertFalse($eligibility->isEligible($user));

        // 0 switches the rule off.
        SiteSetting::put(KycSelfServeEligibility::MIN_TRANSACTIONS_KEY, '0', 'features');
        Cache::flush();
        $this->assertTrue($eligibility->isEligible($this->customer()));
    }

    /**
     * Both dials are seeded 1, which the Settings page took for a switch: it
     * drew On/Off with no number field, and switching Off wrote "off", which
     * read as the default 1, so the rule stayed on. They are always a number
     * field now, at 0 and 1 too.
     */
    public function test_the_dials_render_as_number_fields_not_switches(): void
    {
        foreach (['1', '0'] as $value) {
            SiteSetting::put(KycSelfServeEligibility::MIN_TRANSACTIONS_KEY, $value, 'features');
            SiteSetting::put(KycSelfServeEligibility::MIN_TRANSACTION_USD_KEY, $value, 'features');
            Cache::flush();

            $html = Volt::test('admin.system-settings')->html();

            foreach ([KycSelfServeEligibility::MIN_TRANSACTIONS_KEY, KycSelfServeEligibility::MIN_TRANSACTION_USD_KEY] as $key) {
                $label = preg_quote('aria-label="'.$key.'"', '/');

                $this->assertSame(1, preg_match('/<input\b(?:(?!<).)*?'.$label.'/s', $html), "{$key} at {$value} renders a number field");
                $this->assertSame(0, preg_match('/role="switch"(?:(?!<).)*?'.$label.'/s', $html), "{$key} at {$value} does not render a switch");
            }
        }
    }

    public function test_a_dial_saved_on_the_settings_page_reaches_the_rule(): void
    {
        $eligibility = app(KycSelfServeEligibility::class);

        Volt::test('admin.system-settings')
            ->call('updateSetting', KycSelfServeEligibility::MIN_TRANSACTIONS_KEY, '2')
            ->assertHasNoErrors();
        Cache::flush();

        $this->assertSame(2, $eligibility->minTransactions());

        Volt::test('admin.system-settings')
            ->call('updateSetting', KycSelfServeEligibility::MIN_TRANSACTIONS_KEY, '0')
            ->assertHasNoErrors();
        Cache::flush();

        $this->assertSame(0, $eligibility->minTransactions());
        $this->assertTrue($eligibility->isEligible($this->customer()), '0 switches the rule off');
    }

    /**
     * A row the old switch left as a word reads the way the switch showed
     * it: Off was 0, On was 1.
     */
    public function test_a_dial_left_on_or_off_by_the_old_switch_reads_as_it_showed(): void
    {
        $eligibility = app(KycSelfServeEligibility::class);

        SiteSetting::put(KycSelfServeEligibility::MIN_TRANSACTIONS_KEY, 'off', 'features');
        SiteSetting::put(KycSelfServeEligibility::MIN_TRANSACTION_USD_KEY, 'off', 'features');
        Cache::flush();

        $this->assertSame(0, $eligibility->minTransactions());
        $this->assertSame(0.0, $eligibility->minTransactionUsd());
        $this->assertTrue($eligibility->isEligible($this->customer()));

        SiteSetting::put(KycSelfServeEligibility::MIN_TRANSACTIONS_KEY, 'on', 'features');
        SiteSetting::put(KycSelfServeEligibility::MIN_TRANSACTION_USD_KEY, 'on', 'features');
        Cache::flush();

        $this->assertSame(1, $eligibility->minTransactions());
        $this->assertSame(1.0, $eligibility->minTransactionUsd());
        $this->assertFalse($eligibility->isEligible($this->customer()));
    }

    /**
     * The step-up door never reads this rule. A brand-new customer whose
     * first order is above the Tier 2 amount is asked to verify right there,
     * exactly as before, or they could neither pay nor verify.
     */
    public function test_the_checkout_step_up_ignores_the_rule(): void
    {
        SiteSetting::put('features.kyc_enforce_enabled', 'on', 'features');
        SiteSetting::put('features.kyc_tier2_threshold_usd', '500', 'features');
        Cache::flush();

        $fake = new class implements KycProvider
        {
            public function key(): string
            {
                return 'fake';
            }

            public function startVerification(User $user, ?string $issuingCountry = null): KycSession
            {
                return new KycSession('ref-1', token: 'tok-1', metadata: ['partner_id' => 'p', 'product' => 'biometric_kyc', 'environment' => 'sandbox']);
            }

            public function verdictFromWebhook(array $payload): ?KycVerdict
            {
                return null;
            }
        };
        $this->mock(KycProviderFactory::class, fn ($mock) => $mock->shouldReceive('forUser')->andReturn($fake));

        $user = $this->customer();
        $this->assertFalse(app(KycSelfServeEligibility::class)->isEligible($user));

        $this->expectException(KycStepUpRequiredException::class);

        app(KycGate::class)->guard($user, 600, 'order');
    }

    public function test_the_page_still_offers_verify_now_to_a_customer_who_has_not_transacted(): void
    {
        $this->actingAs($this->customer())->get('/dashboard/kyc')
            ->assertOk()
            ->assertSee('Verify now');
    }
}
