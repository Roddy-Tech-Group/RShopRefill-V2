<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The gift-card category row.
 *
 * It says what KIND of gift card we carry, which is the question most visitors
 * arrive with, and each item deep-links into the listing with that category
 * already selected.
 *
 * IT IS NOT ON THE HOME PAGE, and that is deliberate. The trust panel it used
 * to sit in was given over to the live customer counts, and this row is what
 * those counts replaced. HomeTrustPanelTest owns that fact and asserts the row
 * stays out.
 *
 * So these render the component DIRECTLY rather than through `/`. The component
 * is kept and kept working because the behaviour below is worth keeping: which
 * categories earn a place, which are withheld, the marks, the carousel. Going
 * through the home page instead would make this file fail for a reason that has
 * nothing to do with any of that, which is exactly what happened when the row
 * was first taken out.
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

    /**
     * The component on its own, as HTML.
     *
     * Blade rather than a route, because the row is mounted nowhere: rendering
     * it in isolation is the only way to exercise it, and it is also the more
     * honest test of a component, since nothing on a surrounding page can make
     * an assertion pass by accident.
     */
    private function render(): string
    {
        return (string) $this->blade('<x-home.gift-card-categories />');
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

        $html = $this->render();

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

        $html = $this->render();

        $this->assertStringNotContainsString('aria-label="Gift card categories"', $html);
    }

    /** A category whose only destination is the empty state is not a shortcut. */
    public function test_a_category_with_nothing_available_is_not_listed(): void
    {
        $this->stock($this->subcategory('Gaming', 'gaming'), brand: 'Steam');
        $this->stock($this->subcategory('Pets', 'pets'), available: false, brand: 'Chewy');
        $this->subcategory('Ghosts', 'ghosts');

        $row = $this->row($this->render());

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

        $row = $this->row($this->render());

        $this->assertStringNotContainsString('subcategory=pets', $row);
    }

    /** With no gift cards at all, the block does not render an empty heading. */
    public function test_nothing_renders_when_there_are_no_categories(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('aria-label="Gift card categories"', $html);
    }

    /**
     * Each category carries its own mark, and an unknown slug still renders.
     *
     * Every mark is inline path data now. Most of them used to be files in
     * public/assets, picked so a category read the same here as it did in the
     * nav and the mobile sheet. That sameness cost one HTTP request per
     * category on a row that draws eleven of them, and the artwork was flat
     * black rather than currentColor, so each one also needed a
     * `dark:brightness-0 dark:invert` correction to survive dark mode. Drawn
     * inline they cost no extra request, no decode and no layout shift while a
     * lazy image arrives, and they inherit the label's colour, so dark mode
     * needs no correction at all.
     *
     * So what this guards is the whole set being inline rather than any one
     * category still pointing at any one file: a single entry sliding back to
     * an 'asset' would quietly bring both problems back with it.
     */
    public function test_every_category_card_draws_an_icon(): void
    {
        // Gaming names a mark of its own; Pets has none and falls back.
        $this->stock($this->subcategory('Gaming', 'gaming'), brand: 'Steam');
        $this->stock($this->subcategory('Pets', 'pets'), brand: 'Chewy');

        $row = $this->row($this->render());

        $icons = config('gift_card_category_icons');
        $categories = config('gift_card_categories');

        $this->assertArrayHasKey('default', $icons, 'an unknown slug must have somewhere to fall back to');

        foreach ($icons as $slug => $icon) {
            $this->assertArrayHasKey('path', $icon, "The {$slug} mark must be inline path data.");
            $this->assertArrayNotHasKey('asset', $icon, "The {$slug} mark must not be a file.");
        }

        // Gaming names its own mark, and it is drawn against Gaming's own deep
        // link rather than simply existing somewhere on the page.
        $gamingIcon = $icons[$categories['gaming']['icon']];
        $this->assertMatchesRegularExpression(
            '/subcategory=gaming".*?<path d="'.preg_quote($gamingIcon['path'], '/').'"/s',
            $row
        );

        // Pets names no icon of its own, so it draws the fallback path rather
        // than leaving a hole where a mark should be.
        $this->assertSame('default', $categories['pets']['icon'] ?? 'default');
        $this->assertMatchesRegularExpression(
            '/subcategory=pets".*?<path d="'.preg_quote($icons['default']['path'], '/').'"/s',
            $row
        );
    }

    /** A row that scrolls, not four lines of wrapped pills. */
    public function test_the_categories_render_as_a_carousel(): void
    {
        $this->stock($this->subcategory('Gaming', 'gaming'), brand: 'Steam');

        $html = $this->render();

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
        //
        // The size is a pair now, not a single 14px. A phone shows two or three
        // categories of a row that has eleven, which reads as a stalled row
        // rather than a scrollable one, so the label steps down to 11px (and the
        // mark and the gaps with it) to fit more of the row on screen, then
        // returns to 14px from sm where the space is there anyway.
        $this->assertStringContainsString('whitespace-nowrap text-[11px] font-bold', $html);
        $this->assertStringContainsString('sm:text-[14px]', $html);
        $this->assertStringNotContainsString('w-[132px]', $html);

        // A screenful per press, settling on the nearest item edge so nothing
        // sits half-cut. This used to step to the very next edge, which is one
        // chip per press: eleven presses to cross eleven categories, and the row
        // read as barely moving. The reach is measured off the track and the
        // landing off the items, so neither part is a fixed pixel step - that
        // would overshoot a row of short labels and undershoot a row of long
        // ones. Asserted against the row, because the brand carousels further
        // down the page carry stepping code of their own.
        $this->assertStringContainsString('track.clientWidth * 0.8', $this->row($html));
        $this->assertStringContainsString('item.getBoundingClientRect().left - edge', $this->row($html));

        // No "All gift cards" item in the ROW: the four tiles above already
        // lead there. The mega menu offers one, which is its job, not this
        // row's - so this is asserted against the row alone.
        $this->assertStringNotContainsString('All gift cards', $this->row($html));
    }

    /**
     * It is not on the home page, and it has to be asserted here as well as in
     * HomeTrustPanelTest.
     *
     * This test used to say the opposite: that the row sat after the four
     * category tiles, inside the panel below them. That was true until the
     * panel was handed to the live counts. Anyone arriving at this file will
     * assume a component with a full test suite belongs on the page it is named
     * after, which is how it got put back once already. The assertion lives
     * where that assumption gets made.
     */
    public function test_the_row_is_deliberately_not_on_the_home_page(): void
    {
        $this->stock($this->subcategory('Gaming', 'gaming'), brand: 'Steam');

        // It renders perfectly well on its own, so this is not a broken row.
        $this->assertStringContainsString('aria-label="Gift card categories"', $this->render());

        // The home page simply does not carry it.
        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('aria-label="Browse categories"', $home, 'The four tiles DO belong there.');
        $this->assertStringNotContainsString('aria-label="Gift card categories"', $home);
    }
}
