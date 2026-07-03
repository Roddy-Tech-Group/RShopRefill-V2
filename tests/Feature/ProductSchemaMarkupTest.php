<?php

namespace Tests\Feature;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProductSchemaMarkupTest extends TestCase
{
    use RefreshDatabase;

    private function giftCardBrand(): Product
    {
        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);

        // "Acme" has no display-name alias, so the schema name stays deterministic.
        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'brand_key' => 'Acme',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Acme Gift Card',
            'slug' => 'acme-gift-card',
            'is_active' => true,
        ]);

        // manual_retail_price_usd pins the payable USD price (bypasses markup),
        // so the schema's low/high prices are deterministic.
        ProductVariant::factory()->for($product)->create([
            'currency' => 'USD',
            'face_value' => 10,
            'retail_price' => 10,
            'manual_retail_price_usd' => 10,
            'is_available' => true,
            'is_variable' => false,
        ]);
        ProductVariant::factory()->for($product)->create([
            'currency' => 'USD',
            'face_value' => 50,
            'retail_price' => 50,
            'manual_retail_price_usd' => 50,
            'is_available' => true,
            'is_variable' => false,
        ]);

        return $product;
    }

    /**
     * A gift card denominated in a non-USD currency. Its face value is local
     * (EUR) but the customer pays in USD, so the schema must advertise USD.
     */
    private function euroGiftCardBrand(): Product
    {
        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);

        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'brand_key' => 'Euro',
            'country_code' => 'FR',
            'currency_code' => 'EUR',
            'name' => 'Euro Gift Card',
            'slug' => 'euro-gift-card',
            'is_active' => true,
        ]);

        ProductVariant::factory()->for($product)->create([
            'currency' => 'EUR',
            'face_value' => 25,
            'retail_price' => 25,
            'manual_retail_price_usd' => 27,
            'is_available' => true,
            'is_variable' => false,
        ]);

        return $product;
    }

    public function test_gift_card_page_emits_product_schema_with_aggregate_offer(): void
    {
        $this->withoutVite();
        $this->giftCardBrand();

        $response = $this->get(route('shop.brand', ['brandSlug' => 'acme']))->assertOk();

        $response->assertSee('"@type":"Product"', false);
        $response->assertSee('Acme Gift Card', false);
        $response->assertSee('"@type":"AggregateOffer"', false);
        // The offer should span the cheapest -> priciest denomination.
        $response->assertSee('"lowPrice":"10"', false);
        $response->assertSee('"highPrice":"50"', false);
        $response->assertSee('"priceCurrency":"USD"', false);
        $response->assertSee('https://schema.org/InStock', false);
    }

    public function test_brand_page_renders_unique_data_driven_content(): void
    {
        $this->withoutVite();
        $this->giftCardBrand();

        $response = $this->get(route('shop.brand', ['brandSlug' => 'acme']))->assertOk();

        // Long-form copy woven from real catalog data (brand, category, country)
        // so the page isn't a thin near-duplicate template.
        $response->assertSee('Buy Acme Gift Card in United States', false);
        $response->assertSee('How to buy Acme gift card', false);
        // Richer, per-brand meta description (country + denomination range).
        $response->assertSee('Buy Acme gift card in United States', false);
    }

    public function test_non_usd_gift_card_advertises_usd_price_currency_for_merchant_shipping_match(): void
    {
        $this->withoutVite();
        $this->euroGiftCardBrand();

        $response = $this->get(route('shop.brand', ['brandSlug' => 'euro']))->assertOk();

        $response->assertSee('"@type":"AggregateOffer"', false);
        // Priced in USD (the settlement + Merchant shipping currency), and the USD
        // payable amount - never the card's local EUR face value. A local
        // priceCurrency is what triggers Google's shipping-currency mismatch.
        $response->assertSee('"priceCurrency":"USD"', false);
        $response->assertDontSee('"priceCurrency":"EUR"', false);
        $response->assertSee('"lowPrice":"27"', false);
    }

    public function test_gift_card_page_emits_aggregate_rating_and_reviews_from_published_reviews(): void
    {
        $this->withoutVite();
        $this->giftCardBrand();

        Review::create([
            'initials' => 'JD',
            'author_name' => 'Jane Doe',
            'body' => 'Fast delivery and great prices, very happy.',
            'rating' => 5,
            'source' => 'RshopRefills',
            'reviewed_at' => now(),
            'is_published' => true,
        ]);

        // The schema review aggregate is cached; clear it so the new review counts.
        Cache::flush();

        $response = $this->get(route('shop.brand', ['brandSlug' => 'acme']))->assertOk();

        $response->assertSee('"@type":"AggregateRating"', false);
        $response->assertSee('"ratingValue":"5"', false);
        $response->assertSee('"reviewCount":"1"', false);
        $response->assertSee('"@type":"Review"', false);
        $response->assertSee('Jane Doe', false);
    }

    public function test_gift_card_page_prefers_this_brands_own_reviews_over_the_site_aggregate(): void
    {
        $this->withoutVite();
        $product = $this->giftCardBrand();
        $user = User::factory()->create();

        // A site-wide review (no order) that would otherwise feed the aggregate.
        Review::create([
            'initials' => 'GG', 'author_name' => 'Global Gary', 'body' => 'Generally a great store to use.',
            'rating' => 5, 'source' => 'RshopRefills', 'reviewed_at' => now(), 'is_published' => true,
        ]);

        // A review tied to an Acme order - this is the brand's own review.
        $order = Order::create([
            'user_id' => $user->id, 'order_number' => 'ORD-ACME-1',
            'settlement_currency' => 'USD', 'display_currency' => 'USD',
            'subtotal_amount' => 10, 'markup_amount' => 0, 'total_amount' => 10,
            'payment_method' => 'wallet', 'payment_status' => PaymentStatus::Paid,
            'fulfillment_status' => FulfillmentStatus::Fulfilled, 'order_status' => OrderStatus::Completed,
        ]);
        $subcategory = Subcategory::factory()->create(['category_id' => $product->category_id]);
        $variant = ProductVariant::factory()->for($product)->create();
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $product->category_id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'test_provider',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 10,
            'provider_cost_usd' => 8,
            'markup_amount' => 2,
            'subtotal_amount' => 10,
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
        ]);

        Review::create([
            'user_id' => $user->id, 'order_id' => $order->id,
            'initials' => 'AB', 'author_name' => 'Acme Buyer', 'body' => 'This Acme card delivered instantly.',
            'rating' => 4, 'source' => 'RshopRefills', 'reviewed_at' => now(),
            'is_published' => true, 'is_customer_submitted' => true,
        ]);

        Cache::flush();

        $response = $this->get(route('shop.brand', ['brandSlug' => 'acme']))->assertOk();

        // Brand-specific rating (4 from the Acme review), not the blended 4.5.
        $response->assertSee('"ratingValue":"4"', false);
        $response->assertSee('"reviewCount":"1"', false);
        $response->assertSee('Acme Buyer', false);
        $response->assertDontSee('Global Gary', false);
    }

    public function test_gift_card_page_omits_review_schema_when_no_published_reviews(): void
    {
        $this->withoutVite();
        $this->giftCardBrand();

        Cache::flush();

        $response = $this->get(route('shop.brand', ['brandSlug' => 'acme']))->assertOk();

        $response->assertDontSee('"@type":"AggregateRating"', false);
    }

    public function test_gift_card_page_emits_breadcrumb_schema(): void
    {
        $this->withoutVite();
        $this->giftCardBrand();

        $response = $this->get(route('shop.brand', ['brandSlug' => 'acme']))->assertOk();

        $response->assertSee('"@type":"BreadcrumbList"', false);
        $response->assertSee('Gift Cards', false);
    }
}
