<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\NowPaymentsProvider;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The dust rule: the one exception to zero underpayment tolerance.
 *
 * A shortfall that converts to under half a cent in the price currency is
 * absorbed and the payment settles. Below that line the top-up flow rounds
 * the balance to 0.00 and refuses to charge it, so holding the order would
 * strand the customer over an amount nobody can pay. The real case: order
 * RSR-20260908-F11KDB, quoted 12.360274 USDTBSC, customer sent 12.36, short
 * by 0.000274 USDT (about $0.0003), stuck with no exit.
 *
 * Everything at or above the line is held exactly as before. The ceiling is
 * absolute (half a cent per order, never a percentage), so it cannot grow
 * with the order size.
 */
class CryptoDustSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nowpayments.api_key' => 'live-key-xyz']);
    }

    private function attempt(float $price = 12.20, string $currency = 'USD'): PaymentAttempt
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'payment_method' => 'crypto',
            'payment_status' => PaymentStatus::Pending,
        ]);

        return PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'nowpayments',
            'gateway_reference' => (string) random_int(1000000000, 9999999999),
            'idempotency_key' => (string) Str::uuid(),
            'currency' => $currency,
            'amount' => $price,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function gatewayReports(array $overrides): void
    {
        Http::fake([
            '*' => Http::response(array_merge([
                'payment_id' => 5052290289,
                'payment_status' => 'partially_paid',
                'pay_currency' => 'usdtbsc',
                'purchase_id' => '5052290289',
            ], $overrides), 200),
        ]);
    }

    public function test_a_sub_cent_shortfall_settles_as_dust(): void
    {
        $attempt = $this->attempt(12.20);
        $this->gatewayReports(['pay_amount' => 12.36027436, 'actually_paid' => 12.36]);

        $this->assertTrue((new NowPaymentsProvider)->verifyPayment($attempt));

        $fresh = $attempt->fresh();
        $this->assertSame(PaymentStatus::Paid, $fresh->payment_status);
        $this->assertNotNull($fresh->confirmed_at);
        $this->assertTrue($fresh->settledAsDust());
        $this->assertTrue($fresh->wasSettledShort());

        // The shortfall is recorded, not erased.
        $this->assertLessThan(0, (float) $fresh->settlement_delta);
        $this->assertEqualsWithDelta(0.00027436, data_get($fresh->metadata, 'crypto.dust_settlement.shortfall_crypto'), 1e-8);
        $this->assertEqualsWithDelta(0.000271, data_get($fresh->metadata, 'crypto.dust_settlement.shortfall_fiat'), 1e-5);
        $this->assertSame('USD', data_get($fresh->metadata, 'crypto.dust_settlement.price_currency'));

        // And it is no longer an underpayment anyone should top up.
        $this->assertFalse($fresh->isUnderpaid());
    }

    public function test_dust_also_applies_when_the_gateway_status_is_finished_but_short(): void
    {
        $attempt = $this->attempt(12.20);
        $this->gatewayReports(['payment_status' => 'finished', 'pay_amount' => 12.36027436, 'actually_paid' => 12.36]);

        $this->assertTrue((new NowPaymentsProvider)->verifyPayment($attempt));
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
        $this->assertTrue($attempt->fresh()->settledAsDust());
    }

    /**
     * Exactly half a cent is NOT dust. round() would carry it to 0.01, so the
     * top-up can charge it, so the customer is held for it.
     */
    public function test_a_shortfall_of_exactly_half_a_cent_is_held(): void
    {
        $attempt = $this->attempt(100.00);
        $this->gatewayReports(['pay_amount' => 100, 'actually_paid' => 99.995]);

        $this->assertFalse((new NowPaymentsProvider)->verifyPayment($attempt));

        $fresh = $attempt->fresh();
        $this->assertSame(PaymentStatus::PartiallyPaid, $fresh->payment_status);
        $this->assertFalse($fresh->settledAsDust());
        $this->assertTrue($fresh->isUnderpaid());
    }

    public function test_a_two_cent_shortfall_is_held_for_top_up(): void
    {
        $attempt = $this->attempt(12.20);
        // 12.35 against 12.37 quoted: two cents short in fiat terms.
        $this->gatewayReports(['pay_amount' => 12.37, 'actually_paid' => 12.35]);

        $this->assertFalse((new NowPaymentsProvider)->verifyPayment($attempt));
        $this->assertSame(PaymentStatus::PartiallyPaid, $attempt->fresh()->payment_status);
        $this->assertTrue($attempt->fresh()->isUnderpaid());
    }

    /**
     * The ceiling is absolute. The same fiat gap is a large percentage of a
     * $1 order and a vanishing one of a $500 order; the rule looks only at
     * the fiat value of the gap.
     */
    public function test_half_a_percent_short_on_a_one_dollar_order_is_held(): void
    {
        // $0.005 exactly: the largest percentage the ceiling could ever
        // represent, and still not dust.
        $attempt = $this->attempt(1.00);
        $this->gatewayReports(['pay_amount' => 1.02, 'actually_paid' => 1.0149]);

        $this->assertFalse((new NowPaymentsProvider)->verifyPayment($attempt));
        $this->assertSame(PaymentStatus::PartiallyPaid, $attempt->fresh()->payment_status);
    }

    public function test_a_vanishing_percentage_on_a_large_order_is_still_capped_at_half_a_cent(): void
    {
        // 0.0009% short on a $500 order is $0.0045: dust, and the most any
        // order of any size could ever have absorbed is still under $0.005.
        $attempt = $this->attempt(500.00);
        $this->gatewayReports(['pay_amount' => 506.32, 'actually_paid' => 506.3154]);

        $this->assertTrue((new NowPaymentsProvider)->verifyPayment($attempt));
        $this->assertTrue($attempt->fresh()->settledAsDust());
        $this->assertLessThan(NowPaymentsProvider::DUST_CEILING, data_get($attempt->fresh()->metadata, 'crypto.dust_settlement.shortfall_fiat'));
    }

    /** Nothing received is not-paid-yet, never dust. */
    public function test_nothing_received_is_never_absorbed(): void
    {
        $attempt = $this->attempt(12.20);
        $this->gatewayReports(['pay_amount' => 12.36027436, 'actually_paid' => 0]);

        $this->assertFalse((new NowPaymentsProvider)->verifyPayment($attempt));
        $this->assertSame(PaymentStatus::PartiallyPaid, $attempt->fresh()->payment_status);
        $this->assertFalse($attempt->fresh()->settledAsDust());
    }

    /**
     * Prices in a currency the gateway does not quote in (XAF, NGN) are
     * converted to USD first, so the ceiling is half a US cent for them too.
     */
    public function test_the_ceiling_applies_in_usd_for_a_converted_price(): void
    {
        CurrencyRate::create([
            'code' => 'XAF',
            'name' => 'Central African CFA franc',
            'type' => 'fiat',
            'rate_per_usd' => 600,
            'is_active' => true,
        ]);

        // 10,000 XAF is $16.67; a 0.0003 USDT gap on a 17.4 USDT quote is
        // about $0.0003 of it.
        $attempt = $this->attempt(10000, 'XAF');
        $this->gatewayReports(['pay_amount' => 17.4003, 'actually_paid' => 17.40]);

        $this->assertTrue((new NowPaymentsProvider)->verifyPayment($attempt));
        $this->assertTrue($attempt->fresh()->settledAsDust());
        $this->assertSame('USD', data_get($attempt->fresh()->metadata, 'crypto.dust_settlement.price_currency'));
    }

    /**
     * The gateway reports partially_paid for this payment id forever. A
     * replayed IPN or the reconcile sweep must not demote a settled attempt.
     */
    public function test_a_later_verification_does_not_walk_a_dust_settlement_back(): void
    {
        $attempt = $this->attempt(12.20);
        $this->gatewayReports(['pay_amount' => 12.36027436, 'actually_paid' => 12.36]);

        $this->assertTrue((new NowPaymentsProvider)->verifyPayment($attempt));
        $this->assertTrue((new NowPaymentsProvider)->verifyPayment($attempt->fresh()));

        $fresh = $attempt->fresh();
        $this->assertSame(PaymentStatus::Paid, $fresh->payment_status);
        $this->assertTrue($fresh->settledAsDust());
    }

    /** The status poll must not offer the customer a top-up on a settled payment. */
    public function test_the_status_poll_offers_no_top_up_after_a_dust_settlement(): void
    {
        $attempt = $this->attempt(12.20);
        $this->gatewayReports(['pay_amount' => 12.36027436, 'actually_paid' => 12.36]);
        (new NowPaymentsProvider)->verifyPayment($attempt);

        $session = PaymentSession::create([
            'payment_attempt_id' => $attempt->id,
            'provider' => 'nowpayments',
            'session_type' => 'crypto',
            'status' => 'awaiting_transfer',
            'client_reference' => 'SESS_'.Str::random(40),
            'amount' => 12.20,
            'currency' => 'USD',
            'display_currency' => 'USD',
            'exchange_rate_snapshot' => 1.0,
            'payment_payload' => ['pay_address' => '0xdeadbeef'],
            'customer_email' => $attempt->user->email,
            'expires_at' => now()->addMinutes(20),
        ]);

        $this->actingAs($attempt->user)
            ->getJson(route('api.payment-sessions.status', $session->id))
            ->assertOk()
            ->assertJsonPath('underpayment', null);
    }

    public function test_the_settlement_audit_reports_dust_as_its_own_category(): void
    {
        $attempt = $this->attempt(12.20);
        $this->gatewayReports(['pay_amount' => 12.36027436, 'actually_paid' => 12.36]);
        (new NowPaymentsProvider)->verifyPayment($attempt);

        $this->assertSame(0, Artisan::call('crypto:settlements', ['--json' => true]));
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(1, $report['dust_settled_count']);
        $this->assertSame(0, $report['underpaid_count']);
        $this->assertSame('dust', $report['rows'][0]['resolution']);

        // A closed dust settlement is not an alert; only a held one is.
        $this->assertSame(0, Artisan::call('crypto:settlements'));
        $this->assertStringContainsString('1 dust-settled', Artisan::output());
    }

    /**
     * Valuing a shortfall in XAF or NGN needs a live rate. When the rate
     * service throws (no rate, or a stale one), verification must fail safe:
     * hold the payment as before, never crash, never absorb an amount that
     * could not be valued.
     */
    public function test_a_missing_rate_holds_the_payment_instead_of_crashing(): void
    {
        // No XAF rate of any kind exists.
        $attempt = $this->attempt(10000, 'XAF');
        $this->gatewayReports(['pay_amount' => 17.4003, 'actually_paid' => 17.40]);

        $this->assertFalse((new NowPaymentsProvider)->verifyPayment($attempt));

        $fresh = $attempt->fresh();
        $this->assertSame(PaymentStatus::PartiallyPaid, $fresh->payment_status);
        $this->assertFalse($fresh->settledAsDust());
        $this->assertSame(17.40, (float) $fresh->actually_paid);
    }

    /** The same rule protects a wallet deposit: dust short still credits in full. */
    public function test_a_wallet_funding_short_by_dust_credits_the_wallet_in_full(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => Currency::USD, 'balance' => 100.0]);
        CurrencyRate::create(['code' => 'USD', 'name' => 'United States Dollar', 'type' => 'fiat', 'rate_per_usd' => 1.0, 'is_active' => true]);

        $reference = 'FUND-'.Str::upper(Str::random(10));
        $funding = WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => $reference,
            'currency' => Currency::USD->value,
            'display_currency' => 'USD',
            'amount' => 50.0,
            'requested_amount' => 50.0,
            'settled_amount_usd' => 50.0,
            'exchange_rate_snapshot' => 1.0,
            'gateway' => 'nowpayments',
            'status' => FundingStatus::Pending,
            'idempotency_key' => 'fund_init_'.Str::random(16),
        ]);
        PaymentAttempt::create([
            'user_id' => $user->id,
            'payable_type' => WalletFunding::class,
            'payable_id' => $funding->id,
            'gateway' => 'nowpayments',
            'gateway_reference' => '5519782510',
            'idempotency_key' => $reference,
            'currency' => 'USD',
            'amount' => 50.0,
            'exchange_rate_snapshot' => 1.0,
        ]);

        // 50.6403 quoted, 50.64 received: $0.0003 short.
        $this->gatewayReports(['pay_amount' => 50.6403, 'actually_paid' => 50.64]);

        app(WalletFundingService::class)->processSuccessfulFunding($reference, '5519782510');

        $this->assertSame(150.0, (float) $wallet->fresh()->balance);
        $this->assertSame(FundingStatus::Completed, $funding->fresh()->status);
        $this->assertTrue(PaymentAttempt::where('payable_id', $funding->id)->first()->settledAsDust());
    }
}
