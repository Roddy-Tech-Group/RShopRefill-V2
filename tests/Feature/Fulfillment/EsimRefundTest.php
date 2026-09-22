<?php

namespace Tests\Feature\Fulfillment;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Queries\FinanceReportQuery;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Fulfillment\Enums\EsimStatus;
use App\Domain\Fulfillment\Services\EsimRefundService;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\RefundIssued;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Models\Admin;
use App\Models\Esim;
use App\Models\OrderRefund;
use App\Models\PaymentAttempt;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Refunding an unused eSIM in two steps. Request: checked live with Airalo,
 * claim filed, no money moves. Complete (once Airalo credits us): the
 * customer's wallet in the currency they paid is credited what they paid for
 * that eSIM, whatever the payment method. Or Refused: nothing moves.
 * Airalo is always faked.
 */
class EsimRefundTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://airalo.test/v2';

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.airalo.base_url' => self::BASE, 'services.airalo.refund_window_days' => 14]);
        Cache::put('airalo_access_token', 'test-token', now()->addHour());
        Http::preventStrayRequests();
        Event::fake([RefundIssued::class]);

        // No eSIM refund may ever go back through a payment gateway.
        $gateways = $this->createMock(PaymentGatewayFactory::class);
        $gateways->expects($this->never())->method('getProvider');
        $this->app->instance(PaymentGatewayFactory::class, $gateways);

        $this->admin = Admin::create([
            'name' => 'Refund Admin',
            'email' => 'esim-refund-admin@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    /**
     * A paid, delivered, unused eSIM line.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function paidEsim(array $attributes = [], int $quantity = 1, float $unitPrice = 10, string $currency = 'USD', string $gateway = 'flutterwave', ?float $paid = null, float $rate = 1): Esim
    {
        $esim = Esim::factory()->create($attributes);
        $item = $esim->orderItem;
        $item->forceFill(['quantity' => $quantity, 'subtotal_amount' => $unitPrice * $quantity, 'display_currency' => $currency, 'provider_cost_usd' => 6])->saveQuietly();

        $paid ??= $unitPrice * $quantity;

        $order = $esim->order;
        $order->forceFill([
            'subtotal_amount' => $unitPrice * $quantity,
            'total_amount' => $paid,
            'payment_status' => PaymentStatus::Paid,
            'order_status' => 'completed',
            'completed_at' => now(),
            'display_currency' => $currency,
            'metadata' => ['exchange_rate' => $rate],
        ])->save();

        PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'gateway' => $gateway,
            'idempotency_key' => (string) Str::uuid(),
            'currency' => $currency,
            'amount' => $paid,
            'payment_status' => PaymentStatus::Paid,
        ]);

        return $esim->fresh();
    }

    private function fakeAiralo(string $usageStatus = 'NOT_ACTIVE', bool $claimAccepted = true, int $usageHttp = 200): void
    {
        Http::fake([
            self::BASE.'/sims/*/usage' => Http::response(['data' => ['status' => $usageStatus, 'remaining' => 5120, 'total' => 5120, 'is_unlimited' => false]], $usageHttp),
            self::BASE.'/refund' => $claimAccepted
                ? Http::response(['data' => ['refund_id' => 'RF-7781', 'created_at' => '2026-09-21 10:00:00']], 202)
                : Http::response(['message' => 'Rejected'], 422),
        ]);
    }

    private function request(Esim $esim, string $reason = 'TRIP_CANCELLATION', string $note = 'Trip was cancelled by the airline'): array
    {
        return app(EsimRefundService::class)->request($esim, $this->admin, $reason, $note);
    }

    private function complete(Esim $esim): array
    {
        return app(EsimRefundService::class)->complete($esim->fresh(), $this->admin);
    }

    private function walletBalance(Esim $esim, Currency $currency): ?float
    {
        $wallet = Wallet::where('user_id', $esim->order->user_id)->where('currency', $currency)->first();

        return $wallet ? (float) $wallet->balance : null;
    }

    // ---------------------------------------------------------- step 1: request

    public function test_requesting_files_the_claim_and_moves_no_money(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo();

        $result = $this->request($esim);

        $this->assertTrue($result['ok'], $result['message']);
        $esim->refresh();
        $this->assertSame(EsimStatus::RefundPending, $esim->status);
        $this->assertSame(Esim::REFUND_PENDING, $esim->refund_status);
        $this->assertSame('RF-7781', $esim->refund_claim_reference);
        $this->assertEqualsWithDelta(10.00, (float) $esim->refund_amount, 0.001);
        $this->assertSame('USD', $esim->refund_currency);
        $this->assertSame($this->admin->id, $esim->refund_requested_by);

        // Nothing moved.
        $this->assertNull($esim->refunded_at);
        $this->assertSame(0, OrderRefund::count());
        $this->assertNull($this->walletBalance($esim, Currency::USD));
        $this->assertSame(PaymentStatus::Paid, $esim->order->fresh()->payment_status);
        Event::assertNotDispatched(RefundIssued::class);

        $this->assertTrue(AuditLog::where('action', 'admin.esim_refund_requested')->exists());
        Http::assertSent(function (Request $request) use ($esim) {
            if (! str_ends_with($request->url(), '/refund')) {
                return false;
            }
            $parts = collect($request->data())->pluck('contents', 'name');

            return $parts['iccids[]'] === $esim->iccid && $parts['reason'] === 'TRIP_CANCELLATION';
        });
    }

    public function test_an_esim_activated_since_the_last_sync_cannot_be_requested(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo('ACTIVE');

        $result = $this->request($esim);

        $this->assertFalse($result['ok']);
        $this->assertSame('Only an eSIM that was never activated can be refunded.', $result['message']);
        $this->assertNull($esim->fresh()->refund_status);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/refund'));
    }

    public function test_when_airalo_cannot_confirm_the_esim_is_unused_nothing_is_requested(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo(usageHttp: 503);

        $this->assertFalse($this->request($esim)['ok']);
        $this->assertNull($esim->fresh()->refund_status);
    }

    public function test_an_esim_past_the_refund_window_cannot_be_requested(): void
    {
        $esim = $this->paidEsim(['purchased_at' => now()->subDays(15)]);
        $this->fakeAiralo();

        $this->assertSame('Past the 14-day refund window.', $this->request($esim)['message']);
    }

    public function test_an_esim_with_a_refund_pending_cannot_be_requested_again(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo();
        $this->request($esim);

        $second = $this->request($esim->fresh());

        $this->assertFalse($second['ok']);
        $this->assertSame('A refund is already pending with the provider.', $second['message']);
        Http::assertSentCount(3); // usage, claim, usage: never a second claim
    }

    /**
     * An admin refunds the whole order from the Orders page (money back the
     * way it was paid). Its eSIM reads Refunded and is never offered again.
     */
    public function test_an_order_refunded_in_full_from_the_orders_page_refunds_its_esims(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo();
        $orders = app(OrderService::class);

        $orders->recordRefundedAmount($esim->order, 10.00, 'admin-'.Str::uuid());
        $orders->markRefunded($esim->order->fresh());

        $esim->refresh();
        $this->assertSame(EsimStatus::Refunded, $esim->status);
        $this->assertNotNull($esim->refunded_at);
        $this->assertSame('This eSIM was already refunded.', app(EsimRefundService::class)->blockedReason($esim));
        $this->assertFalse($this->request($esim)['ok']);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/refund'));
    }

    /**
     * Orders refunded by hand before this release: the backfill records their
     * eSIMs as Refunded, dated when the refund was issued.
     */
    public function test_the_backfill_records_esims_of_an_already_refunded_order_as_refunded(): void
    {
        $esim = $this->paidEsim();
        $esim->orderItem->forceFill(['fulfillment_payload' => ['iccid' => $esim->iccid]])->saveQuietly();
        $esim->order->forceFill([
            'payment_status' => PaymentStatus::Refunded,
            'order_status' => OrderStatus::Refunded,
            'metadata' => ['exchange_rate' => 1, 'refund_records' => ['admin-'.Str::uuid() => 10.00], 'refunded_total' => 10.00],
        ])->save();
        $iccid = $esim->iccid;
        $esim->delete();

        $this->artisan('esims:backfill')->assertSuccessful();

        $recorded = Esim::where('iccid', $iccid)->sole();
        $this->assertSame(EsimStatus::Refunded, $recorded->status);
        $this->assertNotNull($recorded->refunded_at);
        $this->assertNotNull(app(EsimRefundService::class)->blockedReason($recorded));
    }

    /**
     * One eSIM of two was refunded by hand from the Orders page, and that
     * refund does not say which. Neither can be refunded here, so the same
     * eSIM is never paid twice.
     */
    public function test_a_partly_refunded_order_blocks_esim_refunds(): void
    {
        $esim = $this->paidEsim(quantity: 2);
        $this->fakeAiralo();
        $orders = app(OrderService::class);

        $orders->recordRefundedAmount($esim->order, 10.00, 'admin-'.Str::uuid());
        $orders->markRefunded($esim->order->fresh());

        $esim->refresh();
        $this->assertNotSame(EsimStatus::Refunded, $esim->status);
        $this->assertSame('This order already has a refund from the Orders page.', app(EsimRefundService::class)->blockedReason($esim));
        $this->assertFalse($this->request($esim)['ok']);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/refund'));
    }

    /**
     * An automatic reversal for an undelivered item is not a refund of the
     * eSIM, so it does not block one.
     */
    public function test_an_automatic_reversal_does_not_block_an_esim_refund(): void
    {
        $esim = $this->paidEsim();
        $esim->order->forceFill(['metadata' => ['exchange_rate' => 1, 'refund_records' => ['item-'.Str::uuid() => 0.00]]])->save();

        $this->assertNull(app(EsimRefundService::class)->blockedReason($esim->fresh()));
    }

    /**
     * A refund was pending here when someone refunded the order from the
     * Orders page. Completing it now could pay twice, so it is refused.
     */
    public function test_a_pending_refund_cannot_complete_after_an_orders_page_refund(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo();
        $this->request($esim);

        app(OrderService::class)->recordRefundedAmount($esim->order, 4.00, 'admin-'.Str::uuid());
        $result = $this->complete($esim);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('refunded from the Orders page since this request', $result['message']);
        $this->assertNull($this->walletBalance($esim, Currency::USD));
        $this->assertSame(Esim::REFUND_PENDING, $esim->fresh()->refund_status);
    }

    public function test_an_esim_from_a_provider_without_refunds_is_blocked(): void
    {
        $esim = $this->paidEsim(['provider' => 'zendit']);

        $this->assertSame('Refunds here are only for eSIMs from a provider with a refund process.', app(EsimRefundService::class)->blockedReason($esim));
    }

    public function test_the_request_needs_a_known_reason(): void
    {
        $esim = $this->paidEsim();

        $this->assertFalse($this->request($esim, 'BECAUSE')['ok']);
        Http::assertNothingSent();
    }

    public function test_a_request_that_does_not_reach_airalo_stays_pending_and_can_be_sent_again(): void
    {
        $esim = $this->paidEsim();
        Http::fake([
            self::BASE.'/sims/*/usage' => Http::response(['data' => ['status' => 'NOT_ACTIVE', 'remaining' => 5120, 'total' => 5120, 'is_unlimited' => false]]),
            self::BASE.'/refund' => Http::sequence()
                ->push(['message' => 'Temporarily unavailable'], 503)
                ->push(['data' => ['refund_id' => 'RF-7781']], 202),
        ]);

        $result = $this->request($esim);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('did not reach the provider', $result['message']);
        $this->assertNull($esim->fresh()->refund_claim_reference);

        $this->assertTrue(app(EsimRefundService::class)->fileSupplierClaim($esim->fresh()));
        $this->assertSame('RF-7781', $esim->fresh()->refund_claim_reference);
    }

    // ---------------------------------------------------------- step 2: complete

    public function test_completing_credits_the_customers_wallet_and_marks_everything_refunded(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo();
        $this->request($esim);

        $result = $this->complete($esim);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame("Credited USD 10.00 to the customer's USD wallet.", $result['message']);

        // The wallet.
        $this->assertEqualsWithDelta(10.00, $this->walletBalance($esim, Currency::USD), 0.001);
        $tx = WalletTransaction::where('idempotency_key', 'esim-refund-'.$esim->id)->sole();
        $this->assertSame(TransactionCategory::Refund, $tx->transaction_category);
        $this->assertStringContainsString('eSIM refund credited to your wallet', $tx->description);

        // The eSIM.
        $esim->refresh();
        $this->assertSame(EsimStatus::Refunded, $esim->status);
        $this->assertSame(Esim::REFUND_COMPLETED, $esim->refund_status);
        $this->assertNotNull($esim->refunded_at);

        // The order shows it: a single-eSIM order is fully refunded.
        $order = $esim->order->fresh();
        $this->assertSame(PaymentStatus::Refunded, $order->payment_status);
        $this->assertSame(OrderStatus::Refunded, $order->order_status);

        // The ledger: revenue and Airalo's credit recorded together.
        $row = $esim->refund;
        $this->assertSame('esim-'.$esim->id, $row->tag);
        $this->assertSame('wallet', $row->gateway);
        $this->assertSame(OrderRefund::CREDIT_CREDITED, $row->supplier_credit_status);
        $this->assertEqualsWithDelta(6.00, (float) $row->supplier_credit_usd, 0.001);
        $this->assertSame('RF-7781', $row->supplier_credit_reference);

        Event::assertDispatched(RefundIssued::class, fn ($event) => abs($event->amount - 10.00) < 0.001);
        $audit = AuditLog::where('action', 'admin.esim_refunded')->sole();
        $this->assertSame('wallet', $audit->metadata['credited_to']);
        $this->assertSame('USD', $audit->metadata['currency']);
    }

    /**
     * Paid by card in naira: the credit is naira, into a naira wallet that is
     * created for them, never converted.
     */
    public function test_a_naira_order_credits_a_new_naira_wallet_with_no_conversion(): void
    {
        $esim = $this->paidEsim(unitPrice: 16000, currency: 'NGN', rate: 1600);
        $this->fakeAiralo();
        $this->assertNull($this->walletBalance($esim, Currency::NGN));

        $this->request($esim);
        $this->complete($esim);

        $this->assertEqualsWithDelta(16000.00, $this->walletBalance($esim, Currency::NGN), 0.001);
        $this->assertNull($this->walletBalance($esim, Currency::USD));
    }

    public function test_a_crypto_paid_order_is_still_credited_to_the_wallet(): void
    {
        $esim = $this->paidEsim(gateway: 'nowpayments');
        $this->fakeAiralo();

        $this->request($esim);
        $this->assertTrue($this->complete($esim)['ok']);

        $this->assertEqualsWithDelta(10.00, $this->walletBalance($esim, Currency::USD), 0.001);
    }

    /**
     * A $10 eSIM bought for $8 with a coupon: they get back the $8 they paid.
     */
    public function test_a_discounted_esim_credits_what_was_actually_paid(): void
    {
        $esim = $this->paidEsim(paid: 8.00);
        $this->fakeAiralo();

        $this->request($esim);
        $this->complete($esim);

        $this->assertEqualsWithDelta(8.00, $this->walletBalance($esim, Currency::USD), 0.001);
    }

    /**
     * Two eSIMs on one $20 line: refunding one credits $10 and the order is
     * partially refunded, not refunded.
     */
    public function test_one_esim_of_two_credits_its_share_and_partially_refunds_the_order(): void
    {
        $esim = $this->paidEsim(quantity: 2);
        $this->fakeAiralo();

        $this->request($esim);
        $this->complete($esim);

        $this->assertEqualsWithDelta(10.00, $this->walletBalance($esim, Currency::USD), 0.001);
        $order = $esim->order->fresh();
        $this->assertSame(PaymentStatus::PartiallyRefunded, $order->payment_status);
        $this->assertNotSame(OrderStatus::Refunded, $order->order_status);
    }

    public function test_completing_twice_credits_once(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo();
        $this->request($esim);

        $this->assertTrue($this->complete($esim)['ok']);
        $second = $this->complete($esim);

        $this->assertFalse($second['ok']);
        $this->assertEqualsWithDelta(10.00, $this->walletBalance($esim, Currency::USD), 0.001);
        $this->assertSame(1, WalletTransaction::where('idempotency_key', 'esim-refund-'.$esim->id)->count());
    }

    public function test_an_esim_without_a_pending_refund_cannot_be_completed(): void
    {
        $esim = $this->paidEsim();

        $this->assertFalse($this->complete($esim)['ok']);
        $this->assertNull($this->walletBalance($esim, Currency::USD));
    }

    /**
     * An earlier refund on the order leaves less to give back: the credit
     * never takes the order past what the customer paid.
     */
    public function test_the_credit_never_exceeds_what_is_left_refundable_on_the_order(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo();
        $this->request($esim);
        app(OrderService::class)->recordRefundedAmount($esim->order, 7.00, 'final-'.Str::uuid());

        $this->complete($esim);

        $this->assertEqualsWithDelta(3.00, $this->walletBalance($esim, Currency::USD), 0.001);
    }

    // ---------------------------------------------------------- refused

    public function test_a_refused_refund_moves_nothing_and_restores_the_status(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo();
        $this->request($esim);

        $result = app(EsimRefundService::class)->reject($esim->fresh(), $this->admin, 'Installed per their logs');

        $this->assertTrue($result['ok']);
        $esim->refresh();
        $this->assertSame(Esim::REFUND_REJECTED, $esim->refund_status);
        $this->assertSame(EsimStatus::Inactive, $esim->status);
        $this->assertSame('Installed per their logs', $esim->refund_resolution_note);
        $this->assertNull($this->walletBalance($esim, Currency::USD));
        $this->assertSame(0, OrderRefund::count());
        $this->assertSame('The provider already declined a refund for this eSIM.', app(EsimRefundService::class)->blockedReason($esim));
        $this->assertTrue(AuditLog::where('action', 'admin.esim_refund_rejected')->exists());
    }

    // ---------------------------------------------------------- finances

    /**
     * Nothing changes on request. On completion revenue and Airalo's cost come
     * off together: profit goes from +$4 to $0 and never dips below.
     */
    public function test_finances_change_only_on_completion_and_revenue_and_cost_come_off_together(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo();
        $finance = app(FinanceReportQuery::class);

        $this->request($esim);
        $pending = $finance->monthlyBreakdown(now()->startOfMonth())['totals'];
        $this->assertEqualsWithDelta(10.00, $pending['revenue_usd'], 0.001);
        $this->assertEqualsWithDelta(4.00, $pending['profit_usd'], 0.001);

        $this->complete($esim);
        $done = $finance->monthlyBreakdown(now()->startOfMonth())['totals'];
        $this->assertEqualsWithDelta(0.00, $done['revenue_usd'], 0.001);
        $this->assertEqualsWithDelta(0.00, $done['cost_usd'], 0.001);
        $this->assertEqualsWithDelta(0.00, $done['profit_usd'], 0.001);
    }

    // ---------------------------------------------------------- pages

    public function test_the_list_shows_each_esim_and_blocks_refunding_an_active_one(): void
    {
        $unused = $this->paidEsim();
        $active = $this->paidEsim();
        $active->forceFill(['status' => EsimStatus::Active])->save();

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.esims'))
            ->assertOk()
            ->assertSee($unused->iccid)
            ->assertSee($active->iccid)
            ->assertSee('Only an eSIM that was never activated can be refunded.');
    }

    public function test_the_list_filters_by_status_and_searches_by_iccid(): void
    {
        $unused = $this->paidEsim();
        $active = $this->paidEsim();
        $active->forceFill(['status' => EsimStatus::Active])->save();

        $this->actingAs($this->admin, 'admin');

        Volt::test('admin.esims')
            ->set('status', 'active')
            ->assertSee($active->iccid)
            ->assertDontSee($unused->iccid)
            ->set('status', 'all')
            ->set('search', substr($unused->iccid, -8))
            ->assertSee($unused->iccid)
            ->assertDontSee($active->iccid);
    }

    public function test_requesting_from_the_page_needs_a_reason(): void
    {
        $esim = $this->paidEsim();
        $this->actingAs($this->admin, 'admin');

        Volt::test('admin.esims')
            ->call('openRefund', $esim->id)
            ->call('confirmRefund')
            ->assertHasErrors(['refundReason']);

        $this->assertNull($esim->fresh()->refund_status);
    }

    /**
     * Every reason speaks for itself except Other, which Airalo needs explained.
     */
    public function test_only_the_other_reason_needs_a_note(): void
    {
        $esim = $this->paidEsim();
        $this->actingAs($this->admin, 'admin');

        Volt::test('admin.esims')
            ->call('openRefund', $esim->id)
            ->set('refundReason', 'OTHERS')
            ->call('confirmRefund')
            ->assertHasErrors(['refundNote' => 'required_if']);

        $this->assertNull($esim->fresh()->refund_status);
        $this->assertFalse(app(EsimRefundService::class)->request($esim, $this->admin, 'OTHERS', '')['ok']);
    }

    /**
     * A note left behind after switching away from Other never blocks the
     * request, and is not sent.
     */
    public function test_a_note_left_behind_by_switching_reasons_is_dropped(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo();
        $this->actingAs($this->admin, 'admin');

        Volt::test('admin.esims')
            ->call('openRefund', $esim->id)
            ->set('refundReason', 'OTHERS')
            ->set('refundNote', 'short')
            ->set('refundReason', 'NO_COVERAGE')
            ->call('confirmRefund')
            ->assertHasNoErrors();

        $this->assertNull($esim->fresh()->refund_note);
        $this->assertSame(EsimStatus::RefundPending, $esim->fresh()->status);
    }

    /**
     * With no note, the reason itself explains the refund to Airalo and to the
     * customer in their email.
     */
    public function test_without_a_note_the_reason_explains_the_refund_everywhere(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo();

        $this->request($esim, 'NO_COVERAGE', '');
        $this->complete($esim);

        Http::assertSent(function (Request $request) {
            $parts = collect($request->data())->pluck('contents', 'name');

            return str_ends_with($request->url(), '/refund') && $parts['notes'] === 'No coverage';
        });
        Event::assertDispatched(RefundIssued::class, fn ($event) => $event->reason === 'No coverage');
        $this->assertSame('No coverage', $esim->fresh()->refund->reason);
    }

    public function test_an_other_refund_sends_the_note(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo();

        $this->request($esim, 'OTHERS', 'Customer was charged twice by their bank');
        $this->complete($esim);

        Http::assertSent(function (Request $request) {
            $parts = collect($request->data())->pluck('contents', 'name');

            return str_ends_with($request->url(), '/refund') && $parts['reason'] === 'OTHERS' && $parts['notes'] === 'Customer was charged twice by their bank';
        });
        Event::assertDispatched(RefundIssued::class, fn ($event) => $event->reason === 'Customer was charged twice by their bank');
    }

    public function test_requesting_from_the_page_marks_the_esim_pending(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo();
        $this->actingAs($this->admin, 'admin');

        Volt::test('admin.esims')
            ->call('openRefund', $esim->id)
            ->assertSee('Wallet credit on completion')
            ->set('refundReason', 'WRONG_PURCHASE')
            ->call('confirmRefund')
            ->assertHasNoErrors()
            ->assertSet('refundingEsimId', null);

        $this->assertSame(EsimStatus::RefundPending, $esim->fresh()->status);
    }

    public function test_the_detail_page_completes_the_refund(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo();
        $this->request($esim);
        $this->actingAs($this->admin, 'admin');

        $this->get(route('admin.esims.show', $esim))
            ->assertOk()
            ->assertSee('RF-7781')
            ->assertSee('Waiting for provider')
            ->assertSee('Complete the refund once the provider confirms it.')
            ->assertSee('Provider declined')
            ->assertDontSee('Airalo credit')
            ->assertSee('Trip was cancelled by the airline');

        Volt::test('admin.esim', ['esim' => $esim->fresh()])
            ->call('completeRefund')
            ->assertHasNoErrors()
            ->assertSet('refundActionError', null);

        $this->assertSame(Esim::REFUND_COMPLETED, $esim->fresh()->refund_status);
        $this->assertEqualsWithDelta(10.00, $this->walletBalance($esim, Currency::USD), 0.001);
    }

    public function test_the_detail_page_records_a_refusal(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo();
        $this->request($esim);
        $this->actingAs($this->admin, 'admin');

        Volt::test('admin.esim', ['esim' => $esim->fresh()])
            ->set('rejectionNote', 'Installed per their logs')
            ->call('rejectRefund')
            ->assertHasNoErrors();

        $this->assertSame(Esim::REFUND_REJECTED, $esim->fresh()->refund_status);
    }

    public function test_the_order_page_shows_the_esim_refund(): void
    {
        $esim = $this->paidEsim();
        $this->fakeAiralo();
        $this->request($esim);
        $this->actingAs($this->admin, 'admin');

        $this->get(route('admin.order', $esim->order_id))->assertOk()->assertSee('eSIM refund pending');

        $this->complete($esim);

        $this->get(route('admin.order', $esim->order_id))
            ->assertOk()
            ->assertSee('eSIM refunded')
            ->assertSee(OrderStatus::Refunded->label());
    }

    public function test_an_admin_without_the_orders_permission_cannot_open_manage_esims(): void
    {
        $moderator = Admin::create([
            'name' => 'Moderator',
            'email' => 'esim-moderator@example.test',
            'password' => 'password',
            'role' => AdminRole::Moderator,
            'is_active' => true,
        ]);

        $response = $this->actingAs($moderator, 'admin')->get(route('admin.esims'));

        $this->assertContains($response->status(), [302, 403, 404]);
    }
}
