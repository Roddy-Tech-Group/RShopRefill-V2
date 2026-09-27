<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Queries\DashboardMetricsQuery;
use App\Domain\Admin\Queries\FinanceReportQuery;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Services\OrderService;
use App\Domain\Order\Services\RefundLedger;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
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
        ?Category $category = null,
    ): Order {
        $category ??= $this->category();
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

    private function esims(): Category
    {
        return Category::firstOrCreate(
            ['slug' => 'esims'],
            ['name' => 'eSIMs', 'type' => 'digital'],
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

    /**
     * A customer refund recorded through the real ledger, so its per-item
     * allocations carry the item's category exactly as production writes
     * them. A supplier credit, when given, is granted on the same day.
     */
    private function ledgerRefund(Order $order, float $amount, string $at, ?float $supplierCredit = null): OrderRefund
    {
        $context = ['refunded_at' => Carbon::parse($at)];

        if ($supplierCredit !== null) {
            $context += [
                'supplier_credit_status' => OrderRefund::CREDIT_CREDITED,
                'supplier_credit_usd' => $supplierCredit,
                'supplier_credited_at' => Carbon::parse($at),
            ];
        }

        return app(RefundLedger::class)->record($order->fresh(), $amount, 'admin-'.Str::uuid(), $context);
    }

    /**
     * The Reports page's own series for this calendar month, summed.
     *
     * @return array{sales: float, cost: float, profit: float}
     */
    private function reportMonthTotals(?int $categoryId = null): array
    {
        $series = $this->metrics()->getReportSeries(now()->startOfMonth(), now()->endOfMonth(), 'daily', $categoryId);

        return [
            'sales' => round(array_sum(array_column($series, 'sales_usd')), 2),
            'cost' => round(array_sum(array_column($series, 'cost_usd')), 2),
            'profit' => round(array_sum(array_column($series, 'profit_usd')), 2),
        ];
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
     * THE TEST THAT WAS MISSING, and the reason this went to production wrong.
     *
     * An order that completed with one item undelivered. The customer keeps the
     * delivered item and was refunded for the other, so revenue is the
     * delivered item alone. Counting the whole order total as revenue while
     * costing only what delivered reports money we gave back against cost we
     * never paid, and inflates profit by exactly the failed item's cost.
     *
     * Reports and Finances must agree to the cent on the same month. They are
     * different measures of the same thing, and if they diverge one of them is
     * lying.
     */
    public function test_a_failed_item_is_in_neither_revenue_nor_cost_and_reports_matches_finances(): void
    {
        $soldOn = now()->startOfMonth()->addDays(2);

        // Delivered: $100 of goods that cost us $70.
        $order = $this->order(total: 150.0, cost: 70.0, completedAt: $soldOn->toDateTimeString());
        $order->items->first()->forceFill(['subtotal_amount' => 100.0])->save();

        // Undelivered: $50 the customer was refunded, which cost us nothing
        // because it never reached the supplier.
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

        $row = collect($this->metrics()->getReportSeries(now()->startOfMonth(), now()->endOfMonth(), 'daily'))
            ->firstWhere('date', $soldOn->format('Y-m-d'));

        $this->assertEqualsWithDelta(100.0, $row['sales_usd'], 0.01, 'the undelivered $50 was refunded, it is not revenue');
        $this->assertEqualsWithDelta(70.0, $row['cost_usd'], 0.01, 'the undelivered item cost us nothing');
        $this->assertEqualsWithDelta(30.0, $row['profit_usd'], 0.01);

        $finances = app(FinanceReportQuery::class)->monthlyBreakdown(now()->startOfMonth());

        $this->assertEqualsWithDelta(
            $finances['totals']['revenue_usd'],
            $row['sales_usd'],
            0.01,
            'Reports and Finances must not disagree about the same month',
        );
        $this->assertEqualsWithDelta(
            $finances['totals']['cost_usd'],
            $row['cost_usd'],
            0.01,
        );
        $this->assertEqualsWithDelta(
            $finances['totals']['profit_usd'],
            $row['profit_usd'],
            0.01,
        );
    }

    /**
     * The same basis rule on the all-time cards: an undelivered item is in
     * neither gross sales nor supplier cost.
     */
    public function test_the_money_split_leaves_a_failed_item_out_of_both_sides(): void
    {
        $order = $this->order(total: 150.0, cost: 70.0);
        $order->items->first()->forceFill(['subtotal_amount' => 100.0])->save();

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

        $this->assertEqualsWithDelta(100.0, $split['gross_sales_usd'], 0.01);
        $this->assertEqualsWithDelta(70.0, $split['supplier_cost_usd'], 0.01);
        $this->assertEqualsWithDelta(30.0, $split['company_profit_usd'], 0.01);

        $this->assertEqualsWithDelta(100.0, $this->metrics()->getOverviewMetrics()['total_revenue'], 0.01);
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

    // ------------------------------------------------ narrowed to a category

    /**
     * Narrowing Reports to one category narrowed the sales and the cost but
     * still took EVERY category's refunds and supplier credits off them. An
     * eSIM refund showed up as a gift card loss.
     */
    public function test_reports_narrowed_to_a_category_deducts_only_that_categorys_refunds_and_credits(): void
    {
        $soldOn = now()->startOfMonth()->addDay()->toDateTimeString();
        $refundedOn = now()->startOfMonth()->addDays(3)->toDateTimeString();

        $giftCard = $this->order(total: 100.0, cost: 70.0, completedAt: $soldOn);
        $esim = $this->order(total: 10.0, cost: 7.0, completedAt: $soldOn, category: $this->esims());

        $this->ledgerRefund($giftCard, 20.0, $refundedOn);
        $this->ledgerRefund($esim, 10.0, $refundedOn, supplierCredit: 7.0);

        $giftCards = $this->reportMonthTotals($this->category()->id);
        $esims = $this->reportMonthTotals($this->esims()->id);

        $this->assertEqualsWithDelta(80.0, $giftCards['sales'], 0.01, 'the eSIM refund is not a gift card refund');
        $this->assertEqualsWithDelta(70.0, $giftCards['cost'], 0.01, 'the eSIM credit is not a gift card credit');
        $this->assertEqualsWithDelta(0.0, $esims['sales'], 0.01);
        $this->assertEqualsWithDelta(0.0, $esims['cost'], 0.01);

        // The Finances page splits the same month by category from the same
        // allocations, and the two must agree row for row.
        $categories = collect(app(FinanceReportQuery::class)->monthlyBreakdown(now()->startOfMonth())['categories'])
            ->keyBy('category');

        foreach (['Gift Cards' => $giftCards, 'eSIMs' => $esims] as $name => $report) {
            $this->assertEqualsWithDelta($categories[$name]['revenue_usd'], $report['sales'], 0.01, "{$name} revenue");
            $this->assertEqualsWithDelta($categories[$name]['cost_usd'], $report['cost'], 0.01, "{$name} cost");
            $this->assertEqualsWithDelta($categories[$name]['profit_usd'], $report['profit'], 0.01, "{$name} profit");
        }

        // Unfiltered, every refund and credit still comes off.
        $all = $this->reportMonthTotals();
        $this->assertEqualsWithDelta(80.0, $all['sales'], 0.01);
        $this->assertEqualsWithDelta(70.0, $all['cost'], 0.01);
    }

    /**
     * The map used to skip refunds altogether once narrowed to a category.
     * The allocations say which category each refund reversed, so the
     * narrowed map can take off exactly that category's share.
     */
    public function test_the_country_map_narrowed_to_a_category_deducts_that_categorys_refunds(): void
    {
        $giftCard = $this->order(total: 100.0, completedAt: now()->subDays(2)->toDateTimeString());
        $esim = $this->order(total: 10.0, cost: 7.0, completedAt: now()->subDays(2)->toDateTimeString(), category: $this->esims());

        $this->ledgerRefund($giftCard, 30.0, now()->subDay()->toDateTimeString());
        $this->ledgerRefund($esim, 10.0, now()->subDay()->toDateTimeString());

        $this->assertEqualsWithDelta(70.0, $this->metrics()->getBestSellingCountries(7, 'gift')['US'] ?? 0.0, 0.01);
        $this->assertEqualsWithDelta(0.0, $this->metrics()->getBestSellingCountries(7, 'esim')['US'] ?? 0.0, 0.01);
        $this->assertEqualsWithDelta(70.0, $this->metrics()->getBestSellingCountries(7)['US'] ?? 0.0, 0.01);
    }

    /**
     * The revenue-by-category chart can place a refund in its own category on
     * the day it was given back, so it nets refunds like every other page.
     */
    public function test_the_category_revenue_chart_takes_a_refund_off_its_own_category_on_the_day_given_back(): void
    {
        $soldOn = now()->subDays(5);
        $refundedOn = now()->subDays(2);

        $giftCard = $this->order(total: 100.0, completedAt: $soldOn->toDateTimeString());
        $this->order(total: 10.0, cost: 7.0, completedAt: $soldOn->toDateTimeString(), category: $this->esims());
        $this->ledgerRefund($giftCard, 30.0, $refundedOn->toDateTimeString());

        $chart = collect($this->metrics()->getRevenueChartData(30))->keyBy('date');

        $this->assertEqualsWithDelta(100.0, $chart[$soldOn->format('Y-m-d')]['gift_cards'], 0.01);
        $this->assertEqualsWithDelta(10.0, $chart[$soldOn->format('Y-m-d')]['esim'], 0.01);
        $this->assertEqualsWithDelta(-30.0, $chart[$refundedOn->format('Y-m-d')]['gift_cards'], 0.01);
        $this->assertEqualsWithDelta(0.0, $chart[$refundedOn->format('Y-m-d')]['esim'], 0.01);
        $this->assertSame($chart->keys()->sort()->values()->all(), $chart->keys()->all(), 'rows stay in date order');
    }

    // ------------------------------------------------ no per-bucket floor

    /**
     * Each day's cost was floored at zero, so a supplier credit landing on a
     * day with no sales was thrown away. An eSIM sold on the 5th for $10 at a
     * $7 cost and refunded on the 12th with the supplier's $7 credit read on
     * Reports as sales 0, cost 7, profit -7, while Finances read 0 / 0 / 0.
     */
    public function test_a_supplier_credit_on_a_day_with_no_sales_is_not_thrown_away(): void
    {
        $soldOn = now()->startOfMonth()->addDays(4);
        $refundedOn = now()->startOfMonth()->addDays(11);

        $esim = $this->order(total: 10.0, cost: 7.0, completedAt: $soldOn->toDateTimeString(), category: $this->esims());
        $this->ledgerRefund($esim, 10.0, $refundedOn->toDateTimeString(), supplierCredit: 7.0);
        $esim->forceFill(['order_status' => OrderStatus::Refunded])->save();

        $finances = app(FinanceReportQuery::class)->monthlyBreakdown(now()->startOfMonth())['totals'];

        $this->assertEqualsWithDelta(0.0, $finances['revenue_usd'], 0.001);
        $this->assertEqualsWithDelta(0.0, $finances['cost_usd'], 0.001);
        $this->assertEqualsWithDelta(0.0, $finances['profit_usd'], 0.001);

        // The Reports page's own KPI strip for the month, daily buckets.
        $totals = Volt::test('admin.reports')
            ->set('preset', 'custom')
            ->set('start', now()->startOfMonth()->toDateString())
            ->set('end', now()->endOfMonth()->toDateString())
            ->set('granularity', 'daily')
            ->instance()
            ->totals;

        $this->assertEqualsWithDelta($finances['revenue_usd'], $totals['sales'], 0.001, 'Reports sales = Finances revenue');
        $this->assertEqualsWithDelta($finances['cost_usd'], $totals['cost'], 0.001, 'Reports cost = Finances cost');
        $this->assertEqualsWithDelta($finances['profit_usd'], $totals['profit'], 0.001, 'Reports profit = Finances profit');

        // The credit sits on the day it was granted, as a negative cost.
        $refundDay = collect($this->metrics()->getReportSeries(now()->startOfMonth(), now()->endOfMonth(), 'daily'))
            ->firstWhere('date', $refundedOn->format('Y-m-d'));
        $this->assertEqualsWithDelta(-7.0, $refundDay['cost_usd'], 0.001);
        $this->assertEqualsWithDelta(-10.0, $refundDay['sales_usd'], 0.001);
        $this->assertEqualsWithDelta(-3.0, $refundDay['profit_usd'], 0.001);
    }

    public function test_the_trends_chart_keeps_a_supplier_credit_that_lands_on_a_quiet_day(): void
    {
        $esim = $this->order(total: 10.0, cost: 7.0, completedAt: now()->subDays(10)->toDateTimeString(), category: $this->esims());
        $this->ledgerRefund($esim, 10.0, now()->subDays(3)->toDateTimeString(), supplierCredit: 7.0);

        $series = collect($this->metrics()->getSalesCostTimeseries(30));

        $this->assertEqualsWithDelta(0.0, $series->sum('sales'), 0.001);
        $this->assertEqualsWithDelta(0.0, $series->sum('cost'), 0.001, 'the credit comes off in full');
        $this->assertEqualsWithDelta(-7.0, $series->firstWhere('date', now()->subDays(3)->format('Y-m-d'))['cost'], 0.001);
    }

    // ------------------------------------------------ never-booked sales

    /**
     * An order refunded in full while it still waited on the supplier never
     * completed, so its sale was never booked. Overview booked it anyway (the
     * refunded status is a booked status) and counted the undelivered item's
     * cost, while Reports, Trends and Finances booked no sale but still took
     * the refund off. Nothing was sold and nothing was kept, so every page
     * must show nothing.
     */
    public function test_a_refund_of_an_order_that_never_completed_moves_no_page(): void
    {
        $order = $this->order(status: OrderStatus::RequiresAttention->value, total: 30.0, cost: 22.0, itemStatus: 'delayed');
        $order->forceFill(['completed_at' => null])->save();

        // The admin refund path, less the gateway call.
        $orders = app(OrderService::class);
        $orders->recordRefundedAmount($order, 30.0, 'admin-'.Str::uuid(), ['refunded_at' => now()]);
        $orders->markRefunded($order->fresh());

        $order->refresh();
        $this->assertSame(OrderStatus::Refunded, $order->order_status);
        $this->assertNull($order->completed_at);
        $this->assertEqualsWithDelta(30.0, (float) OrderRefund::query()->sole()->revenue_reversed_usd, 0.001, 'the ledger records the refund');

        $overview = $this->metrics()->getOverviewMetrics();
        $this->assertEqualsWithDelta(0.0, $overview['total_revenue'], 0.001);
        $this->assertEqualsWithDelta(0.0, $overview['donuts']['completed_orders_pct'], 0.001, 'it never completed');

        $split = $this->metrics()->getMoneySplit();
        $this->assertEqualsWithDelta(0.0, $split['gross_sales_usd'], 0.001);
        $this->assertEqualsWithDelta(0.0, $split['supplier_cost_usd'], 0.001, 'an undelivered item is not a cost');

        $reports = $this->reportMonthTotals();
        $this->assertEqualsWithDelta(0.0, $reports['sales'], 0.001, 'a refund of a sale never booked reverses nothing');
        $this->assertEqualsWithDelta(0.0, $reports['cost'], 0.001);

        $this->assertEqualsWithDelta(0.0, collect($this->metrics()->getSalesCostTimeseries(30))->sum('sales'), 0.001);

        $finances = app(FinanceReportQuery::class)->monthlyBreakdown(now()->startOfMonth());
        $this->assertEqualsWithDelta(0.0, $finances['totals']['revenue_usd'], 0.001);
        $this->assertEqualsWithDelta(0.0, $finances['totals']['refunds_usd'], 0.001);
        $this->assertEqualsWithDelta(0.0, $finances['totals']['cost_usd'], 0.001);
        $this->assertSame(0, $finances['refunds']['count']);
    }

    /**
     * The same order, delivered after all. Completion books the sale, and
     * from then on the refund reverses it: revenue nets to zero and the cost
     * of goods handed over for free is a real loss, on every page alike.
     */
    public function test_a_refunded_order_that_delivers_after_all_books_the_sale_and_its_refund(): void
    {
        $order = $this->order(status: OrderStatus::RequiresAttention->value, total: 30.0, cost: 22.0, itemStatus: 'delayed');
        $order->forceFill(['completed_at' => null])->save();

        $orders = app(OrderService::class);
        $orders->recordRefundedAmount($order, 30.0, 'admin-'.Str::uuid(), ['refunded_at' => now()]);
        $orders->markRefunded($order->fresh());

        $order->items()->update(['fulfillment_status' => 'fulfilled']);
        $order->fresh()->forceFill(['order_status' => OrderStatus::Completed, 'completed_at' => now()])->save();

        $finances = app(FinanceReportQuery::class)->monthlyBreakdown(now()->startOfMonth())['totals'];
        $this->assertEqualsWithDelta(0.0, $finances['revenue_usd'], 0.001);
        $this->assertEqualsWithDelta(22.0, $finances['cost_usd'], 0.001);

        $reports = $this->reportMonthTotals();
        $this->assertEqualsWithDelta($finances['revenue_usd'], $reports['sales'], 0.001);
        $this->assertEqualsWithDelta($finances['cost_usd'], $reports['cost'], 0.001);

        $this->assertEqualsWithDelta(0.0, $this->metrics()->getOverviewMetrics()['total_revenue'], 0.001);
        $this->assertEqualsWithDelta(22.0, $this->metrics()->getMoneySplit()['supplier_cost_usd'], 0.001);
    }

    /**
     * A refund taken in September while the order still waited on the
     * supplier, on an order that delivers in October. September booked no
     * sale and has closed, so the refund must not reach back into it once the
     * order completes. It used to: it was dated by its own day, so September
     * dropped by $10 after the fact. It reverses the sale where the sale was
     * booked, in October, on every page.
     */
    public function test_a_refund_taken_before_the_order_completes_never_reopens_a_closed_month(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));

        $order = $this->order(status: OrderStatus::RequiresAttention->value, total: 30.0, cost: 22.0, itemStatus: 'delayed');
        $order->forceFill(['completed_at' => null])->save();
        app(OrderService::class)->recordRefundedAmount($order, 10.0, 'admin-'.Str::uuid(), ['refunded_at' => now()]);

        $september = Carbon::parse('2026-09-01');
        $october = Carbon::parse('2026-10-01');
        $finances = app(FinanceReportQuery::class);

        $closed = $finances->monthlyBreakdown($september);
        $this->assertEqualsWithDelta(0.0, $closed['totals']['refunds_usd'], 0.001, 'no sale booked yet, so nothing to reverse');

        // October: the item delivers and the order completes.
        $this->travelTo(Carbon::parse('2026-10-05 09:00:00'));
        $order->items()->update(['fulfillment_status' => 'fulfilled']);
        $order->fresh()->forceFill(['order_status' => OrderStatus::Completed, 'completed_at' => now()])->save();

        $reopened = $finances->monthlyBreakdown($september);
        $this->assertSame($closed['totals'], $reopened['totals'], 'a closed month stays closed');
        $this->assertSame(0, $reopened['refunds']['count']);

        $booked = $finances->monthlyBreakdown($october);
        $this->assertEqualsWithDelta(30.0, $booked['totals']['gross_revenue_usd'], 0.001);
        $this->assertEqualsWithDelta(10.0, $booked['totals']['refunds_usd'], 0.001, 'the refund lands with the sale it reverses');
        $this->assertEqualsWithDelta(20.0, $booked['totals']['revenue_usd'], 0.001);
        $this->assertEqualsWithDelta(22.0, $booked['totals']['cost_usd'], 0.001);
        $this->assertSame(1, $booked['refunds']['count']);

        // Reports, Trends, the category chart and the map place it the same
        // way: on the day the order completed.
        $septemberReport = $this->metrics()->getReportSeries($september, $september->copy()->endOfMonth(), 'daily');
        $this->assertEqualsWithDelta(0.0, array_sum(array_column($septemberReport, 'sales_usd')), 0.001);

        $completionDay = collect($this->metrics()->getReportSeries($october, $october->copy()->endOfMonth(), 'daily'))
            ->firstWhere('date', '2026-10-05');
        $this->assertEqualsWithDelta(20.0, $completionDay['sales_usd'], 0.001);

        $narrowed = collect($this->metrics()->getReportSeries($october, $october->copy()->endOfMonth(), 'daily', $this->category()->id))
            ->firstWhere('date', '2026-10-05');
        $this->assertEqualsWithDelta(20.0, $narrowed['sales_usd'], 0.001);

        $trends = collect($this->metrics()->getSalesCostTimeseries(30))->keyBy('date');
        $this->assertEqualsWithDelta(0.0, $trends['2026-09-20']['sales'], 0.001);
        $this->assertEqualsWithDelta(20.0, $trends['2026-10-05']['sales'], 0.001);

        $chart = collect($this->metrics()->getRevenueChartData(30))->keyBy('date');
        $this->assertArrayNotHasKey('2026-09-20', $chart->all(), 'no refund row in September');
        $this->assertEqualsWithDelta(20.0, $chart['2026-10-05']['gift_cards'], 0.001);

        $this->assertEqualsWithDelta(20.0, $this->metrics()->getBestSellingCountries(3)['US'] ?? 0.0, 0.001, 'the refund is inside the window the sale is');
        $this->assertEqualsWithDelta(20.0, $this->metrics()->getOverviewMetrics()['total_revenue'], 0.001);
    }
}
