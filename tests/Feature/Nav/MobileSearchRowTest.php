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
     * The primary row collapses on scroll behind a max-height cap. Two rows on
     * a phone need more headroom than one; desktop still has a single row and
     * keeps the tighter cap, because a cap far above the content height leaves
     * the collapse looking like it stalls before it starts.
     */
    public function test_the_collapse_cap_clears_both_rows_on_a_phone(): void
    {
        $this->assertStringContainsString('max-h-[150px] md:max-h-[140px]', $this->nav());
    }

    public function test_both_search_fields_render_on_the_storefront(): void
    {
        $html = $this->get(route('shop.gift-cards'))->assertOk()->getContent();

        // One for the logo line at md+, one for the phone row. Both post to the
        // same results page.
        $this->assertSame(2, substr_count($html, 'name="q"'));
    }
}
