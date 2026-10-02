<?php

namespace Tests\Feature\Api\V2;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Security\Services\ApiTokenService;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Order history.
 *
 * Two things here are about not leaking rather than about features, and both would
 * be invisible if nobody wrote them down.
 *
 * An order line carries who supplied it, what we paid, what we made, and the
 * supplier's entire response. None of that may reach a customer, so the resource
 * names its fields one by one and a test stands over it.
 *
 * And order amounts are HISTORICAL. A July order was charged at July's rate, and
 * the customer's bank statement says so. Re-pricing it at today's rate because the
 * app happens to be showing naira would produce a figure that matches nothing.
 */
class OrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = app(ApiTokenService::class)->issuePair($this->user, 'install-phone-1')->accessToken;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function api(string $path, array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson($path, array_merge(['Authorization' => 'Bearer '.$this->token], $headers));
    }

    private function order(array $attributes = [], ?User $owner = null): Order
    {
        return Order::factory()->create(array_merge([
            'user_id' => ($owner ?? $this->user)->id,
        ], $attributes));
    }

    // ------------------------------------------------------------------ reading

    public function test_orders_need_a_signed_in_customer(): void
    {
        $this->getJson('/v2/orders')
            ->assertUnauthorized()
            ->assertJsonPath('error.type', 'unauthenticated');
    }

    public function test_only_the_callers_own_orders_are_listed(): void
    {
        $this->order(['order_number' => 'RSR-MINE-1']);
        $this->order(['order_number' => 'RSR-THEIRS-1'], User::factory()->create());

        $numbers = array_column($this->api('/v2/orders')->assertOk()->json('data'), 'order_number');

        $this->assertSame(['RSR-MINE-1'], $numbers);
    }

    public function test_an_order_can_be_fetched_by_its_number(): void
    {
        $order = $this->order(['order_number' => 'RSR-20261001-ABC123']);
        OrderItem::factory()->create(['order_id' => $order->id]);

        $this->api('/v2/orders/RSR-20261001-ABC123')
            ->assertOk()
            ->assertJsonPath('data.order_number', 'RSR-20261001-ABC123')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonStructure(['data' => [
                'order_number', 'subtotal', 'total', 'payment_status',
                'fulfillment_status', 'order_status', 'items',
            ]]);
    }

    /**
     * An order number is short and quotable, which makes it guessable. Ownership is
     * part of the query, and the answer is identical to "no such order" so the
     * endpoint cannot confirm that somebody else's number is real.
     */
    public function test_another_customers_order_answers_exactly_like_a_missing_one(): void
    {
        $this->order(['order_number' => 'RSR-THEIRS-9'], User::factory()->create());

        $theirs = $this->api('/v2/orders/RSR-THEIRS-9')->assertNotFound();
        $missing = $this->api('/v2/orders/RSR-NO-SUCH-1')->assertNotFound();

        $this->assertSame($theirs->json(), $missing->json());
        $this->assertSame('order_not_found', $theirs->json('error.type'));
    }

    // -------------------------------------------------------------- money facts

    /**
     * The order was charged ₦16,000 at 1,600 to the dollar, so $10. Today the app is
     * showing naira at a different rate, and the order must not move.
     */
    public function test_order_amounts_are_historical_and_never_reconverted(): void
    {
        CurrencyRate::updateOrCreate(
            ['code' => 'NGN'],
            ['name' => 'Nigerian Naira', 'type' => 'fiat', 'rate_per_usd' => 5000, 'is_active' => true, 'sort_order' => 1],
        );
        CurrencyRate::updateOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'type' => 'fiat', 'rate_per_usd' => 1, 'is_active' => true, 'sort_order' => 0],
        );

        $this->order([
            'order_number' => 'RSR-JULY-1',
            'display_currency' => 'NGN',
            'subtotal_amount' => 16000,
            'total_amount' => 16000,
            'metadata' => ['settlement_total_usd' => 10, 'settlement_subtotal_usd' => 10],
        ]);

        // The app is displaying naira at a rate five times higher than the order's.
        $total = $this->api('/v2/orders/RSR-JULY-1', ['X-Display-Currency' => 'NGN'])
            ->assertOk()
            ->json('data.total');

        $this->assertSame('16000.00', $total['amount'], 'a historical charge must not be re-priced');
        $this->assertSame('NGN', $total['currency']);
        $this->assertSame('10.0000', $total['usd']);
    }

    /**
     * Our margin, our supplier and our cost. None of it is the customer's business,
     * and publishing it would hand a competitor our sourcing.
     */
    public function test_margins_costs_and_suppliers_are_never_published(): void
    {
        $order = $this->order(['order_number' => 'RSR-SECRETS-1', 'markup_amount' => 4.44]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'provider_name' => 'zendit',
            'provider_offer_id' => 'zendit_offer_12345',
            'provider_cost_usd' => 7.77,
            'markup_amount' => 2.22,
            'fulfillment_payload' => ['raw_response' => ['wholesale' => 1.11]],
        ]);

        $body = $this->api('/v2/orders/RSR-SECRETS-1')->assertOk()->getContent();

        foreach (['zendit', 'markup', '7.77', '2.22', '4.44', 'raw_response', 'wholesale', 'provider_cost'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($body), $forbidden.' must not be published');
        }
    }

    // -------------------------------------------------------------- the delivery

    public function test_delivered_goods_appear_only_once_actually_delivered(): void
    {
        $order = $this->order(['order_number' => 'RSR-PENDING-1']);

        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'fulfillment_status' => FulfillmentStatus::Processing,
            'fulfillment_payload' => ['pins' => ['SHOULD-NOT-SHOW-YET']],
        ]);

        // Mid-fulfilment: publishing a half-written payload is how a customer ends
        // up with a code that does not work yet.
        $this->assertNull($this->api('/v2/orders/RSR-PENDING-1')->assertOk()->json('data.items.0.delivery'));

        $item->forceFill([
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'delivered_at' => now(),
        ])->save();

        $delivery = $this->api('/v2/orders/RSR-PENDING-1')->assertOk()->json('data.items.0.delivery');

        $this->assertSame(['SHOULD-NOT-SHOW-YET'], $delivery['pins']);
    }

    public function test_an_esim_delivery_publishes_only_the_install_details(): void
    {
        $order = $this->order(['order_number' => 'RSR-ESIM-1']);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'delivered_at' => now(),
            'fulfillment_payload' => [
                'pins' => [],
                'esim' => [
                    'iccid' => '8944',
                    'lpaUrl' => 'LPA:1$example.com$ABC',
                    'manualActivationCode' => 'ABC',
                    'directInstallUrl' => 'https://example.com/install',
                    'sharingLink' => 'https://example.com/share',
                    'sharingAccessCode' => '1234',
                    'supplier_internal_note' => 'do not publish me',
                ],
                'raw_response' => ['net_price' => 0.99],
            ],
        ]);

        $esim = $this->api('/v2/orders/RSR-ESIM-1')->assertOk()->json('data.items.0.delivery.esim');

        $this->assertSame('8944', $esim['iccid']);
        $this->assertSame('LPA:1$example.com$ABC', $esim['lpaUrl']);
        // The allowlist is the point: anything a supplier adds upstream stays
        // private until somebody decides otherwise.
        $this->assertArrayNotHasKey('supplier_internal_note', $esim);
    }

    // -------------------------------------------------------------- pagination

    public function test_history_pages_by_cursor_newest_first(): void
    {
        foreach (range(1, 5) as $i) {
            $this->order([
                'order_number' => 'RSR-PAGE-'.$i,
                'created_at' => now()->subMinutes(10 - $i),
            ]);
        }

        $seen = [];
        $cursor = null;

        do {
            $url = '/v2/orders?per_page=2'.($cursor !== null ? '&cursor='.urlencode($cursor) : '');
            $page = $this->api($url)->assertOk();

            foreach ($page->json('data') as $row) {
                $seen[] = $row['order_number'];
            }

            $cursor = $page->json('page.next_cursor');
        } while ($cursor !== null);

        $this->assertCount(5, $seen);
        $this->assertSame($seen, array_unique($seen), 'no order may appear on two pages');
        // Newest first.
        $this->assertSame('RSR-PAGE-5', $seen[0]);
    }

    public function test_history_can_be_filtered_by_status(): void
    {
        $this->order(['order_number' => 'RSR-DONE-1', 'order_status' => 'completed']);
        $this->order(['order_number' => 'RSR-OPEN-1', 'order_status' => 'processing']);

        $numbers = array_column($this->api('/v2/orders?status=completed')->assertOk()->json('data'), 'order_number');

        $this->assertSame(['RSR-DONE-1'], $numbers);
    }

    // ---------------------------------------------------------- line amounts

    /**
     * A line has no USD column of its own. Its settlement figure comes from the rate
     * the ORDER was placed at; publishing the naira figure as dollars put the
     * item-level USD out by the whole exchange rate on every non-USD order.
     */
    public function test_line_amounts_use_the_rate_the_order_was_placed_at(): void
    {
        $order = $this->order([
            'order_number' => 'RSR-NGN-1',
            'display_currency' => 'NGN',
            'subtotal_amount' => 16000,
            'total_amount' => 16000,
            'metadata' => ['exchange_rate' => 1600, 'settlement_total_usd' => 10, 'settlement_subtotal_usd' => 10],
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'display_currency' => 'NGN',
            'subtotal_amount' => 16000,
            'provider_face_value' => 1000,
            'variant_snapshot' => ['currency' => 'NGN'],
        ]);

        $item = $this->api('/v2/orders/RSR-NGN-1')->assertOk()->json('data.items.0');

        $this->assertSame('16000.00', $item['subtotal']['amount']);
        $this->assertSame('NGN', $item['subtotal']['currency']);
        $this->assertSame('10.0000', $item['subtotal']['usd']);

        // The delivered face is in the OFFER'S currency, not the buyer's.
        $this->assertSame('NGN', $item['delivered_face_value']['currency']);
        $this->assertSame('1000.00', $item['delivered_face_value']['amount']);
    }

    /**
     * orders.payment_method holds the gateway chosen at checkout for a card order.
     * A gateway's name is not something a customer-facing surface repeats.
     */
    public function test_a_gateway_name_is_never_published_as_the_payment_method(): void
    {
        $this->order(['order_number' => 'RSR-CARD-1', 'payment_method' => 'flutterwave']);

        $order = $this->api('/v2/orders/RSR-CARD-1')->assertOk()->json('data');

        $this->assertSame('card', $order['payment_method']);
        $this->assertStringNotContainsStringIgnoringCase('flutterwave', json_encode($order));
    }

    public function test_a_settled_order_carries_no_payment_link(): void
    {
        $this->order(['order_number' => 'RSR-PAID-1']);

        $this->assertNull($this->api('/v2/orders/RSR-PAID-1')->assertOk()->json('data.payment'));
    }
}
