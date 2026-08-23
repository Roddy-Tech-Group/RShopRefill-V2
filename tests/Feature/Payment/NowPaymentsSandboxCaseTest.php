<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\NowPaymentsProvider;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Services\WalletService;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The sandbox outcome simulator (`case`) is whitelisted before being sent:
 * NOWPayments' sandbox 400s the ENTIRE payment creation on an unknown value
 * (the near-miss "fail" instead of "failed" broke every crypto payment), so an
 * invalid configured case is dropped with a warning instead of forwarded.
 */
class NowPaymentsSandboxCaseTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(): PaymentAttempt
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::USD);

        $funding = WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'FUND-'.Str::upper(Str::random(10)),
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

        return PaymentAttempt::create([
            'user_id' => $user->id,
            'payable_type' => WalletFunding::class,
            'payable_id' => $funding->id,
            'gateway' => 'nowpayments',
            'idempotency_key' => $funding->reference,
            'currency' => 'USD',
            'amount' => 50,
            'exchange_rate_snapshot' => 1.0,
            'payment_status' => PaymentStatus::Pending,
        ]);
    }

    private function charge(string $configuredCase): array
    {
        config([
            'services.nowpayments.api_key' => 'NP_TEST_KEY',
            'services.nowpayments.sandbox' => true,
            'services.nowpayments.sandbox_case' => $configuredCase,
            'services.payment_mock' => false,
        ]);

        Http::fake(['*' => Http::response([
            'payment_id' => 123,
            'pay_address' => 'addr',
            'pay_amount' => 0.001,
            'pay_currency' => 'btc',
            'payment_status' => 'waiting',
        ], 200)]);

        app(NowPaymentsProvider::class)->chargeCrypto($this->attempt(), 'BTC');

        $payload = [];
        Http::assertSent(function ($request) use (&$payload) {
            $payload = $request->data();

            return true;
        });

        return $payload;
    }

    public function test_a_valid_case_is_forwarded(): void
    {
        $payload = $this->charge('success');

        $this->assertSame('success', $payload['case'] ?? null);
    }

    public function test_an_invalid_case_is_dropped_not_forwarded(): void
    {
        $payload = $this->charge('fail'); // the near-miss that broke payments

        $this->assertArrayNotHasKey('case', $payload);
    }
}
