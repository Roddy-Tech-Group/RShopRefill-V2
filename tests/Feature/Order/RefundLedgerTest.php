<?php

namespace Tests\Feature\Order;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Order\Services\OrderService;
use App\Domain\Order\Services\RefundLedger;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The refund ledger. Every refund reaches it through
 * OrderService::applyRefundRecord, once per tag, and the one judgement it
 * makes is how much revenue a refund unwinds: the fraction of what was
 * captured for the refunded items, applied to what was booked for them.
 */
class RefundLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::firstOrCreate(['name' => 'eSIMs'], ['slug' => 'esims', 'type' => 'digital', 'is_active' => true, 'sort_order' => 0]);
    }

    /**
     * @param  array<int, array{0: float, 1: string}>  $items  [subtotal, fulfillment_status]
     */
    private function order(array $items, ?float $total = null, string $currency = 'USD', float $rate = 1.0): Order
    {
        $subtotal = array_sum(array_column($items, 0));

        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'RSR-LED-'.Str::random(8),
            'settlement_currency' => 'USD',
            'display_currency' => $currency,
            'subtotal_amount' => $subtotal,
            'markup_amount' => 0,
            'total_amount' => $total ?? $subtotal,
            'payment_method' => 'flutterwave',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'order_status' => 'completed',
            'completed_at' => now(),
            'metadata' => ['exchange_rate' => $rate],
        ]);

        $subcategory = Subcategory::factory()->create(['category_id' => $this->category->id]);

        foreach ($items as [$amount, $status]) {
            $product = Product::factory()->create(['category_id' => $this->category->id, 'provider_name' => 'airalo']);

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_variant_id' => ProductVariant::factory()->create(['product_id' => $product->id])->id,
                'category_id' => $this->category->id,
                'subcategory_id' => $subcategory->id,
                'provider_name' => 'airalo',
                'quantity' => 1,
                'display_currency' => $currency,
                'display_amount' => $amount,
                'provider_cost_usd' => 1,
                'markup_amount' => 0,
                'subtotal_amount' => $amount,
                'fulfillment_status' => $status,
            ]);
        }

        return $order->fresh();
    }

    private function ledger(): RefundLedger
    {
        return app(RefundLedger::class);
    }

    public function test_an_admin_refund_is_recorded_as_a_customer_refund_with_who_and_why(): void
    {
        $order = $this->order([[25.00, 'fulfilled']]);
        $admin = Admin::create([
            'name' => 'Test Admin',
            'email' => 'ledger-admin@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);

        $row = $this->ledger()->record($order, 25.00, 'admin-'.Str::uuid(), [
            'gateway' => 'flutterwave',
            'reason' => 'eSIM never installed',
            'refunded_by' => $admin->id,
        ]);

        $this->assertSame(OrderRefund::KIND_CUSTOMER_REFUND, $row->kind);
        $this->assertSame('flutterwave', $row->gateway);
        $this->assertSame('eSIM never installed', $row->reason);
        $this->assertSame($admin->id, $row->refunded_by);
        $this->assertSame($order->user_id, $row->user_id);
        $this->assertEqualsWithDelta(25.00, (float) $row->revenue_reversed_usd, 0.001);
        $this->assertSame(OrderRefund::CREDIT_NOT_APPLICABLE, $row->supplier_credit_status);
    }

    public function test_every_reversal_tag_is_a_fulfilment_reversal_that_reverses_nothing(): void
    {
        $order = $this->order([[20.00, 'fulfilled'], [30.00, 'failed']]);
        $failed = $order->items()->where('fulfillment_status', 'failed')->firstOrFail();

        foreach (['settle-'.$order->id, 'final-'.$order->id, 'item-'.$failed->id, 'release-item-'.$failed->id] as $tag) {
            $row = $this->ledger()->record($order, 30.00, $tag);

            $this->assertSame(OrderRefund::KIND_FULFILMENT_REVERSAL, $row->kind, $tag);
            $this->assertEqualsWithDelta(0.0, (float) $row->revenue_reversed_usd, 0.001, $tag);
        }

        // item- and release-item- name their item in the tag.
        $this->assertSame((string) $failed->id, OrderRefund::where('tag', 'item-'.$failed->id)->value('order_item_id'));
    }

    public function test_recording_the_same_tag_twice_records_once(): void
    {
        $order = $this->order([[25.00, 'fulfilled']]);
        $tag = 'admin-'.Str::uuid();

        $first = $this->ledger()->record($order, 25.00, $tag);
        $second = $this->ledger()->record($order, 25.00, $tag);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, OrderRefund::count());
    }

    /**
     * An $8 payment for a $10 item (a $2 coupon), fully refunded. Converting
     * the $8 would reverse $8 and leave $2 of revenue on a sale that gave
     * everything back. The whole $10 that was booked must come off.
     */
    public function test_a_discounted_item_fully_refunded_reverses_everything_booked(): void
    {
        $order = $this->order([[10.00, 'fulfilled']], total: 8.00);

        $row = $this->ledger()->record($order, 8.00, 'admin-'.Str::uuid());

        $this->assertEqualsWithDelta(10.00, (float) $row->revenue_reversed_usd, 0.001);
    }

    /**
     * $20 delivered, $30 failed (already reversed). Refunding the $20 in full
     * must reverse all of the booked $20, not a fraction diluted by the $50
     * order total.
     */
    public function test_refunding_the_delivered_item_of_a_half_failed_order_reverses_all_of_it(): void
    {
        $order = $this->order([[20.00, 'fulfilled'], [30.00, 'failed']]);

        $row = $this->ledger()->record($order, 20.00, 'admin-'.Str::uuid());

        $this->assertEqualsWithDelta(20.00, (float) $row->revenue_reversed_usd, 0.001);
        $this->assertCount(1, $row->allocations);
    }

    public function test_a_partial_refund_reverses_its_share(): void
    {
        $order = $this->order([[40.00, 'fulfilled']]);

        $row = $this->ledger()->record($order, 10.00, 'admin-'.Str::uuid());

        $this->assertEqualsWithDelta(10.00, (float) $row->revenue_reversed_usd, 0.001);
    }

    /**
     * An order-level refund on a two-item order is split across both items in
     * proportion, so each provider and category row carries its own part.
     */
    public function test_an_order_level_refund_is_split_across_the_items_it_covers(): void
    {
        $order = $this->order([[30.00, 'fulfilled'], [10.00, 'fulfilled']]);

        $row = $this->ledger()->record($order, 20.00, 'admin-'.Str::uuid());

        $shares = collect($row->allocations)->pluck('revenue_reversed_usd')->sort()->values();

        $this->assertEqualsWithDelta(5.00, $shares[0], 0.001);
        $this->assertEqualsWithDelta(15.00, $shares[1], 0.001);
        $this->assertEqualsWithDelta(20.00, (float) $row->revenue_reversed_usd, 0.001);
    }

    /**
     * An eSIM refund names one item, and reverses only that item.
     */
    public function test_an_item_refund_reverses_only_that_item(): void
    {
        $order = $this->order([[30.00, 'fulfilled'], [10.00, 'fulfilled']]);
        $item = $order->items()->orderBy('id')->firstOrFail();

        $row = $this->ledger()->record($order, (float) $item->subtotal_amount, 'esim-'.Str::uuid(), ['order_item_id' => $item->id]);

        $this->assertSame(OrderRefund::KIND_CUSTOMER_REFUND, $row->kind);
        $this->assertCount(1, $row->allocations);
        $this->assertSame((string) $item->id, $row->allocations[0]['order_item_id']);
        $this->assertEqualsWithDelta((float) $item->subtotal_amount, (float) $row->revenue_reversed_usd, 0.001);
    }

    /**
     * A refund never unwinds more than was booked, whatever amount is passed.
     */
    public function test_a_refund_never_reverses_more_than_was_booked(): void
    {
        $order = $this->order([[25.00, 'fulfilled']]);

        $row = $this->ledger()->record($order, 999.00, 'admin-'.Str::uuid());

        $this->assertEqualsWithDelta(25.00, (float) $row->revenue_reversed_usd, 0.001);
    }

    /**
     * A naira order is booked at subtotal / its own exchange rate, and a
     * refund unwinds it at that same rate: no exchange-rate noise.
     */
    public function test_a_non_usd_refund_reverses_at_the_rate_the_sale_was_booked_at(): void
    {
        $order = $this->order([[16000.00, 'fulfilled']], currency: 'NGN', rate: 1600.0);

        $row = $this->ledger()->record($order, 16000.00, 'admin-'.Str::uuid());

        $this->assertSame('NGN', $row->currency);
        $this->assertEqualsWithDelta(10.00, (float) $row->revenue_reversed_usd, 0.001);
    }

    // ------------------------------------------------ through OrderService

    public function test_every_refund_recorded_on_an_order_reaches_the_ledger(): void
    {
        $order = $this->order([[25.00, 'fulfilled']]);

        app(OrderService::class)->recordRefundedAmount($order, 25.00, 'admin-'.Str::uuid(), ['reason' => 'Wrong country']);

        $this->assertSame(1, OrderRefund::count());
        $this->assertSame('Wrong country', OrderRefund::first()->reason);
    }

    /**
     * By the time an admin refund is recorded the gateway has already moved
     * the money. A ledger failure must not roll back the refund's own record,
     * or the refund cap would forget money that really went out.
     */
    public function test_a_ledger_failure_never_blocks_the_refund_record(): void
    {
        $order = $this->order([[25.00, 'fulfilled']]);

        $this->mock(RefundLedger::class, fn ($mock) => $mock->shouldReceive('record')->andThrow(new \RuntimeException('ledger down')));

        $total = app(OrderService::class)->recordRefundedAmount($order, 25.00, 'admin-'.Str::uuid());

        $this->assertEqualsWithDelta(25.00, $total, 0.001);
        $this->assertEqualsWithDelta(25.00, app(OrderService::class)->refundedTotalFor($order->fresh()), 0.001);
    }
}
