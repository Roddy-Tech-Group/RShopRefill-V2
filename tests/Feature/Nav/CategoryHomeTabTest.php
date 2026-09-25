<?php

namespace Tests\Feature\Nav;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The category row opens with Home. The tabs narrow the catalogue, and the
 * first step of that is the whole of it, so the way back is in the same row
 * rather than only behind the logo.
 */
class CategoryHomeTabTest extends TestCase
{
    use RefreshDatabase;

    /** The category row, from its landmark to the end of its links. */
    private function row(string $url = '/gift-cards'): string
    {
        $html = $this->withoutVite()->get($url)->assertOk()->getContent();

        $start = strpos($html, 'aria-label="Product categories"');
        $this->assertNotFalse($start, 'The category row did not render.');

        return substr($html, $start, 8000);
    }

    public function test_home_leads_the_row(): void
    {
        $row = $this->row();

        $this->assertMatchesRegularExpression('/>\s*Home\s*<\/a>/', $row, 'Home is not in the category row.');

        $home = strpos($row, 'href="'.e(route('home')).'"');
        $this->assertNotFalse($home, 'Home is not a link in the category row.');
        $this->assertLessThan(strpos($row, 'Gift Cards'), $home);
        $this->assertStringContainsString('href="'.e(route('home')).'"', $row);
    }

    public function test_home_is_filled_on_the_home_page_and_not_elsewhere(): void
    {
        // The page tone, as on a phone, where the tabs sit on the bar itself.
        // Inside the desktop rail it lifts to white (NavGlassRailTest), but
        // that is an md-scoped override of this same fill.
        $filled = 'bg-[#f4f6f5] px-3.5 font-bold';

        // On the home page it wears the open-page fill and says so.
        $home = $this->row('/');
        $this->assertMatchesRegularExpression('/href="'.preg_quote(e(route('home')), '/').'"[^>]*'.preg_quote($filled, '/').'/', $home);
        $this->assertMatchesRegularExpression('/href="'.preg_quote(e(route('home')), '/').'"[^>]*aria-current="page"/s', $home);

        // On a category page it does not.
        $giftCards = $this->row();
        $this->assertDoesNotMatchRegularExpression('/href="'.preg_quote(e(route('home')), '/').'"[^>]*'.preg_quote($filled, '/').'/', $giftCards);
    }

    /** Its label is the one the breadcrumb uses, so both say Home in any language. */
    public function test_the_label_is_translated(): void
    {
        $french = $this->withoutVite()->withSession(['locale' => 'fr'])->get('/gift-cards')->assertOk()->getContent();

        // The row's own label is translated too, so it is found by its French name.
        $start = strpos($french, 'aria-label="'.e(__('navigation.product_categories', [], 'fr')).'"');
        $this->assertNotFalse($start, 'the category row was not found');
        $row = substr($french, $start, 8000);

        $this->assertStringContainsString(e(__('products.detail_crumb_home', [], 'fr')), $row);
    }
}
