<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Order\Events\PaymentConfirmed;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\NowPaymentsProvider;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\FundingStatus;
use App\Jobs\FulfillOrderItemJob;
use App\Jobs\VerifyPaymentJob;
use App\Models\Admin;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The operator exit for a crypto payment that arrived short: settle it with
 * a stated reason from the transaction page.
 *
 * The action must not be a second way to settle an order. It stamps the
 * attempt Paid with who and why, writes the audit row, and hands the attempt
 * to VerifyPaymentJob, which already finishes a Paid attempt whose settlement
 * never landed. The previous way of doing this (a tinker script updating two
 * rows) skipped the session confirmation, the receipt, the analytics and the
 * audit log, and needed a second script to dispatch fulfilment.
 */
class TransactionManualSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nowpayments.api_key' => 'live-key-xyz']);
    }

    private function admin(AdminRole $role = AdminRole::SuperAdmin): Admin
    {
        return Admin::create([
            'name' => 'Test Admin',
            'email' => $role->value.'-'.Str::lower(Str::random(6)).'@example.test',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /**
     * A real shortfall, well above dust: 12.00 received against 12.360274
     * quoted on a $12.20 order, about 36 cents short.
     *
     * @return array{0: Order, 1: PaymentAttempt, 2: PaymentSession}
     */
    private function shortPayment(PaymentStatus $status = PaymentStatus::PartiallyPaid, float $actuallyPaid = 12.00): array
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'payment_method' => 'crypto',
            'payment_status' => PaymentStatus::Pending,
        ]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'nowpayments',
            'gateway_reference' => (string) random_int(1000000000, 9999999999),
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'USD',
            'amount' => 12.20,
            'payment_status' => $status,
            'pay_currency' => 'usdtbsc',
            'pay_amount' => 12.36027436,
            'actually_paid' => $actuallyPaid,
            'settlement_delta' => $actuallyPaid > 0 ? round($actuallyPaid - 12.36027436, 8) : null,
            'network' => 'bsc',
            'pay_address' => '0xdeadbeef',
            'purchase_id' => '5052290289',
            'metadata' => ['crypto' => ['status' => 'partially_paid', 'status_rank' => 90]],
        ]);

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
            'payment_payload' => ['pay_address' => '0xdeadbeef', 'pay_amount' => '12.37'],
            'customer_email' => $user->email,
            'expires_at' => now()->addMinutes(20),
        ]);

        return [$order, $attempt, $session];
    }

    private function settle(Admin $admin, PaymentAttempt $attempt, string $reason = 'Customer paid the 0.37 USDT balance to support by hand, ticket #1234')
    {
        return $this->actingAs($admin, 'admin')
            ->from(route('admin.transaction', $attempt))
            ->post(route('admin.transaction.settle', $attempt), ['reason' => $reason]);
    }

    // --- The happy path ------------------------------------------------------

    public function test_a_super_admin_can_settle_a_short_payment_with_a_reason(): void
    {
        Bus::fake([VerifyPaymentJob::class]);
        [, $attempt] = $this->shortPayment();
        $admin = $this->admin();

        $this->settle($admin, $attempt)
            ->assertRedirect(route('admin.transaction', $attempt))
            ->assertSessionHas('status');

        $fresh = $attempt->fresh();
        $this->assertSame(PaymentStatus::Paid, $fresh->payment_status);
        $this->assertNotNull($fresh->confirmed_at);
        $this->assertTrue($fresh->settledManually());
        $this->assertTrue($fresh->wasSettledShort());
        $this->assertFalse($fresh->isUnderpaid());

        $note = data_get($fresh->metadata, 'crypto.manual_settlement');
        $this->assertSame('Customer paid the 0.37 USDT balance to support by hand, ticket #1234', $note['reason']);
        $this->assertSame($admin->id, $note['admin_id']);
        $this->assertSame($admin->email, $note['admin_email']);
        $this->assertSame('partially_paid', $note['previous_status']);
        $this->assertEqualsWithDelta(0.36027436, $note['shortfall_crypto'], 1e-8);
        $this->assertEqualsWithDelta(0.3556, $note['shortfall_fiat'], 1e-3);
        $this->assertSame('USD', $note['price_currency']);

        // The shortfall itself is untouched: the record of what was short stays.
        $this->assertLessThan(0, (float) $fresh->settlement_delta);

        // Audited with the admin's identity and the reason.
        $log = AuditLog::where('action', 'crypto.settled_manually')->first();
        $this->assertNotNull($log);
        $this->assertSame($attempt->id, (string) $log->model_id);
        $this->assertSame('Customer paid the 0.37 USDT balance to support by hand, ticket #1234', data_get($log->metadata, 'reason'));
        $this->assertSame($admin->email, data_get($log->metadata, 'actor_email'));

        // The settlement chain is handed off, not re-implemented.
        Bus::assertDispatched(VerifyPaymentJob::class, fn (VerifyPaymentJob $job) => true);
    }

    /**
     * What the hand-off buys: order paid, session confirmed, confirmation
     * event, fulfilment queued. Run the job directly so no gateway round-trip
     * is needed (the attempt is already Paid).
     */
    public function test_the_settlement_runs_the_normal_chain_to_fulfilment(): void
    {
        Bus::fake([VerifyPaymentJob::class, FulfillOrderItemJob::class]);
        Event::fake([PaymentConfirmed::class]);
        [$order, $attempt, $session] = $this->shortPayment();

        $this->settle($this->admin(), $attempt)->assertSessionHas('status');

        $factory = $this->createMock(PaymentGatewayFactory::class);
        $factory->expects($this->never())->method('getProvider');
        (new VerifyPaymentJob($attempt->fresh()))->handle($factory, app(OrderService::class));

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame('confirmed', (string) ($session->fresh()->status->value ?? $session->fresh()->status));
        Event::assertDispatched(PaymentConfirmed::class);
    }

    /**
     * The gateway keeps reporting partially_paid for this payment id. The
     * hourly reconcile or a replayed IPN must not undo the operator's call.
     */
    public function test_a_later_gateway_verification_keeps_the_operator_settlement(): void
    {
        Bus::fake([VerifyPaymentJob::class]);
        [, $attempt] = $this->shortPayment();

        $this->settle($this->admin(), $attempt)->assertSessionHas('status');

        Http::fake([
            '*' => Http::response([
                'payment_id' => 5052290289,
                'payment_status' => 'partially_paid',
                'pay_amount' => 12.36027436,
                'actually_paid' => 12.00,
                'pay_currency' => 'usdtbsc',
            ], 200),
        ]);

        $this->assertTrue((new NowPaymentsProvider)->verifyPayment($attempt->fresh()));

        $fresh = $attempt->fresh();
        $this->assertSame(PaymentStatus::Paid, $fresh->payment_status);
        $this->assertTrue($fresh->settledManually());
    }

    public function test_the_settlement_audit_reports_an_operator_settlement(): void
    {
        Bus::fake([VerifyPaymentJob::class]);
        [, $attempt] = $this->shortPayment();
        $this->settle($this->admin(), $attempt);

        $this->assertSame(0, Artisan::call('crypto:settlements', ['--json' => true]));
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(1, $report['manually_settled_count']);
        $this->assertSame(0, $report['underpaid_count']);
        $this->assertSame('operator', $report['rows'][0]['resolution']);
    }

    // --- Refusals ------------------------------------------------------------

    public function test_only_a_super_admin_may_settle(): void
    {
        Bus::fake([VerifyPaymentJob::class]);
        [, $attempt] = $this->shortPayment();

        // Two layers refuse this: the admin middleware hides an unmapped route
        // name from anyone but a Super Admin (404), and the controller aborts
        // 403 on top should the route ever be mapped to a section.
        $response = $this->settle($this->admin(AdminRole::Admin), $attempt);
        $this->assertContains($response->status(), [403, 404]);

        $this->assertSame(PaymentStatus::PartiallyPaid, $attempt->fresh()->payment_status);
        $this->assertFalse($attempt->fresh()->settledManually());
        Bus::assertNotDispatched(VerifyPaymentJob::class);
        $this->assertSame(0, AuditLog::where('action', 'crypto.settled_manually')->count());
    }

    public function test_a_payment_that_is_not_partially_paid_is_refused(): void
    {
        Bus::fake([VerifyPaymentJob::class]);
        [, $attempt] = $this->shortPayment(PaymentStatus::Paid, 12.36027436);

        $this->settle($this->admin(), $attempt)->assertSessionHasErrors('settle');

        $this->assertFalse($attempt->fresh()->settledManually());
        Bus::assertNotDispatched(VerifyPaymentJob::class);
    }

    public function test_a_payment_with_nothing_received_is_refused(): void
    {
        Bus::fake([VerifyPaymentJob::class]);
        [, $attempt] = $this->shortPayment(PaymentStatus::PartiallyPaid, 0);

        $this->settle($this->admin(), $attempt)->assertSessionHasErrors('settle');

        $this->assertSame(PaymentStatus::PartiallyPaid, $attempt->fresh()->payment_status);
        Bus::assertNotDispatched(VerifyPaymentJob::class);
    }

    public function test_an_order_already_paid_by_another_attempt_is_refused(): void
    {
        Bus::fake([VerifyPaymentJob::class]);
        [$order, $attempt] = $this->shortPayment();
        $order->update(['payment_status' => PaymentStatus::Paid]);

        $this->settle($this->admin(), $attempt)->assertSessionHasErrors('settle');

        $this->assertSame(PaymentStatus::PartiallyPaid, $attempt->fresh()->payment_status);
        Bus::assertNotDispatched(VerifyPaymentJob::class);
    }

    public function test_a_reason_is_required_and_must_say_something(): void
    {
        Bus::fake([VerifyPaymentJob::class]);
        [, $attempt] = $this->shortPayment();

        $admin = $this->admin();

        $this->settle($admin, $attempt, 'ok')->assertSessionHasErrors('reason');
        $this->settle($admin, $attempt, '')->assertSessionHasErrors('reason');

        $this->assertSame(PaymentStatus::PartiallyPaid, $attempt->fresh()->payment_status);
        Bus::assertNotDispatched(VerifyPaymentJob::class);
    }

    // --- The page ------------------------------------------------------------

    public function test_the_page_offers_the_panel_only_where_it_applies(): void
    {
        [, $short] = $this->shortPayment();
        [, $paid] = $this->shortPayment(PaymentStatus::Paid, 12.36027436);
        $superAdmin = $this->admin();

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.transaction', $short))
            ->assertOk()
            ->assertSee('Settle short payment')
            ->assertSee(route('admin.transaction.settle', $short));

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.transaction', $paid))
            ->assertOk()
            ->assertDontSee('Settle short payment');
    }

    public function test_the_page_shows_the_settlement_record_afterwards(): void
    {
        Bus::fake([VerifyPaymentJob::class]);
        [, $attempt] = $this->shortPayment();
        $admin = $this->admin();
        $this->settle($admin, $attempt);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.transaction', $attempt))
            ->assertOk()
            ->assertSee('Settled short')
            ->assertSee('Settled by an operator')
            ->assertSee('Customer paid the 0.37 USDT balance to support by hand, ticket #1234')
            ->assertSee($admin->email)
            ->assertDontSee('Settle short payment');
    }

    // --- Wallet funding -------------------------------------------------------

    /**
     * The same button works for a crypto wallet deposit that arrived short.
     * The hand-off lands in the funding path of VerifyPaymentJob, which credits
     * the wallet without a second gateway round trip because the attempt is
     * already Paid.
     */
    public function test_an_operator_can_settle_a_short_wallet_funding_and_the_wallet_is_credited(): void
    {
        Bus::fake([VerifyPaymentJob::class]);
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
        $attempt = PaymentAttempt::create([
            'user_id' => $user->id,
            'payable_type' => WalletFunding::class,
            'payable_id' => $funding->id,
            'gateway' => 'nowpayments',
            'gateway_reference' => (string) random_int(1000000000, 9999999999),
            'idempotency_key' => $reference,
            'currency' => 'USD',
            'amount' => 50.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_status' => PaymentStatus::PartiallyPaid,
            'pay_currency' => 'usdtbsc',
            'pay_amount' => 50.64,
            'actually_paid' => 50.00,
            'settlement_delta' => -0.64,
        ]);

        $this->settle($this->admin(), $attempt, 'Returning customer, 0.64 USDT shortfall forgiven')->assertSessionHas('status');
        $this->assertTrue($attempt->fresh()->settledManually());

        Http::fake(['*' => Http::response([], 500)]);
        (new VerifyPaymentJob($attempt->fresh()))->handle(app(PaymentGatewayFactory::class), app(OrderService::class));

        $this->assertSame(150.0, (float) $wallet->fresh()->balance);
        $this->assertSame(FundingStatus::Completed, $funding->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_wallet_funding_already_credited_is_refused(): void
    {
        Bus::fake([VerifyPaymentJob::class]);
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => Currency::USD, 'balance' => 100.0]);
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
            'status' => FundingStatus::Completed,
            'idempotency_key' => 'fund_init_'.Str::random(16),
        ]);
        $attempt = PaymentAttempt::create([
            'user_id' => $user->id,
            'payable_type' => WalletFunding::class,
            'payable_id' => $funding->id,
            'gateway' => 'nowpayments',
            'gateway_reference' => (string) random_int(1000000000, 9999999999),
            'idempotency_key' => $reference,
            'currency' => 'USD',
            'amount' => 50.0,
            'payment_status' => PaymentStatus::PartiallyPaid,
            'pay_currency' => 'usdtbsc',
            'pay_amount' => 50.64,
            'actually_paid' => 50.00,
            'settlement_delta' => -0.64,
        ]);

        $this->settle($this->admin(), $attempt, 'Trying to settle something already credited')->assertSessionHasErrors('settle');

        $this->assertSame(PaymentStatus::PartiallyPaid, $attempt->fresh()->payment_status);
        Bus::assertNotDispatched(VerifyPaymentJob::class);
    }
}
