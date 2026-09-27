<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Queries\DashboardMetricsQuery;
use App\Domain\Admin\Queries\FinanceReportQuery;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Overview dashboard and the Reports page read the refund ledger.
 *
 * Every order query on both pages filtered `order_status = completed` and
 * never touched order_refunds, so a fully refunded order vanished from the
 * month it was SOLD in, a partial refund was never deducted, supplier credits
 * never reduced cost, and a partially completed order was dropped whole,
 * delivered items included. Finances was cured of exactly this in September;
 * these two pages never were, which is why they disagreed.
 *
 * The rule proved here: a sale is booked when the order completes and is
 * never un-booked. What was given back is deducted on the day it was given
 * back, which is what the ledger's own dates exist for.
 */
class OverviewReadsRefundsTest extends TestCase
{
    use RefreshDatabase;

    private function order(
        string $status = 'completed',
        float $total = 100.0,
        float $cost = 70.0,
        ?string $completedAt = null,
        string $itemStatus = 'fulfilled',
    ): Order {
        $category = $this->category();
        $variant = $this->variant();

        $order = Order::factory()->create([
            'user_id' => User::factory()->create()->id,
            'order_status' => $status,
            'payment_status' => PaymentStatus::Paid,
            'display_currency' => 'USD',
            'subtotal_amount' => $total,
            'total_amount' => $total,
            'completed_at' => $completedAt ?? now()->subDays(2),
            'metadata' => ['exchange_rate' => 1.0, 'settlement_total_usd' => $total, 'settlement_subtotal_usd' => $total],
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $this->subcategory()->id,
            'quantity' => 1,
            'provider_cost_usd' => $cost,
            'subtotal_amount' => $total,
            'fulfillment_status' => $itemStatus,
            'product_snapshot' => ['name' => 'Amazon', 'country_code' => 'US'],
        ]);

        return $order->fresh();
    }

    private function category(): Category
    {
        return Category::firstOrCreate(
            ['slug' => 'gift-cards'],
            ['name' => 'Gift Cards', 'type' => 'digital'],
        );
    }

    private function subcategory(): Subcategory
    {
        return Subcategory::firstOrCreate(
            ['slug' => 'cards'],
            ['category_id' => $this->category()->id, 'name' => 'Cards'],
        );
    }

    private function variant(): ProductVariant
    {
        $product = Product::factory()->create([
            'category_id' => $this->category()->id,
            'subcategory_id' => $this->subcategory()->id,
            'country_code' => 'US',
        ]);

        return ProductVariant::factory()->create(['product_id' => $product->id]);
    }

    private function refund(Order $order, float $revenueReversed, string $kind = OrderRefund::KIND_CUSTOMER_REFUND, ?string $at = null): OrderRefund
    {
        return OrderRefund::query()->create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'kind' => $kind,
            'tag' => 'esim-'.uniqid(),
            'amount' => $revenueReversed,
            'currency' => 'USD',
            'revenue_reversed_usd' => $revenueReversed,
            'refunded_at' => $at ?? now()->subDay(),
        ]);
    }

    private function metrics(): DashboardMetricsQuery
    {
        return app(DashboardMetricsQuery::class);
    }

    /**
     * THE HEADLINE BUG. A refunded order used to disappear from revenue
     * entirely, including from the month it was sold in.
     */
    public function test_a_refunded_order_stays_booked_and_its_refund_is_deducted(): void
    {
        $order = $this->order(status: 'refunded', total: 100.0);
        $this->refund($order, 100.0);

        $metrics = $this->metrics()->getOverviewMetrics();

        $this->assertEqualsWithDelta(0.0, $metrics['total_revenue'], 0.01, 'sale booked, refund deducted, net zero');
        $this->assertSame(1, $metrics['total_orders']);
    }

    public function test_a_refunded_order_used_to_vanish_entirely(): void
    {
        $this->order(status: 'refunded', total: 100.0);

        // No refund row yet: the sale is booked and nothing is deducted.
        $this->assertEqualsWithDelta(
            100.0,
            $this->metrics()->getOverviewMetrics()['total_revenue'],
            0.01,
            'a refunded order must not drop out of the month it was sold in',
        );
    }

    public function test_a_partial_refund_comes_off_a_still_completed_order(): void
    {
        $order = $this->order(total: 100.0);
        $this->refund($order, 30.0);

        $this->assertEqualsWithDelta(70.0, $this->metrics()->getOverviewMetrics()['total_revenue'], 0.01);
    }

    /**
     * An item that never delivered was never revenue, so its reversal has
     * nothing to reverse. Deducting it would double-count the miss.
     */
    public function test_a_fulfilment_reversal_does_not_touch_revenue(): void
    {
        $order = $this->order(total: 100.0);
        $this->refund($order, 0.0, OrderRefund::KIND_FULFILMENT_REVERSAL);

        $this->assertEqualsWithDelta(100.0, $this->metrics()->getOverviewMetrics()['total_revenue'], 0.01);
    }

    public function test_a_partially_completed_order_books_only_its_delivered_items(): void
    {
        $order = $this->order(status: 'partially_completed', total: 100.0, cost: 70.0);

        $failed = $this->variant();

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $failed->product_id,
            'product_variant_id' => $failed->id,
            'category_id' => $this->category()->id,
            'subcategory_id' => $this->subcategory()->id,
            'quantity' => 1,
            'provider_cost_usd' => 40.0,
            'subtotal_amount' => 50.0,
            'fulfillment_status' => FulfillmentStatus::Failed->value,
            'product_snapshot' => ['name' => 'Steam', 'country_code' => 'US'],
        ]);

        $split = $this->metrics()->getMoneySplit();

        $this->assertEqualsWithDelta(100.0, $split['gross_sales_usd'], 0.01, 'the order is booked, not dropped');
        $this->assertEqualsWithDelta(70.0, $split['supplier_cost_usd'], 0.01, 'the failed item cost us nothing');
    }

    public function test_a_supplier_credit_reduces_cost_on_the_day_it_was_granted(): void
    {
        $order = $this->order(total: 100.0, cost: 70.0);
        $this->refund($order, 0.0, OrderRefund::KIND_FULFILMENT_REVERSAL)->forceFill([
            'supplier_credit_status' => OrderRefund::CREDIT_CREDITED,
            'supplier_credit_usd' => 25.0,
            'supplier_credited_at' => now()->subDay(),
        ])->save();

        $this->assertEqualsWithDelta(45.0, $this->metrics()->getMoneySplit()['supplier_cost_usd'], 0.01);
    }

    /**
     * A credit that has not been granted yet is not money we have.
     */
    public function test_a_pending_supplier_credit_does_not_reduce_cost(): void
    {
        $order = $this->order(total: 100.0, cost: 70.0);
        $this->refund($order, 0.0, OrderRefund::KIND_FULFILMENT_REVERSAL)->forceFill([
            'supplier_credit_status' => OrderRefund::CREDIT_PENDING,
            'supplier_credit_usd' => 25.0,
        ])->save();

        $this->assertEqualsWithDelta(70.0, $this->metrics()->getMoneySplit()['supplier_cost_usd'], 0.01);
    }

    /**
     * The whole reason the ledger keeps its own dates: a September sale
     * refunded in October is September revenue and an October deduction.
     */
    public function test_a_refund_lands_in_the_bucket_it_happened_in_not_the_sale_bucket(): void
    {
        $soldOn = now()->subDays(10)->startOfDay();
        $refundedOn = now()->subDays(3)->startOfDay();

        $order = $this->order(total: 100.0, completedAt: $soldOn->toDateTimeString());
        $this->refund($order, 100.0, at: $refundedOn->toDateTimeString());

        $series = collect($this->metrics()->getReportSeries(now()->subDays(20), now(), 'daily'))
            ->keyBy('date');

        $this->assertEqualsWithDelta(100.0, $series[$soldOn->format('Y-m-d')]['sales_usd'], 0.01, 'the sale stays where it happened');
        $this->assertEqualsWithDelta(-100.0, $series[$refundedOn->format('Y-m-d')]['sales_usd'], 0.01, 'the refund lands where it happened');
    }

    public function test_the_reports_page_counts_a_refunded_order_as_a_transaction(): void
    {
        $order = $this->order(status: 'refunded', total: 100.0, completedAt: now()->subDays(2)->toDateTimeString());
        $this->refund($order, 100.0, at: now()->subDays(2)->toDateTimeString());

        $row = collect($this->metrics()->getReportSeries(now()->subDays(20), now(), 'daily'))
            ->firstWhere('date', now()->subDays(2)->format('Y-m-d'));

        $this->assertSame(1, $row['transactions'], 'the sale really happened, it is still a transaction');
        $this->assertEqualsWithDelta(0.0, $row['sales_usd'], 0.01);
    }

    public function test_the_trends_chart_dips_on_the_refund_day(): void
    {
        $order = $this->order(total: 100.0, completedAt: now()->subDays(5)->toDateTimeString());
        $this->refund($order, 40.0, at: now()->subDays(2)->toDateTimeString());

        $series = collect($this->metrics()->getSalesCostTimeseries(30))->keyBy('date');

        $this->assertEqualsWithDelta(100.0, $series[now()->subDays(5)->format('Y-m-d')]['sales'], 0.01);
        $this->assertEqualsWithDelta(-40.0, $series[now()->subDays(2)->format('Y-m-d')]['sales'], 0.01);
    }

    public function test_the_country_map_deducts_a_refund_from_the_country_that_sold_it(): void
    {
        $order = $this->order(total: 100.0, completedAt: now()->subDay()->toDateTimeString());
        $item = $order->items->first();

        $this->refund($order, 30.0)->forceFill([
            'allocations' => [[
                'order_item_id' => $item->id,
                'provider' => 'zendit',
                'category_id' => $item->category_id,
                'revenue_reversed_usd' => 30.0,
            ]],
        ])->save();

        $this->assertEqualsWithDelta(70.0, $this->metrics()->getBestSellingCountries(7)['US'] ?? 0.0, 0.01);
    }

    /**
     * The two pages must agree on what a sale is. If anyone adds a status to
     * one page's idea of "booked" without the other, this fails.
     */
    public function test_overview_and_finances_read_the_same_definition_of_a_booked_sale(): void
    {
        $this->assertSame(
            ['completed', 'partially_completed', 'refunded'],
            OrderStatus::booked(),
        );

        $order = $this->order(status: 'refunded', total: 100.0, completedAt: now()->startOfMonth()->addDay()->toDateTimeString());
        $this->refund($order, 25.0, at: now()->startOfMonth()->addDays(2)->toDateTimeString());

        $overview = $this->metrics()->getOverviewMetrics();
        $finances = app(FinanceReportQuery::class)->monthlyBreakdown(now()->startOfMonth());

        $this->assertEqualsWithDelta(
            75.0,
            $overview['total_revenue'],
            0.01,
            'a $100 sale less a $25 refund is $75 on Overview',
        );
        $this->assertEqualsWithDelta(
            $finances['totals']['revenue_usd'],
            $overview['total_revenue'],
            0.01,
            'Overview and Finances must not disagree about the same month',
        );
    }
}
