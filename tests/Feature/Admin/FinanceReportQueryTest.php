<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Queries\FinanceReportQuery;
use App\Domain\Order\Services\RefundLedger;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceReportQueryTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompletedOrder(
        User $user,
        string $provider,
        float $subtotalUsd,
        float $costUsd,
        Carbon $completedAt,
        string $categoryName = 'Gift Cards',
    ): Order {
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-FIN-'.Str::random(8),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => $subtotalUsd,
            'markup_amount' => 0,
            'total_amount' => $subtotalUsd,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'order_status' => 'completed',
            'placed_at' => $completedAt,
            'completed_at' => $completedAt,
            'metadata' => [
                'exchange_rate' => 1.0,
                'settlement_total_usd' => $subtotalUsd,
                'settlement_subtotal_usd' => $subtotalUsd,
            ],
        ]);

        $category = Category::firstOrCreate(
            ['name' => $categoryName],
            ['slug' => Str::slug($categoryName), 'type' => 'digital', 'is_active' => true, 'sort_order' => 0],
        );
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'provider_name' => $provider,
        ]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => $provider,
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => $subtotalUsd,
            'provider_cost_usd' => $costUsd,
            'markup_amount' => 0,
            'subtotal_amount' => $subtotalUsd,
            'fulfillment_status' => 'fulfilled',
        ]);

        return $order;
    }

    public function test_monthly_breakdown_reports_cost_and_profit_per_provider(): void
    {
        $user = User::factory()->create();

        $this->makeCompletedOrder($user, 'zendit', 20.00, 15.00, now());
        $this->makeCompletedOrder($user, 'airalo', 50.00, 30.00, now(), 'eSIMs');

        $breakdown = app(FinanceReportQuery::class)->monthlyBreakdown(now());

        $providers = collect($breakdown['providers'])->keyBy('provider');

        $this->assertEqualsWithDelta(20.00, $providers['zendit']['revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(15.00, $providers['zendit']['cost_usd'], 0.01);
        $this->assertEqualsWithDelta(5.00, $providers['zendit']['profit_usd'], 0.01);
        $this->assertSame('Zendit', $providers['zendit']['label']);

        $this->assertEqualsWithDelta(50.00, $providers['airalo']['revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(30.00, $providers['airalo']['cost_usd'], 0.01);
        $this->assertEqualsWithDelta(20.00, $providers['airalo']['profit_usd'], 0.01);

        $this->assertSame(2, $breakdown['totals']['orders']);
        $this->assertEqualsWithDelta(70.00, $breakdown['totals']['revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(45.00, $breakdown['totals']['cost_usd'], 0.01);
        $this->assertEqualsWithDelta(25.00, $breakdown['totals']['profit_usd'], 0.01);
        $this->assertEqualsWithDelta(35.71, $breakdown['totals']['margin_pct'], 0.05);
    }

    public function test_breakdown_is_scoped_to_the_requested_month(): void
    {
        $user = User::factory()->create();

        $this->makeCompletedOrder($user, 'zendit', 100.00, 60.00, now()->subMonthNoOverflow());
        $this->makeCompletedOrder($user, 'zendit', 10.00, 6.00, now());

        $breakdown = app(FinanceReportQuery::class)->monthlyBreakdown(now());

        $this->assertEqualsWithDelta(10.00, $breakdown['totals']['revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(100.00, $breakdown['previous_totals']['revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(40.00, $breakdown['previous_totals']['profit_usd'], 0.01);
    }

    public function test_category_rows_split_the_same_money_by_category(): void
    {
        $user = User::factory()->create();

        $this->makeCompletedOrder($user, 'zendit', 20.00, 15.00, now(), 'Gift Cards');
        $this->makeCompletedOrder($user, 'airalo', 50.00, 30.00, now(), 'eSIMs');

        $categories = collect(app(FinanceReportQuery::class)->monthlyBreakdown(now())['categories'])
            ->keyBy('category');

        $this->assertEqualsWithDelta(20.00, $categories['Gift Cards']['revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(50.00, $categories['eSIMs']['revenue_usd'], 0.01);
    }

    /**
     * Refunds are read from the refund ledger, not from wallet refund credits.
     * The wallet-only reading missed every card refund (a Flutterwave refund
     * never touches a wallet), and counted the automatic reversals of
     * undelivered items as if they were refunds of sales.
     */
    public function test_refunds_are_read_from_the_refund_ledger(): void
    {
        $user = User::factory()->create();
        $order = $this->makeCompletedOrder($user, 'airalo', 12.50, 8.00, now()->subMonthNoOverflow(), 'eSIMs');

        $this->customerRefund($order, 12.50, now());

        $refunds = app(FinanceReportQuery::class)->monthlyBreakdown(now())['refunds'];

        $this->assertSame(1, $refunds['count']);
        $this->assertEqualsWithDelta(12.50, $refunds['total_usd'], 0.01);
    }

    /**
     * A wallet refund credit alone is no longer read as a refund of a sale:
     * the ledger is the one source, so a reversal cannot be counted twice.
     */
    public function test_a_bare_wallet_refund_credit_is_not_read_as_a_refund(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'USD']);

        DB::table('wallet_transactions')->insert([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => 12.50,
            'currency' => 'USD',
            'transaction_category' => 'refund',
            'idempotency_key' => 'test-refund-1',
            'balance_before' => 0,
            'balance_after' => 12.50,
            'description' => 'Test refund',
            'reference' => 'refund-test-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, app(FinanceReportQuery::class)->monthlyBreakdown(now())['refunds']['count']);
    }

    // ------------------------------------------------ refunds in the P&L

    /**
     * Bug 1. A full refund set the order to `refunded`, the page read
     * `completed` only, and the sale vanished from the month it was made in:
     * a closed month's revenue AND cost changed after the fact.
     */
    public function test_a_fully_refunded_order_stays_booked_in_the_month_it_sold(): void
    {
        $user = User::factory()->create();
        $soldAt = now()->subMonthNoOverflow()->startOfMonth()->addDays(5);
        $order = $this->makeCompletedOrder($user, 'airalo', 50.00, 30.00, $soldAt, 'eSIMs');

        $order->update(['order_status' => 'refunded', 'payment_status' => 'refunded']);
        $this->customerRefund($order, 50.00, now());

        $lastMonth = app(FinanceReportQuery::class)->monthlyBreakdown($soldAt);

        $this->assertEqualsWithDelta(50.00, $lastMonth['totals']['revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(30.00, $lastMonth['totals']['cost_usd'], 0.01);
        $this->assertSame(1, $lastMonth['totals']['orders']);
    }

    /**
     * Bug 2. A refund comes off revenue in the month it is issued, and leaves
     * the month of the sale alone.
     */
    public function test_a_refund_comes_off_revenue_in_the_month_it_was_issued(): void
    {
        $user = User::factory()->create();
        $soldAt = now()->subMonthNoOverflow()->startOfMonth()->addDays(5);
        $order = $this->makeCompletedOrder($user, 'airalo', 50.00, 30.00, $soldAt, 'eSIMs');
        $this->makeCompletedOrder($user, 'airalo', 20.00, 12.00, now(), 'eSIMs');

        $this->customerRefund($order, 50.00, now());

        $thisMonth = app(FinanceReportQuery::class)->monthlyBreakdown(now());

        // $20 sold this month, $50 refunded this month.
        $this->assertEqualsWithDelta(20.00, $thisMonth['totals']['gross_revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(50.00, $thisMonth['totals']['refunds_usd'], 0.01);
        $this->assertEqualsWithDelta(-30.00, $thisMonth['totals']['revenue_usd'], 0.01);

        // With no supplier credit the cost stays sunk: profit falls by the
        // whole refund.
        $this->assertEqualsWithDelta(12.00, $thisMonth['totals']['cost_usd'], 0.01);
        $this->assertEqualsWithDelta(-42.00, $thisMonth['totals']['profit_usd'], 0.01);

        // The month it sold in is untouched.
        $this->assertEqualsWithDelta(50.00, app(FinanceReportQuery::class)->monthlyBreakdown($soldAt)['totals']['revenue_usd'], 0.01);
    }

    /**
     * Bug 3. A partial refund left the order `completed` and was never taken
     * off revenue.
     */
    public function test_a_partial_refund_comes_off_revenue(): void
    {
        $user = User::factory()->create();
        $order = $this->makeCompletedOrder($user, 'zendit', 40.00, 30.00, now());

        $this->customerRefund($order, 10.00, now());

        $totals = app(FinanceReportQuery::class)->monthlyBreakdown(now())['totals'];

        $this->assertEqualsWithDelta(40.00, $totals['gross_revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(30.00, $totals['revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(0.00, $totals['profit_usd'], 0.01);
    }

    /**
     * Bug 4. A partially fulfilled order is marked `completed`, and its failed
     * item (never delivered, already reversed to the customer) was counted as
     * revenue and as cost.
     */
    public function test_a_failed_item_in_a_completed_order_is_not_revenue(): void
    {
        $user = User::factory()->create();
        $order = $this->makeCompletedOrder($user, 'zendit', 20.00, 15.00, now());
        $this->addItem($order, 'zendit', 30.00, 25.00, 'failed');

        $totals = app(FinanceReportQuery::class)->monthlyBreakdown(now())['totals'];

        $this->assertEqualsWithDelta(20.00, $totals['revenue_usd'], 0.01);
        $this->assertEqualsWithDelta(15.00, $totals['cost_usd'], 0.01);
        $this->assertSame(1, $totals['units']);
    }

    /**
     * The money given back for an undelivered item is a reversal, not a refund
     * of a sale: that item was never booked, so taking it off revenue again
     * would count the same money twice.
     */
    public function test_a_fulfilment_reversal_does_not_come_off_revenue(): void
    {
        $user = User::factory()->create();
        $order = $this->makeCompletedOrder($user, 'zendit', 20.00, 15.00, now());
        $failed = $this->addItem($order, 'zendit', 30.00, 25.00, 'failed');

        OrderRefund::factory()->fulfilmentReversal()->create([
            'order_id' => $order->id,
            'order_item_id' => $failed->id,
            'tag' => 'item-'.$failed->id,
            'amount' => 30.00,
            'refunded_at' => now(),
        ]);

        $breakdown = app(FinanceReportQuery::class)->monthlyBreakdown(now());

        $this->assertEqualsWithDelta(20.00, $breakdown['totals']['revenue_usd'], 0.01);
        $this->assertSame(0, $breakdown['refunds']['count']);
    }

    // ------------------------------------------------ supplier credits

    /**
     * Airalo debits its account credit as we sell, invoices monthly, and
     * credits the account back for a refunded eSIM. The credit lands in the
     * month Airalo grants it, which is the month its invoice carries it.
     */
    public function test_a_supplier_credit_reduces_cost_in_the_month_it_was_granted(): void
    {
        $user = User::factory()->create();
        $soldAt = now()->subMonthNoOverflow()->startOfMonth()->addDays(5);
        $order = $this->makeCompletedOrder($user, 'airalo', 50.00, 30.00, $soldAt, 'eSIMs');

        $refund = $this->customerRefund($order, 50.00, now());
        $refund->update([
            'supplier_credit_status' => OrderRefund::CREDIT_CREDITED,
            'supplier_credit_usd' => 30.00,
            'supplier_credited_at' => now(),
        ]);

        $thisMonth = app(FinanceReportQuery::class)->monthlyBreakdown(now());

        $this->assertEqualsWithDelta(30.00, $thisMonth['totals']['supplier_credits_usd'], 0.01);
        $this->assertEqualsWithDelta(-30.00, $thisMonth['totals']['cost_usd'], 0.01);
        // Refund of $50 revenue, recovery of $30 cost: the sale nets to zero.
        $this->assertEqualsWithDelta(-20.00, $thisMonth['totals']['profit_usd'], 0.01);
        $this->assertSame(1, $thisMonth['supplier_credits']['count']);

        // Across the two months the refunded sale nets to zero profit.
        $lastMonth = app(FinanceReportQuery::class)->monthlyBreakdown($soldAt);
        $this->assertEqualsWithDelta(0.00, $lastMonth['totals']['profit_usd'] + $thisMonth['totals']['profit_usd'], 0.01);
    }

    /**
     * Until the supplier grants a claim the cost is sunk: a pending claim
     * reduces nothing, and the page says how many are out.
     */
    public function test_a_pending_supplier_claim_leaves_the_cost_sunk(): void
    {
        $user = User::factory()->create();
        $order = $this->makeCompletedOrder($user, 'airalo', 50.00, 30.00, now(), 'eSIMs');

        $this->customerRefund($order, 50.00, now())->update(['supplier_credit_status' => OrderRefund::CREDIT_PENDING]);

        $breakdown = app(FinanceReportQuery::class)->monthlyBreakdown(now());

        $this->assertEqualsWithDelta(30.00, $breakdown['totals']['cost_usd'], 0.01);
        $this->assertEqualsWithDelta(0.00, $breakdown['totals']['supplier_credits_usd'], 0.01);
        $this->assertSame(1, $breakdown['supplier_credits']['pending_count']);
    }

    // ------------------------------------------------ breakdowns

    public function test_refunds_and_credits_land_on_their_provider_and_category(): void
    {
        $user = User::factory()->create();
        $esim = $this->makeCompletedOrder($user, 'airalo', 50.00, 30.00, now(), 'eSIMs');
        $this->makeCompletedOrder($user, 'zendit', 20.00, 15.00, now(), 'Gift Cards');

        $this->customerRefund($esim, 50.00, now())->update([
            'supplier_credit_status' => OrderRefund::CREDIT_CREDITED,
            'supplier_credit_usd' => 30.00,
            'supplier_credited_at' => now(),
        ]);

        $breakdown = app(FinanceReportQuery::class)->monthlyBreakdown(now());
        $providers = collect($breakdown['providers'])->keyBy('provider');
        $categories = collect($breakdown['categories'])->keyBy('category');

        $this->assertEqualsWithDelta(50.00, $providers['airalo']['refunds_usd'], 0.01);
        $this->assertEqualsWithDelta(30.00, $providers['airalo']['supplier_credits_usd'], 0.01);
        $this->assertEqualsWithDelta(0.00, $providers['airalo']['profit_usd'], 0.01);
        $this->assertEqualsWithDelta(0.00, $providers['zendit']['refunds_usd'], 0.01);
        $this->assertEqualsWithDelta(5.00, $providers['zendit']['profit_usd'], 0.01);

        $this->assertEqualsWithDelta(50.00, $categories['eSIMs']['refunds_usd'], 0.01);
        $this->assertEqualsWithDelta(0.00, $categories['Gift Cards']['refunds_usd'], 0.01);
    }

    /**
     * A provider refunded this month but with no sales this month still gets
     * its row, carrying the negative revenue.
     */
    public function test_a_provider_with_only_a_refund_this_month_still_appears(): void
    {
        $user = User::factory()->create();
        $order = $this->makeCompletedOrder($user, 'bamboo', 10.00, 7.00, now()->subMonthNoOverflow());

        $this->customerRefund($order, 10.00, now());

        $providers = collect(app(FinanceReportQuery::class)->monthlyBreakdown(now())['providers'])->keyBy('provider');

        $this->assertTrue($providers->has('bamboo'));
        $this->assertEqualsWithDelta(-10.00, $providers['bamboo']['revenue_usd'], 0.01);
    }

    // ------------------------------------------------ helpers

    private function addItem(Order $order, string $provider, float $subtotalUsd, float $costUsd, string $status): OrderItem
    {
        $existing = OrderItem::query()->where('order_id', $order->id)->firstOrFail();
        $product = Product::factory()->create(['category_id' => $existing->category_id, 'provider_name' => $provider]);

        $order->update(['total_amount' => (float) $order->total_amount + $subtotalUsd, 'subtotal_amount' => (float) $order->subtotal_amount + $subtotalUsd]);

        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => ProductVariant::factory()->create(['product_id' => $product->id])->id,
            'category_id' => $existing->category_id,
            'subcategory_id' => $existing->subcategory_id,
            'provider_name' => $provider,
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => $subtotalUsd,
            'provider_cost_usd' => $costUsd,
            'markup_amount' => 0,
            'subtotal_amount' => $subtotalUsd,
            'fulfillment_status' => $status,
        ]);
    }

    /**
     * A customer refund recorded through the real ledger, so the revenue it
     * reverses is computed exactly as production computes it.
     */
    private function customerRefund(Order $order, float $amount, Carbon $at): OrderRefund
    {
        return app(RefundLedger::class)->record($order->fresh(), $amount, 'admin-'.Str::uuid(), [
            'gateway' => 'flutterwave',
            'reason' => 'Customer changed their mind',
            'refunded_at' => $at,
        ]);
    }

    public function test_core_providers_are_always_listed_even_with_zero_sales(): void
    {
        $user = User::factory()->create();
        $this->makeCompletedOrder($user, 'zendit', 20.00, 15.00, now());

        $providers = collect(app(FinanceReportQuery::class)->monthlyBreakdown(now())['providers'])
            ->keyBy('provider');

        // Airalo sold nothing this month but must still appear as a line item.
        $this->assertTrue($providers->has('airalo'));
        $this->assertSame(0, $providers['airalo']['orders']);
        $this->assertEqualsWithDelta(0.0, $providers['airalo']['revenue_usd'], 0.001);
        $this->assertSame('Airalo', $providers['airalo']['label']);
    }

    public function test_core_categories_are_always_listed_even_with_zero_sales(): void
    {
        $user = User::factory()->create();
        $this->makeCompletedOrder($user, 'zendit', 20.00, 15.00, now(), 'Gift Cards');

        // eSIMs exists in the catalog but sold nothing this month.
        Category::firstOrCreate(
            ['name' => 'eSIMs'],
            ['slug' => 'esims', 'type' => 'digital', 'is_active' => true, 'sort_order' => 1],
        );

        $categories = collect(app(FinanceReportQuery::class)->monthlyBreakdown(now())['categories'])
            ->keyBy('category');

        $this->assertEqualsWithDelta(20.00, $categories['Gift Cards']['revenue_usd'], 0.01);
        $this->assertTrue($categories->has('eSIMs'));
        $this->assertSame(0, $categories['eSIMs']['orders']);
    }
}
