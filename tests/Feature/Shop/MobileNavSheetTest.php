<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The phone menu sheet: four categories, one page.
 *
 * It used to be two pages deep. Gift Cards and Mobile Refill swapped the sheet
 * for their own page listing every curated category and popular brand, while
 * eSIMs and Bill Payments navigated directly.
 *
 * That drill-down is gone. Tapping a category row from the menu, Gaming say,
 * landed on the gift-card listing with a category preselected, and that listing
 * is the heaviest page in the app: every brand for the region, rendered in every
 * section. The wait was long enough that the menu felt broken. The listing
 * carries the same categories as pills, where switching is a class toggle on
 * markup the browser already holds, so the menu was the slow road to something
 * the page itself does instantly. Building those panels also cost every phone
 * visit a navMenu() call for something most people never opened.
 *
 * The desktop hover panel is untouched. It renders from its own component and
 * still shows the full catalogue.
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
     * A brand with an available variant: the shape that would have put a
     * curated category on the old menu page.
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

    /** The sheet itself, cut out of the document. */
    private function sheet(string $html): string
    {
        $start = strpos($html, 'role="dialog"');
        $this->assertNotFalse($start, 'The menu sheet did not render.');

        $end = strpos($html, 'open-mobile-menu.window', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    /**
     * On a phone the bar shows the menu button and nothing else of the brand:
     * the mark lives in the sheet, at the top, where a tap goes home.
     */
    public function test_the_sheet_opens_with_the_mark_linking_home(): void
    {
        $sheet = $this->sheet($this->get('/')->assertOk()->getContent());

        // The wordmark, as a link home, before the category rows. The drawer is
        // 86vw of its own and has room to say the name; the bar keeps the bare
        // mark because it shares its line with search and the account chips.
        $this->assertMatchesRegularExpression(
            '/<a\s+href="'.preg_quote(e(route('home')), '/').'"[^>]*>\s*<img src="[^"]*logo\.webp[^"]*"/s',
            $sheet
        );
        $this->assertStringNotContainsString('PWAicon.webp', $sheet, 'the drawer should not fall back to the bare mark');
        $this->assertStringContainsString('class="site-logo h-9 w-auto object-contain"', $sheet);

        // And the bar's own logo link is gone below md: hidden there, flex from md.
        $nav = file_get_contents(resource_path('views/components/nav/main-nav.blade.php'));
        $this->assertStringContainsString('justify-self-start -ml-1 relative hidden md:flex items-center', $nav);
    }

    /** All four categories are plain links that navigate and close the sheet. */
    public function test_the_four_categories_are_plain_links(): void
    {
        $sheet = $this->sheet($this->get('/')->assertOk()->getContent());

        foreach ([
            route('shop.gift-cards'),
            route('shop.esims'),
            route('shop.topups'),
            route('shop.bills'),
        ] as $url) {
            $this->assertMatchesRegularExpression(
                '/<a\s+href="'.preg_quote(e($url), '/').'"\s+wire:navigate\s+@click="menuOpen = false"/',
                $sheet,
                $url.' is not a plain closing link in the sheet'
            );
        }
    }

    /**
     * The regression this replaced. No row opens a second page, so there is no
     * drill-down state, no back button and no panel to build.
     */
    public function test_no_row_drills_down(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $sheet = $this->sheet($html);

        $this->assertStringNotContainsString('aria-controls="mobile-', $sheet, 'a row still opens its own page');
        $this->assertStringNotContainsString('id="mobile-gift-cards-menu"', $html);
        $this->assertStringNotContainsString('id="mobile-topups-menu"', $html);

        // The paging state is gone with the pages it drove.
        $nav = file_get_contents(resource_path('views/components/nav/main-nav.blade.php'));
        $this->assertStringContainsString('x-data="{ menuOpen: false }"', $nav);
        $this->assertStringNotContainsString('sub === ', $nav);
        $this->assertStringNotContainsString('sub = null', $nav);
    }

    /**
     * And a stocked catalogue does not bring the category list back. This is
     * what actually made the menu slow, so it is asserted with stock in place
     * rather than against an empty database, where anything would pass.
     */
    public function test_a_stocked_catalogue_adds_no_category_links_to_the_sheet(): void
    {
        $this->stock('Steam');

        $sheet = $this->sheet($this->get('/')->assertOk()->getContent());

        $this->assertStringNotContainsString('subcategory=gaming', $sheet);
        $this->assertStringNotContainsString('subcategory=popular', $sheet);
        $this->assertStringNotContainsString('sort=recent', $sheet);
        $this->assertStringNotContainsString('subcategory=credit', $sheet);
        $this->assertStringNotContainsString('subcategory=bundles', $sheet);

        // Nor the brand deep-links the popular column carried.
        $this->assertStringNotContainsString(
            route('shop.brand', ['brandSlug' => Product::brandSlug('Steam')]),
            $sheet
        );
    }

    /**
     * The desktop hover panel keeps the catalogue. Only the phone sheet was
     * flattened, and the two render from different components, so this is what
     * proves the change did not reach across.
     */
    public function test_the_desktop_panel_still_carries_the_catalogue(): void
    {
        $this->stock('Steam');

        $html = $this->get('/')->assertOk()->getContent();
        $sheet = $this->sheet($html);

        // Present on the page, absent from the sheet.
        $this->assertStringContainsString('subcategory=gaming', $html);
        $this->assertStringNotContainsString('subcategory=gaming', $sheet);
    }

    /** The sheet foot still carries the footer-style theme control. */
    public function test_the_sheet_foot_carries_the_segmented_theme_control(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $start = strpos($html, 'mt-auto flex flex-col gap-1 pt-6');
        $this->assertNotFalse($start);
        $foot = substr($html, $start, strpos($html, 'open-mobile-menu.window', $start) - $start);

        // The footer arrangement: the Theme label beside the segmented chip
        // (the pill with the locale chip's ring), not the dropdown trigger it
        // replaced. The label reads "Theme" as written, not shouted in caps.
        $this->assertStringContainsString(__('footer.theme'), $foot);
        $this->assertStringContainsString('rounded-full p-0.5 ring-1 ring-zinc-200', $foot);
        $this->assertStringNotContainsString('uppercase tracking-wider text-zinc-500', $foot);
    }
}
