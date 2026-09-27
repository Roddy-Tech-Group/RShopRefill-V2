<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Queries\DashboardMetricsQuery;
use App\Domain\Admin\Queries\FinanceReportQuery;
use App\Domain\Admin\Queries\LeaderboardQuery;
use App\Domain\Admin\Support\RefundAdjustments;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Revenue on the admin money pages is what the customer paid in cash.
 *
 * Checkout takes Rcoin (reward points) off the order total after the item
 * prices are set, so a $20 item paid with $6 of Rcoin and $14 of cash still
 * carries a $20 subtotal. Every money page summed that subtotal and reported
 * $20 of revenue for $14 of money. A coupon never had this problem, because
 * checkout lowers the item's own price for it.
 *
 * The rule proved here: revenue counts only the cash-paid share of each
 * order, a refund takes off only the share that was counted, cost is
 * untouched, and the customer rankings keep reading the full price.
 */
class RcoinCashRevenueTest extends TestCase
{
    use RefreshDatabase;

    private const SOLD_ON = '2026-09-10 10:00:00';

    private const REFUNDED_ON = '2026-09-12 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
    }

    /**
     * A completed order with one delivered item, shaped the way checkout
     * writes it: the item carries its full price in the display currency,
     * total_amount is what was left to pay in cash, and the metadata records
     * both halves in USD.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function order(
        float $itemUsd,
        float $cost,
        array $metadata,
        float $rate = 1.0,
        string $currency = 'USD',
        ?User $user = null,
    ): Order {
        $variant = $this->variant();
        $cashUsd = (float) ($metadata['settlement_total_usd'] ?? $itemUsd);

        $order = Order::factory()->create([
            'user_id' => ($user ?? User::factory()->create())->id,
            'order_status' => 'completed',
            'payment_status' => PaymentStatus::Paid,
            'settlement_currency' => $currency,
            'display_currency' => $currency,
            'subtotal_amount' => $itemUsd * $rate,
            'total_amount' => $cashUsd * $rate,
            'completed_at' => self::SOLD_ON,
            'metadata' => ['exchange_rate' => $rate] + $metadata,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'category_id' => $this->category()->id,
            'subcategory_id' => $this->subcategory()->id,
            'provider_name' => 'zendit',
            'quantity' => 1,
            'display_currency' => $currency,
            'provider_cost_usd' => $cost,
            'subtotal_amount' => $itemUsd * $rate,
            'fulfillment_status' => 'fulfilled',
            'product_snapshot' => ['name' => 'Amazon', 'country_code' => 'US'],
        ]);

        return $order->fresh();
    }

    /**
     * $20 of goods, $6 paid with Rcoin and $14 in cash, costing us $12.
     * Whole numbers on purpose: sqlite divides whole numbers as integers.
     */
    private function partRcoinOrder(?User $user = null): Order
    {
        return $this->order(20.0, 12.0, [
            'settlement_total_usd' => 14,
            'rcoin_applied' => 600,
            'rcoin_discount_usd' => 6,
        ], user: $user);
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
            'provider_name' => 'zendit',
        ]);

        return ProductVariant::factory()->create(['product_id' => $product->id]);
    }

    /**
     * A customer refund of the whole captured amount, recorded through the
     * real ledger exactly as the admin refund path writes it.
     */
    private function refundInFull(Order $order): OrderRefund
    {
        return app(RefundLedger::class)->record(
            $order->fresh(),
            (float) $order->total_amount,
            'admin-'.Str::uuid(),
            ['refunded_at' => Carbon::parse(self::REFUNDED_ON)],
        );
    }

    private function metrics(): DashboardMetricsQuery
    {
        return app(DashboardMetricsQuery::class);
    }

    /**
     * Every admin money figure for September 2026, one entry per page.
     *
     * @return array<string, float>
     */
    private function revenueEverywhere(): array
    {
        $september = Carbon::parse('2026-09-01');
        $report = $this->metrics()->getReportSeries($september, $september->copy()->endOfMonth(), 'daily');
        $narrowed = $this->metrics()->getReportSeries($september, $september->copy()->endOfMonth(), 'daily', $this->category()->id);
        $finances = app(FinanceReportQuery::class)->monthlyBreakdown($september);

        return [
            'overview' => round($this->metrics()->getOverviewMetrics()['total_revenue'], 2),
            'money split' => round($this->metrics()->getMoneySplit()['gross_sales_usd'], 2),
            'reports' => round(array_sum(array_column($report, 'sales_usd')), 2),
            'reports by category' => round(array_sum(array_column($narrowed, 'sales_usd')), 2),
            'trends' => round(collect($this->metrics()->getSalesCostTimeseries(30))->sum('sales'), 2),
            'map' => round($this->metrics()->getBestSellingCountries(30)['US'] ?? 0.0, 2),
            'category chart' => round(collect($this->metrics()->getRevenueChartData(30))->sum('gift_cards'), 2),
            'finances' => round($finances['totals']['revenue_usd'], 2),
            'finances by category' => round(collect($finances['categories'])->firstWhere('category', 'Gift Cards')['revenue_usd'], 2),
            'finances by provider' => round(collect($finances['providers'])->firstWhere('provider', 'zendit')['revenue_usd'], 2),
        ];
    }

    /**
     * @return array<string, float>
     */
    private function everywhere(float $amount): array
    {
        return array_fill_keys(array_keys($this->revenueEverywhere()), $amount);
    }

    public function test_the_part_paid_with_rcoin_is_not_revenue_on_any_money_page(): void
    {
        $this->partRcoinOrder();

        $this->assertSame($this->everywhere(14.0), $this->revenueEverywhere(), 'the $6 of Rcoin is not revenue');

        $split = $this->metrics()->getMoneySplit();
        $this->assertEqualsWithDelta(12.0, $split['supplier_cost_usd'], 0.001, 'cost is unchanged');
        $this->assertEqualsWithDelta(2.0, $split['company_profit_usd'], 0.001);

        $finances = app(FinanceReportQuery::class)->monthlyBreakdown(Carbon::parse('2026-09-01'))['totals'];
        $this->assertEqualsWithDelta(14.0, $finances['gross_revenue_usd'], 0.001);
        $this->assertEqualsWithDelta(12.0, $finances['cost_usd'], 0.001);
        $this->assertEqualsWithDelta(2.0, $finances['profit_usd'], 0.001);

        $this->artisan('money:report')
            ->assertSuccessful()
            ->expectsOutputToContain('Gross sales:         $14.00')
            ->expectsOutputToContain('Profit:              $2.00');
    }

    /**
     * The ledger reverses the item's full $20, because the customer got back
     * everything they paid for it. Only $14 of that was ever counted, so only
     * $14 comes off. Taking $20 off would leave the month $6 below zero.
     */
    public function test_a_full_refund_reverses_only_the_cash_that_was_counted(): void
    {
        $order = $this->partRcoinOrder();
        $refund = $this->refundInFull($order);

        $this->assertEqualsWithDelta(20.0, (float) $refund->revenue_reversed_usd, 0.001, 'the ledger row keeps the full price');

        $this->assertSame($this->everywhere(0.0), $this->revenueEverywhere());

        $finances = app(FinanceReportQuery::class)->monthlyBreakdown(Carbon::parse('2026-09-01'));
        $this->assertEqualsWithDelta(14.0, $finances['totals']['refunds_usd'], 0.001);
        $this->assertEqualsWithDelta(14.0, $finances['refunds']['total_usd'], 0.001);
        $this->assertEqualsWithDelta(14.0, collect($finances['categories'])->firstWhere('category', 'Gift Cards')['refunds_usd'], 0.001);
        $this->assertEqualsWithDelta(14.0, collect($finances['providers'])->firstWhere('provider', 'zendit')['refunds_usd'], 0.001);

        // Every RefundAdjustments reading of the same refund agrees.
        $adjustments = app(RefundAdjustments::class);
        $start = Carbon::parse('2026-09-01');
        $end = $start->copy()->endOfMonth();
        $categoryId = $this->category()->id;

        $this->assertEqualsWithDelta(14.0, $adjustments->customerRefundsUsd(), 0.001);
        $this->assertEqualsWithDelta(14.0, $adjustments->customerRefundsUsd($start, $end), 0.001);
        $this->assertEqualsWithDelta(14.0, $adjustments->customerRefundsByBucket($start, $end)['2026-09-12'], 0.001);
        $this->assertEqualsWithDelta(14.0, $adjustments->customerRefundsByBucket($start, $end, 'daily', $categoryId)['2026-09-12'], 0.001);
        $this->assertEqualsWithDelta(14.0, $adjustments->customerRefundsByCategory($start, $end)['2026-09-12'][$categoryId], 0.001);
        $this->assertEqualsWithDelta(14.0, $adjustments->customerRefundsByCountry($start, $end)['US'], 0.001);
        $this->assertEqualsWithDelta(14.0, $adjustments->customerRefundsByCountry($start, $end, [$categoryId])['US'], 0.001);
    }

    /**
     * Rcoin is a real cost to the business: the supplier was paid in full for
     * goods the customer paid for with points.
     */
    public function test_an_order_paid_entirely_with_rcoin_earns_nothing_but_still_costs(): void
    {
        $this->order(20.0, 12.0, [
            'settlement_total_usd' => 0,
            'rcoin_applied' => 2000,
            'rcoin_discount_usd' => 20,
        ]);

        $this->assertSame($this->everywhere(0.0), $this->revenueEverywhere());

        $split = $this->metrics()->getMoneySplit();
        $this->assertEqualsWithDelta(12.0, $split['supplier_cost_usd'], 0.001);

        $finances = app(FinanceReportQuery::class)->monthlyBreakdown(Carbon::parse('2026-09-01'))['totals'];
        $this->assertEqualsWithDelta(12.0, $finances['cost_usd'], 0.001);
        $this->assertEqualsWithDelta(-12.0, $finances['profit_usd'], 0.001);

        $september = Carbon::parse('2026-09-01');
        $report = $this->metrics()->getReportSeries($september, $september->copy()->endOfMonth(), 'daily');
        $this->assertEqualsWithDelta(12.0, array_sum(array_column($report, 'cost_usd')), 0.001);
        $this->assertEqualsWithDelta(-12.0, array_sum(array_column($report, 'profit_usd')), 0.001);
    }

    public function test_an_order_without_rcoin_counts_in_full(): void
    {
        // No Rcoin keys at all, the way older orders and eSIM top-ups look.
        $order = $this->order(20.0, 12.0, ['settlement_total_usd' => 20]);
        // Checkout with Rcoin switched off writes a zero.
        $this->order(20.0, 12.0, ['settlement_total_usd' => 20, 'rcoin_applied' => 0, 'rcoin_discount_usd' => 0.0]);

        $this->assertSame($this->everywhere(40.0), $this->revenueEverywhere());

        $this->refundInFull($order);

        $finances = app(FinanceReportQuery::class)->monthlyBreakdown(Carbon::parse('2026-09-01'))['totals'];
        $this->assertEqualsWithDelta(20.0, $finances['refunds_usd'], 0.001, 'a refund of a cash order comes off whole');
        $this->assertEqualsWithDelta(20.0, $finances['revenue_usd'], 0.001);
    }

    /**
     * Both halves are recorded in USD whatever the customer saw, so the share
     * holds for a franc order: 12,000 XAF of goods, $6 of it Rcoin.
     */
    public function test_a_display_currency_order_with_rcoin_counts_its_cash_in_usd(): void
    {
        $order = $this->order(20.0, 12.0, [
            'settlement_total_usd' => 14.0,
            'rcoin_applied' => 600,
            'rcoin_discount_usd' => 6.0,
        ], rate: 600.0, currency: 'XAF');

        $this->assertSame(12000.0, (float) $order->items->first()->subtotal_amount);
        $this->assertSame($this->everywhere(14.0), $this->revenueEverywhere());

        $this->refundInFull($order);

        $this->assertSame($this->everywhere(0.0), $this->revenueEverywhere());
        $this->assertEqualsWithDelta(
            14.0,
            app(FinanceReportQuery::class)->monthlyBreakdown(Carbon::parse('2026-09-01'))['totals']['refunds_usd'],
            0.001,
        );
    }

    /**
     * The customer rankings are not revenue. They rank what a customer
     * bought at its full price, and stay exactly as they were.
     */
    public function test_the_leaderboard_is_unchanged_for_the_same_customer(): void
    {
        $customer = User::factory()->create();
        $this->partRcoinOrder($customer);

        $boards = app(LeaderboardQuery::class)->boards(null);

        $margin = collect($boards['highest_margin'])->firstWhere('user_id', $customer->id);
        $this->assertEqualsWithDelta(20.0, $margin['revenue_usd'], 0.001);
        $this->assertEqualsWithDelta(8.0, $margin['margin_usd'], 0.001);

        $giftCards = collect($boards['most_gift_cards'])->firstWhere('user_id', $customer->id);
        $this->assertEqualsWithDelta(20.0, $giftCards['revenue_usd'], 0.001);

        $top = collect($boards['top_customers'])->firstWhere('user_id', $customer->id);
        $this->assertEqualsWithDelta(14.0, $top['spend_usd'], 0.001, 'spend already read the settlement figure');
    }

    /**
     * The SQL share and the PHP share are one rule. Reports sums in PHP and
     * the other pages in SQL, and they must agree on every shape of order.
     */
    public function test_the_sql_and_php_cash_shares_agree(): void
    {
        $cases = [
            'no Rcoin keys' => [[], 1.0],
            'Rcoin zero' => [['settlement_total_usd' => 20, 'rcoin_discount_usd' => 0], 1.0],
            'part Rcoin' => [['settlement_total_usd' => 14, 'rcoin_discount_usd' => 6], 0.7],
            'part Rcoin, decimals' => [['settlement_total_usd' => 7.5, 'rcoin_discount_usd' => 2.5], 0.75],
            'all Rcoin' => [['settlement_total_usd' => 0, 'rcoin_discount_usd' => 20], 0.0],
            'Rcoin without a settlement figure' => [['rcoin_discount_usd' => 6], 1.0],
        ];

        foreach ($cases as $label => [$metadata, $share]) {
            $order = $this->order(20.0, 12.0, $metadata);

            $this->assertEqualsWithDelta($share, $order->cashShare(), 0.0001, "{$label}: PHP");

            $sql = (float) DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('orders.id', $order->id)
                ->value(DB::raw($this->metrics()->itemCashUsdSql()));

            $this->assertEqualsWithDelta(20.0 * $share, $sql, 0.0001, "{$label}: SQL");
        }
    }
}
