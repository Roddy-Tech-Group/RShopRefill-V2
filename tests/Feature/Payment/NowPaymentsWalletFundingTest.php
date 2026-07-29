<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Models\CurrencyRate;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression cover for the production blocker where a crypto wallet top-up could
 * never credit.
 *
 * FundingVerificationService only implemented Flutterwave; every other gateway
 * fell through to a log line and `false`, which the caller treats as a failed
 * funding. So the customer's coins landed, NOWPayments confirmed the payment, and
 * then our own code marked the deposit failed and never credited the wallet.
 * Retrying could not recover it either — the attempt was already Paid, so
 * VerifyPaymentJob short-circuited before reaching the funding path.
 *
 * The existing orchestration test stopped at `awaiting_transfer` and never
 * asserted a balance change, which is precisely why this went unnoticed. Every
 * test here asserts the money actually moves.
 */
class NowPaymentsWalletFundingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.nowpayments.api_key' => 'live-key-xyz']);

        $this->user = User::factory()->create();
        $this->wallet = Wallet::create([
            'user_id' => $this->user->id,
            'currency' => Currency::USD,
            'balance' => 100.0,
        ]);

        CurrencyRate::create([
            'code' => 'USD',
            'name' => 'United States Dollar',
            'type' => 'fiat',
            'rate_per_usd' => 1.0,
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: WalletFunding, 1: PaymentAttempt}
     */
    private function cryptoFunding(float $amount = 50.0, PaymentStatus $attemptStatus = PaymentStatus::Pending): array
    {
        $reference = 'FUND-'.Str::upper(Str::random(10));

        $funding = WalletFunding::create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'reference' => $reference,
            'currency' => Currency::USD->value,
            'display_currency' => 'USD',
            'amount' => $amount,
            'requested_amount' => $amount,
            'settled_amount_usd' => $amount,
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
            'amount' => $amount,
            'exchange_rate_snapshot' => 1.0,
            'payment_status' => $attemptStatus,
        ]);

        return [$funding, $attempt];
    }

    public function test_crypto_funding_credits_the_wallet(): void
    {
        [$funding] = $this->cryptoFunding(50.0);

        Http::fake([
            '*' => Http::response([
                'payment_id' => 5519782510,
                'payment_status' => 'finished',
                'pay_amount' => 50,
                'actually_paid' => 50,
            ], 200),
        ]);

        app(WalletFundingService::class)->processSuccessfulFunding($funding->reference, '5519782510');

        $this->assertSame(150.0, (float) $this->wallet->fresh()->balance);
        $this->assertSame(FundingStatus::Completed, $funding->fresh()->status);
    }

    /** An already-verified attempt must not require a second gateway round trip. */
    public function test_crypto_funding_credits_when_attempt_is_already_paid(): void
    {
        [$funding] = $this->cryptoFunding(25.0, PaymentStatus::Paid);

        Http::fake(['*' => Http::response([], 500)]);

        app(WalletFundingService::class)->processSuccessfulFunding($funding->reference, '5519782510');

        $this->assertSame(125.0, (float) $this->wallet->fresh()->balance);
        $this->assertSame(FundingStatus::Completed, $funding->fresh()->status);
        Http::assertNothingSent();
    }

    /** Crediting must be idempotent — a redelivered IPN cannot double the balance. */
    public function test_repeat_processing_does_not_double_credit(): void
    {
        [$funding] = $this->cryptoFunding(40.0, PaymentStatus::Paid);

        $service = app(WalletFundingService::class);
        $service->processSuccessfulFunding($funding->reference, '5519782510');
        $service->processSuccessfulFunding($funding->reference, '5519782510');

        $this->assertSame(140.0, (float) $this->wallet->fresh()->balance);
    }

    /** A short payment must never credit, however small the shortfall. */
    public function test_underpaid_crypto_funding_does_not_credit(): void
    {
        [$funding] = $this->cryptoFunding(50.0);

        Http::fake([
            '*' => Http::response([
                'payment_id' => 5519782510,
                'payment_status' => 'partially_paid',
                'pay_amount' => 50,
                'actually_paid' => 49.99,
            ], 200),
        ]);

        try {
            app(WalletFundingService::class)->processSuccessfulFunding($funding->reference, '5519782510');
        } catch (\Throwable) {
            // failFunding() raises after recording the failure; the balance is what matters.
        }

        $this->assertSame(100.0, (float) $this->wallet->fresh()->balance);
        $this->assertNotSame(FundingStatus::Completed, $funding->fresh()->status);
    }

    /**
     * failFunding() referenced App\Enums\PaymentStatus — a class that does not
     * exist — so it threw a fatal Error the moment a funding had an attempt
     * attached, rolling the transaction back and leaving the record on Pending
     * instead of Failed. Affected production records are therefore sitting in
     * Pending, which is what the remediation query has to look for.
     */
    public function test_failing_a_funding_marks_it_failed_instead_of_throwing(): void
    {
        [$funding, $attempt] = $this->cryptoFunding(30.0);

        app(WalletFundingService::class)->failFunding($funding, 'Gateway reported expiry.');

        $this->assertSame(FundingStatus::Failed, $funding->fresh()->status);
        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->payment_status);
        $this->assertSame(100.0, (float) $this->wallet->fresh()->balance);
    }
}
