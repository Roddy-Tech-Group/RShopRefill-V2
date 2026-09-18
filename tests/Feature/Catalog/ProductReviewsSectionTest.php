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
use Tests\TestCase;

/**
 * A gift card page carries its own reviews: the score with the spread of
 * ratings beside it, then the reviews in two columns, ten at a time with the
 * rest a click away. They are the same reviews every other surface shows -
 * approved, collected by us, left on delivered orders containing this brand -
 * and they are written into the HTML rather than fetched, so the page carries
 * them with scripting off.
 */
class ProductReviewsSectionTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

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

    /** A review left on an order that contained this brand's gift card. */
    private function review(array $attributes = [], ?Product $product = null): Review
    {
        $product ??= $this->product;
        $variant = $product->is($this->product)
            ? $this->variant
            : ProductVariant::factory()->for($product)->create(['is_available' => true, 'is_variable' => false]);

        $subcategory = Subcategory::firstOrCreate(
            ['category_id' => $product->category_id, 'slug' => 'gift-cards'],
            ['name' => 'Gift Cards']
        );

        $order = Order::factory()->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $product->category_id,
            'subcategory_id' => $subcategory->id,
        ]);

        return Review::factory()->create(array_merge([
            'order_id' => $order->id,
            'body' => 'Delivered in under a minute and the code worked first time.',
            'rating' => 5,
            'is_customer_submitted' => true,
        ], $attributes));
    }

    private function page(): string
    {
        return $this->withoutVite()->get(route('shop.brand', ['brandSlug' => 'acme']))->assertOk()->getContent();
    }

    /** The reviews section, from its own aria-label through the end of the page. */
    private function section(string $html): string
    {
        $start = strpos($html, 'aria-label="Acme customer reviews"');
        $this->assertNotFalse($start, 'The reviews section did not render.');

        return substr($html, $start);
    }

    public function test_a_brand_with_no_reviews_shows_no_review_section(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString('customer reviews"', $html);
        $this->assertStringNotContainsString('Most relevant', $html);
    }

    /** Someone else's reviews are not this product's. */
    public function test_only_reviews_left_on_orders_for_this_brand_are_shown(): void
    {
        $other = Product::create([
            'category_id' => $this->product->category_id,
            'provider_name' => 'zendit',
            'brand_key' => 'Other',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Other Gift Card',
            'slug' => 'other-gift-card',
            'is_active' => true,
        ]);

        $this->review(['body' => 'Mine: this brand, delivered fast.']);
        $this->review(['body' => 'Theirs: a different brand entirely.'], $other);

        // Hidden sets: awaiting approval, and one attributed to another platform.
        $this->review(['body' => 'Still waiting on approval.', 'is_published' => false]);
        $this->review(['body' => 'Collected somewhere else.', 'is_customer_submitted' => false, 'source' => 'Trustpilot']);

        $section = $this->section($this->page());

        $this->assertStringContainsString('Mine: this brand, delivered fast.', $section);
        $this->assertStringNotContainsString('Theirs: a different brand entirely.', $section);
        $this->assertStringNotContainsString('Still waiting on approval.', $section);
        $this->assertStringNotContainsString('Collected somewhere else.', $section);
    }

    public function test_the_score_and_the_spread_are_the_reviews_own_numbers(): void
    {
        $this->review(['rating' => 5]);
        $this->review(['rating' => 5]);
        $this->review(['rating' => 5]);
        $this->review(['rating' => 3]);

        $section = $this->section($this->page());

        // (5 + 5 + 5 + 3) / 4 = 4.5, over four reviews.
        $this->assertStringContainsString('>4.5</span>', $section);
        $this->assertStringContainsString('4 Reviews', $section);

        // Three of four at five stars, one of four at three, nothing at the rest.
        $this->assertStringContainsString('style="width: 75%"', $section);
        $this->assertStringContainsString('style="width: 25%"', $section);
        $this->assertSame(3, substr_count($section, 'style="width: 0%"'));
    }

    public function test_the_reviews_are_two_columns_of_name_stars_date_and_body(): void
    {
        $review = $this->review(['author_name' => 'Ada Lovelace', 'rating' => 4]);

        $section = $this->section($this->page());

        $this->assertStringContainsString('grid gap-x-10 gap-y-8 sm:grid-cols-2', $section);
        $this->assertStringContainsString('Ada Lovelace', $section);
        $this->assertStringContainsString($review->reviewed_at->format('M j, Y'), $section);
        $this->assertStringContainsString('Delivered in under a minute and the code worked first time.', $section);

        // Four filled stars and one empty for a four-star review.
        $this->assertSame(4, substr_count($section, 'h-4 w-4 text-blue-600 dark:text-blue-500'));
        $this->assertSame(1, substr_count($section, 'h-4 w-4 text-blue-600/20 dark:text-blue-500/20'));
    }

    /**
     * A reviewer is shown by their profile photo when they set one, and by the
     * same plain user glyph the account button falls back to when they did not.
     * Initials are not drawn here.
     */
    public function test_a_reviewer_shows_their_photo_or_the_plain_user_glyph(): void
    {
        $withPhoto = User::factory()->create(['avatar_url' => 'https://cdn.example.test/me.jpg']);

        $this->review(['author_name' => 'Has Photo', 'user_id' => $withPhoto->id]);
        $this->review(['author_name' => 'No Photo', 'user_id' => null]);

        $section = $this->section($this->page());

        $this->assertStringContainsString('src="https://cdn.example.test/me.jpg"', $section);
        $this->assertSame(1, substr_count($section, '<img src="https://cdn.example.test/me.jpg"'));

        // The other one falls back to the glyph, inside the same round frame.
        $this->assertStringContainsString('h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full', $section);
        $this->assertStringNotContainsString('>HP</span>', $section);
        $this->assertStringNotContainsString('>NP</span>', $section);
    }

    /**
     * Every review is in the HTML, so the page carries them without scripting;
     * the list only hides the ones past the cursor.
     */
    public function test_all_reviews_are_written_out_and_ten_are_shown_at_a_time(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->review(['body' => "Review number {$i} of twelve."]);
        }

        $section = $this->section($this->page());

        for ($i = 1; $i <= 12; $i++) {
            $this->assertStringContainsString("Review number {$i} of twelve.", $section);
        }

        $this->assertStringContainsString('perPage: 10', file_get_contents(resource_path('js/app.js')));
        $this->assertStringContainsString('x-show="shows(11)"', $section);
        $this->assertStringContainsString('Showing 10 out of 12', $section);
        $this->assertStringContainsString('Load more', $section);
    }

    /** Under eleven reviews there is nothing to load, so the control is not drawn. */
    public function test_a_short_list_has_no_load_more(): void
    {
        $this->review();

        $section = $this->section($this->page());

        $this->assertStringContainsString('x-show="total > perPage"', $section);
        $this->assertStringNotContainsString('Showing 10 out of', $section);
    }

    public function test_the_sort_is_the_sites_own_dropdown_and_its_labels_are_translated(): void
    {
        $this->review();

        $html = $this->page();
        $section = $this->section($html);

        $this->assertStringNotContainsString('<select', $section);
        $this->assertStringContainsString('aria-haspopup="listbox"', $section);
        foreach (['Most relevant', 'Newest', 'Highest rating', 'Lowest rating'] as $label) {
            $this->assertStringContainsString($label, $section);
        }

        // The labels are passed into the list rather than written in the script,
        // so they follow the visitor's language.
        $this->assertMatchesRegularExpression('/x-data="productReviews\(.*sorts.*Most relevant/', $section);
        $this->assertStringNotContainsString("relevant: 'Most relevant'", file_get_contents(resource_path('js/app.js')));

        $french = $this->withSession(['locale' => 'fr'])->get(route('shop.brand', ['brandSlug' => 'acme']))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('products.reviews_sort_relevant', [], 'fr')), $french);
        $this->assertStringNotContainsString('Most relevant', $french);
    }

    /** The carousel stays where it is used elsewhere; the page has one review surface. */
    public function test_the_page_shows_one_review_surface(): void
    {
        $this->review();

        $html = $this->page();

        $this->assertStringNotContainsString('What customers say about', $html);
        $this->assertSame(1, substr_count($html, 'customer reviews"'));
    }
}
