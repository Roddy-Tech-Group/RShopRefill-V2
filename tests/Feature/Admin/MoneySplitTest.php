<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Queries\DashboardMetricsQuery;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * getMoneySplit() keeps the two money pools distinct: what the business HOLDS
 * for customers (wallet balances = a liability) vs what it has EARNED (markup =
 * completed sales minus supplier cost). This is the data behind the dashboard's
 * "Money separation" panel.
 */
class MoneySplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_separates_customer_float_from_company_profit(): void
    {
        $user = User::factory()->create();

        // Customer float: $100 held in a USD wallet (counts at face value).
        Wallet::create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'balance' => 100,
            'locked_balance' => 0,
            'is_active' => true,
        ]);

        // A completed order: $30 sale, $27 supplier cost -> $3 company profit.
        $this->completedOrder($user, total: 30.00, providerCost: 27.00);

        // A still-processing order must NOT count toward earnings.
        $this->completedOrder($user, total: 999.00, providerCost: 10.00, status: 'processing');

        $split = app(DashboardMetricsQuery::class)->getMoneySplit();

        $this->assertEqualsWithDelta(100.0, $split['customer_float_usd'], 0.01);
        $this->assertEqualsWithDelta(30.0, $split['gross_sales_usd'], 0.01);
        $this->assertEqualsWithDelta(27.0, $split['supplier_cost_usd'], 0.01);
        $this->assertEqualsWithDelta(3.0, $split['company_profit_usd'], 0.01);
    }

    private function completedOrder(User $user, float $total, float $providerCost, string $status = 'completed'): void
    {
        $category = Category::factory()->create(['slug' => 'gift-cards-'.uniqid()]);
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id]);
        $variant = ProductVariant::factory()->for($product)->create();

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-'.uniqid(),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => $total,
            'markup_amount' => max(0, $total - $providerCost),
            'total_amount' => $total,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'fulfillment_status' => $status === 'completed' ? 'fulfilled' : 'processing',
            'order_status' => $status,
            'placed_at' => now(),
            'completed_at' => $status === 'completed' ? now() : null,
            'metadata' => ['exchange_rate' => 1.0],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => $total,
            'provider_cost_usd' => $providerCost,
            'markup_amount' => max(0, $total - $providerCost),
            'subtotal_amount' => $total,
            'fulfillment_status' => $status === 'completed' ? 'fulfilled' : 'processing',
        ]);
    }
}
