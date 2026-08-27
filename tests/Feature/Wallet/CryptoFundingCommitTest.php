<?php

namespace Tests\Feature\Wallet;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Support\FundingMethodCatalog;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Services\WalletService;
use App\Models\CurrencyRate;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Crypto wallet funding writes nothing until the customer commits to a coin.
 *
 * The funding sheet used to mint a funding, an attempt and a session the
 * moment an amount was typed, then learn the method and the coin afterwards.
 * Every abandoned amount became a Pending deposit in the customer's history,
 * a "your top-up failed" email thirty minutes later, and a risk assessment.
 * These tests pin the new shape: the amount screen and the method screen are
 * write-free, the first row appears together with a deposit address, and a
 * gateway refusal leaves no trace.
 */
class CryptoFundingCommitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock NOWPayments: no key in testing means the provider fakes a quote.
        config(['services.nowpayments.api_key' => null]);
        config(['services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK']);

        $this->user = User::factory()->create();

        // updateOrCreate: the crypto-launch migration already seeds these codes.
        CurrencyRate::updateOrCreate(['code' => 'USD'], [
            'name' => 'United States Dollar', 'type' => 'fiat', 'rate_per_usd' => 1.0, 'is_active' => true,
        ]);
        CurrencyRate::updateOrCreate(['code' => 'USDTTRC20'], [
            'name' => 'Tether (Tron)', 'type' => 'crypto', 'rate_per_usd' => 1.0,
            'is_active' => true, 'is_payable' => true, 'network' => 'tron', 'min_amount_usd' => 10.0,
        ]);
    }

    private function fiatDark(): void
    {
        SiteSetting::put('features.gateway_flutterwave_enabled', 'off', 'features');
        SiteSetting::put('features.gateway_paystack_enabled', 'off', 'features');
        SiteSetting::put('features.gateway_korapay_enabled', 'off', 'features');
    }

    private function assertNothingWritten(): void
    {
        $this->assertDatabaseCount('wallet_fundings', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('payment_sessions', 0);
    }

    public function test_continue_writes_nothing_and_hands_the_sheet_a_draft(): void
    {
        $this->actingAs($this->user);

        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '50')
            ->call('prepare')
            ->assertHasNoErrors()
            ->assertDispatched('funding-draft-ready');

        $this->assertNothingWritten();
    }

    public function test_with_fiat_dark_the_amount_screen_enforces_the_crypto_floor(): void
    {
        $this->fiatDark();
        $this->actingAs($this->user);

        // $5 clears the USD currency minimum ($2) but not the crypto floor
        // ($13); with crypto the only rail, it is refused right here.
        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '5')
            ->call('prepare')
            ->assertHasErrors(['amount'])
            ->assertNotDispatched('funding-draft-ready');

        $this->assertNothingWritten();
    }

    public function test_with_fiat_live_the_same_amount_passes_the_amount_screen(): void
    {
        $this->actingAs($this->user);

        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '5')
            ->call('prepare')
            ->assertHasNoErrors()
            ->assertDispatched('funding-draft-ready');
    }

    public function test_the_draft_greys_a_dark_rail_instead_of_dropping_it(): void
    {
        $this->fiatDark();

        $methods = collect(FundingMethodCatalog::forCurrency('USD'));

        $this->assertFalse($methods->firstWhere('type', 'card')['enabled']);
        $this->assertTrue($methods->firstWhere('type', 'crypto')['enabled']);
        $this->assertFalse(FundingMethodCatalog::hasLiveFiat('USD'));
        $this->assertSame(['Card and bank payments'], FundingMethodCatalog::downRails('USD'));
    }

    public function test_the_draft_lights_fiat_back_up_when_the_flag_flips(): void
    {
        $methods = collect(FundingMethodCatalog::forCurrency('USD'));

        $this->assertTrue($methods->firstWhere('type', 'card')['enabled']);
        $this->assertTrue(FundingMethodCatalog::hasLiveFiat('USD'));
        $this->assertSame([], FundingMethodCatalog::downRails('USD'));
    }

    public function test_the_crypto_tile_carries_the_crypto_floor(): void
    {
        $crypto = collect(FundingMethodCatalog::forCurrency('USD', '$13.00'))->firstWhere('type', 'crypto');

        $this->assertStringContainsString('$13.00', $crypto['description']);
    }

    public function test_picking_a_coin_creates_the_funding_with_its_address_in_one_step(): void
    {
        $this->actingAs($this->user);

        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '50')
            ->call('fundCrypto', 'usdttrc20')
            ->assertDispatched('payment-session-initialized');

        $this->assertDatabaseCount('wallet_fundings', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('payment_sessions', 1);

        $funding = WalletFunding::sole();
        $attempt = PaymentAttempt::sole();
        $session = PaymentSession::sole();

        $this->assertSame('nowpayments', $funding->gateway);
        $this->assertSame(FundingStatus::Pending, $funding->status);

        $this->assertSame('nowpayments', $attempt->gateway);
        $this->assertNotNull($attempt->gateway_reference);
        $this->assertNotNull($attempt->pay_address);
        $this->assertSame('crypto', $attempt->payment_method);

        // Born straight into the address screen, never a method-less limbo.
        $this->assertSame('awaiting_transfer', $session->status);
        $this->assertSame('nowpayments', $session->provider);
        $this->assertNotEmpty($session->payment_payload['pay_address'] ?? null);
    }

    public function test_an_unknown_coin_is_refused_and_leaves_no_trace(): void
    {
        $this->actingAs($this->user);

        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '50')
            ->call('fundCrypto', 'notacoin')
            ->assertDispatched('funding-error')
            ->assertNotDispatched('payment-session-initialized');

        $this->assertNothingWritten();
    }

    public function test_a_coin_below_its_own_minimum_is_refused_and_leaves_no_trace(): void
    {
        CurrencyRate::where('code', 'USDTTRC20')->update(['min_amount_usd' => 100.0]);
        $this->actingAs($this->user);

        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '50')
            ->call('fundCrypto', 'usdttrc20')
            ->assertDispatched('funding-error');

        $this->assertNothingWritten();
    }

    public function test_a_gateway_refusal_rolls_everything_back(): void
    {
        // A real key switches the provider out of mock mode; the gateway then
        // rejects the payment. The funding, attempt and session created around
        // that call must all roll back.
        config(['services.nowpayments.api_key' => 'live-key-xyz']);
        Http::fake(['*' => Http::response(['message' => 'MIN_AMOUNT_ERROR'], 400)]);

        $this->actingAs($this->user);

        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '50')
            ->call('fundCrypto', 'usdttrc20')
            ->assertDispatched('funding-error')
            ->assertNotDispatched('payment-session-initialized');

        $this->assertNothingWritten();
    }

    public function test_the_crypto_kill_switch_refuses_at_commit_and_leaves_no_trace(): void
    {
        SiteSetting::put('features.gateway_nowpayments_enabled', 'off', 'features');
        $this->actingAs($this->user);

        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '50')
            ->call('fundCrypto', 'usdttrc20')
            ->assertDispatched('funding-error');

        $this->assertNothingWritten();
    }

    public function test_the_risk_engine_only_sees_committed_fundings(): void
    {
        SiteSetting::put('features.risk_engine_enabled', 'on', 'features');
        $this->actingAs($this->user);

        $component = Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '50')
            ->call('prepare');

        // Typing an amount is not an attempt.
        $this->assertDatabaseCount('risk_assessments', 0);

        $component->call('fundCrypto', 'usdttrc20');

        $this->assertDatabaseCount('risk_assessments', 1);
    }

    public function test_a_fiat_tile_still_mints_the_funding_the_way_it_always_has(): void
    {
        $this->actingAs($this->user);

        // The sheet calls fund() when a card / bank tile is picked. Unchanged
        // path: funding + attempt + session, session awaiting a method.
        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '50')
            ->call('fund')
            ->assertHasNoErrors()
            ->assertDispatched('payment-session-initialized');

        $this->assertDatabaseCount('wallet_fundings', 1);
        $this->assertSame('awaiting_method', PaymentSession::sole()->status);
        $this->assertNotSame('nowpayments', WalletFunding::sole()->gateway);
    }

    public function test_a_never_started_failure_is_hidden_from_recent_deposits_but_a_real_one_shows(): void
    {
        $this->actingAs($this->user);
        $wallet = app(WalletService::class)->getOrCreateWallet($this->user, Currency::USD);

        $phantom = WalletFunding::create([
            'user_id' => $this->user->id, 'wallet_id' => $wallet->id, 'reference' => 'FUND-PHANTOM',
            'currency' => 'USD', 'amount' => 20, 'gateway' => 'nowpayments', 'status' => FundingStatus::Failed,
        ]);
        PaymentAttempt::create([
            'user_id' => $this->user->id, 'payable_type' => WalletFunding::class, 'payable_id' => $phantom->id,
            'gateway' => 'nowpayments', 'idempotency_key' => 'FUND-PHANTOM', 'currency' => 'USD', 'amount' => 20,
            'payment_status' => PaymentStatus::Expired,
        ]);

        $real = WalletFunding::create([
            'user_id' => $this->user->id, 'wallet_id' => $wallet->id, 'reference' => 'FUND-REAL',
            'currency' => 'USD', 'amount' => 20, 'gateway' => 'nowpayments', 'status' => FundingStatus::Failed,
        ]);
        PaymentAttempt::create([
            'user_id' => $this->user->id, 'payable_type' => WalletFunding::class, 'payable_id' => $real->id,
            'gateway' => 'nowpayments', 'idempotency_key' => 'FUND-REAL', 'currency' => 'USD', 'amount' => 20,
            'gateway_reference' => 'NP-123', 'payment_status' => PaymentStatus::Failed,
        ]);

        Volt::test('dashboard.transactions')
            ->assertViewHas('recentDeposits', function ($deposits) {
                $references = $deposits->pluck('reference')->all();

                return in_array('FUND-REAL', $references, true) && ! in_array('FUND-PHANTOM', $references, true);
            });
    }
}
