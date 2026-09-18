<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The phone menu sheet's category pages.
 *
 * On a phone the mega menu has nothing to hover, so its catalogue opens as a
 * second page inside the menu sheet instead: Gift Cards and Mobile Refill
 * swap the sheet for their own page (with a way back), eSIMs and Bill
 * Payments navigate directly. The Gift Cards content comes from the same
 * GiftCardCategories::navMenu() call the desktop hover panel renders, so the
 * two menus cannot drift.
 */
class MobileNavSheetTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->category = Category::factory()->create(['slug' => 'gift-cards', 'name' => 'Gift Cards']);
    }

    /**
     * A brand with an available variant, the same seed shape
     * HomeGiftCardCategoriesTest uses: the brand is what puts a curated
     * category on the menu.
     */
    private function stock(string $brand, string $subcategorySlug = 'gaming'): void
    {
        $subcategory = Subcategory::factory()->create([
            'category_id' => $this->category->id,
            'name' => ucfirst($subcategorySlug),
            'slug' => $subcategorySlug,
        ]);

        $product = Product::factory()->create([
            'category_id' => $this->category->id,
            'brand_key' => $brand,
            'country_code' => 'US',
            'is_active' => true,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'subcategory_id' => $subcategory->id,
            'is_available' => true,
            'retail_price' => 10,
            'face_value' => 10,
        ]);
    }

    /** The sheet's Gift Cards page, cut out of the whole document. */
    private function giftPage(string $html): string
    {
        $start = strpos($html, 'id="mobile-gift-cards-menu"');
        $end = strpos($html, 'id="mobile-topups-menu"');

        return ($start !== false && $end !== false) ? substr($html, $start, $end - $start) : '';
    }

    /** The sheet's Mobile Refill page, cut out of the whole document. */
    private function topupPage(string $html): string
    {
        $start = strpos($html, 'id="mobile-topups-menu"');

        if ($start === false) {
            return '';
        }

        // The open-mobile-menu listener span is the first distinctive marker
        // after the sheet's pages.
        return substr($html, $start, strpos($html, 'open-mobile-menu.window', $start) - $start);
    }

    public function test_gift_cards_and_mobile_refill_rows_open_their_own_pages(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // Buttons that swap the sheet for a page, not links that navigate away.
        $this->assertStringContainsString('aria-controls="mobile-gift-cards-menu"', $html);
        $this->assertStringContainsString('aria-controls="mobile-topups-menu"', $html);

        // Two pages only: eSIMs and Bill Payments stay plain links.
        $this->assertSame(2, substr_count($html, 'aria-controls="mobile-'));

        // Each page starts with a way back to the sheet's root.
        $this->assertStringContainsString(__('common.menu'), $this->giftPage($html));
        $this->assertStringContainsString(__('common.menu'), $this->topupPage($html));
    }

    public function test_the_gift_cards_page_opens_the_catalogue(): void
    {
        // Steam is one of Gaming's brands in config/gift_card_categories.php,
        // and is on the featured list - so it also proves the popular column.
        $this->stock('Steam');

        $page = $this->giftPage($this->get('/')->assertOk()->getContent());
        $this->assertNotSame('', $page);

        // The three ways in that head the desktop panel.
        $this->assertStringContainsString(__('products.all_gift_cards'), $page);
        $this->assertStringContainsString('subcategory=popular"', $page);
        $this->assertStringContainsString('sort=recent"', $page);

        // The stocked curated category, deep-linking into the listing.
        $this->assertStringContainsString('subcategory=gaming"', $page);

        // Popular in the visitor's region: Steam stocks US, the default region.
        $this->assertStringContainsString(route('shop.brand', ['brandSlug' => Product::brandSlug('Steam')]), $page);
    }

    /** With no catalogue at all, the page still offers the three ways in. */
    public function test_an_empty_catalogue_leaves_no_category_headings(): void
    {
        $page = $this->giftPage($this->get('/')->assertOk()->getContent());
        $this->assertNotSame('', $page);

        $this->assertStringContainsString(__('products.all_gift_cards'), $page);
        $this->assertStringNotContainsString(__('navigation.categories'), $page);
        $this->assertStringNotContainsString('subcategory=gaming', $page);
    }

    /** The sheet foot carries the footer-style theme control. */
    public function test_the_sheet_foot_carries_the_segmented_theme_control(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $start = strpos($html, 'mt-auto flex flex-col gap-1 pt-6');
        $this->assertNotFalse($start);
        $end = strpos($html, 'id="mobile-gift-cards-menu"', $start);
        $this->assertNotFalse($end);
        $foot = substr($html, $start, $end - $start);

        // The footer arrangement: the Theme label beside the segmented chip
        // (the pill with the locale chip's ring), not the dropdown trigger it
        // replaced. The label reads "Theme" as written, not shouted in caps.
        $this->assertStringContainsString(__('footer.theme'), $foot);
        $this->assertStringContainsString('rounded-full p-0.5 ring-1 ring-[#dee3e1]', $foot);
        $this->assertStringNotContainsString('uppercase tracking-wider text-zinc-500', $foot);
    }

    /**
     * The Mobile Refill page mirrors the filter pills on the top-ups page -
     * same labels, same URLs - so the menu and the page never name a view
     * differently.
     */
    public function test_the_mobile_refill_page_lists_the_filter_views(): void
    {
        $page = $this->topupPage($this->get('/')->assertOk()->getContent());
        $this->assertNotSame('', $page);

        $this->assertStringContainsString('All networks', $page);
        $this->assertStringContainsString('subcategory=credit"', $page);
        $this->assertStringContainsString('subcategory=bundles"', $page);
    }
}
