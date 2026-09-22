<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\RefundIssued;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Interfaces\PaymentProviderInterface;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Models\Admin;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Admin refunds must believe the GATEWAY, not themselves. The old handler
 * ignored refundPayment()'s answer, stamped the order Refunded (via an enum
 * case that did not even exist, so it 500ed after the money moved), emailed
 * the customer, and had no cumulative cap across partial refunds.
 */
class AdminOrderRefundTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private Order $order;

    private PaymentAttempt $attempt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $this->order = Order::factory()->create([
            'user_id' => $user->id,
            'total_amount' => 100,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $this->attempt = PaymentAttempt::create([
            'order_id' => $this->order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'USD',
            'amount' => 100,
            'payment_status' => PaymentStatus::Paid,
        ]);
    }

    private function fakeGatewayRefund(bool $answer): void
    {
        $provider = $this->createMock(PaymentProviderInterface::class);
        $provider->method('refundPayment')->willReturn($answer);
        $factory = $this->createMock(PaymentGatewayFactory::class);
        $factory->method('getProvider')->willReturn($provider);
        $this->app->instance(PaymentGatewayFactory::class, $factory);
    }

    private function refund(float $amount): TestResponse
    {
        return $this->actingAs($this->admin, 'admin')->postJson(
            route('admin.api.commerce.refund', $this->order->id),
            ['amount' => $amount, 'reason' => 'Customer request'],
        );
    }

    public function test_a_declined_gateway_refund_records_nothing_and_tells_no_one(): void
    {
        Event::fake([RefundIssued::class]);
        $this->fakeGatewayRefund(false);

        $this->refund(50)->assertStatus(400);

        $this->assertSame(PaymentStatus::Paid, $this->order->fresh()->payment_status, 'a declined refund must not stamp the order');
        $this->assertNull($this->order->fresh()->metadata['refunded_total'] ?? null);
        Event::assertNotDispatched(RefundIssued::class);
        $this->assertTrue(
            AuditLog::where('action', 'admin.order_refund_failed')->exists(),
            'the failed attempt must still leave an audit trail',
        );
    }

    /**
     * During a deploy the new code can run a minute before its migrations.
     * A full refund in that window has already moved the money at the
     * gateway, so the eSIM bookkeeping failing (its table not created yet)
     * must never roll back the refund's own record.
     */
    public function test_a_full_refund_is_recorded_even_when_the_esim_table_is_not_migrated_yet(): void
    {
        Event::fake([RefundIssued::class]);
        $this->fakeGatewayRefund(true);
        Schema::drop('esims');

        $this->refund(100)->assertOk();

        $order = $this->order->fresh();
        $this->assertSame(100.0, (float) $order->metadata['refunded_total']);
        $this->assertSame(PaymentStatus::Refunded, $order->payment_status);
        $this->assertSame(OrderStatus::Refunded, $order->order_status);
        $this->assertTrue(AuditLog::where('action', 'admin.order_refunded')->exists());
        Event::assertDispatched(RefundIssued::class);
    }

    public function test_a_partial_refund_marks_partially_refunded_and_tracks_the_total(): void
    {
        Event::fake([RefundIssued::class]);
        $this->fakeGatewayRefund(true);

        $this->refund(40)->assertOk();

        $order = $this->order->fresh();
        $this->assertSame(PaymentStatus::PartiallyRefunded, $order->payment_status);
        $this->assertNotSame(OrderStatus::Refunded, $order->order_status, 'a partial refund must not mark the whole order refunded');
        $this->assertSame(40.0, (float) $order->metadata['refunded_total']);
        Event::assertDispatched(RefundIssued::class);
    }

    public function test_partial_refunds_accumulate_to_full_and_never_exceed_the_cap(): void
    {
        Event::fake([RefundIssued::class]);
        $this->fakeGatewayRefund(true);

        $this->refund(60)->assertOk();

        // 60 already returned: anything above the remaining 40 is refused.
        $this->refund(50)->assertStatus(400);
        $this->assertSame(60.0, (float) $this->order->fresh()->metadata['refunded_total']);

        // The exact remainder completes the refund.
        $this->refund(40)->assertOk();

        $order = $this->order->fresh();
        $this->assertSame(100.0, (float) $order->metadata['refunded_total']);
        $this->assertSame(PaymentStatus::Refunded, $order->payment_status);
        $this->assertSame(OrderStatus::Refunded, $order->order_status);

        // A fully refunded order takes nothing more.
        $this->refund(1)->assertStatus(400);
    }

    public function test_the_cap_counts_automatic_refunds_from_failed_fulfillment(): void
    {
        Event::fake([RefundIssued::class]);
        $this->fakeGatewayRefund(true);

        // A failed item was auto-refunded before the admin ever opened the
        // screen. The two refund families share one ledger now, so the cap
        // must see it - it used to read zero and pay the money back twice.
        app(OrderService::class)
            ->recordRefundedAmount($this->order, 60.0, 'item-999');

        $this->refund(50)->assertStatus(400);
        $this->refund(40)->assertOk();

        $order = $this->order->fresh();
        $this->assertSame(100.0, (float) $order->metadata['refunded_total']);
        $this->assertSame(PaymentStatus::Refunded, $order->payment_status);
    }

    public function test_a_reserved_attempt_is_refused_because_its_money_was_never_taken(): void
    {
        Event::fake([RefundIssued::class]);
        $this->fakeGatewayRefund(true);

        // Reserved = wallet funds locked, not debited. "Refunding" it would
        // credit money that never left the customer.
        $this->attempt->update(['payment_status' => PaymentStatus::Reserved]);

        $response = $this->refund(50);

        $response->assertStatus(400);
        $this->assertStringContainsString('never captured', $response->json('message'));
        $this->assertNull($this->order->fresh()->metadata['refunded_total'] ?? null);
        Event::assertNotDispatched(RefundIssued::class);
    }

    public function test_crypto_refunds_explain_the_manual_path(): void
    {
        Event::fake([RefundIssued::class]);
        $this->attempt->update(['gateway' => 'nowpayments']);

        // Real provider: NOWPayments has no refund API and always answers false.
        $response = $this->refund(50);

        $response->assertStatus(400);
        $this->assertStringContainsString('manually', $response->json('message'));
        $this->assertSame(PaymentStatus::Paid, $this->order->fresh()->payment_status);
        Event::assertNotDispatched(RefundIssued::class);
    }
}
