<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Switching category is a class toggle, not a page load.
 *
 * Every category is already on the page as its own section, so a sidebar click
 * used to fetch a server render of tiles the browser was holding all along -
 * a progress bar and a repaint to show markup that already existed.
 */
class CategoryClientFilterTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->category = Category::factory()->create(['slug' => 'gift-cards', 'name' => 'Gift Cards']);
    }

    private function stock(string $brand, string $subName, string $subSlug): void
    {
        $subcategory = Subcategory::firstOrCreate(
            ['category_id' => $this->category->id, 'slug' => $subSlug],
            ['name' => $subName]
        );

        $product = Product::factory()->create([
            'category_id' => $this->category->id,
            'brand_key' => $brand,
            'name' => $brand,
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

    /**
     * A deep-linked category still ships EVERY section, or switching away from
     * it would need a request after all.
     */
    public function test_a_deep_linked_category_still_renders_every_section(): void
    {
        $this->stock('GamerBrand', 'Gaming', 'gaming');
        $this->stock('TravelBrand', 'Travel', 'travel');

        $html = $this->get('/gift-cards?country=US&subcategory=gaming')->assertOk()->getContent();

        // Both brands are on the page even though only one category is selected.
        // Tiles print Product::brandDisplayName(), which splits a CamelCase key
        // into words - "GamerBrand" renders as "Gamer Brand".
        $this->assertStringContainsString('Gamer Brand', $html);
        $this->assertStringContainsString('Travel Brand', $html);

        // And the selected one is what shows on arrival.
        $this->assertStringContainsString("activeCategory: 'gaming'", $html);
    }

    public function test_the_all_view_starts_with_nothing_selected(): void
    {
        $this->stock('GamerBrand', 'Gaming', 'gaming');

        $html = $this->get('/gift-cards?country=US')->assertOk()->getContent();

        $this->assertStringContainsString("activeCategory: ''", $html);
    }

    /**
     * The scope has to enclose the sidebar, the mobile sheet AND the sections.
     * Scoped to the sections alone, the two menus have no activeCategory to
     * read and their bindings throw.
     */
    public function test_the_filter_scope_encloses_both_menus_and_the_sections(): void
    {
        $this->stock('GamerBrand', 'Gaming', 'gaming');

        $html = $this->get('/gift-cards?country=US')->assertOk()->getContent();

        $scope = strpos($html, 'activeCategory: ');
        $this->assertNotFalse($scope);

        // Exactly one scope - two would leave one menu bound to a stale copy.
        $this->assertSame(1, substr_count($html, 'activeCategory: '));

        $this->assertLessThan(strpos($html, 'aria-label="Subcategories"'), $scope);
        $this->assertLessThan(strpos($html, '<h2 id="cat-0"'), $scope);
    }

    /** Sections toggle with x-show, so the tiles never leave the DOM. */
    public function test_sections_toggle_rather_than_re_render(): void
    {
        $section = File::get(resource_path('views/components/shop/category-section.blade.php'));

        $this->assertStringContainsString(
            "x-show=\"activeCategory === '' || activeCategory === ",
            $section
        );
        $this->assertStringNotContainsString('x-if="activeCategory', $section);
    }

    /** The links keep a real href, so middle-click and JS-off still work. */
    public function test_the_menu_links_keep_a_real_url_and_drop_wire_navigate(): void
    {
        $this->stock('GamerBrand', 'Gaming', 'gaming');

        $html = $this->get('/gift-cards?country=US')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<a\s+href="[^"]*subcategory=gaming"/',
            $html,
            'the link must still carry the server URL'
        );

        // Between the two menus, no subcategory link asks Livewire to navigate.
        //
        // Scoped to the listing's own menus - the sidebar and the mobile
        // picker - which is what this rule is about: their sections are all
        // already on the page, so a Livewire navigation would re-fetch markup
        // the browser is holding. The nav chrome above them carries
        // subcategory links of its own (the menu sheet's Gift Cards page, and
        // its Mobile Refill filters, which point at a DIFFERENT page that
        // really does filter server-side), so a page-wide sweep would be
        // asserting this rule somewhere it does not apply.
        preg_match_all('/<a\s+href="[^"]*subcategory=[^"]*"[^>]*>/s', $this->listingMenus($html), $links);
        $this->assertNotEmpty($links[0], 'the listing menus must carry subcategory links');
        foreach ($links[0] as $link) {
            $this->assertStringNotContainsString('wire:navigate', $link);
        }
    }

    /**
     * The listing's own category menus, cut out of the page: everything from
     * the mobile picker's sheet through the end of the desktop sidebar. The
     * nav chrome sits above it and the tile sections below.
     */
    private function listingMenus(string $html): string
    {
        $start = strpos($html, 'aria-label="'.__('products.pick_a_category').'"');
        $end = strpos($html, '<h2 id="cat-0"');

        $this->assertNotFalse($start, 'the mobile category picker must render');
        $this->assertNotFalse($end, 'the first category section must render');

        return substr($html, $start, $end - $start);
    }
}
