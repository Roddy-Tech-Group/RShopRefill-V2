<?php

namespace Tests\Feature\Shop;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A category a country has nothing in is not a room with no door.
 *
 * A region with no billers, no networks or no eSIMs is a dead end the visitor
 * did not walk into by choice: there are no filters to clear and nothing to
 * search for. Bills, top-ups and eSIMs said "coming soon" and left it there.
 * Gift cards offered the country switcher and nothing else.
 *
 * Every one of them now offers the same pair: back to where the customer came
 * from, and the switcher for anyone whose country is simply set wrong.
 */
class EmptyCategoryHasAWayOutTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function listings(): array
    {
        return [
            'gift cards' => ['gift-cards'],
            'bills' => ['bills'],
            'top-ups' => ['topups'],
            'esims' => ['esims'],
        ];
    }

    #[DataProvider('listings')]
    public function test_every_listing_offers_a_way_out_of_an_empty_region(string $view): void
    {
        $source = File::get(resource_path('views/shop/'.$view.'.blade.php'));

        $this->assertStringContainsString(
            "@include('shop._empty-state-back'",
            $source,
            $view.' leaves an empty region with nowhere to go'
        );
    }

    /** With nothing in the catalogue at all, the page still shows the door. */
    public function test_an_empty_catalogue_renders_the_way_out(): void
    {
        foreach (['bills', 'topups'] as $view) {
            $html = $this->withoutVite()->get('/'.$view)->assertOk()->getContent();

            $this->assertTrue(
                str_contains($html, e(__('products.back_to_home'))),
                $view.' does not offer the way back'
            );
            $this->assertTrue(
                str_contains($html, e(__('products.switch_country'))),
                $view.' does not offer the country switcher'
            );
        }
    }

    /**
     * Back goes where the customer came from. Inside the dashboard that is the
     * overview; on the storefront there is no overview, so it is home.
     */
    public function test_back_leads_to_the_overview_in_the_dashboard_and_home_outside_it(): void
    {
        $storefront = $this->withoutVite()->get('/bills')->assertOk()->getContent();
        $this->assertTrue(str_contains($storefront, 'href="'.route('home').'"'), 'the storefront should go home');
        $this->assertTrue(str_contains($storefront, e(__('products.back_to_home'))));

        $dashboard = $this->withoutVite()
            ->actingAs(User::factory()->create())
            ->get(route('dashboard.shop.bills'))
            ->assertOk()
            ->getContent();

        $this->assertTrue(str_contains($dashboard, 'href="'.route('dashboard').'"'), 'the dashboard should go to the overview');
        $this->assertTrue(str_contains($dashboard, e(__('products.back_to_overview'))));
        $this->assertFalse(str_contains($dashboard, e(__('products.back_to_home'))), 'the dashboard should not send them to the storefront');
    }

    /**
     * Gift cards already drew its own switcher, so it takes the back button
     * alone rather than a second one beside it.
     */
    public function test_gift_cards_does_not_render_two_switchers(): void
    {
        $source = File::get(resource_path('views/shop/gift-cards.blade.php'));

        $this->assertStringContainsString("@include('shop._empty-state-back', ['switcher' => false])", $source);
        $this->assertSame(1, substr_count($source, "__('products.switch_country')"), 'gift cards draws the switcher twice');
    }

    /** The copy exists in every language the storefront speaks. */
    public function test_the_wording_is_translated_everywhere(): void
    {
        foreach (array_map('basename', File::directories(lang_path())) as $locale) {
            $strings = require lang_path($locale.'/products.php');

            foreach (['back_to_overview', 'back_to_home', 'switch_country'] as $key) {
                $this->assertArrayHasKey($key, $strings, $locale.' is missing '.$key);
                $this->assertNotSame('', trim((string) $strings[$key]), $locale.' has an empty '.$key);
            }
        }
    }
}
