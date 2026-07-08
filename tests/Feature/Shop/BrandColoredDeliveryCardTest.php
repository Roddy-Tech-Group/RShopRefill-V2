<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Delivered gift-card visuals wear the brand's own colour (config override or
 * the Zendit-synced brand_color) with contrast-aware text; brands without a
 * colour keep the neutral grey card.
 */
class BrandColoredDeliveryCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_color_is_dark_judges_luminance(): void
    {
        $this->assertTrue(Product::colorIsDark('#101010'));   // Razer Gold black
        $this->assertTrue(Product::colorIsDark('#003791'));   // PlayStation blue
        $this->assertFalse(Product::colorIsDark('#F5F5F7'));  // Apple light grey
        $this->assertFalse(Product::colorIsDark(null));
        $this->assertFalse(Product::colorIsDark('not-a-color'));
    }

    public function test_order_page_card_wears_the_brand_color(): void
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-'.uniqid(),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 25.00,
            'markup_amount' => 2.00,
            'total_amount' => 27.00,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'order_status' => 'completed',
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0],
        ]);

        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id, 'brand_key' => 'Netflix']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'test',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 25.00,
            'provider_cost_usd' => 22.00,
            'markup_amount' => 2.00,
            'subtotal_amount' => 25.00,
            'product_snapshot' => ['brand_key' => 'Netflix', 'name' => 'Netflix Gift Card', 'country_code' => 'US'],
            'variant_snapshot' => ['face_value' => 25, 'currency' => 'USD'],
            'fulfillment_payload' => ['code' => 'NFLX-TEST-CODE-1234'],
            'fulfillment_status' => 'fulfilled',
        ]);

        $html = $this->withoutVite()
            ->actingAs($user)
            ->get(route('shop.order', $order->order_number))
            ->assertOk()
            ->getContent();

        // Netflix red from config/brand_colors.php, with white (dark-surface) text.
        $this->assertStringContainsString('background: #E50914', $html);
        $this->assertStringContainsString('text-white', $html);
    }
}
