<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gift-card categories on the home page.
 *
 * The four tiles above them say what we sell; these say what KIND of gift
 * card, which is the question most visitors arrive with. Each one deep-links
 * into the listing with that category already selected.
 */
class HomeGiftCardCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->category = Category::factory()->create(['slug' => 'gift-cards', 'name' => 'Gift Cards']);
    }

    private function subcategory(string $name, string $slug): Subcategory
    {
        return Subcategory::factory()->create([
            'category_id' => $this->category->id,
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    /**
     * The row's own markup, cut out of the page.
     *
     * The mega menu lists the same curated categories on every page, so a
     * page-wide assertion cannot tell the two apart.
     */
    private function row(string $html): string
    {
        $start = strpos($html, 'aria-label="Gift card categories"');

        if ($start === false) {
            return '';
        }

        $open = strrpos(substr($html, 0, $start), '<nav');
        $close = strpos($html, '</nav>', $start);

        return substr($html, $open, $close - $open);
    }

    /**
     * A brand is what puts a curated category on the row: the config maps
     * brand keys to categories, so the subcategory here only decides what the
     * listing's own filter would return.
     */
    private function stock(Subcategory $subcategory, bool $available = true, string $brand = ''): void
    {
        $product = Product::factory()->create([
            'category_id' => $this->category->id,
            'brand_key' => $brand !== '' ? $brand : 'Brand'.$subcategory->id,
            'country_code' => 'US',
            'is_active' => true,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'subcategory_id' => $subcategory->id,
            'is_available' => $available,
            'retail_price' => 10,
            'face_value' => 10,
        ]);
    }

    public function test_a_stocked_category_is_listed_and_deep_links_into_the_listing(): void
    {
        // Steam is one of Gaming's brands in config/gift_card_categories.php.
        $this->stock($this->subcategory('Gaming', 'gaming'), brand: 'Steam');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Gift card categories"', $html);

        // The label sits on its own line inside the <a>, after the mark, so it
        // never renders as ">Gaming<" and never directly follows the tag
        // either. The deep link is the unique anchor; /s lets the mark sit
        // between the two.
        $this->assertStringContainsString('subcategory=gaming"', $html);
        $this->assertMatchesRegularExpression('/subcategory=gaming".*?>\s*Gaming\s*</s', $html);
    }

    /**
     * A brand no curated category claims puts nothing on the row, however well
     * stocked it is. The row is the curated list; an unmapped brand is still
     * reachable through the listing itself.
     */
    public function test_an_unmapped_brand_puts_nothing_on_the_row(): void
    {
        $this->stock($this->subcategory('Gaming', 'gaming'), brand: 'BrandNobodyCurated');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('aria-label="Gift card categories"', $html);
    }

    /** A category whose only destination is the empty state is not a shortcut. */
    public function test_a_category_with_nothing_available_is_not_listed(): void
    {
        $this->stock($this->subcategory('Gaming', 'gaming'), brand: 'Steam');
        $this->stock($this->subcategory('Pets', 'pets'), available: false, brand: 'Chewy');
        $this->subcategory('Ghosts', 'ghosts');

        $row = $this->row($this->get('/')->assertOk()->getContent());

        $this->assertStringContainsString('subcategory=gaming"', $row);
        $this->assertStringNotContainsString('subcategory=pets', $row);
        $this->assertStringNotContainsString('subcategory=ghosts', $row);
    }

    /**
     * A brand whose every variant is sold out is not a shortcut either. The
     * row resolves brands through an AVAILABLE variant, so its links always
     * land on something.
     */
    public function test_a_sold_out_brand_does_not_carry_its_category(): void
    {
        $this->stock($this->subcategory('Pets', 'pets'), available: false, brand: 'Chewy');

        $row = $this->row($this->get('/')->assertOk()->getContent());

        $this->assertStringNotContainsString('subcategory=pets', $row);
    }

    /** With no gift cards at all, the block does not render an empty heading. */
    public function test_nothing_renders_when_there_are_no_categories(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('aria-label="Gift card categories"', $html);
    }

    /** Each category carries its own mark, and an unknown slug still renders. */
    public function test_every_category_card_draws_an_icon(): void
    {
        // Gaming's icon is a file; Pets has none of its own and falls back.
        $this->stock($this->subcategory('Gaming', 'gaming'), brand: 'Steam');
        $this->stock($this->subcategory('Pets', 'pets'), brand: 'Chewy');

        $html = $this->get('/')->assertOk()->getContent();

        $icons = config('gift_card_category_icons');
        $categories = config('gift_card_categories');

        $this->assertArrayHasKey('default', $icons, 'an unknown slug must have somewhere to fall back to');

        // Gaming points at a file already in public/assets, so a category
        // reads the same here as it does in the nav and the mobile sheet.
        $gamingIcon = $icons[$categories['gaming']['icon']];
        $this->assertArrayHasKey('asset', $gamingIcon);
        $this->assertStringContainsString(rawurlencode($gamingIcon['asset']), $html);

        // Pets names no icon of its own, so it draws the fallback path rather
        // than leaving a hole where a mark should be.
        $this->assertSame('default', $categories['pets']['icon'] ?? 'default');
        $this->assertMatchesRegularExpression(
            '/subcategory=pets".*?<path d="'.preg_quote($icons['default']['path'], '/').'"/s',
            $html
        );
    }

    /** A row that scrolls, not four lines of wrapped pills. */
    public function test_the_categories_render_as_a_carousel(): void
    {
        $this->stock($this->subcategory('Gaming', 'gaming'), brand: 'Steam');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('x-ref="track"', $html);
        $this->assertStringContainsString('overflow-x-auto', $html);

        // Each arrow appears on its own: a back arrow at the start of a row
        // has nothing to go back to.
        $this->assertStringContainsString('x-show="canPrev"', $html);
        $this->assertStringContainsString('x-show="canNext"', $html);

        // Unboxed: the mark over its label, no tile. Nineteen bordered cards
        // would compete with the four real category cards right above them.
        $this->assertStringNotContainsString(
            'flex h-full w-[140px] flex-col items-center gap-3 rounded-[12px] bg-[#ffffff]',
            $html
        );

        // Every name on ONE line, and no fixed item width - at a fixed one the
        // long names wrapped and the row sat at the height of the longest.
        $this->assertStringContainsString('whitespace-nowrap text-[14px] font-bold', $html);
        $this->assertStringNotContainsString('w-[132px]', $html);

        // One item per press, measured off the first item - a fixed pixel step
        // would overshoot a row of short labels and undershoot a row of long ones.
        $this->assertStringContainsString('getBoundingClientRect().width + gap', $html);

        // No "All gift cards" item in the ROW: the four tiles above already
        // lead there. The mega menu offers one, which is its job, not this
        // row's - so this is asserted against the row alone.
        $this->assertStringNotContainsString('All gift cards', $this->row($html));
    }

    /** It sits after the four category tiles, inside the panel below them. */
    public function test_it_renders_after_the_four_category_cards(): void
    {
        $this->stock($this->subcategory('Gaming', 'gaming'), brand: 'Steam');

        $html = $this->get('/')->assertOk()->getContent();

        $tiles = strpos($html, 'aria-label="Browse categories"');
        $block = strpos($html, 'aria-label="Gift card categories"');

        $this->assertNotFalse($tiles);
        $this->assertNotFalse($block);
        $this->assertLessThan($block, $tiles);
    }
}
