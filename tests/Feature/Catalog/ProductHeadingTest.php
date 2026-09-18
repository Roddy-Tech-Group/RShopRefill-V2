<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A gift card page opens with where it sits, what it is, how it is rated and
 * what it does for you: a breadcrumb, a large name, the customer rating, then
 * the three promises as bullets rather than the badge row the other kinds keep.
 */
class ProductHeadingTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);

        $this->product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'brand_key' => 'Acme',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Acme Gift Card',
            'slug' => 'acme-gift-card',
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::factory()->for($this->product)->create([
            'currency' => 'USD',
            'face_value' => 10,
            'retail_price' => 10,
            'manual_retail_price_usd' => 10,
            'is_available' => true,
            'is_variable' => false,
        ]);
    }

    private function page(): string
    {
        return $this->withoutVite()->get(route('shop.brand', ['brandSlug' => 'acme']))->assertOk()->getContent();
    }

    private function review(int $rating = 5): void
    {
        $subcategory = Subcategory::firstOrCreate(
            ['category_id' => $this->product->category_id, 'slug' => 'gift-cards'],
            ['name' => 'Gift Cards']
        );

        $order = Order::factory()->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->variant->id,
            'category_id' => $this->product->category_id,
            'subcategory_id' => $subcategory->id,
        ]);

        Review::factory()->create([
            'order_id' => $order->id,
            'body' => 'Arrived instantly and redeemed without trouble.',
            'rating' => $rating,
            'is_customer_submitted' => true,
        ]);
    }

    public function test_the_trail_names_the_country_then_the_kind(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('aria-label="Breadcrumb"', $html);

        // The country first, narrowing to the kind, both of them real links.
        $country = strpos($html, 'href="'.e(route('shop.gift-cards', ['country' => 'US'])).'"');
        $this->assertNotFalse($country, 'The country crumb is not a link to its listing.');
        $this->assertStringContainsString('>United States</a>', $html);
        $this->assertStringContainsString('>Gift Cards</a>', $html);
        $this->assertLessThan(strpos($html, '<h1'), $country);
    }

    public function test_the_name_leads_at_heading_size(): void
    {
        $this->assertStringContainsString('<h1 class="text-[30px] sm:text-[36px] font-bold leading-tight', $this->page());
    }

    public function test_the_rating_stands_under_the_name_once_the_card_has_reviews(): void
    {
        $this->assertStringNotContainsString('reviews)', $this->page());

        $this->review(5);
        $this->review(4);
        Cache::flush();

        $html = $this->page();

        // 4.5 over two, linking to where the reviews are read.
        $this->assertStringContainsString('>4.5</span>', $html);
        $this->assertStringContainsString('(2 reviews)', $html);
        $this->assertStringContainsString('href="'.e(route('shop.reviews')).'"', $html);

        $rating = strpos($html, '>4.5</span>');
        $this->assertGreaterThan(strpos($html, '<h1'), $rating);
        $this->assertLessThan(strpos($html, 'Instant delivery'), $rating);
    }

    /** The three promises read as a list here, not as the badge row. */
    public function test_the_promises_are_bullets_and_the_badge_row_is_gone(): void
    {
        $html = $this->page();

        foreach (['Instant delivery', 'Online redeemable', 'Fair refund policy'] as $promise) {
            $this->assertStringContainsString($promise, $html);
        }

        $this->assertStringContainsString('<ul class="mt-4 space-y-2 text-base text-zinc-800', $html);
        $this->assertSame(3, substr_count($html, '<path d="M0 0l8 5-8 5z"/>'));

        // The emerald badge circles are not drawn for a gift card.
        $this->assertStringNotContainsString('rounded-[12px] bg-emerald-50 text-emerald-600', $html);

        // Top-ups and bills still have them; a source check, because building a
        // top-up fixture here would test the fixture.
        $source = file_get_contents(resource_path('views/shop/product.blade.php'));
        $this->assertStringContainsString('bg-emerald-50 text-emerald-600 ring-1 ring-emerald-100', $source);
        $this->assertStringContainsString('@unless ($isGiftCard)', $source);
    }

    public function test_the_breadcrumb_landmark_is_translated(): void
    {
        $french = $this->withoutVite()
            ->withSession(['locale' => 'fr'])
            ->get(route('shop.brand', ['brandSlug' => 'acme']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('aria-label="'.e(__('products.detail_breadcrumb', [], 'fr')).'"', $french);
        $this->assertStringNotContainsString('aria-label="Breadcrumb"', $french);
    }
}
