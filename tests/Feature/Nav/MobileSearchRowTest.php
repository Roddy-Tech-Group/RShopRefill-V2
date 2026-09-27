<?php

namespace Tests\Feature\Nav;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * On a phone, search is a field on its own row under the logo - edge to edge -
 * not an icon that opens a sheet over the page.
 *
 * It was two taps and a full-screen layer to reach the one control people
 * arrive wanting. The field is now the first thing under the logo row, and its
 * results drop inline beneath it.
 *
 * Source-reading only, apart from the rendered check at the end.
 */
class MobileSearchRowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Category::factory()->create(['slug' => 'gift-cards', 'name' => 'Gift Cards']);
    }

    private function nav(): string
    {
        return file_get_contents(resource_path('views/components/nav/main-nav.blade.php'));
    }

    public function test_the_phone_search_is_a_field_on_its_own_row(): void
    {
        $nav = $this->nav();

        // Its own md:hidden block, driven by the same factory as the desktop
        // field so debouncing and the endpoint stay in one place.
        $this->assertStringContainsString('class="relative pb-3 md:hidden"', $nav);
        $this->assertSame(2, substr_count($nav, 'x-data="navBrandSearch()"'));
        $this->assertStringContainsString('x-ref="mobileSearch"', $nav);
    }

    public function test_the_search_sheet_is_gone(): void
    {
        $nav = $this->nav();

        // The trigger, its backdrop and the fixed sheet all went with it.
        $this->assertStringNotContainsString('mobileSearchOpen', $nav);
        $this->assertStringNotContainsString('x-ref="mobileSearchInput"', $nav);
    }

    /**
     * The row sits below the logo line, so it has to be rendered after the
     * actions cluster that closes that line.
     */
    public function test_the_row_comes_after_the_logo_line(): void
    {
        $nav = $this->nav();

        $this->assertLessThan(
            strpos($nav, 'x-ref="mobileSearch"'),
            strpos($nav, '<x-nav.account-cart />'),
        );
    }

    /**
     * The row stays on scroll. It used to fold with the category strip behind
     * a max-height cap; now only the strip folds (NavFoldTest), and it does so
     * as a grid row rather than under a cap, so no cap exists anywhere in the
     * nav for this row to clear.
     */
    public function test_the_row_does_not_fold_on_scroll(): void
    {
        $nav = $this->nav();

        // The phone row's own element carries no scroll-driven class binding.
        preg_match('/<div\s+x-data="navBrandSearch\(\)"[^>]*md:hidden"/s', $nav, $row);
        $this->assertNotEmpty($row, 'The phone search row did not render.');
        $this->assertStringNotContainsString(':class="hidden', $row[0]);
        $this->assertStringNotContainsString('max-h-', $row[0]);

        $this->assertStringNotContainsString('max-h-[150px]', $nav);
        $this->assertStringNotContainsString('max-h-[120px]', $nav);
    }

    public function test_both_search_fields_render_on_the_storefront(): void
    {
        $html = $this->get(route('shop.gift-cards'))->assertOk()->getContent();

        // One for the logo line at md+, one for the phone row. Both post to the
        // same results page.
        $this->assertSame(2, substr_count($html, 'name="q"'));
    }
}
