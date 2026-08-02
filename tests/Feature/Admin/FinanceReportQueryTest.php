<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Queries\FinanceReportQuery;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
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

    public function test_refunds_are_summed_from_refund_wallet_credits(): void
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

        $refunds = app(FinanceReportQuery::class)->monthlyBreakdown(now())['refunds'];

        $this->assertSame(1, $refunds['count']);
        $this->assertEqualsWithDelta(12.50, $refunds['total_usd'], 0.01);
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
