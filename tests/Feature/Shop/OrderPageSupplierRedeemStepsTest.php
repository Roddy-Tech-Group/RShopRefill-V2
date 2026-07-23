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
 * Vouchers that carry supplier-issued redemption steps (MintRoute POSA cards
 * like Discord Nitro) must show those steps on the completed-order page, render
 * the redeemable PIN, and never surface the receipt account_reference as a
 * copyable code - that hex string sent customers to Discord with the wrong
 * value.
 */
class OrderPageSupplierRedeemStepsTest extends TestCase
{
    use RefreshDatabase;

    private function completedOrderWithPayload(User $user, array $payload): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-'.uniqid(),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 9.99,
            'markup_amount' => 1.00,
            'total_amount' => 10.99,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'order_status' => 'completed',
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0],
        ]);

        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id, 'brand_key' => 'Discord']);
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
            'display_amount' => 9.99,
            'provider_cost_usd' => 9.00,
            'markup_amount' => 1.00,
            'subtotal_amount' => 9.99,
            'product_snapshot' => ['brand_key' => 'Discord', 'name' => 'Discord Nitro (US)', 'country_code' => 'US'],
            'variant_snapshot' => ['face_value' => 9.99, 'currency' => 'USD'],
            'fulfillment_payload' => $payload,
            'fulfillment_status' => 'fulfilled',
        ]);

        return $order;
    }

    public function test_completed_order_shows_pin_and_supplier_steps_but_never_the_account_reference(): void
    {
        $user = User::factory()->create();
        $order = $this->completedOrderWithPayload($user, [
            'pin' => '38eq-THbU-ppHJ-NfJS-msPA-sGHQ',
            'account_reference' => 'c0a791be85fd55a9b85cd10a2df36b',
            'instructions' => "1- Go to https://posa.mintroute.com.\n2- Select Discord service.",
            'pins' => [['pin' => '38eq-THbU-ppHJ-NfJS-msPA-sGHQ']],
        ]);

        $html = $this->withoutVite()
            ->actingAs($user)
            ->get(route('shop.order', $order->order_number))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('38eq-THbU-ppHJ-NfJS-msPA-sGHQ', $html);
        $this->assertStringContainsString('posa.mintroute.com', $html);
        $this->assertStringNotContainsString('c0a791be85fd55a9b85cd10a2df36b', $html);
    }

    public function test_dashboard_orders_page_shows_supplier_steps_and_hides_the_account_reference(): void
    {
        $user = User::factory()->create();
        $this->completedOrderWithPayload($user, [
            'pin' => '38eq-THbU-ppHJ-NfJS-msPA-sGHQ',
            'account_reference' => 'c0a791be85fd55a9b85cd10a2df36b',
            'instructions' => "1- Go to https://posa.mintroute.com.\n2- Select Discord service.",
            'pins' => [['pin' => '38eq-THbU-ppHJ-NfJS-msPA-sGHQ']],
        ]);

        $html = $this->withoutVite()
            ->actingAs($user)
            ->get(route('dashboard.orders'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('38eq-THbU-ppHJ-NfJS-msPA-sGHQ', $html);
        $this->assertStringContainsString('posa.mintroute.com', $html);
        $this->assertStringNotContainsString('c0a791be85fd55a9b85cd10a2df36b', $html);
    }
}
