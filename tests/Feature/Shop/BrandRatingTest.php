<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Gift-card tiles carry a rating when the brand has been reviewed.
 *
 * The rollup is one query for the whole page: the listing renders a card per
 * brand and there are ~688 of them, so a per-card lookup would be a page of
 * N+1 on top of the variant load the price range already needs.
 */
class BrandRatingTest extends TestCase
{
    use RefreshDatabase;

    /** Products need a category; every test here builds them the same way. */
    private function product(string $brand): Product
    {
        return Product::factory()->create([
            'category_id' => Category::factory()->create()->id,
            'brand_key' => $brand,
        ]);
    }

    /** An order line for a brand. order_items requires a variant. */
    private function lineItem(Order $order, Product $product): void
    {
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => ProductVariant::factory()->create(['product_id' => $product->id])->id,
            'category_id' => $product->category_id,
            'subcategory_id' => Subcategory::factory()->create(['category_id' => $product->category_id])->id,
        ]);
    }

    /** A published review on an order containing the brand. */
    private function reviewBrand(string $brand, float $rating, array $state = []): Review
    {
        $product = $this->product($brand);
        $order = Order::factory()->create();
        $this->lineItem($order, $product);

        return Review::factory()->create(array_merge([
            'order_id' => $order->id,
            'rating' => $rating,
        ], $state));
    }

    public function test_a_reviewed_brand_gets_an_average_and_a_count(): void
    {
        $this->reviewBrand('Apple', 5);
        $this->reviewBrand('Apple', 4);

        $ratings = Review::brandRatings(['Apple']);

        $this->assertSame(4.5, $ratings['Apple']['average']);
        $this->assertSame(2, $ratings['Apple']['count']);
    }

    /** No review, no rating - the card shows nothing rather than a zero. */
    public function test_an_unreviewed_brand_is_absent(): void
    {
        $this->product('Steam');

        $this->assertSame([], Review::brandRatings(['Steam']));
    }

    public function test_unpublished_reviews_do_not_count(): void
    {
        $this->reviewBrand('Apple', 5, ['is_published' => false]);

        $this->assertSame([], Review::brandRatings(['Apple']));
    }

    /**
     * Trustpilot and Google host their own reviews; the storefront shows only
     * what our form collected. A brand's number must match what its page shows.
     */
    public function test_external_platform_reviews_do_not_count(): void
    {
        $this->reviewBrand('Apple', 5, ['source' => 'Trustpilot', 'is_customer_submitted' => false]);

        $this->assertSame([], Review::brandRatings(['Apple']));
    }

    /** A customer submission is ours however the source column ended up labelled. */
    public function test_a_customer_submission_counts_whatever_the_source_says(): void
    {
        $this->reviewBrand('Apple', 5, ['source' => 'Trustpilot', 'is_customer_submitted' => true]);

        $this->assertSame(1, Review::brandRatings(['Apple'])['Apple']['count']);
    }

    /**
     * One order holding two cards of the same brand joins its single review
     * twice. Without the DISTINCT that reads as two reviews.
     */
    public function test_one_review_counts_once_even_across_two_items_of_a_brand(): void
    {
        $product = $this->product('Apple');
        $second = $this->product('Apple');
        $order = Order::factory()->create();
        $this->lineItem($order, $product);
        $this->lineItem($order, $second);
        Review::factory()->create(['order_id' => $order->id, 'rating' => 5]);

        $this->assertSame(1, Review::brandRatings(['Apple'])['Apple']['count']);
    }

    public function test_brands_are_scoped_to_the_keys_asked_for(): void
    {
        $this->reviewBrand('Apple', 5);
        $this->reviewBrand('Steam', 3);

        $this->assertSame(['Apple'], array_keys(Review::brandRatings(['Apple'])));
    }

    public function test_no_keys_means_no_query(): void
    {
        DB::enableQueryLog();
        $this->assertSame([], Review::brandRatings([]));
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    /** The whole page's ratings load in a single round trip. */
    public function test_the_rollup_is_one_query_for_any_number_of_brands(): void
    {
        foreach (['Apple', 'Steam', 'Netflix', 'Spotify'] as $brand) {
            $this->reviewBrand($brand, 5);
        }

        DB::enableQueryLog();
        Review::brandRatings(['Apple', 'Steam', 'Netflix', 'Spotify']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }

    /**
     * The tile: rating beside the name, price underneath at 16px.
     * Source-level, so it does not need the catalogue seeded.
     */
    public function test_the_tile_shows_the_rating_beside_the_name_and_a_16px_price(): void
    {
        // The tile moved into its own component when the listing grew a section
        // per category: it renders from a grouped section AND from the flat
        // filtered grid, so it cannot stay inline in the page.
        $tile = (string) preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            File::get(resource_path('views/components/shop/brand-tile.blade.php'))
        );
        $page = (string) preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            File::get(resource_path('views/shop/gift-cards.blade.php'))
        );

        // Rendered only when the brand has one.
        $this->assertStringContainsString('@if ($rating)', $tile);

        // Name takes the slack so the rating pins to the card's edge.
        // Name, rating and price share one size and weight, so the caption
        // reads as one block rather than four typographic decisions.
        $this->assertStringContainsString('min-w-0 flex-1 truncate text-[16px] font-bold', $tile);
        $this->assertStringContainsString('truncate text-[14px] font-bold text-zinc-900', $tile);
        $this->assertStringNotContainsString('text-[13px]', $tile);

        // The page resolves the rating and hands it down - the tile never
        // looks one up, or the listing is back to a query per card.
        $this->assertStringContainsString('$brandRatings[$product->brand_key] ?? null', $page);
        $this->assertStringNotContainsString('brandRatings', $tile);

        // One rollup for the page. Anchored on the assignment: the // comment
        // above it names the method too, and a PHP comment survives the
        // Blade-comment strip.
        $this->assertSame(1, substr_count($page, '$brandRatings = '));
    }

    /**
     * The carousel tile is a different component doing the same job - home
     * category rows and the dashboard carousels. It gets the rating and the
     * same 16px price, or one catalogue reads as two designs.
     */
    public function test_the_carousel_tile_matches_the_grid_tile(): void
    {
        $card = File::get(resource_path('views/components/home/brand-card.blade.php'));
        $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $card);

        $this->assertStringContainsString("'rating' => null,", $card);
        $this->assertStringContainsString('@if ($rating)', $markup);
        $this->assertStringContainsString('min-w-0 flex-1 truncate', $markup);

        // The price is the grid tiles own line, to the class: it was muted grey
        // at a larger size than the brand name, which is the opposite of matching.
        $tile = File::get(resource_path('views/components/shop/brand-tile.blade.php'));
        $price = 'mt-0.5 truncate text-[14px] font-bold text-zinc-900 dark:text-white';

        $this->assertStringContainsString($price, $markup);
        $this->assertStringContainsString($price, $tile);
        $this->assertStringNotContainsString('text-xs text-zinc-600', $markup);
        $this->assertStringNotContainsString('text-[16px] text-zinc-600', $markup);
    }

    /** Every page that renders the carousel tile feeds it a rating. */
    public function test_the_carousel_pages_pass_a_rating(): void
    {
        foreach ([
            'welcome.blade.php',
            'livewire/dashboard/overview.blade.php',
        ] as $view) {
            $markup = (string) preg_replace(
                '/\{\{--.*?--\}\}/s',
                '',
                File::get(resource_path('views/'.$view))
            );

            $this->assertStringContainsString(
                ':rating="$brandRatings[$p->brand_key] ?? null"',
                $markup,
                $view.' should pass a rating to every brand card.'
            );

            // One rollup per page, not one lookup per card.
            $this->assertSame(
                substr_count($markup, '<x-home.brand-card'),
                substr_count($markup, ':rating="$brandRatings['),
                $view.' should feed every card from the same rollup.'
            );
        }
    }

    /**
     * The listing tile is one pattern used by three pages. The price size has
     * to move on all of them together or the catalogue reads as three
     * different designs.
     */
    public function test_every_listing_tile_prices_at_14px(): void
    {
        // gift-cards renders x-shop.brand-tile; bills and topups still carry the
        // tile inline. Both shapes have to price the same, or the catalogue
        // reads as two designs.
        foreach ([
            'components/shop/brand-tile',
            'shop/bills',
            'shop/topups',
        ] as $view) {
            $markup = (string) preg_replace(
                '/\{\{--.*?--\}\}/s',
                '',
                File::get(resource_path('views/'.$view.'.blade.php'))
            );

            // 14px bold in the name's ink: plainly second to the brand name,
            // where 16px grey read as a competing line.
            $this->assertStringContainsString(
                'truncate text-[14px] font-bold text-zinc-900',
                $markup,
                $view.' should price its tiles at 14px bold.'
            );
            $this->assertStringNotContainsString('text-[16px] font-bold text-zinc-600', $markup);
        }
    }
}
