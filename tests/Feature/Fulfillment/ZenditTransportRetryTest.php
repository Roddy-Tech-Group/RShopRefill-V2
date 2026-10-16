<?php

namespace Tests\Feature\Fulfillment;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Providers\ZenditFulfillmentProvider;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Jobs\FulfillOrderItemJob;
use App\Models\Category;
use App\Models\FulfillmentLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A transport failure on the way to Zendit (connect timeout, DNS, reset) is
 * not a verdict on the order. It used to come back as a terminal failure, so
 * one network blip refunded a paid order within the second. It now throws,
 * the queue retries with the SAME transactionId, and Zendit either fulfils
 * it or answers transaction_duplicate_id, which the provider already turns
 * into the real status. A paid order of XAF 9,830 was refunded this way.
 */
class ZenditTransportRetryTest extends TestCase
{
    use RefreshDatabase;

    private OrderItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.zendit.api_key' => 'zendit-live-test-key', 'services.zendit.base_url' => 'https://test-api.zendit.io/v1']);

        $user = User::factory()->create();
        $category = Category::factory()->create(['slug' => 'gift-cards']);
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-'.Str::upper(Str::random(8)),
            'settlement_currency' => 'USD',
            'display_currency' => 'XAF',
            'subtotal_amount' => 9830,
            'markup_amount' => 0,
            'total_amount' => 9830,
            'payment_method' => 'korapay',
            'payment_status' => PaymentStatus::Paid,
            'fulfillment_status' => FulfillmentStatus::NotStarted,
            'order_status' => OrderStatus::Processing,
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 584.887, 'settlement_total_usd' => 16.8],
        ]);

        $this->item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'provider_offer_id' => 'UBEREATS_US_15_WHS',
            'quantity' => 1,
            'display_currency' => 'XAF',
            'display_amount' => 9830,
            'provider_cost_usd' => 14.25,
            'markup_amount' => 0,
            'subtotal_amount' => 9830,
            'product_snapshot' => ['name' => 'Uber Eats', 'category' => ['slug' => 'gift-cards']],
            'variant_snapshot' => ['face_value' => 15, 'currency' => 'USD'],
            'fulfillment_status' => FulfillmentStatus::NotStarted,
        ]);
    }

    public function test_a_connection_failure_throws_instead_of_failing_the_item(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Connection timeout after 10006 ms'));

        try {
            app(ZenditFulfillmentProvider::class)->fulfill($this->item);
            $this->fail('a transport failure must propagate so the queue retries');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('Connection timeout', $e->getMessage());
        }

        $log = FulfillmentLog::where('order_item_id', $this->item->id)->sole();
        $this->assertSame('EXCEPTION', $log->status);
        $this->assertStringContainsString('cURL error 28', $log->error_message);
    }

    public function test_a_non_transport_error_is_still_a_terminal_failure(): void
    {
        Http::fake(fn () => throw new \RuntimeException('malformed response'));

        $result = app(ZenditFulfillmentProvider::class)->fulfill($this->item);

        $this->assertSame(FulfillmentStatus::Failed, $result['status']);
    }

    /**
     * The whole path: the first run throws (no refund, no reversal), the retry
     * resends the identical transactionId and the card is delivered.
     */
    public function test_the_retry_resends_the_same_transaction_id_and_fulfils(): void
    {
        $sent = [];
        $calls = 0;
        Http::fake(function (Request $request) use (&$sent, &$calls) {
            $calls++;
            $sent[] = $request['transactionId'] ?? null;

            if ($calls === 1) {
                throw new ConnectionException('cURL error 28: Connection timeout after 10006 ms');
            }

            return Http::response([
                'transactionId' => $request['transactionId'],
                'status' => 'DONE',
                'value' => ['code' => 'UBER-TEST-CODE'],
            ], 200);
        });

        try {
            FulfillOrderItemJob::dispatchSync($this->item);
            $this->fail('the first attempt must rethrow for the queue');
        } catch (ConnectionException) {
        }

        $this->item->refresh();
        $this->assertSame(FulfillmentStatus::Failed, $this->item->fulfillment_status, 'marked failed between attempts, as every crash is');
        $this->assertSame(0.0, (float) ($this->item->order->fresh()->metadata['refunded_total'] ?? 0), 'nothing is refunded while a retry is still coming');

        FulfillOrderItemJob::dispatchSync($this->item->fresh());

        $this->assertSame(FulfillmentStatus::Fulfilled, $this->item->fresh()->fulfillment_status);
        $this->assertCount(2, $sent);
        $this->assertSame($sent[0], $sent[1], 'the retry reuses the transactionId so Zendit can never sell the card twice');
        $this->assertStringStartsWith('RSR-', (string) $sent[0]);
    }
}
