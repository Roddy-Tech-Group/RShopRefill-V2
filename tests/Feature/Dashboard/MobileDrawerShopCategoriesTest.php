<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The phone drawer's Shop row. Tapping it drops the shop categories down
 * beneath it, the same list the desktop sidebar shows, in the same order and
 * under the same feature gating. On a shop page the list starts open with
 * the current category on the tile, and the logo above it is 40px tall.
 */
class MobileDrawerShopCategoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Livewire::withoutLazyLoading();
    }

    private function drawer(string $url): string
    {
        $html = $this->withoutVite()
            ->actingAs(User::factory()->create())
            ->get($url)
            ->assertOk()
            ->getContent();

        $aside = substr($html, strpos($html, 'class="dash-drawer'));

        return substr($aside, 0, strpos($aside, '</aside>'));
    }

    public function test_tapping_shop_drops_the_categories_down(): void
    {
        $aside = $this->drawer('/dashboard');

        // A button, not a link: the row itself opens the list, and says so.
        $this->assertMatchesRegularExpression(
            '/<button\s+type="button"\s+@click="shopOpen = ! shopOpen"\s+:aria-expanded="shopOpen\.toString\(\)"\s+aria-controls="dash-drawer-shop-categories"/s',
            $aside
        );
        $this->assertStringContainsString('x-data="{ shopOpen: false }"', $aside, 'the list starts closed away from the shop');
        $this->assertStringContainsString('<div id="dash-drawer-shop-categories" x-show="shopOpen" x-collapse', $aside);

        // The desktop sidebar's categories, in its order, each a working link.
        $expected = [
            'Gift Cards' => route('dashboard.shop.gift-cards'),
            'eSIMs' => route('dashboard.shop.esims'),
            'Mobile Top-Ups' => route('dashboard.shop.topups'),
            'Bill Payments' => route('dashboard.shop.bills'),
            'Gaming' => route('dashboard.shop.gift-cards', ['subcategory' => 'gaming-entertainment']),
        ];

        $list = substr($aside, strpos($aside, 'id="dash-drawer-shop-categories"'));

        $last = -1;
        foreach ($expected as $label => $href) {
            $at = strpos($list, '<span class="truncate">'.$label.'</span>');
            $this->assertNotFalse($at, $label.' is missing from the Shop list.');
            $this->assertGreaterThan($last, $at, $label.' is out of order.');
            $this->assertStringContainsString('href="'.$href.'"', $list, $label.' does not link where the desktop sidebar does.');
            $last = $at;
        }

        $this->assertSame(0, substr_count($aside, 'dash-drawer-subrow-active'), 'no category is current on the overview');
    }

    public function test_on_a_shop_page_the_list_starts_open_with_the_current_category_tiled(): void
    {
        $aside = $this->drawer(route('dashboard.shop.gift-cards'));

        $this->assertStringContainsString('x-data="{ shopOpen: true }"', $aside);
        $this->assertSame(1, substr_count($aside, 'dash-drawer-subrow-active'), 'exactly one category is current');
        $this->assertMatchesRegularExpression(
            '/dash-drawer-subrow-active"\s+aria-current="page"\s*>\s*<span class="truncate">Gift Cards<\/span>/s',
            $aside
        );
    }

    public function test_the_category_rows_have_their_own_drawer_colours(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('body.dashboard .dash-drawer-subrow { color: rgb(255 255 255 / .7); }', $css);
        $this->assertStringContainsString('body.dashboard .dash-drawer-subrow:hover { background-color: rgb(255 255 255 / .06); color: #fff; }', $css);
        $this->assertStringContainsString('body.dashboard .dash-drawer-subrow-active { background-color: rgb(255 255 255 / .12); color: #fff; }', $css);
    }

    public function test_the_drawer_logo_is_forty_pixels_tall(): void
    {
        $this->assertStringContainsString(
            'class="site-logo h-10 w-auto object-contain"',
            $this->drawer('/dashboard')
        );
    }
}
