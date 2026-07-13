<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The money:report command separates the money the business HOLDS for customers
 * (wallet float, a liability) from what it has EARNED (profit), and lets the
 * owner carve their own accounts out of the float with --mine.
 */
class MoneyReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_the_split_and_isolates_owner_accounts(): void
    {
        // A real customer holding $100.
        $customer = User::factory()->create(['email' => 'customer@example.com']);
        $this->fundedWallet($customer, 100);

        // The owner's own test account holding $40.
        $owner = User::factory()->create(['email' => 'mine@example.com']);
        $this->fundedWallet($owner, 40);

        // A completed order: $30 sale, $27 supplier cost -> $3 profit.
        $this->completedOrder($customer, total: 30.00, providerCost: 27.00);

        $this->artisan('money:report', ['--mine' => 'mine@example.com'])
            ->assertExitCode(0)
            ->expectsOutputToContain('HELD FOR CUSTOMERS')
            ->expectsOutputToContain('$100.00')   // real customer float
            ->expectsOutputToContain('Your own accounts')
            ->expectsOutputToContain('$40.00')    // owner's own money
            ->expectsOutputToContain('$3.00');    // company profit
    }

    private function fundedWallet(User $user, float $usd): void
    {
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'balance' => $usd,
            'locked_balance' => 0,
            'is_active' => true,
        ]);

        WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'FND-'.uniqid(),
            'currency' => 'USD',
            'amount' => $usd,
            'settled_amount_usd' => $usd,
            'exchange_rate_snapshot' => 1.0,
            'gateway' => 'stripe',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    private function completedOrder(User $user, float $total, float $providerCost): void
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
            'fulfillment_status' => 'fulfilled',
            'order_status' => 'completed',
            'placed_at' => now(),
            'completed_at' => now(),
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
            'fulfillment_status' => 'fulfilled',
        ]);
    }
}
