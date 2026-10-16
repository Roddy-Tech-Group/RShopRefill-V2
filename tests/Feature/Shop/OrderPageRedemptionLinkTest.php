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
 * Some cards are delivered as a registration link rather than a code (the
 * supplier's pin-redemptionURL products: Uber Eats among them). The order
 * history and the email already showed the link; the order success page
 * only knew codes and PINs, so a delivered card read "Your code appears here
 * once payment clears" on the one page the customer lands on after paying.
 */
class OrderPageRedemptionLinkTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $payload */
    private function completedOrderWith(array $payload): array
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-'.uniqid(),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 15.00,
            'markup_amount' => 1.00,
            'total_amount' => 16.00,
            'payment_method' => 'korapay',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'order_status' => 'completed',
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0],
        ]);
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id, 'brand_key' => 'UberEats']);
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
            'display_amount' => 15.00,
            'provider_cost_usd' => 14.00,
            'markup_amount' => 1.00,
            'subtotal_amount' => 15.00,
            'product_snapshot' => ['brand_key' => 'UberEats', 'name' => 'Uber Eats', 'country_code' => 'US'],
            'variant_snapshot' => ['face_value' => 15, 'currency' => 'USD'],
            'fulfillment_payload' => $payload,
            'fulfillment_status' => 'fulfilled',
        ]);

        return [$user, $order];
    }

    public function test_a_card_delivered_as_a_link_shows_the_link_not_a_pending_notice(): void
    {
        [$user, $order] = $this->completedOrderWith(['redemption_url' => 'https://redeem.example/ubereats/abc123', 'pins' => []]);

        $this->withoutVite()->actingAs($user)
            ->get(route('shop.order', $order->order_number))
            ->assertOk()
            ->assertSee('href="https://redeem.example/ubereats/abc123"', false)
            ->assertSee('data-redemption-link', false)
            ->assertSee(__('orders.redeem_your_card'))
            ->assertDontSee(__('orders.code_pending'));
    }

    public function test_a_card_with_both_a_code_and_a_link_shows_both(): void
    {
        [$user, $order] = $this->completedOrderWith(['code' => 'UBER-CODE-9999', 'redemption_url' => 'https://redeem.example/ubereats/abc123']);

        $this->withoutVite()->actingAs($user)
            ->get(route('shop.order', $order->order_number))
            ->assertOk()
            ->assertSee('UBER-CODE-9999')
            ->assertSee('data-redemption-link', false);
    }

    public function test_an_undelivered_card_still_says_the_code_is_pending(): void
    {
        [$user, $order] = $this->completedOrderWith([]);

        $this->withoutVite()->actingAs($user)
            ->get(route('shop.order', $order->order_number))
            ->assertOk()
            ->assertSee(__('orders.code_pending'))
            ->assertDontSee('data-redemption-link', false);
    }
}
