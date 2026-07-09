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
 * Top-up orders credit a phone number, not a code. The order confirmation
 * card shows the recipient number (from the provider payload, or the number
 * the buyer typed, kept in item metadata) instead of the "code appears here"
 * placeholder - including while fulfillment is still pending.
 */
class OrderPageTopupPhoneTest extends TestCase
{
    use RefreshDatabase;

    private function topupOrder(User $buyer, string $fulfillmentStatus, ?array $payload, ?array $metadata): Order
    {
        $order = Order::create([
            'user_id' => $buyer->id,
            'order_number' => 'RSR-'.uniqid(),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 5.00,
            'markup_amount' => 1.00,
            'total_amount' => 6.00,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'fulfillment_status' => $fulfillmentStatus,
            'order_status' => $fulfillmentStatus === 'fulfilled' ? 'completed' : 'processing',
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0],
        ]);

        $category = Category::factory()->create(['name' => 'Mobile Airtime', 'slug' => 'mobile-airtime']);
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id, 'brand_key' => 'MTN']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 5.00,
            'provider_cost_usd' => 4.50,
            'markup_amount' => 1.00,
            'subtotal_amount' => 5.00,
            'product_snapshot' => ['brand_key' => 'MTN', 'name' => 'MTN Cameroon', 'category' => ['slug' => 'mobile-airtime']],
            'fulfillment_payload' => $payload,
            'metadata' => $metadata,
            'fulfillment_status' => $fulfillmentStatus,
        ]);

        return $order;
    }

    public function test_fulfilled_topup_shows_the_credited_number_from_the_payload(): void
    {
        $buyer = User::factory()->create();
        $order = $this->topupOrder($buyer, 'fulfilled', ['topup_recipient_phone' => '+237670000001'], null);

        $this->withoutVite()->actingAs($buyer)
            ->get(route('shop.order', $order->order_number))
            ->assertOk()
            ->assertSee('Credited to')
            ->assertSee('+237670000001')
            ->assertDontSee('Your code appears here');
    }

    public function test_topup_falls_back_to_the_typed_number_when_the_provider_does_not_echo_it(): void
    {
        // Provider payload has no phone keys; the number the buyer typed at
        // purchase (item metadata) still fills the tile.
        $buyer = User::factory()->create();
        $order = $this->topupOrder($buyer, 'fulfilled', ['topup' => ['operator' => 'MTN']], ['recipient_phone' => '+237670000002']);

        $this->withoutVite()->actingAs($buyer)
            ->get(route('shop.order', $order->order_number))
            ->assertOk()
            ->assertSee('Credited to')
            ->assertSee('+237670000002')
            ->assertDontSee('Your code appears here');
    }
}
