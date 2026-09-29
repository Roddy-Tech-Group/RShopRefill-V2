<?php

namespace Tests\Feature\Api;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API responses carry what the customer pays, never what it costs us or what
 * we make on it. The cart endpoint is public to anyone holding a guest token,
 * and used to return the markup and the supplier cost alongside the total.
 */
class CustomerFacingPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_cart_shows_totals_without_markup_or_supplier_cost(): void
    {
        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Gaming', 'slug' => 'gaming']);
        $product = Product::create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Steam Gift Card',
            'slug' => 'steam-gift-card',
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'provider_offer_id' => 'steam-offer-10',
            'sku' => 'STEAM10',
            'currency' => 'USD',
            'face_value' => 10.00,
            'cost_price' => 9.50,
            'retail_price' => 10.50,
            'is_available' => true,
        ]);
        $cart = Cart::create(['guest_token' => 'guest-pricing-test', 'status' => 'active', 'last_activity_at' => now()]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 11.00,
            'provider_cost_usd' => 9.50,
            'markup_amount' => 1.00,
            'unit_price_snapshot' => 11.00,
            'subtotal_snapshot' => 11.00,
        ]);

        $this->withHeader('X-Guest-Token', 'guest-pricing-test')
            ->getJson('/api/storefront/cart')
            ->assertOk()
            ->assertJsonPath('data.totals.total', 11)
            ->assertJsonPath('data.totals.currency', 'USD')
            ->assertJsonMissingPath('data.totals.total_markup')
            ->assertJsonMissingPath('data.totals.total_provider_cost');
    }

    public function test_an_order_shows_what_was_paid_without_the_margin(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'subtotal_amount' => 10.00,
            'markup_amount' => 2.00,
            'total_amount' => 12.00,
        ]);

        $this->actingAs($user)
            ->getJson('/api/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonPath('data.total_amount', 12)
            ->assertJsonMissingPath('data.markup_amount');
    }
}
