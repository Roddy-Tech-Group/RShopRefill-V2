<?php

namespace Tests\Feature\Shop;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The catalog sidebar.
 *
 * Gift cards drops the list of OTHER storefronts: the nav's category rail
 * already carries it on every page, so in the sidebar it was a second copy
 * pushing the subcategory filters - the reason the sidebar exists - down the
 * panel. The other storefronts still show it.
 *
 * Source-level: this class has no RefreshDatabase, so it must not hit routes -
 * the storefront queries categories.
 */
class CategorySidebarTest extends TestCase
{
    private function sidebar(): string
    {
        return File::get(resource_path('views/components/shop/category-sidebar.blade.php'));
    }

    private function page(string $name): string
    {
        $source = File::get(resource_path('views/shop/'.$name.'.blade.php'));

        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }

    public function test_the_category_list_is_opt_out_rather_than_deleted(): void
    {
        $sidebar = $this->sidebar();

        // Shared by bills and topups, so it stays available to them.
        $this->assertStringContainsString("'showCategories' => true,", $sidebar);
        $this->assertStringContainsString('@if ($showCategories)', $sidebar);
    }

    public function test_gift_cards_hides_the_other_categories(): void
    {
        $this->assertStringContainsString(
            ':show-categories="false"',
            $this->page('gift-cards')
        );
    }

    public function test_the_other_storefronts_keep_their_category_list(): void
    {
        foreach (['bills', 'topups'] as $page) {
            $this->assertStringNotContainsString(
                ':show-categories="false"',
                $this->page($page),
                $page.' should still list the categories.'
            );
        }
    }

    /**
     * With the category list off, Subcategories heads the panel - so it must
     * not carry the top margin that separated it from the list above.
     */
    public function test_the_subcategory_heading_drops_its_margin_when_it_leads(): void
    {
        $this->assertStringContainsString(
            "{{ \$showCategories ? 'mt-4 ' : '' }}px-3 pb-2 pt-1",
            $this->sidebar()
        );
    }

    /** The dashboard sub-navigation panel carries no "Subcategories" heading. */
    public function test_the_dashboard_panel_has_no_subcategories_heading(): void
    {
        $sidebar = $this->sidebar();
        $heading = strpos($sidebar, "__('products.subcategories') }}</p>");

        $this->assertNotFalse($heading);
        $this->assertLessThan($heading, strpos($sidebar, '@unless ($inDashboard)'));
        $this->assertGreaterThan($heading, strpos($sidebar, '@endunless'));
    }

    /** Whatever else changes, the filters people came for stay. */
    public function test_the_subcategory_list_is_never_conditional_on_that_flag(): void
    {
        $sidebar = $this->sidebar();

        $subHeading = strpos($sidebar, "__('products.subcategories') }}</p>");
        $guard = strpos($sidebar, '@if ($showCategories)');

        $this->assertNotFalse($subHeading);
        $this->assertNotFalse($guard);

        // The guard closes before the subcategory block opens.
        $this->assertLessThan($subHeading, strpos($sidebar, '</nav>
        @endif'));
    }
}
