<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Jobs\ExpireStalePaymentSessionsJob;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Exceptions\WalletFundingException;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Jobs\VerifyPaymentJob;
use App\Models\CurrencyRate;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A crypto wallet funding must never be written off by the clock while the
 * customer's coins are real.
 *
 * The expiry sweep failed any funding still unpaid at thirty minutes. Bitcoin
 * routinely needs longer than that to confirm, so a customer who had sent
 * their coins was told the top-up died, and then every recovery path refused
 * the money when it landed: VerifyPaymentJob returned early on Failed, the
 * reconciler excluded Failed and Expired attempts, and the funding service
 * threw on a Failed funding. Three doors, all shut, real money at the gateway.
 */
class CryptoFundingStrandingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        // A real key takes the provider out of mock mode so Http::fake answers.
        config(['services.nowpayments.api_key' => 'live-key-xyz']);

        $this->user = User::factory()->create();
        $this->wallet = Wallet::create([
            'user_id' => $this->user->id,
            'currency' => Currency::USD,
            'balance' => 100.0,
        ]);

        CurrencyRate::updateOrCreate(['code' => 'USD'], [
            'name' => 'United States Dollar', 'type' => 'fiat', 'rate_per_usd' => 1.0, 'is_active' => true,
        ]);
    }

    /**
     * A crypto funding whose deposit window closed forty minutes ago.
     *
     * @return array{0: WalletFunding, 1: PaymentAttempt, 2: PaymentSession}
     */
    private function staleCryptoFunding(string $lastGatewayStatus = 'waiting', PaymentStatus $attemptStatus = PaymentStatus::Pending, ?float $actuallyPaid = null): array
    {
        $reference = 'FUND-'.Str::upper(Str::random(10));

        $funding = WalletFunding::create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'reference' => $reference,
            'currency' => 'USD',
            'display_currency' => 'USD',
            'amount' => 50,
            'requested_amount' => 50,
            'settled_amount_usd' => 50,
            'exchange_rate_snapshot' => 1.0,
            'gateway' => 'nowpayments',
            'status' => FundingStatus::Pending,
            'idempotency_key' => 'fund_init_'.Str::random(16),
        ]);

        $attempt = PaymentAttempt::create([
            'user_id' => $this->user->id,
            'payable_type' => WalletFunding::class,
            'payable_id' => $funding->id,
            'gateway' => 'nowpayments',
            'gateway_reference' => '5519782510',
            'idempotency_key' => $reference,
            'currency' => 'USD',
            'amount' => 50,
            'pay_currency' => 'usdttrc20',
            'pay_amount' => 52.5,
            'actually_paid' => $actuallyPaid,
            'exchange_rate_snapshot' => 1.0,
            'payment_status' => $attemptStatus,
            'metadata' => ['crypto' => ['status' => $lastGatewayStatus]],
        ]);

        $session = PaymentSession::create([
            'payment_attempt_id' => $attempt->id,
            'provider' => 'nowpayments',
            'session_type' => 'crypto',
            'status' => 'awaiting_transfer',
            'client_reference' => 'SESS_'.Str::random(40),
            'provider_reference' => '5519782510',
            'amount' => 50,
            'currency' => 'USD',
            'display_currency' => 'USD',
            'exchange_rate_snapshot' => 1.0,
            'payment_payload' => ['pay_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'],
            'checkout_context' => ['wallet_funding_reference' => $reference],
            'customer_email' => $this->user->email,
            'expires_at' => now()->subMinutes(20),
        ]);
        $session->forceFill(['created_at' => now()->subMinutes(40)])->save();

        return [$funding, $attempt, $session];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function gatewaySays(string $status, array $extra = []): void
    {
        Http::fake(['*' => Http::response(array_merge([
            'payment_id' => '5519782510',
            'payment_status' => $status,
            'pay_amount' => 52.5,
            'actually_paid' => 0,
            'pay_currency' => 'usdttrc20',
        ], $extra), 200)]);
    }

    public function test_the_sweep_extends_a_funding_whose_coins_are_still_confirming(): void
    {
        [$funding, $attempt, $session] = $this->staleCryptoFunding();
        $this->gatewaySays('confirming');

        dispatch_sync(new ExpireStalePaymentSessionsJob);

        $this->assertSame(FundingStatus::Pending, $funding->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->payment_status);
        $this->assertSame('awaiting_transfer', $session->fresh()->status);
        $this->assertTrue($session->fresh()->expires_at->isFuture(), 'The window should have been pushed out, not closed.');
    }

    public function test_the_sweep_still_expires_a_funding_nobody_ever_paid(): void
    {
        [$funding, $attempt, $session] = $this->staleCryptoFunding();
        $this->gatewaySays('waiting');

        dispatch_sync(new ExpireStalePaymentSessionsJob);

        // No deposit was ever seen: this IS abandonment, and closes as before.
        $this->assertSame(FundingStatus::Failed, $funding->fresh()->status);
        $this->assertSame('expired', $session->fresh()->status);
    }

    public function test_the_sweep_never_fails_a_funding_that_settled_short(): void
    {
        [$funding, $attempt, $session] = $this->staleCryptoFunding('partially_paid', PaymentStatus::PartiallyPaid, 40.0);
        $this->gatewaySays('partially_paid', ['actually_paid' => 40.0]);

        // Well past any extension: the window closes, the money does not.
        $session->forceFill(['created_at' => now()->subHours(30)])->save();

        dispatch_sync(new ExpireStalePaymentSessionsJob);

        $this->assertSame('expired', $session->fresh()->status);
        $this->assertSame(FundingStatus::Pending, $funding->fresh()->status);
        $this->assertSame(PaymentStatus::PartiallyPaid, $attempt->fresh()->payment_status);
    }

    public function test_a_late_gateway_finish_credits_a_funding_the_sweep_had_failed(): void
    {
        // The sweep ran while the gateway still said `waiting`; the customer's
        // coins landed afterwards and NOWPayments sent `finished`. The IPN
        // dispatches VerifyPaymentJob, which used to return early on Failed.
        [$funding, $attempt] = $this->staleCryptoFunding('waiting', PaymentStatus::Failed);
        $funding->update(['status' => FundingStatus::Failed, 'failed_reason' => 'session_expired']);

        $this->gatewaySays('finished', ['actually_paid' => 52.5, 'outcome_amount' => 50.0, 'outcome_currency' => 'usdttrc20']);

        dispatch_sync(new VerifyPaymentJob($attempt));

        $funding->refresh();
        $this->assertSame(FundingStatus::Completed, $funding->status);
        $this->assertNull($funding->failed_reason);
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
        $this->assertEqualsWithDelta(150.0, (float) $this->wallet->fresh()->balance, 0.0001);
    }

    public function test_the_reconciler_recovers_a_funding_the_sweep_had_failed(): void
    {
        [$funding, $attempt] = $this->staleCryptoFunding('waiting', PaymentStatus::Failed);
        $funding->update(['status' => FundingStatus::Failed, 'failed_reason' => 'session_expired']);

        $this->gatewaySays('finished', ['actually_paid' => 52.5, 'outcome_amount' => 50.0]);

        $this->artisan('reconcile:nowpayments', ['--hours' => 48])
            ->expectsOutputToContain('recovered')
            ->assertExitCode(0);

        $this->assertSame(FundingStatus::Completed, $funding->fresh()->status);
        $this->assertEqualsWithDelta(150.0, (float) $this->wallet->fresh()->balance, 0.0001);
    }

    public function test_a_funding_the_gateway_itself_expired_stays_closed(): void
    {
        // The gateway's own verdict is final; nothing to poll for.
        [$funding, $attempt] = $this->staleCryptoFunding('expired', PaymentStatus::Expired);
        $funding->update(['status' => FundingStatus::Failed]);

        Http::fake();

        $this->artisan('reconcile:nowpayments', ['--hours' => 48])
            ->expectsOutputToContain('No pending NOWPayments attempts')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(FundingStatus::Failed, $funding->fresh()->status);
    }

    public function test_a_failed_funding_is_not_credited_when_the_gateway_still_says_unpaid(): void
    {
        [$funding, $attempt] = $this->staleCryptoFunding('waiting', PaymentStatus::Failed);
        $funding->update(['status' => FundingStatus::Failed]);

        $this->gatewaySays('waiting');

        dispatch_sync(new VerifyPaymentJob($attempt));

        $this->assertSame(FundingStatus::Failed, $funding->fresh()->status);
        $this->assertEqualsWithDelta(100.0, (float) $this->wallet->fresh()->balance, 0.0001);
    }

    public function test_a_failed_fiat_funding_still_refuses_a_replayed_webhook(): void
    {
        // The guard that stops a replayed Flutterwave webhook resurrecting a
        // failed funding is untouched: the exception is crypto only.
        $funding = WalletFunding::create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'reference' => 'FUND-FIAT',
            'currency' => 'USD',
            'display_currency' => 'USD',
            'amount' => 50,
            'requested_amount' => 50,
            'settled_amount_usd' => 50,
            'exchange_rate_snapshot' => 1.0,
            'gateway' => 'flutterwave',
            'status' => FundingStatus::Failed,
            'idempotency_key' => 'fund_init_fiat',
        ]);

        $this->expectException(WalletFundingException::class);

        app(WalletFundingService::class)->processSuccessfulFunding('FUND-FIAT', 'FLW-1', []);
    }

    public function test_recovery_never_credits_twice(): void
    {
        [$funding, $attempt] = $this->staleCryptoFunding('waiting', PaymentStatus::Failed);
        $funding->update(['status' => FundingStatus::Failed]);

        $this->gatewaySays('finished', ['actually_paid' => 52.5, 'outcome_amount' => 50.0]);

        dispatch_sync(new VerifyPaymentJob($attempt));
        dispatch_sync(new VerifyPaymentJob($attempt));
        app(WalletFundingService::class)->processSuccessfulFunding($funding->reference, '5519782510', []);

        $this->assertEqualsWithDelta(150.0, (float) $this->wallet->fresh()->balance, 0.0001);
    }
}
