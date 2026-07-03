<?php

namespace Tests\Feature;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Providers\AiraloFulfillmentProvider;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * eSIM top-up payment: any active wallet can pay, as long as its balance covers
 * the USD price converted into that wallet's currency. Recoverable states
 * (nothing covers it, package gone, provider hiccup) show a friendly message,
 * never a raw "422 Unprocessable Content" error page.
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

        $variant = ProductVariant::factory()->for($product)->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-PARENT-ESIM-1',
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

    private function pinnedUser(): User
    {
        return User::factory()->create([
            'transaction_pin' => Hash::make('1234'),
            'transaction_pin_set_at' => now(),
        ]);
    }

    public function test_topup_can_be_paid_from_a_non_usd_wallet_with_the_usd_equivalent(): void
    {
        Queue::fake();
        CurrencyRate::create(['code' => 'USD', 'name' => 'US Dollar', 'type' => 'fiat', 'rate_per_usd' => 1.0, 'is_active' => true]);
        CurrencyRate::create(['code' => 'XAF', 'name' => 'CFA Franc', 'type' => 'fiat', 'rate_per_usd' => 600.0, 'is_active' => true]);

        $user = $this->pinnedUser();
        // Only a local-currency wallet, no USD wallet - but plenty of balance.
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'XAF', 'balance' => 1_000_000, 'is_active' => true]);

        $item = $this->fulfilledEsimItem($user);

        $this->mock(AiraloFulfillmentProvider::class)
            ->shouldReceive('listTopupsForIccid')
            ->andReturn([['id' => 'topup_1gb', 'net_price' => 5.0]]);

        $response = $this->actingAs($user)->post(
            route('dashboard.esim.topup.purchase', $item),
            ['package_id' => 'topup_1gb', 'net_price' => 5.0, 'wallet_pin' => '1234'],
        );

        // Succeeds: a new top-up order is created and the XAF wallet is debited.
        $response->assertSessionHasNoErrors();

        $topupOrder = Order::where('user_id', $user->id)
            ->where('order_number', '!=', 'RSR-PARENT-ESIM-1')
            ->latest()->first();

        $this->assertNotNull($topupOrder);
        $this->assertSame('XAF', $topupOrder->metadata['paid_from_wallet_currency']);
        $this->assertLessThan(1_000_000, (float) $wallet->fresh()->balance);
    }

    public function test_topup_with_no_wallet_covering_the_price_shows_a_friendly_error_not_a_422(): void
    {
        $user = $this->pinnedUser();
        // A USD wallet, but far too little to cover the ~$5.50 top-up.
        Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'balance' => 1.00, 'is_active' => true]);

        $item = $this->fulfilledEsimItem($user);

        $this->mock(AiraloFulfillmentProvider::class)
            ->shouldReceive('listTopupsForIccid')
            ->andReturn([['id' => 'topup_1gb', 'net_price' => 5.0]]);

        $response = $this->actingAs($user)->post(
            route('dashboard.esim.topup.purchase', $item),
            ['package_id' => 'topup_1gb', 'net_price' => 5.0, 'wallet_pin' => '1234'],
        );

        // Friendly redirect with a specific message - NOT the raw 422 page.
        $response->assertRedirect(route('dashboard.esim.topup', $item));
        $response->assertSessionHasErrors('package_id');
        $this->assertNotSame(422, $response->status());
    }
}
