<?php

namespace Tests\Feature;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Providers\AiraloFulfillmentProvider;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The eSIM top-up purchase flow must never show the customer a raw
 * "422 Unprocessable Content" error page for recoverable states (no funds,
 * package gone, provider hiccup). Those come back as a friendly message on the
 * top-up page instead.
 */
class EsimTopupTest extends TestCase
{
    use RefreshDatabase;

    private function fulfilledEsimItem(User $user): OrderItem
    {
        $category = Category::firstOrCreate(['slug' => 'esims'], ['name' => 'eSIMs', 'type' => 'digital']);

        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'airalo',
            'brand_key' => 'TestEsim',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Test eSIM',
            'slug' => 'test-esim',
            'is_active' => true,
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-TEST-ESIM-1',
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 0,
            'markup_amount' => 0,
            'total_amount' => 0,
            'payment_method' => 'wallet',
            'payment_status' => PaymentStatus::Paid,
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'order_status' => OrderStatus::Completed,
        ]);

        $variant = ProductVariant::factory()->for($product)->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);

        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'airalo',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 5.00,
            'provider_cost_usd' => 5.00,
            'markup_amount' => 0,
            'subtotal_amount' => 5.00,
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'fulfillment_payload' => ['iccid' => '8944000000000000001'],
        ]);
    }

    public function test_topup_without_usd_wallet_shows_a_friendly_error_not_a_422(): void
    {
        $user = User::factory()->create([
            'transaction_pin' => Hash::make('1234'),
            'transaction_pin_set_at' => now(),
        ]);

        $item = $this->fulfilledEsimItem($user);

        // Airalo returns a package matching the submitted id, so we get past the
        // package check and reach the wallet check (the user has no USD wallet).
        $this->mock(AiraloFulfillmentProvider::class)
            ->shouldReceive('listTopupsForIccid')
            ->andReturn([['id' => 'topup_1gb', 'net_price' => 5.0]]);

        $response = $this->actingAs($user)->post(
            route('dashboard.esim.topup.purchase', $item),
            ['package_id' => 'topup_1gb', 'net_price' => 5.0, 'wallet_pin' => '1234'],
        );

        // Friendly redirect back to the top-up page with a specific message -
        // NOT the raw 422 "Unprocessable Content" page the customer was seeing.
        $response->assertRedirect(route('dashboard.esim.topup', $item));
        $response->assertSessionHasErrors('package_id');
        $this->assertStringContainsString('USD wallet', (string) session('errors')->first('package_id'));
        $this->assertNotSame(422, $response->status());
    }
}
