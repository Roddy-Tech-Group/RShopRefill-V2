<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A gift card's values are a standing four-column grid, not a dropdown: a small
 * tile per value showing its face value and currency code, smallest first, with
 * a blue outline on the chosen one and no standing highlight on any other.
 * Top-ups and bills keep their dropdown.
 */
class GiftCardDenominationGridTest extends TestCase
{
    use RefreshDatabase;

    private function giftCard(array $values): string
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

        foreach ($values as $value) {
            ProductVariant::factory()->for($product)->create([
                'currency' => 'USD',
                'face_value' => $value,
                'retail_price' => $value,
                'manual_retail_price_usd' => $value,
                'is_available' => true,
                'is_variable' => false,
            ]);
        }

        return $this->withoutVite()->get(route('shop.brand', ['brandSlug' => 'acme']))->assertOk()->getContent();
    }

    /**
     * The grid, cut out of the page. Anchored on its own class string: the
     * page carries other radiogroups (the theme picker is one), and the first
     * of those is not this.
     */
    private function grid(string $html): string
    {
        $start = strpos($html, 'class="grid grid-cols-4 gap-2" role="radiogroup"');
        $this->assertNotFalse($start, 'The denomination grid did not render.');

        return substr($html, $start, strpos($html, '</div>', $start) - $start);
    }

    public function test_values_are_a_four_column_grid_of_tiles_not_a_dropdown(): void
    {
        $html = $this->giftCard([10, 25, 50]);
        $grid = $this->grid($html);

        $this->assertStringContainsString('grid grid-cols-4 gap-2', $html);
        $this->assertSame(3, substr_count($grid, 'role="radio"'));

        // The amount dropdown's trigger is gone from a gift card page. (Other
        // dropdowns on the page keep their listboxes, so that role is not asserted.)
        $this->assertStringNotContainsString("x-text=\"amount ? amount : 'Select amount'\"", $html);
    }

    /**
     * A tile carries its face value and nothing else. What the value costs is in
     * the purchase card, which follows the chosen tile, so the grid stays small
     * and one price is on the page rather than one per tile.
     */
    public function test_each_tile_shows_its_face_value_with_its_code_and_no_price(): void
    {
        $grid = $this->grid($this->giftCard([10, 50]));

        $this->assertStringContainsString('>50 USD</span>', $grid);
        $this->assertStringContainsString('>10 USD</span>', $grid);
        $this->assertDoesNotMatchRegularExpression('/>50 USD<\/span>\s*<span/', $grid);
        $this->assertStringNotContainsString('formatUsdIn(selectedCrypto', $grid);
    }

    public function test_values_run_smallest_to_largest(): void
    {
        $grid = $this->grid($this->giftCard([5, 100, 25]));

        $this->assertLessThan(strpos($grid, '>25 USD<'), strpos($grid, '>5 USD<'));
        $this->assertLessThan(strpos($grid, '>100 USD<'), strpos($grid, '>25 USD<'));
    }

    public function test_selection_is_by_variant_and_outlines_the_chosen_tile(): void
    {
        $grid = $this->grid($this->giftCard([10]));
        $id = ProductVariant::sole()->id;

        $this->assertStringContainsString("selectedVariantId = {$id}; customMode = false", $grid);
        $this->assertStringContainsString("(! customMode && selectedVariantId === {$id})", $grid);
        $this->assertStringContainsString("'border-blue-600 ring-1 ring-blue-600 dark:border-blue-500 dark:ring-blue-500'", $grid);

        // The project's 12px corners, and the dark surface class the panel uses.
        $this->assertStringContainsString('pure-card relative flex aspect-square flex-col items-center justify-center rounded-[12px] border bg-white px-2', $grid);
    }

    /** No invented values: only the variants that exist are offered. */
    public function test_only_real_values_are_offered(): void
    {
        $grid = $this->grid($this->giftCard([10]));

        $this->assertStringContainsString('>10 USD<', $grid);
        $this->assertStringNotContainsString('>25 USD<', $grid);
        $this->assertSame(1, substr_count($grid, 'role="radio"'));
    }

    /** No tile is singled out: only the customer's own choice is marked. */
    public function test_no_value_carries_a_standing_highlight(): void
    {
        $grid = $this->grid($this->giftCard([10, 25, 50]));

        $this->assertStringNotContainsString('ring-2 ring-[#2563eb]', $grid);
        $this->assertStringNotContainsString('border-transparent', $grid);

        // Every unchosen tile wears the same quiet border.
        $this->assertSame(3, substr_count($grid, "'border-zinc-200 hover:border-blue-300 dark:border-white/10 dark:hover:border-blue-400/50'"));
    }

    /** The chosen tile also shows a check, so "featured" and "chosen" never look alike. */
    public function test_the_chosen_tile_shows_a_check(): void
    {
        $grid = $this->grid($this->giftCard([10]));
        $id = ProductVariant::sole()->id;

        $this->assertStringContainsString("x-show=\"! customMode && selectedVariantId === {$id}\" x-cloak class=\"absolute -right-1.5 -top-1.5", $grid);
    }
}
