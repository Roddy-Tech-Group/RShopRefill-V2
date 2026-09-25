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
use Tests\TestCase;

/**
 * Offers outside Bill Payments can still be paid straight into an account:
 * Sporty Bet (NG) syncs in as a gift card, and Zendit refused the paid order
 * with "billing.accountId: cannot be empty" because only bill payments asked
 * for, or sent, the account number. Zendit's own requiredFields decides now.
 */
class ZenditRequiredAccountIdTest extends TestCase
{
    use RefreshDatabase;

    /** Zendit's offer as the catalogue sync stores it in variant metadata. */
    private const SPORTY_BET_OFFER = [
        'offerId' => 'SPORTYBET_NG_1000',
        'requiredFields' => ['billing.accountId'],
    ];

    private function giftCardVariant(array $metadata): ProductVariant
    {
        $category = Category::firstOrCreate(['slug' => 'gift-cards'], ['name' => 'Gift Cards', 'type' => 'digital', 'is_active' => true, 'sort_order' => 0]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Sporty Bet (NG)',
            'brand_key' => 'SportyBet',
            'country_code' => 'NG',
            'is_active' => true,
        ]);

        return ProductVariant::factory()->create([
            'product_id' => $product->id,
            'provider_offer_id' => $metadata['offerId'] ?? 'OFFER_1',
            'is_available' => true,
            'is_variable' => false,
            'face_value' => 10,
            'retail_price' => 10,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $itemMetadata
     * @param  array<string, mixed>|null  $snapshot
     */
    private function orderItem(ProductVariant $variant, ?array $itemMetadata, ?array $snapshot = null): OrderItem
    {
        $order = Order::create([
            'user_id' => User::factory()->create(['name' => 'Ada Obi'])->id,
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

        return OrderItem::create([
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
            'product_snapshot' => ['name' => 'Sporty Bet (NG)', 'category' => ['slug' => 'gift-cards']],
            'variant_snapshot' => $snapshot ?? $variant->toArray(),
            'fulfillment_status' => 'processing',
            'metadata' => $itemMetadata,
        ]);
    }

    private function fakeZendit(): void
    {
        config(['services.zendit.api_key' => 'zendit-live-test-key']);

        Http::fake(['*' => Http::response(['transactionId' => 'RSR-TEST', 'status' => 'ACCEPTED'], 200)]);
    }

    /**
     * @return array<string, string>
     */
    private function sentFields(): array
    {
        $sent = Http::recorded()->first(fn ($pair) => str_contains($pair[0]->url(), '/vouchers/purchases'));
        $this->assertNotNull($sent, 'A voucher purchase must have been sent to Zendit.');

        return collect($sent[0]->data()['fields'] ?? [])->pluck('value', 'key')->all();
    }

    public function test_an_offer_that_requires_an_account_number_sends_it(): void
    {
        $this->fakeZendit();
        $item = $this->orderItem($this->giftCardVariant(self::SPORTY_BET_OFFER), ['account_id' => ' 8012345678 ']);

        app(ZenditFulfillmentProvider::class)->fulfill($item);

        $fields = $this->sentFields();
        $this->assertSame('8012345678', $fields['billing.accountId']);
        $this->assertArrayHasKey('recipient.email', $fields);
        $this->assertSame('Ada', $fields['recipient.firstName']);
    }

    /**
     * Without the number the purchase is refused before it reaches Zendit,
     * with a clear reason on the fulfilment log.
     */
    public function test_a_missing_account_number_fails_before_reaching_zendit(): void
    {
        $this->fakeZendit();
        $item = $this->orderItem($this->giftCardVariant(self::SPORTY_BET_OFFER), null);

        $result = app(ZenditFulfillmentProvider::class)->fulfill($item);

        $this->assertSame(FulfillmentStatus::Failed, $result['status']);
        Http::assertNothingSent();
        $this->assertSame('No account_id in order item metadata', FulfillmentLog::where('order_item_id', $item->id)->value('error_message'));
    }

    public function test_an_ordinary_gift_card_sends_no_account_number(): void
    {
        $this->fakeZendit();
        $item = $this->orderItem($this->giftCardVariant(['offerId' => 'APPLE_US_10', 'requiredFields' => []]), null);

        app(ZenditFulfillmentProvider::class)->fulfill($item);

        $fields = $this->sentFields();
        $this->assertArrayNotHasKey('billing.accountId', $fields);
        $this->assertSame(['recipient.email', 'recipient.firstName', 'recipient.lastName'], array_keys($fields));
    }

    /**
     * An item whose snapshot predates requiredFields reads the live variant.
     */
    public function test_an_older_snapshot_falls_back_to_the_live_offer(): void
    {
        $this->fakeZendit();
        $variant = $this->giftCardVariant(self::SPORTY_BET_OFFER);
        $item = $this->orderItem($variant, ['account_id' => '8012345678'], ['id' => $variant->id, 'is_variable' => false]);

        app(ZenditFulfillmentProvider::class)->fulfill($item);

        $this->assertSame('8012345678', $this->sentFields()['billing.accountId']);
    }

    // ---------------------------------------------------------- storefront

    public function test_the_product_page_asks_for_the_account_number(): void
    {
        $this->giftCardVariant(self::SPORTY_BET_OFFER);

        $this->get('/gift-cards/sporty-bet')
            ->assertOk()
            ->assertSee(__('products.detail_account_label', ['brand' => 'Sporty Bet']))
            ->assertSee('requiresAccountId: true', false);
    }

    public function test_an_ordinary_gift_card_page_does_not_ask_for_it(): void
    {
        $this->giftCardVariant(['offerId' => 'APPLE_US_10', 'requiredFields' => []]);

        $this->get('/gift-cards/sporty-bet')
            ->assertOk()
            ->assertDontSee('account number')
            ->assertSee('requiresAccountId: false', false);
    }

    public function test_the_cart_refuses_the_offer_without_an_account_number(): void
    {
        $variant = $this->giftCardVariant(self::SPORTY_BET_OFFER);
        $this->actingAs(User::factory()->create());

        $this->postJson(route('cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metadata.account_id');

        $this->postJson(route('cart.items.add'), [
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'metadata' => ['account_id' => '8012345678'],
        ])->assertOk();
    }

    public function test_the_cart_still_takes_an_ordinary_gift_card_without_one(): void
    {
        $variant = $this->giftCardVariant(['offerId' => 'APPLE_US_10', 'requiredFields' => []]);
        $this->actingAs(User::factory()->create());

        $this->postJson(route('cart.items.add'), ['product_variant_id' => $variant->id, 'quantity' => 1])->assertOk();
    }
}
