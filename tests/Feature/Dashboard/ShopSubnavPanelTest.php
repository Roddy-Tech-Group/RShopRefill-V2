<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dashboard shop pages with a category panel (gift cards, top-ups, bills):
 * the panel moves out of the page into a slot beside the dashboard rail, the
 * rail folds to icons, and the two read as one sidebar. Shop pages also stop
 * painting their own page slab, so every dashboard page shares one ground.
 */
class ShopSubnavPanelTest extends TestCase
{
    use RefreshDatabase;

    private function file(string $path): string
    {
        return file_get_contents(resource_path($path));
    }

    public function test_the_dashboard_gift_cards_page_teleports_its_panel_beside_the_rail(): void
    {
        $html = $this->withoutVite()
            ->actingAs(User::factory()->create())
            ->get(route('dashboard.shop.gift-cards'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<div data-dash-subnav class="hidden lg:block"></div>', $html);
        $this->assertStringContainsString('<template x-teleport="[data-dash-subnav]" data-subnav-teleport>', $html);
        $this->assertStringContainsString('dash-subnav-panel', $html);
        // The storefront's sticky boxed panel is not what renders in the dashboard.
        $this->assertStringNotContainsString('max-h-[calc(100vh-180px)] overflow-y-auto rounded-[14px] bg-[#eff6ff]', $html);
    }

    public function test_the_storefront_keeps_its_own_boxed_panel(): void
    {
        $html = $this->withoutVite()->get(route('shop.gift-cards'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-subnav-teleport', $html);
        $this->assertStringContainsString('max-h-[calc(100vh-180px)] overflow-y-auto rounded-[14px] bg-[#eff6ff]', $html);
    }

    public function test_the_layout_has_a_subnav_column_that_opens_and_slides_in(): void
    {
        $layout = $this->file('views/components/layouts/dashboard.blade.php');

        $this->assertStringContainsString('grid-template-areas: "header header header header" "sidebar subnav main aside" "sidebar subnav footer aside" !important;', $layout);
        $this->assertMatchesRegularExpression('/body\.dashboard > \[data-dash-subnav\]:has\(> \*\) \{[^}]*width: 248px;[^}]*background-color: #eaeeec;/', $layout);
        $this->assertStringContainsString('transition: width 280ms', $layout);
        $this->assertStringContainsString('animation: dash-subnav-in 320ms', $layout);
        $this->assertStringContainsString('.grid:has(> template[data-subnav-teleport])', $layout);
    }

    public function test_the_rail_folds_on_those_pages_without_touching_the_saved_preference(): void
    {
        $app = file_get_contents(resource_path('js/app.js'));
        $layout = $this->file('views/components/layouts/dashboard.blade.php');

        $this->assertStringContainsString('folded: false,', $app);
        $this->assertStringContainsString("return !! document.querySelector('template[data-subnav-teleport]');", $app);
        $this->assertStringContainsString("classList.toggle('dashboard-sidebar-collapsed', this.isCollapsed)", $app);
        $this->assertStringContainsString("document.addEventListener('livewire:navigated'", $app);

        // Folded before first paint on the pages that carry the panel.
        $this->assertStringContainsString("request()->routeIs('dashboard.shop.gift-cards', 'dashboard.shop.topups', 'dashboard.shop.bills')", $layout);
        $this->assertStringContainsString('$store.dashboardSidebar.isCollapsed ? \'rotate-180\' : \'rotate-0\'', $layout);
    }

    public function test_shop_page_slabs_step_aside_for_the_dashboard_ground(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(
            'html body.dashboard [data-page-content] > div > :is(section, div).min-h-full:is(.bg-\[\#eff6ff\], .bg-zinc-100) {',
            $css
        );
    }

    /**
     * The account menu's Language row opens the preferences modal on its root
     * pane, where language, currency and country sit together, rather than
     * deep-linking to the language list alone. The modal keeps accepting a
     * pane for the callers that do want one screen.
     */
    public function test_the_account_menu_language_row_opens_the_preferences_modal(): void
    {
        $html = $this->withoutVite()->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->getContent();
        $modal = $this->file('views/components/nav/locale-modal.blade.php');

        $row = strpos($html, '>Language<');
        $this->assertNotFalse($row, 'the Language row is missing');
        $rowMarkup = substr($html, strrpos(substr($html, 0, $row), '<button'), $row - strrpos(substr($html, 0, $row), '<button'));
        $this->assertStringContainsString("\$dispatch('open-locale-modal')\"", $rowMarkup);
        $this->assertStringNotContainsString("pane: 'language'", $rowMarkup);
        $this->assertStringContainsString("['country', 'language', 'currency'].includes(\$event.detail.pane)", $modal);
    }
}
