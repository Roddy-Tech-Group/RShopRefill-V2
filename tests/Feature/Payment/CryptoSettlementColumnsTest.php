<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\NowPaymentsProvider;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Crypto settlement facts must be queryable columns, not JSON.
 *
 * They previously lived only inside payment_attempts.metadata, which meant we could
 * not reconcile our ledger against a gateway settlement export in SQL, could not
 * report on which coins customers actually use, and could not find historical
 * overpayments at all.
 */
class CryptoSettlementColumnsTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(): PaymentAttempt
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'payment_method' => 'crypto',
        ]);

        return PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'nowpayments',
            'gateway_reference' => '5519782510',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'USD',
            'amount' => 25,
        ]);
    }

    public function test_creating_a_payment_populates_the_crypto_columns(): void
    {
        config(['services.nowpayments.api_key' => 'live-key-xyz']);
        $attempt = $this->attempt();

        Http::fake([
            '*' => Http::response([
                'payment_id' => '9911223344',
                'pay_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                'pay_amount' => 25.4831,
                'pay_currency' => 'usdttrc20',
                'network' => 'tron',
                'payin_extra_id' => null,
                'purchase_id' => '777888',
                'expiration_estimate_date' => now()->addMinutes(20)->toIso8601String(),
            ], 200),
        ]);

        (new NowPaymentsProvider)->chargeCrypto($attempt, 'usdttrc20');

        $fresh = $attempt->fresh();
        $this->assertSame('usdttrc20', $fresh->pay_currency);
        $this->assertSame(25.4831, (float) $fresh->pay_amount);
        $this->assertSame('tron', $fresh->network);
        $this->assertSame('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', $fresh->pay_address);
        $this->assertSame('777888', $fresh->purchase_id);
    }

    public function test_verification_records_settlement_delta_for_an_overpayment(): void
    {
        config(['services.nowpayments.api_key' => 'live-key-xyz']);
        $attempt = $this->attempt();

        Http::fake([
            '*' => Http::response([
                'payment_id' => 5519782510,
                'payment_status' => 'finished',
                'pay_amount' => 100,
                'actually_paid' => 112.5,
                'outcome_amount' => 111.2,
                'outcome_currency' => 'usdttrc20',
                'fee' => ['currency' => 'usdttrc20', 'serviceFee' => 1.1],
            ], 200),
        ]);

        $this->assertTrue((new NowPaymentsProvider)->verifyPayment($attempt));

        $fresh = $attempt->fresh();
        $this->assertSame(12.5, (float) $fresh->settlement_delta);
        $this->assertTrue($fresh->isOverpaid());
        $this->assertFalse($fresh->isUnderpaid());
        $this->assertSame(111.2, (float) $fresh->outcome_amount);
        $this->assertSame(1.1, (float) data_get($fresh->gateway_fee, 'serviceFee'));
    }

    /**
     * An unpaid payment reports actually_paid = 0. Measuring a delta against that
     * produced a full-value negative, which surfaced to the customer as "your
     * payment was short by the entire amount" on a payment they had not made yet.
     * Not-paid-yet and paid-too-little are different states.
     */
    public function test_an_unpaid_payment_is_not_treated_as_underpaid(): void
    {
        config(['services.nowpayments.api_key' => 'live-key-xyz']);
        $attempt = $this->attempt();

        Http::fake([
            '*' => Http::response([
                'payment_id' => 5519782510,
                'payment_status' => 'waiting',
                'pay_amount' => 11.357256,
                'actually_paid' => 0,
            ], 200),
        ]);

        $this->assertFalse((new NowPaymentsProvider)->verifyPayment($attempt));

        $fresh = $attempt->fresh();
        $this->assertNull($fresh->settlement_delta);
        $this->assertFalse($fresh->isUnderpaid());
        $this->assertFalse($fresh->hasSettled());
        // Still awaiting funds — not a shortfall to recover from.
        $this->assertSame(PaymentStatus::Pending, $fresh->payment_status);
    }

    /** The same must hold while the transaction is still confirming on-chain. */
    public function test_a_confirming_payment_is_not_treated_as_underpaid(): void
    {
        config(['services.nowpayments.api_key' => 'live-key-xyz']);
        $attempt = $this->attempt();

        Http::fake([
            '*' => Http::response([
                'payment_id' => 5519782510,
                'payment_status' => 'confirming',
                'pay_amount' => 11.357256,
                'actually_paid' => 0,
            ], 200),
        ]);

        (new NowPaymentsProvider)->verifyPayment($attempt);

        $this->assertFalse($attempt->fresh()->isUnderpaid());
    }

    /**
     * The sandbox reports a settled payment with actually_paid = 0, so a strict
     * comparison makes every simulated success look like a total underpayment and
     * the settlement path can never be exercised before launch.
     */
    public function test_sandbox_accepts_a_settled_payment_without_actually_paid(): void
    {
        config([
            'services.nowpayments.api_key' => 'live-key-xyz',
            'services.nowpayments.sandbox' => true,
        ]);
        $attempt = $this->attempt();

        Http::fake([
            '*' => Http::response([
                'payment_id' => 5519782510,
                'payment_status' => 'finished',
                'pay_amount' => 11.349974,
                'actually_paid' => 0,
                'outcome_amount' => 11.20350243,
                'outcome_currency' => 'usdtbsc',
            ], 200),
        ]);

        $this->assertTrue((new NowPaymentsProvider)->verifyPayment($attempt));
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
    }

    /**
     * The same payload in production must NOT settle. A settled payment there
     * always carries actually_paid, so its absence means something is wrong and
     * the order must be held rather than fulfilled on an unverifiable amount.
     */
    public function test_production_rejects_a_settled_payment_without_actually_paid(): void
    {
        config([
            'services.nowpayments.api_key' => 'live-key-xyz',
            'services.nowpayments.sandbox' => false,
        ]);
        $attempt = $this->attempt();

        Http::fake([
            '*' => Http::response([
                'payment_id' => 5519782510,
                'payment_status' => 'finished',
                'pay_amount' => 11.349974,
                'actually_paid' => 0,
                'outcome_amount' => 11.20350243,
            ], 200),
        ]);

        $this->assertFalse((new NowPaymentsProvider)->verifyPayment($attempt));
        $this->assertSame(PaymentStatus::PartiallyPaid, $attempt->fresh()->payment_status);
    }

    /** The sandbox allowance must never rescue a genuine shortfall. */
    public function test_sandbox_allowance_does_not_mask_a_real_shortfall(): void
    {
        config([
            'services.nowpayments.api_key' => 'live-key-xyz',
            'services.nowpayments.sandbox' => true,
        ]);
        $attempt = $this->attempt();

        Http::fake([
            '*' => Http::response([
                'payment_id' => 5519782510,
                'payment_status' => 'finished',
                'pay_amount' => 100,
                'actually_paid' => 96,
                'outcome_amount' => 95.4,
            ], 200),
        ]);

        $this->assertFalse((new NowPaymentsProvider)->verifyPayment($attempt));
        $this->assertSame(PaymentStatus::PartiallyPaid, $attempt->fresh()->payment_status);
    }

    public function test_verification_records_a_negative_delta_for_a_shortfall(): void
    {
        config(['services.nowpayments.api_key' => 'live-key-xyz']);
        $attempt = $this->attempt();

        Http::fake([
            '*' => Http::response([
                'payment_id' => 5519782510,
                'payment_status' => 'partially_paid',
                'pay_amount' => 50,
                'actually_paid' => 49.4,
            ], 200),
        ]);

        $this->assertFalse((new NowPaymentsProvider)->verifyPayment($attempt));

        $fresh = $attempt->fresh();
        $this->assertTrue($fresh->isUnderpaid());
        $this->assertEqualsWithDelta(-0.6, (float) $fresh->settlement_delta, 0.0000001);
        $this->assertSame(PaymentStatus::PartiallyPaid, $fresh->payment_status);
    }

    /**
     * NOWPayments publishes no idempotency key, so a double-click would otherwise
     * create a second live invoice and orphan whichever address went unpaid.
     */
    public function test_a_second_charge_reuses_the_live_quote_instead_of_creating_another(): void
    {
        config(['services.nowpayments.api_key' => 'live-key-xyz']);
        $attempt = $this->attempt();

        Http::fake([
            '*' => Http::response([
                'payment_id' => '9911223344',
                'pay_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                'pay_amount' => 25.4831,
                'pay_currency' => 'usdttrc20',
                'network' => 'tron',
                'expiration_estimate_date' => now()->addMinutes(20)->toIso8601String(),
            ], 200),
        ]);

        $provider = new NowPaymentsProvider;
        $first = $provider->chargeCrypto($attempt, 'usdttrc20');
        $second = $provider->chargeCrypto($attempt->fresh(), 'usdttrc20');

        $this->assertSame($first['pay_address'], $second['pay_address']);
        $this->assertSame($first['gateway_reference'], $second['gateway_reference']);
        Http::assertSentCount(1);
    }

    /** Switching coin is a genuinely different quote and must not be deduped. */
    public function test_switching_coin_creates_a_new_payment(): void
    {
        config(['services.nowpayments.api_key' => 'live-key-xyz']);
        $attempt = $this->attempt();

        Http::fake([
            '*' => Http::response([
                'payment_id' => '9911223344',
                'pay_address' => 'addr',
                'pay_amount' => 25,
                'network' => 'tron',
                'expiration_estimate_date' => now()->addMinutes(20)->toIso8601String(),
            ], 200),
        ]);

        $provider = new NowPaymentsProvider;
        $provider->chargeCrypto($attempt, 'usdttrc20');
        $provider->chargeCrypto($attempt->fresh(), 'usdtbsc');

        Http::assertSentCount(2);
    }

    /** A lapsed quote was priced against a rate that has since moved. */
    public function test_an_expired_quote_is_not_reused(): void
    {
        config(['services.nowpayments.api_key' => 'live-key-xyz']);
        $attempt = $this->attempt();
        $attempt->forceFill([
            'pay_currency' => 'usdttrc20',
            'pay_address' => 'stale-address',
            'pay_amount' => 10,
            'network' => 'tron',
            'expires_at' => now()->subMinute(),
        ])->save();

        Http::fake([
            '*' => Http::response([
                'payment_id' => 'fresh-id',
                'pay_address' => 'fresh-address',
                'pay_amount' => 11,
                'network' => 'tron',
                'expiration_estimate_date' => now()->addMinutes(20)->toIso8601String(),
            ], 200),
        ]);

        $result = (new NowPaymentsProvider)->chargeCrypto($attempt, 'usdttrc20');

        $this->assertSame('fresh-address', $result['pay_address']);
        Http::assertSentCount(1);
    }
}
