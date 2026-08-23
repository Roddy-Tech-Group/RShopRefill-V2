<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Exceptions\GatewayDisabledException;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Exceptions\WalletFundingException;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Models\CurrencyRate;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Wallet funding rides the same fiat failover as checkout: a top-up started
 * during a Flutterwave outage stamps the live backup (Paystack, then Kora),
 * charges through its hosted redirect, settles through its webhook, and a
 * full fiat outage refuses before any write. In-flight fundings always land
 * on the gateway they started on.
 */
class WalletFundingFailoverTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK']);

        $this->user = User::factory()->create();
        $this->wallet = Wallet::create([
            'user_id' => $this->user->id,
            'currency' => Currency::NGN,
            'balance' => 0.00,
            'locked_balance' => 0.00,
            'is_active' => true,
        ]);

        CurrencyRate::create([
            'code' => 'NGN',
            'name' => 'Nigerian Naira',
            'type' => 'fiat',
            'rate_per_usd' => 1500.0,
            'is_active' => true,
        ]);
    }

    private function setGateway(string $gateway, bool $on): void
    {
        SiteSetting::put("features.gateway_{$gateway}_enabled", $on ? 'on' : 'off', 'features');
    }

    /**
     * @return array{funding: WalletFunding, payment_session: PaymentSession}
     */
    private function startFunding(float $amount = 5000.0): array
    {
        return app(WalletFundingService::class)->initializeFunding(
            $this->user,
            $this->wallet,
            $amount,
            Currency::NGN,
        );
    }

    public function test_funding_during_failover_stamps_the_live_backup_everywhere(): void
    {
        $this->setGateway('flutterwave', false);
        $this->setGateway('paystack', true);

        $result = $this->startFunding();

        $this->assertSame('paystack', $result['funding']->gateway);
        $attempt = PaymentAttempt::where('payable_id', $result['funding']->id)->first();
        $this->assertSame('paystack', $attempt->gateway);
        $this->assertSame('paystack', $result['payment_session']->provider);
    }

    public function test_funding_falls_to_kora_when_it_is_the_only_live_gateway(): void
    {
        $this->setGateway('flutterwave', false);
        $this->setGateway('korapay', true);

        $result = $this->startFunding();

        $this->assertSame('korapay', $result['funding']->gateway);
    }

    public function test_funding_stays_on_flutterwave_while_it_is_enabled(): void
    {
        $this->setGateway('paystack', true);
        $this->setGateway('korapay', true);

        $result = $this->startFunding();

        $this->assertSame('flutterwave', $result['funding']->gateway, 'backups must never take funding traffic while the primary is on');
    }

    /**
     * The kill switch must refuse on the SERVER, not just hide the button:
     * with the flag off, a replayed API call used to open a funding anyway.
     */
    public function test_the_wallet_funding_kill_switch_refuses_new_fundings_server_side(): void
    {
        SiteSetting::put('features.wallet_funding_enabled', 'off', 'features');

        $this->expectException(WalletFundingException::class);

        try {
            $this->startFunding();
        } finally {
            $this->assertSame(0, WalletFunding::count(), 'a refused funding must cost zero rows');
        }
    }

    /**
     * Under enforcement, "fiat is alive but none of it is approved to fund
     * wallets" must fall back to crypto exactly like a fiat outage does when
     * crypto IS approved. The fallback used to catch only the outage
     * exception, so this combination wrongly refused funding outright.
     */
    public function test_funding_falls_to_crypto_when_fiat_is_live_but_not_approved_for_wallets(): void
    {
        // Enforcement on, and the ONLY approved wallet cell is crypto's.
        // Flutterwave stays live but unapproved for the wallet category.
        SiteSetting::put('features.payment_eligibility_enforce_enabled', 'on', 'features');
        SiteSetting::put('features.eligibility_nowpayments_wallet_enabled', 'on', 'features');

        $result = $this->startFunding();

        $this->assertSame('nowpayments', $result['funding']->gateway, 'crypto must carry the funding when it is the only approved rail');
    }

    public function test_a_full_fiat_outage_refuses_funding_before_any_write(): void
    {
        // A TRUE full outage: fiat dark AND crypto dark. With crypto alive,
        // funding deliberately survives on the nowpayments rail (pinned in
        // GatewayKillSwitchTest), so the refusal case must kill both.
        $this->setGateway('flutterwave', false);
        $this->setGateway('nowpayments', false);

        $this->expectException(GatewayDisabledException::class);

        try {
            $this->startFunding();
        } finally {
            $this->assertSame(0, WalletFunding::count(), 'a refused funding must cost zero rows');
        }
    }

    /**
     * The full settlement chain on a backup: signed Paystack webhook →
     * VerifyPaymentJob (sync in tests) → gateway verify → funding credited.
     */
    public function test_a_paystack_funding_webhook_credits_the_wallet(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_realish']);
        $this->setGateway('flutterwave', false);
        $this->setGateway('paystack', true);

        $result = $this->startFunding(5000.0);
        $funding = $result['funding'];
        $attempt = PaymentAttempt::where('payable_id', $funding->id)->first();

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'reference' => $attempt->idempotency_key,
                    'status' => 'success',
                    'amount' => (int) round(((float) $attempt->amount) * 100),
                    'currency' => 'NGN',
                    'id' => 777001,
                ],
            ], 200),
        ]);

        $body = json_encode([
            'event' => 'charge.success',
            'data' => [
                'reference' => $attempt->idempotency_key,
                'status' => 'success',
                'amount' => (int) round(((float) $attempt->amount) * 100),
                'currency' => 'NGN',
                'id' => 777001,
            ],
        ]);

        $response = $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_realish'),
        ], $body);

        $response->assertOk();
        $this->assertSame(FundingStatus::Completed, $funding->fresh()->status, 'the webhook must settle the funding');
        $this->assertSame(5000.0, (float) $this->wallet->fresh()->balance, 'the wallet must be credited the funded amount');
    }

    public function test_the_hosted_return_leg_credits_and_lands_on_the_wallet_page(): void
    {
        // Mock secret: the provider verify marks the attempt paid locally.
        $this->setGateway('flutterwave', false);
        $this->setGateway('korapay', true);

        $result = $this->startFunding(5000.0);
        $session = $result['payment_session'];

        $response = $this->actingAs($this->user)
            ->get("/checkout/return/{$session->id}?reference=".$result['funding']->reference);

        $response->assertRedirect(route('dashboard.wallet'));
        $this->assertSame(FundingStatus::Completed, $result['funding']->fresh()->status);
        $this->assertSame(5000.0, (float) $this->wallet->fresh()->balance);
    }

    public function test_an_in_flight_flutterwave_funding_still_settles_during_failover(): void
    {
        // USD wallet: the mock Flutterwave verification reports a fixed
        // 100 USD success, so the funding must be a USD amount under it.
        $usdWallet = Wallet::create([
            'user_id' => $this->user->id,
            'currency' => Currency::USD,
            'balance' => 0.00,
            'locked_balance' => 0.00,
            'is_active' => true,
        ]);
        CurrencyRate::create([
            'code' => 'USD',
            'name' => 'United States Dollar',
            'type' => 'fiat',
            'rate_per_usd' => 1.0,
            'is_active' => true,
        ]);

        $result = app(WalletFundingService::class)->initializeFunding($this->user, $usdWallet, 50.0, Currency::USD);
        $this->assertSame('flutterwave', $result['funding']->gateway);

        // The outage begins after the customer already initiated.
        $this->setGateway('flutterwave', false);
        $this->setGateway('paystack', true);

        // Verification runs against the funding's OWN gateway, not the
        // failover target, and the wallet credits exactly once.
        app(WalletFundingService::class)->processSuccessfulFunding(
            $result['funding']->reference,
            'FLW-LATE-123',
        );

        $this->assertSame(FundingStatus::Completed, $result['funding']->fresh()->status);
        $this->assertSame(50.0, (float) $usdWallet->fresh()->balance);
    }
}
