<?php

namespace Tests\Feature\Catalog;

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

    /** Home, then the category, then the subcategory this brand sits in. */
    public function test_the_trail_runs_home_category_subcategory(): void
    {
        $this->product->update([
            'subcategory_id' => Subcategory::create([
                'category_id' => $this->product->category_id,
                'name' => 'Digital Apps',
                'slug' => 'digital-apps',
            ])->id,
        ]);

        $html = $this->page();

        $this->assertStringContainsString('aria-label="Breadcrumb"', $html);
        foreach (['>Home</a>', '>Gift Cards</a>', '>Digital Apps</a>'] as $crumb) {
            $this->assertStringContainsString($crumb, $html);
        }

        // Each one is a real link, the last narrowing the listing to this family.
        $this->assertStringContainsString('href="'.e(route('shop.gift-cards', ['subcategory' => 'digital-apps'])).'"', $html);
        $this->assertLessThan(strpos($html, '<h1'), strpos($html, '>Home</a>'));
        $this->assertLessThan(strpos($html, '>Digital Apps</a>'), strpos($html, '>Gift Cards</a>'));
    }

    /** The trail a reader sees is the trail search engines are told. */
    public function test_the_drawn_trail_and_the_markup_agree(): void
    {
        $this->product->update([
            'subcategory_id' => Subcategory::create([
                'category_id' => $this->product->category_id,
                'name' => 'Digital Apps',
                'slug' => 'digital-apps',
            ])->id,
        ]);

        $html = $this->page();

        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        foreach (['"position":1', '"position":2', '"position":3', '"position":4'] as $position) {
            $this->assertStringContainsString($position, $html);
        }
        $this->assertStringContainsString('"name":"Digital Apps"', $html);
        $this->assertStringContainsString('"name":"Acme Gift Card"', $html);
    }

    /**
     * The dashboard renders this same view inside its own chrome, where the
     * sidebar already says where you are, so the trail is a storefront thing.
     */
    public function test_the_dashboard_mirror_draws_no_trail(): void
    {
        $this->product->update([
            'subcategory_id' => Subcategory::create([
                'category_id' => $this->product->category_id,
                'name' => 'Digital Apps',
                'slug' => 'digital-apps',
            ])->id,
        ]);

        $html = $this->withoutVite()
            ->actingAs(User::factory()->create())
            ->get(route('dashboard.shop.brand', ['brandSlug' => 'acme']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('aria-label="Breadcrumb"', $html);

        // The page itself is still the page.
        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('Acme gift card', $html);

        // The storefront, which is the indexed one, keeps both the trail and
        // the markup that describes it.
        $storefront = $this->page();
        $this->assertStringContainsString('aria-label="Breadcrumb"', $storefront);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $storefront);
    }

    /** A brand with no subcategory simply has a shorter trail. */
    public function test_a_brand_with_no_subcategory_has_a_two_step_trail(): void
    {
        $html = $this->page();

        $start = strpos($html, 'aria-label="Breadcrumb"');
        $this->assertNotFalse($start, 'The breadcrumb did not render.');
        $nav = substr($html, $start, strpos($html, '</nav>', $start) - $start);

        $this->assertStringContainsString('>Home</a>', $nav);
        $this->assertStringContainsString('>Gift Cards</a>', $nav);
        $this->assertSame(2, substr_count($nav, '</a>'));
    }

    /**
     * The link out to the listing is the site's own row pill, the one every
     * carousel header wears, and it carries no arrow. This is a source check
     * because the link only draws when the brand has neighbours in its
     * subcategory, which a fixture here would be inventing.
     */
    public function test_the_view_all_link_is_the_shared_pill_without_arrows(): void
    {
        $source = file_get_contents(resource_path('views/shop/product.blade.php'));

        $this->assertStringContainsString('<x-ui.row-controls', $source);
        $this->assertStringContainsString(":label=\"__('products.view_all')\"", $source);
        $this->assertStringContainsString(':arrows="false"', $source);

        // No arrow, and no hand-rolled copy of the pill.
        $this->assertStringNotContainsString("{{ __('products.view_all') }} \u{2192}", $source);
        $this->assertStringNotContainsString("{{ __('products.view_all') }}", $source);
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

        // The stars follow the score: 4.5 is nine tenths of the row, not five stars.
        $this->assertStringContainsString('style="width: 90%"', $html);
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

    /**
     * The line under the rating says what the card is for, which follows the
     * brand's own subcategory. The supplier sends a company blurb instead, the
     * same shape on every brand, and that belongs in the long-form copy.
     */
    public function test_the_line_under_the_name_says_what_the_card_pays_for(): void
    {
        $this->product->update([
            'description' => 'Acme is a multinational technology company that designs and sells consumer electronics.',
            'subcategory_id' => Subcategory::create([
                'category_id' => $this->product->category_id,
                'name' => 'Digital Apps',
                'slug' => 'digital-apps',
            ])->id,
        ]);

        $html = $this->page();

        $purpose = 'Pays for apps, games, subscriptions and devices at Acme in United States.';
        $this->assertStringContainsString($purpose, $html);

        // It leads the copy; the supplier's blurb is further down the page.
        $blurb = strpos($html, 'Acme is a multinational technology company');
        $this->assertNotFalse($blurb, 'The supplier blurb was dropped instead of moved.');
        $this->assertLessThan($blurb, strpos($html, $purpose));
        $this->assertGreaterThan(strpos($html, 'Frequently asked questions'), $blurb);
    }

    /** A different kind of brand gets a different line, not the same one. */
    public function test_the_line_follows_the_brands_own_subcategory(): void
    {
        $this->product->update([
            'subcategory_id' => Subcategory::create([
                'category_id' => $this->product->category_id,
                'name' => 'Restaurant',
                'slug' => 'restaurant',
            ])->id,
        ]);

        $html = $this->page();

        $this->assertStringContainsString('Pays for meals, drinks and groceries at Acme in United States.', $html);
        $this->assertStringNotContainsString('Pays for apps, games', $html);
    }

    /** A brand with no subcategory still says something true about itself. */
    public function test_a_brand_with_no_subcategory_falls_back_to_the_general_line(): void
    {
        $this->assertStringContainsString(
            'Pays for anything Acme sells in United States, in store and online.',
            $this->page()
        );
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
