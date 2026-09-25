<?php

namespace Tests\Feature\Checkout;

use App\Domain\Order\Services\CheckoutService;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The Airalo normaliser keeps the first sim of an order's response, so every
 * eSIM must be its own order item, ordered from Airalo on its own. Checkout
 * guarantees that by splitting a cart line into one order item per unit; this
 * pins it, because a quantity above one on an eSIM line would pay Airalo for
 * N eSIMs and hand the customer one QR.
 */
class EsimQuantitySplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_esims_in_one_cart_line_become_three_order_items_of_one(): void
    {
        config(['services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK']);
        CurrencyRate::create(['code' => 'USD', 'name' => 'US Dollar', 'type' => 'fiat', 'rate_per_usd' => 1.0, 'is_active' => true]);

        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'balance' => 0, 'locked_balance' => 0, 'is_active' => true]);

        $category = Category::create(['name' => 'eSIMs', 'slug' => 'esims', 'type' => 'digital']);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Data', 'slug' => 'data']);
        $product = Product::create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'airalo',
            'country_code' => 'FR',
            'currency_code' => 'USD',
            'name' => 'France 5GB',
            'slug' => 'france-5gb',
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'provider_offer_id' => 'airalo_france-5gb-7days',
            'sku' => 'FR5GB',
            'currency' => 'USD',
            'face_value' => 10.00,
            'cost_price' => 8.00,
            'retail_price' => 10.00,
            'is_available' => true,
        ]);

        Cache::flush();
        PricingRule::create(['markup_type' => 'fixed', 'markup_value' => 1.00, 'is_active' => true]);

        $cart = Cart::create(['user_id' => $user->id, 'status' => 'active', 'last_activity_at' => now()]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 3,
            'display_currency' => 'USD',
            'display_amount' => 33.00,
            'provider_cost_usd' => 8.00,
            'markup_amount' => 1.00,
            'unit_price_snapshot' => 11.00,
            'subtotal_snapshot' => 33.00,
        ]);

        $order = app(CheckoutService::class)->placeOrder(user: $user, cart: $cart, paymentMethod: 'flutterwave', displayCurrency: 'USD');

        $items = $order->items()->get();

        $this->assertCount(3, $items);
        $this->assertSame([1, 1, 1], $items->pluck('quantity')->map(fn ($quantity) => (int) $quantity)->all());
        $this->assertSame(['airalo_france-5gb-7days'], $items->pluck('provider_offer_id')->unique()->values()->all());
        $this->assertEqualsWithDelta(33.00, (float) $order->total_amount, 0.001);
    }
}
