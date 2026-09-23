<?php

namespace Tests\Feature\Fulfillment;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
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
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The Sporty Bet fix handled one required field. 257 live offers declare
 * others the fulfilment never sent: the recipient's phone number (a Kenyan
 * gift card, a Togolese betting wallet), the buyer's own number, the buyer's
 * name as sender. Every one of them was paid for and then refused by Zendit.
 * What Zendit lists as required for the offer is what is sent now, and what
 * the product page asks for, and what the cart refuses without.
 *
 * The route to Zendit's endpoint is also no longer guessed from the product's
 * NAME once its category is known: "Bill's Bar & Burguer", a restaurant gift
 * card, was being sent down the bill-payment branch for containing "bill".
 */
class ZenditRequiredFieldsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $requiredFields
     */
    private function variant(array $requiredFields, string $name = 'Adele Dejak (KE)', string $categorySlug = 'gift-cards', string $offerId = 'ADELE_KE_2000'): ProductVariant
    {
        $category = Category::firstOrCreate(['slug' => $categorySlug], ['name' => ucwords(str_replace('-', ' ', $categorySlug)), 'type' => 'digital', 'is_active' => true, 'sort_order' => 0]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'name' => $name,
            'brand_key' => str($name)->before(' (')->slug()->toString(),
            'country_code' => 'KE',
            'is_active' => true,
        ]);

        return ProductVariant::factory()->create([
            'product_id' => $product->id,
            'provider_offer_id' => $offerId,
            'is_available' => true,
            'is_variable' => false,
            'face_value' => 10,
            'retail_price' => 10,
            'metadata' => ['offerId' => $offerId, 'requiredFields' => $requiredFields],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $itemMetadata
     * @param  array<string, mixed>|null  $snapshotCategory  null leaves the snapshot without a category
     *                                                       and detaches the category relation, as an old
     *                                                       order whose category was since removed reads
     */
    private function orderItem(ProductVariant $variant, ?array $itemMetadata, ?User $buyer = null, ?array $snapshotCategory = ['slug' => 'gift-cards']): OrderItem
    {
        $buyer ??= User::factory()->create(['name' => 'Ada Obi', 'phone' => null]);

        $order = Order::create([
            'user_id' => $buyer->id,
            'order_number' => 'RSR-'.uniqid(),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 10,
            'markup_amount' => 0,
            'total_amount' => 10,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'fulfillment_status' => 'processing',
            'order_status' => 'processing',
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0],
        ]);

        $snapshot = ['name' => $variant->product->name];

        if ($snapshotCategory !== null) {
            $snapshot['category'] = $snapshotCategory;
        }

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'category_id' => $variant->product->category_id,
            'subcategory_id' => Subcategory::factory()->create(['category_id' => $variant->product->category_id])->id,
            'provider_name' => 'zendit',
            'provider_offer_id' => $variant->provider_offer_id,
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 10,
            'provider_cost_usd' => 9,
            'markup_amount' => 1,
            'subtotal_amount' => 10,
            'product_snapshot' => $snapshot,
            'variant_snapshot' => $variant->toArray(),
            'fulfillment_status' => 'processing',
            'metadata' => $itemMetadata,
        ]);

        if ($snapshotCategory === null) {
            $item->setRelation('category', null);
        }

        return $item;
    }

    private function fakeZendit(): void
    {
        config(['services.zendit.api_key' => 'zendit-live-test-key']);

        Http::fake(['*' => Http::response(['transactionId' => 'RSR-TEST', 'status' => 'ACCEPTED'], 200)]);
    }

    /**
     * @return array{url: string, fields: array<string, string>}
     */
    private function sentPurchase(): array
    {
        $sent = Http::recorded()->first(fn ($pair) => str_contains($pair[0]->url(), '/purchases'));
        $this->assertNotNull($sent, 'A purchase must have been sent to Zendit.');

        return [
            'url' => $sent[0]->url(),
            'fields' => collect($sent[0]->data()['fields'] ?? [])->pluck('value', 'key')->all(),
        ];
    }

    // ------------------------------------------------------------ fulfilment

    public function test_an_offer_that_needs_the_recipient_phone_sends_what_the_buyer_typed(): void
    {
        $this->fakeZendit();
        $item = $this->orderItem($this->variant(['recipient.msisdn']), ['recipient_phone' => '+254712345678']);

        app(ZenditFulfillmentProvider::class)->fulfill($item);

        $fields = $this->sentPurchase()['fields'];
        $this->assertSame('+254712345678', $fields['recipient.msisdn']);
        $this->assertSame('Ada', $fields['recipient.firstName'], 'the contact fields every voucher carries are still sent');
    }

    public function test_an_offer_that_needs_the_buyer_phone_takes_it_from_the_profile_when_not_typed(): void
    {
        $this->fakeZendit();
        $buyer = User::factory()->create(['name' => 'Ada Obi', 'phone' => '+2348012345678']);
        $item = $this->orderItem($this->variant(['sender.msisdn', 'sender.firstName', 'sender.lastName']), null, $buyer);

        app(ZenditFulfillmentProvider::class)->fulfill($item);

        $fields = $this->sentPurchase()['fields'];
        $this->assertSame('+2348012345678', $fields['sender.msisdn']);
        $this->assertSame('Ada', $fields['sender.firstName']);
        $this->assertSame('Obi', $fields['sender.lastName']);
    }

    public function test_a_buyer_phone_typed_on_the_page_beats_the_profile(): void
    {
        $this->fakeZendit();
        $buyer = User::factory()->create(['phone' => '+2348012345678']);
        $item = $this->orderItem($this->variant(['sender.msisdn']), ['sender_phone' => '+237612345678'], $buyer);

        app(ZenditFulfillmentProvider::class)->fulfill($item);

        $this->assertSame('+237612345678', $this->sentPurchase()['fields']['sender.msisdn']);
    }

    /**
     * The Togolese betting wallet: account number and both phone numbers,
     * all at once. The product page used to send one or the other.
     */
    public function test_an_offer_that_needs_several_fields_gets_all_of_them(): void
    {
        $this->fakeZendit();
        $buyer = User::factory()->create(['name' => 'Ada Obi', 'phone' => '+2348012345678']);
        $item = $this->orderItem(
            $this->variant(['billing.accountId', 'recipient.msisdn', 'sender.msisdn'], 'CEET (TG)', offerId: 'CEET_TG_5000'),
            ['account_id' => '123456', 'recipient_phone' => '+22890123456'],
            $buyer,
        );

        app(ZenditFulfillmentProvider::class)->fulfill($item);

        $fields = $this->sentPurchase()['fields'];
        $this->assertSame('123456', $fields['billing.accountId']);
        $this->assertSame('+22890123456', $fields['recipient.msisdn']);
        $this->assertSame('+2348012345678', $fields['sender.msisdn']);
    }

    /**
     * Money must never be taken for what cannot be delivered: without the
     * number the item fails before Zendit is called, on the same fulfilment
     * log the account-number check has always written, so the failed-item
     * refund runs.
     */
    public function test_a_missing_recipient_phone_fails_before_reaching_zendit(): void
    {
        $this->fakeZendit();
        $item = $this->orderItem($this->variant(['recipient.msisdn']), null);

        $result = app(ZenditFulfillmentProvider::class)->fulfill($item);

        $this->assertSame(FulfillmentStatus::Failed, $result['status']);
        $this->assertSame('No recipient phone number on file for this purchase.', $result['payload']['error']);
        Http::assertNothingSent();
        $this->assertSame('No recipient_phone in order item metadata', FulfillmentLog::where('order_item_id', $item->id)->value('error_message'));
    }

    public function test_a_missing_buyer_phone_with_none_on_the_profile_fails_before_reaching_zendit(): void
    {
        $this->fakeZendit();
        $item = $this->orderItem($this->variant(['sender.msisdn']), null);

        $result = app(ZenditFulfillmentProvider::class)->fulfill($item);

        $this->assertSame(FulfillmentStatus::Failed, $result['status']);
        Http::assertNothingSent();
        $this->assertSame('No sender_phone in order item metadata', FulfillmentLog::where('order_item_id', $item->id)->value('error_message'));
    }

    /**
     * A field Zendit adds that this integration has never heard of is not a
     * reason to refuse the sale ourselves; Zendit names it, as it does today.
     */
    public function test_a_required_field_we_do_not_know_is_logged_and_left_to_zendit(): void
    {
        $this->fakeZendit();
        Log::spy();
        $item = $this->orderItem($this->variant(['recipient.dateOfBirth']), null);

        $result = app(ZenditFulfillmentProvider::class)->fulfill($item);

        $this->assertNotSame(FulfillmentStatus::Failed, $result['status']);
        $this->assertArrayNotHasKey('recipient.dateOfBirth', $this->sentPurchase()['fields']);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'recipient.dateOfBirth'))->once();
    }

    // --------------------------------------------------------------- routing

    /**
     * A restaurant gift card whose name contains "bill" is a gift card. The
     * bill-payment branch used to demand a meter number for it and fail every
     * order before Zendit was called; its real requirements are two names.
     */
    public function test_a_gift_card_called_bills_is_not_routed_as_a_bill_payment(): void
    {
        $this->fakeZendit();
        $item = $this->orderItem(
            $this->variant(['recipient.firstName', 'recipient.lastName'], "Bill's Bar & Burguer (US)", offerId: 'BILLBARBURGUER_US_25_EGIFT'),
            null,
        );

        $result = app(ZenditFulfillmentProvider::class)->fulfill($item);

        $this->assertNotSame(FulfillmentStatus::Failed, $result['status']);
        $purchase = $this->sentPurchase();
        $this->assertStringEndsWith('/vouchers/purchases', $purchase['url']);
        $this->assertArrayNotHasKey('billing.accountId', $purchase['fields']);
        $this->assertSame('Ada', $purchase['fields']['recipient.firstName']);
    }

    /**
     * The name still decides for an old order that recorded no category at
     * all, which is the one case the fallback was written for.
     */
    public function test_an_order_with_no_category_still_routes_by_name(): void
    {
        $this->fakeZendit();
        $item = $this->orderItem(
            $this->variant([], 'Eko Electricity Bill (NG)', offerId: 'EKO_NG_BILL'),
            ['account_id' => '04220098765'],
            snapshotCategory: null,
        );

        app(ZenditFulfillmentProvider::class)->fulfill($item);

        $this->assertSame('04220098765', $this->sentPurchase()['fields']['billing.accountId'], 'the bill branch ran, so the account number was sent');
    }

    // ------------------------------------------------------------------ cart

    public function test_the_cart_refuses_an_offer_needing_the_recipient_phone_without_one(): void
    {
        $variant = $this->variant(['recipient.msisdn']);
        $this->actingAs(User::factory()->create());

        $this->postJson(route('cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metadata.recipient_phone');

        $this->postJson(route('cart.items.add'), [
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'metadata' => ['recipient_phone' => '+254712345678'],
        ])->assertOk();
    }

    public function test_the_cart_takes_the_buyer_phone_from_the_profile_and_asks_only_when_there_is_none(): void
    {
        $variant = $this->variant(['sender.msisdn']);

        $this->actingAs(User::factory()->create(['phone' => null]));
        $this->postJson(route('cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metadata.sender_phone');

        $this->actingAs(User::factory()->create(['phone' => '+2348012345678']));
        $this->postJson(route('cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => 1])
            ->assertOk();
    }

    public function test_the_cart_names_every_missing_field_at_once(): void
    {
        $variant = $this->variant(['billing.accountId', 'recipient.msisdn', 'sender.msisdn'], 'CEET (TG)', offerId: 'CEET_TG_5000');
        $this->actingAs(User::factory()->create(['phone' => null]));

        $this->postJson(route('cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['metadata.account_id', 'metadata.recipient_phone', 'metadata.sender_phone']);
    }

    public function test_the_api_cart_applies_the_same_rule(): void
    {
        $variant = $this->variant(['recipient.msisdn']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.storefront.cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metadata.recipient_phone');
    }

    // ------------------------------------------------------------ storefront

    public function test_the_product_page_asks_for_the_recipient_phone_when_the_offer_needs_it(): void
    {
        $this->variant(['recipient.msisdn']);

        $this->get('/gift-cards/adele-dejak')
            ->assertOk()
            ->assertSee('phone number to send this to')
            ->assertSee('requiresRecipientPhone: true', false)
            ->assertSee('requiresSenderPhone: false', false);
    }

    public function test_the_product_page_asks_for_the_buyer_phone_prefilled_from_the_profile(): void
    {
        $this->variant(['sender.msisdn']);
        $this->actingAs(User::factory()->create(['phone' => '+2348012345678']));

        $this->get('/gift-cards/adele-dejak')
            ->assertOk()
            ->assertSee('Your phone number')
            ->assertSee('requiresSenderPhone: true', false)
            ->assertSee("senderPhoneOnFile: '+2348012345678'", false);
    }

    public function test_an_ordinary_gift_card_page_asks_for_nothing_extra(): void
    {
        $this->variant([]);

        $this->get('/gift-cards/adele-dejak')
            ->assertOk()
            ->assertDontSee('phone number to send this to')
            ->assertDontSee('Your phone number')
            ->assertSee('requiresRecipientPhone: false', false)
            ->assertSee('requiresSenderPhone: false', false);
    }
}
