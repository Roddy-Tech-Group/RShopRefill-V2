<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Queries\DashboardMetricsQuery;
use App\Domain\Admin\Queries\FinanceReportQuery;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Services\OrderService;
use App\Domain\Order\Services\RefundLedger;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Refunds on orders partly paid with Rcoin, where the whole-order cases in
 * RcoinCashRevenueTest cannot tell right from wrong: a partial refund split
 * across categories, an item-level refund in a display currency, and a refund
 * taken before the order completed. Each page must take off only the cash
 * share of what it reverses, and put it in the right category and month.
 */
class RcoinRefundAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
    }

    public function test_a_partial_refund_of_a_two_category_rcoin_order_splits_its_cash_across_every_page(): void
    {
        $gift = $this->category('gift-cards', 'Gift Cards');
        $esim = $this->category('esims', 'eSIMs');

        // $10 gift card + $10 eSIM, paid $6 Rcoin + $14 cash.
        $order = $this->rcoinOrder([[$gift, 10.0, 6.0], [$esim, 10.0, 6.0]], ['settlement_total_usd' => 14, 'rcoin_discount_usd' => 6]);

        app(RefundLedger::class)->record($order->fresh(), 7.0, 'admin-'.Str::uuid(), ['refunded_at' => Carbon::parse('2026-09-12 10:00:00')]);

        $finances = app(FinanceReportQuery::class)->monthlyBreakdown(Carbon::parse('2026-09-01'));
        $categories = collect($finances['categories'])->keyBy('category');

        $this->assertEqualsWithDelta(14.0, $finances['totals']['gross_revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(7.0, $finances['totals']['refunds_usd'], 0.01);
        $this->assertEqualsWithDelta(7.0, $finances['totals']['revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(3.5, $categories['Gift Cards']['revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(3.5, $categories['eSIMs']['revenue_usd'], 0.01);

        $this->assertEqualsWithDelta(7.0, $this->reportSales(), 0.01);
        $this->assertEqualsWithDelta(3.5, $this->reportSales($gift->id), 0.01);
        $this->assertEqualsWithDelta(3.5, $this->reportSales($esim->id), 0.01);
        $this->assertEqualsWithDelta(7.0, $this->metrics()->getOverviewMetrics()['total_revenue'], 0.01);
        $this->assertEqualsWithDelta(7.0, $this->metrics()->getOverviewMetrics('30d')['total_revenue'], 0.01);
        $this->assertEqualsWithDelta(7.0, $this->metrics()->getMoneySplit()['gross_sales_usd'], 0.01);
        $this->assertEqualsWithDelta(7.0, collect($this->metrics()->getSalesCostTimeseries(30))->sum('sales'), 0.01);
        $this->assertEqualsWithDelta(3.5, $this->metrics()->getBestSellingCountries(30, 'gift')['US'] ?? 0, 0.01);
        $this->assertEqualsWithDelta(3.5, $this->metrics()->getBestSellingCountries(30, 'esim')['US'] ?? 0, 0.01);

        $chart = collect($this->metrics()->getRevenueChartData(30));
        $this->assertEqualsWithDelta(3.5, $chart->sum('gift_cards'), 0.01);
        $this->assertEqualsWithDelta(3.5, $chart->sum('esim'), 0.01);
    }

    public function test_an_item_refund_on_a_display_currency_rcoin_order_reverses_only_that_items_cash(): void
    {
        $gift = $this->category('gift-cards', 'Gift Cards');
        $esim = $this->category('esims', 'eSIMs');

        // XAF at 600: $15 gift card + $5 eSIM, $4 Rcoin + $16 cash (cash share 0.8).
        $order = $this->rcoinOrder([[$gift, 15.0, 9.0], [$esim, 5.0, 3.0]], ['settlement_total_usd' => 16.0, 'rcoin_discount_usd' => 4.0], rate: 600.0, currency: 'XAF');
        $esimItem = $order->items->firstWhere('category_id', $esim->id);

        // The eSIM's captured cash: 5/20 of 9600 XAF = 2400 XAF.
        app(RefundLedger::class)->record($order->fresh(), 2400.0, 'esim-'.Str::uuid(), [
            'order_item_id' => (string) $esimItem->id,
            'refunded_at' => Carbon::parse('2026-09-12 10:00:00'),
        ]);

        $finances = app(FinanceReportQuery::class)->monthlyBreakdown(Carbon::parse('2026-09-01'));
        $categories = collect($finances['categories'])->keyBy('category');

        $this->assertEqualsWithDelta(16.0, $finances['totals']['gross_revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(4.0, $finances['totals']['refunds_usd'], 0.01);
        $this->assertEqualsWithDelta(12.0, $categories['Gift Cards']['revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(0.0, $categories['eSIMs']['revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(0.0, $this->reportSales($esim->id), 0.01);
        $this->assertEqualsWithDelta(12.0, $this->reportSales($gift->id), 0.01);
        $this->assertEqualsWithDelta(12.0, $this->reportSales(), 0.01);
    }

    public function test_an_rcoin_refund_taken_before_completion_lands_in_the_completion_month(): void
    {
        $gift = $this->category('gift-cards', 'Gift Cards');

        $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
        $order = $this->rcoinOrder([[$gift, 20.0, 12.0]], ['settlement_total_usd' => 14, 'rcoin_discount_usd' => 6], status: OrderStatus::RequiresAttention->value, completedAt: null, itemStatus: 'delayed');
        app(OrderService::class)->recordRefundedAmount($order, 7.0, 'admin-'.Str::uuid(), ['refunded_at' => now()]);

        $this->travelTo(Carbon::parse('2026-10-05 09:00:00'));
        $order->items()->update(['fulfillment_status' => 'fulfilled']);
        $order->fresh()->forceFill(['order_status' => OrderStatus::Completed, 'completed_at' => now()])->save();

        $october = app(FinanceReportQuery::class)->monthlyBreakdown(Carbon::parse('2026-10-01'))['totals'];

        $this->assertEqualsWithDelta(14.0, $october['gross_revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(7.0, $october['refunds_usd'], 0.01);
        $this->assertEqualsWithDelta(7.0, $this->reportSales(null, '2026-10-01'), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->reportSales(null, '2026-09-01'), 0.01);
    }

    public function test_reading_refunds_costs_the_same_number_of_queries_however_many_there_are(): void
    {
        $gift = $this->category('gift-cards', 'Gift Cards');

        $this->refundedRcoinOrders($gift, 5);
        $atFive = $this->queryCounts($gift);

        $this->refundedRcoinOrders($gift, 5);
        $atTen = $this->queryCounts($gift);

        $this->assertSame($atFive, $atTen, 'query count must not grow with refund rows');
    }

    private function category(string $slug, string $name): Category
    {
        return Category::firstOrCreate(['slug' => $slug], ['name' => $name, 'type' => 'digital']);
    }

    private function subcategoryFor(Category $category): Subcategory
    {
        return Subcategory::firstOrCreate(['slug' => $category->slug.'-sub'], ['category_id' => $category->id, 'name' => $category->name.' sub']);
    }

    /**
     * @param  list<array{0: Category, 1: float, 2: float}>  $items  [category, usd price, usd cost]
     * @param  array<string, mixed>  $metadata
     */
    private function rcoinOrder(array $items, array $metadata, float $rate = 1.0, string $currency = 'USD', string $status = 'completed', ?string $completedAt = '2026-09-10 10:00:00', string $itemStatus = 'fulfilled'): Order
    {
        $cash = (float) ($metadata['settlement_total_usd'] ?? array_sum(array_column($items, 1)));

        $order = Order::factory()->create([
            'user_id' => User::factory()->create()->id,
            'order_status' => $status,
            'payment_status' => PaymentStatus::Paid,
            'settlement_currency' => $currency,
            'display_currency' => $currency,
            'subtotal_amount' => array_sum(array_column($items, 1)) * $rate,
            'total_amount' => $cash * $rate,
            'completed_at' => $completedAt,
            'metadata' => ['exchange_rate' => $rate] + $metadata,
        ]);

        foreach ($items as [$category, $usd, $cost]) {
            $product = Product::factory()->create(['category_id' => $category->id, 'subcategory_id' => $this->subcategoryFor($category)->id, 'country_code' => 'US', 'provider_name' => 'p1']);
            $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'category_id' => $category->id,
                'subcategory_id' => $this->subcategoryFor($category)->id,
                'provider_name' => 'p1',
                'quantity' => 1,
                'display_currency' => $currency,
                'provider_cost_usd' => $cost,
                'subtotal_amount' => $usd * $rate,
                'fulfillment_status' => $itemStatus,
                'product_snapshot' => ['name' => 'X', 'country_code' => 'US'],
            ]);
        }

        return $order->fresh('items');
    }

    private function refundedRcoinOrders(Category $category, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $order = $this->rcoinOrder([[$category, 20.0, 12.0]], ['settlement_total_usd' => 14, 'rcoin_discount_usd' => 6]);
            app(RefundLedger::class)->record($order->fresh(), 7.0, 'admin-'.Str::uuid(), ['refunded_at' => Carbon::parse('2026-09-12 10:00:00')]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function queryCounts(Category $category): array
    {
        $count = function (callable $read): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $read();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        return [
            'overview' => $count(fn () => $this->metrics()->getOverviewMetrics()),
            'split' => $count(fn () => $this->metrics()->getMoneySplit()),
            'report' => $count(fn () => $this->metrics()->getReportSeries(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), 'daily', $category->id)),
            'finances' => $count(fn () => app(FinanceReportQuery::class)->monthlyBreakdown(Carbon::parse('2026-09-01'))),
            'map' => $count(fn () => $this->metrics()->getBestSellingCountries(30, 'gift')),
            'chart' => $count(fn () => $this->metrics()->getRevenueChartData(30)),
        ];
    }

    private function metrics(): DashboardMetricsQuery
    {
        return app(DashboardMetricsQuery::class);
    }

    private function reportSales(?int $categoryId = null, string $month = '2026-09-01'): float
    {
        $start = Carbon::parse($month);

        return round(array_sum(array_column($this->metrics()->getReportSeries($start, $start->copy()->endOfMonth(), 'daily', $categoryId), 'sales_usd')), 4);
    }
}
