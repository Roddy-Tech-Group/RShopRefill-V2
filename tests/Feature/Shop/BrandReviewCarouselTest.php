<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gift card brand pages carry a review carousel scoped to THAT brand:
 * approved reviews left on delivered orders containing the brand, rendered
 * with the shared review card. Brands without reviews show no carousel.
 */
class BrandReviewCarouselTest extends TestCase
{
    use RefreshDatabase;

    private function seedBrandProduct(): Product
    {
        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);

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

        ProductVariant::factory()->for($product)->create([
            'currency' => 'USD',
            'face_value' => 10,
            'is_available' => true,
        ]);

        return $product;
    }

    private function seedBrandReview(Product $product, string $body): Review
    {
        $buyer = User::factory()->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $product->category_id]);

        $order = Order::create([
            'user_id' => $buyer->id,
            'order_number' => 'RSR-'.uniqid(),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 10.00,
            'markup_amount' => 2.00,
            'total_amount' => 12.00,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'order_status' => 'completed',
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $product->variants()->first()->id,
            'category_id' => $product->category_id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'test',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 10.00,
            'provider_cost_usd' => 8.00,
            'markup_amount' => 2.00,
            'subtotal_amount' => 10.00,
            'product_snapshot' => ['brand_key' => 'Acme', 'name' => 'Acme Gift Card', 'country_code' => 'US'],
            'fulfillment_status' => 'fulfilled',
        ]);

        return Review::create([
            'order_id' => $order->id,
            'user_id' => $buyer->id,
            'author_name' => 'Brand Fan',
            'initials' => 'BF',
            'source' => 'RshopRefills',
            'body' => $body,
            'rating' => 5,
            'reviewed_at' => now()->toDateString(),
            'is_published' => true,
            'is_customer_submitted' => true,
        ]);
    }

    public function test_gift_card_page_shows_its_own_review_carousel(): void
    {
        $product = $this->seedBrandProduct();
        $this->seedBrandReview($product, 'Best Acme gift card purchase experience ever.');

        $this->withoutVite()->get(route('shop.brand', ['brandSlug' => 'acme']))
            ->assertOk()
            ->assertSee('What customers say about Acme')
            ->assertSee('Best Acme gift card purchase experience ever.');
    }

    public function test_no_carousel_renders_when_the_brand_has_no_reviews(): void
    {
        $this->seedBrandProduct();

        $this->withoutVite()->get(route('shop.brand', ['brandSlug' => 'acme']))
            ->assertOk()
            ->assertDontSee('What customers say about');
    }

    public function test_order_page_shows_the_brand_review_carousel_below_view_all_orders(): void
    {
        $product = $this->seedBrandProduct();
        $review = $this->seedBrandReview($product, 'Acme codes arrive in seconds, love it.');

        $buyer = $review->order->user;

        $html = $this->withoutVite()
            ->actingAs($buyer)
            ->get(route('shop.order', $review->order->order_number))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('What customers say about Acme', $html);
        $this->assertStringContainsString('Acme codes arrive in seconds, love it.', $html);

        // Order: the View all my orders button comes first, the carousel after it.
        $this->assertLessThan(
            strpos($html, 'What customers say about Acme'),
            strpos($html, 'View all my orders'),
        );
    }
}
