<?php

namespace Tests\Feature\Fulfillment;

use App\Domain\Fulfillment\Providers\ZenditFulfillmentProvider;
use App\Models\Category;
use App\Models\FulfillmentLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * When a Zendit purchase is ACCEPTED but a status poll resolves FAILED, the
 * decline reason (e.g. OFFER_UNAVAILABLE) must be persisted as a FAILED
 * fulfillment log - previously only the optimistic accept row existed and the
 * reason lived exclusively in Zendit's API.
 */
class ZenditFailureReasonLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_poll_persists_zendits_decline_reason(): void
    {
        config(['services.zendit.api_key' => 'zendit-live-test-key']);

        Http::fake([
            '*/vouchers/purchases/*' => Http::response([
                'transactionId' => 'RSR-TEST123',
                'status' => 'FAILED',
                'error' => [
                    'code' => 'OFFER_UNAVAILABLE',
                    'message' => 'The selected product is not available for purchase at this time.',
                ],
            ], 200),
        ]);

        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-'.uniqid(),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 15.00,
            'markup_amount' => 2.80,
            'total_amount' => 17.80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'fulfillment_status' => 'processing',
            'order_status' => 'processing',
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0],
        ]);

        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 17.80,
            'provider_cost_usd' => 14.73,
            'markup_amount' => 2.80,
            'subtotal_amount' => 17.80,
            'product_snapshot' => ['name' => 'Apple (US)', 'category' => ['slug' => 'gift-cards']],
            'fulfillment_status' => 'processing',
            'fulfillment_reference' => 'RSR-TEST123',
        ]);

        app(ZenditFulfillmentProvider::class)->verifyStatus($item);

        $log = FulfillmentLog::where('order_item_id', $item->id)->where('status', 'FAILED')->first();
        $this->assertNotNull($log, 'A FAILED fulfillment log must be written when the poll resolves FAILED.');
        $this->assertStringContainsString('OFFER_UNAVAILABLE', (string) $log->error_message);
    }
}
