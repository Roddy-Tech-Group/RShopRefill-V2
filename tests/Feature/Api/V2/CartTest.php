<?php

namespace Tests\Feature\Api\V2;

use App\Domain\Security\Services\ApiTokenService;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The basket.
 *
 * Signed in only, and everything routed through CartManager so the app's cart
 * behaves identically to the website's: same deduplication, same quantity ceiling,
 * same price snapshots, same refusal when a supplier needs a recipient number.
 *
 * Two tests here earn their keep more than the rest. One proves that a retried
 * add-to-cart does not quietly double the quantity, which is what happens on a
 * dropped connection without the idempotency guard. The other proves a typed
 * amount is converted by the SERVER: the app sends ₦1,000 because that is what the
 * customer typed, and if the app were left to convert it we would have two
 * exchange rates in play and eventually two different prices.
 */
class CartTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    private ProductVariant $fixed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['phone' => '+2348031234567']);
        $this->token = app(ApiTokenService::class)->issuePair($this->user, 'install-phone-1')->accessToken;

        $category = Category::factory()->create(['is_active' => true, 'slug' => 'mobile-airtime']);
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'is_active' => true,
        ]);

        $this->fixed = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'subcategory_id' => $subcategory->id,
            'is_available' => true,
            'currency' => 'USD',
            'face_value' => 10,
            'cost_price' => 1,
            'manual_retail_price_usd' => 5.0,
        ]);
    }

    private function variableVariant(array $overrides = []): ProductVariant
    {
        return ProductVariant::factory()->create(array_merge([
            'product_id' => $this->fixed->product_id,
            'subcategory_id' => $this->fixed->subcategory_id,
            'is_available' => true,
            'is_variable' => true,
            'currency' => 'NGN',
            'face_value' => null,
            'cost_price' => 0,
            'min_amount' => 100,
            'max_amount' => 50000,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function api(string $method, string $path, array $body = [], ?string $key = 'tap-aaaa-bbbb-cccc'): TestResponse
    {
        // Laravel reuses one application instance across test HTTP calls and
        // Sanctum's guard memoises the user it resolved.
        $this->app['auth']->forgetGuards();

        $headers = ['Authorization' => 'Bearer '.$this->token];

        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }

        return $this->json($method, $path, $body, $headers);
    }

    private function addFixed(?string $key = 'tap-aaaa-bbbb-cccc', int $quantity = 1): TestResponse
    {
        return $this->api('POST', '/v2/cart/items', [
            'variant_id' => $this->fixed->id,
            'quantity' => $quantity,
        ], $key);
    }

    // ---------------------------------------------------------------- the basics

    public function test_a_new_customer_has_an_empty_cart(): void
    {
        $response = $this->api('GET', '/v2/cart')->assertOk();

        $this->assertSame([], $response->json('data.items'));
        $this->assertSame(0, $response->json('data.item_count'));
        $this->assertSame('0.0000', $response->json('data.totals.subtotal.usd'));
    }

    public function test_the_cart_needs_a_signed_in_customer(): void
    {
        $this->getJson('/v2/cart')
            ->assertUnauthorized()
            ->assertJsonPath('error.type', 'unauthenticated');
    }

    public function test_adding_a_fixed_item_snapshots_its_price(): void
    {
        $response = $this->addFixed()->assertCreated();

        $this->assertSame(1, $response->json('data.item_count'));
        $this->assertSame('5.0000', $response->json('data.items.0.unit_price.usd'));
        $this->assertSame('5.0000', $response->json('data.items.0.subtotal.usd'));
        $this->assertSame('5.0000', $response->json('data.totals.subtotal.usd'));
        $this->assertNull($response->json('data.items.0.requested_amount'));
    }

    // --------------------------------------------------------------- idempotency

    /**
     * The one that matters. Adding the same variant again INCREASES its quantity, so
     * without the guard a retry on a dropped connection puts two in the basket and
     * the customer pays twice for something they asked for once.
     */
    public function test_a_retried_add_does_not_double_the_quantity(): void
    {
        $this->addFixed('tap-same-key-1111')->assertCreated();
        $retry = $this->addFixed('tap-same-key-1111')->assertCreated();

        $this->assertSame(1, $retry->json('data.item_count'));
        $this->assertSame('true', $retry->headers->get('Idempotent-Replay'));
    }

    public function test_two_genuine_adds_do_increase_the_quantity(): void
    {
        $this->addFixed('tap-first-one-1111')->assertCreated();
        $second = $this->addFixed('tap-second-one-22')->assertCreated();

        $this->assertSame(2, $second->json('data.item_count'));
        $this->assertCount(1, $second->json('data.items'), 'the same variant merges into one line');
    }

    public function test_adding_without_an_idempotency_key_is_refused(): void
    {
        $this->addFixed(null)
            ->assertStatus(400)
            ->assertJsonPath('error.type', 'idempotency_key_required');

        $this->assertSame(0, $this->api('GET', '/v2/cart')->json('data.item_count'));
    }

    // ------------------------------------------------------------ typed amounts

    /**
     * The server converts, not the app. The customer typed ₦1,600; at 1600 to the
     * dollar that is one dollar of supplier cost, and the price comes back through
     * the same rule chain the cart will charge from.
     */
    public function test_a_typed_amount_is_converted_by_the_server(): void
    {
        CurrencyRate::updateOrCreate(
            ['code' => 'NGN'],
            ['name' => 'Nigerian Naira', 'type' => 'fiat', 'rate_per_usd' => 1600, 'is_active' => true, 'sort_order' => 1],
        );

        $variant = $this->variableVariant();

        $response = $this->api('POST', '/v2/cart/items', [
            'variant_id' => $variant->id,
            'quantity' => 1,
            'amount' => 1600,
        ])->assertCreated();

        // Echoed back in the currency the customer typed in, at full precision.
        $this->assertSame('1600.00', $response->json('data.items.0.requested_amount.amount'));
        $this->assertSame('NGN', $response->json('data.items.0.requested_amount.currency'));

        // A real price was resolved, in the settlement currency.
        $this->assertGreaterThan(0, (float) $response->json('data.items.0.unit_price.usd'));
    }

    public function test_a_variable_item_must_carry_an_amount(): void
    {
        $variant = $this->variableVariant();

        $this->api('POST', '/v2/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['amount']]]);
    }

    /**
     * Ignoring the amount would let the app believe it had set a price it had not.
     * A fixed denomination's amount is the denomination.
     */
    public function test_a_fixed_item_refuses_an_amount(): void
    {
        $this->api('POST', '/v2/cart/items', [
            'variant_id' => $this->fixed->id,
            'quantity' => 1,
            'amount' => 999,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'amount_not_accepted');
    }

    public function test_an_amount_outside_the_variants_range_is_refused(): void
    {
        CurrencyRate::updateOrCreate(
            ['code' => 'NGN'],
            ['name' => 'Nigerian Naira', 'type' => 'fiat', 'rate_per_usd' => 1600, 'is_active' => true, 'sort_order' => 1],
        );

        $variant = $this->variableVariant(['min_amount' => 1000, 'max_amount' => 5000]);

        $this->api('POST', '/v2/cart/items', [
            'variant_id' => $variant->id,
            'quantity' => 1,
            'amount' => 50,
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['requested_value']]]);
    }

    // ------------------------------------------------------- delivery details

    /**
     * A top-up with no recipient number cannot be delivered. Refusing at add-to-cart
     * is the difference between a validation message and a refund.
     */
    public function test_an_offer_that_needs_a_recipient_refuses_without_one(): void
    {
        $variant = ProductVariant::factory()->create([
            'product_id' => $this->fixed->product_id,
            'subcategory_id' => $this->fixed->subcategory_id,
            'is_available' => true,
            'cost_price' => 1,
            'metadata' => ['requiredFields' => ['recipient.msisdn']],
        ]);

        $this->api('POST', '/v2/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['metadata.recipient_phone']]]);

        $this->api('POST', '/v2/cart/items', [
            'variant_id' => $variant->id,
            'quantity' => 1,
            'metadata' => ['recipient_phone' => '08031234567'],
        ], 'tap-with-a-number-1')->assertCreated();
    }

    /**
     * metadata_snapshot also carries figures we compute for our own bookkeeping.
     * Echoing the lot would publish them and make every future internal key a
     * public field by accident.
     */
    public function test_only_the_customers_own_details_are_echoed_back(): void
    {
        $response = $this->api('POST', '/v2/cart/items', [
            'variant_id' => $this->fixed->id,
            'quantity' => 1,
            'metadata' => ['recipient_phone' => '08031234567'],
        ])->assertCreated();

        $metadata = $response->json('data.items.0.metadata');

        $this->assertSame(['recipient_phone' => '08031234567'], $metadata);
        $this->assertArrayNotHasKey('requested_native', $metadata);
    }

    // ------------------------------------------------------------------ editing

    public function test_a_quantity_is_set_absolutely_so_a_retry_is_harmless(): void
    {
        $item = $this->addFixed()->json('data.items.0.id');

        $this->api('PATCH', '/v2/cart/items/'.$item, ['quantity' => 3], null)->assertOk();
        $again = $this->api('PATCH', '/v2/cart/items/'.$item, ['quantity' => 3], null)->assertOk();

        $this->assertSame(3, $again->json('data.item_count'));
    }

    public function test_a_quantity_of_zero_removes_the_line(): void
    {
        $item = $this->addFixed()->json('data.items.0.id');

        $response = $this->api('PATCH', '/v2/cart/items/'.$item, ['quantity' => 0], null)->assertOk();

        $this->assertSame([], $response->json('data.items'));
    }

    public function test_an_item_can_be_removed_and_the_cart_cleared(): void
    {
        $item = $this->addFixed()->json('data.items.0.id');

        $this->assertSame([], $this->api('DELETE', '/v2/cart/items/'.$item, [], null)->assertOk()->json('data.items'));

        $this->addFixed('tap-again-here-111')->assertCreated();

        $this->assertSame([], $this->api('DELETE', '/v2/cart', [], null)->assertOk()->json('data.items'));
    }

    /**
     * Scoped to the caller's own cart. Looking an item up by id alone would let
     * anyone edit a line in somebody else's basket.
     */
    public function test_another_customers_cart_line_cannot_be_touched(): void
    {
        $item = $this->addFixed()->json('data.items.0.id');

        $intruder = User::factory()->create();
        $this->token = app(ApiTokenService::class)->issuePair($intruder, 'install-phone-2')->accessToken;

        $this->api('PATCH', '/v2/cart/items/'.$item, ['quantity' => 99], null)
            ->assertNotFound()
            ->assertJsonPath('error.type', 'cart_item_not_found');

        $this->api('DELETE', '/v2/cart/items/'.$item, [], null)->assertNotFound();
    }

    // --------------------------------------------------------- before the tap

    /**
     * Checkout refuses a basket holding something that can no longer be bought. The
     * app has to be able to say WHICH line and WHY before the customer taps Pay.
     */
    public function test_the_cart_names_the_lines_that_can_no_longer_be_bought(): void
    {
        $this->api('POST', '/v2/cart/items', ['variant_id' => $this->fixed->id, 'quantity' => 1])->assertCreated();

        $this->fixed->forceFill(['is_available' => false])->save();

        $response = $this->api('GET', '/v2/cart', [], null)->assertOk();

        $itemId = $response->json('data.items.0.id');

        $this->assertFalse($response->json('data.is_checkout_ready'));
        $this->assertContains('variant_unavailable', $response->json('data.issues.'.$itemId));
    }

    /**
     * Only the validated metadata keys reach the line. CartManager merges whatever
     * it is given, and checkout copies it onto the order, so an unvalidated key would
     * let the app set our own bookkeeping figures for fulfilment.
     */
    public function test_unknown_metadata_keys_never_reach_the_cart_line(): void
    {
        $this->api('POST', '/v2/cart/items', [
            'variant_id' => $this->fixed->id,
            'quantity' => 1,
            'metadata' => ['recipient_phone' => '+2348031234567', 'requested_native' => 999999, 'provider_offer_id' => 'x'],
        ])->assertCreated();

        $snapshot = (array) CartItem::sole()->metadata_snapshot;

        $this->assertArrayNotHasKey('provider_offer_id', $snapshot);
        $this->assertSame('+2348031234567', $snapshot['recipient_phone'] ?? null);
        // Only ever written by CartManager itself, for a variable item.
        $this->assertNotSame(999999, $snapshot['requested_native'] ?? null);
    }

    public function test_the_cart_never_publishes_a_sku(): void
    {
        $this->fixed->forceFill(['sku' => 'AIRALO-TOPUP-123'])->save();

        $this->api('POST', '/v2/cart/items', ['variant_id' => $this->fixed->id, 'quantity' => 1])->assertCreated();

        $response = $this->api('GET', '/v2/cart', [], null)->assertOk();

        $this->assertArrayNotHasKey('sku', $response->json('data.items.0.variant'));
        $this->assertStringNotContainsStringIgnoringCase('airalo', $response->getContent());
    }
}
