<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use App\Support\FeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Flights and Stays were the only storefront categories with no feature flag:
 * categoryEnabled() fell through to "on" for them, so the homepage cards and
 * the admin sidebar links could not be switched off.
 *
 * Both surfaces now read the same flag, so "hidden in the sidebar" and
 * "hidden on the homepage" can never disagree.
 */
class FlightsAndStaysFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    private function setFlags(string $flights, string $stays): void
    {
        SiteSetting::put('features.flights_enabled', $flights, 'features');
        SiteSetting::put('features.stays_enabled', $stays, 'features');
    }

    private function admin(): Admin
    {
        return Admin::firstOrCreate(
            ['email' => 'flag-admin@example.test'],
            [
                'name' => 'Flag Admin',
                'password' => 'password',
                'role' => AdminRole::SuperAdmin,
                'is_active' => true,
            ],
        );
    }

    public function test_the_migration_seeds_both_flags_on(): void
    {
        // RefreshDatabase runs the migrations, so the rows must already exist
        // and preserve the currently-live homepage cards.
        $this->assertSame('on', SiteSetting::get('features.flights_enabled'));
        $this->assertSame('on', SiteSetting::get('features.stays_enabled'));
    }

    public function test_the_category_map_now_resolves_both_slugs(): void
    {
        $this->assertSame('flights', FeatureFlag::forCategory('flights'));
        $this->assertSame('stays', FeatureFlag::forCategory('stays'));
    }

    /**
     * Before this change categoryEnabled() returned true for these slugs no
     * matter what, because they had no flag to read.
     */
    public function test_category_enabled_follows_the_flag(): void
    {
        $this->setFlags('off', 'off');
        $this->assertFalse(FeatureFlag::categoryEnabled('flights'));
        $this->assertFalse(FeatureFlag::categoryEnabled('stays'));

        $this->setFlags('on', 'on');
        $this->assertTrue(FeatureFlag::categoryEnabled('flights'));
        $this->assertTrue(FeatureFlag::categoryEnabled('stays'));
    }

    public function test_homepage_shows_both_cards_when_the_flags_are_on(): void
    {
        $this->setFlags('on', 'on');

        $this->get('/')
            ->assertOk()
            ->assertSee(e(__('home.teaser_flights_and_stays')), escape: false)
            ->assertSee('Travel smarter with secure bookings', escape: false)
            ->assertSee('Turn digital currency into real-world travel', escape: false);
    }

    public function test_homepage_hides_a_card_when_its_flag_is_off(): void
    {
        $this->setFlags('off', 'on');

        $response = $this->get('/')->assertOk();

        $response->assertDontSee('Travel smarter with secure bookings', escape: false);
        $response->assertSee('Turn digital currency into real-world travel', escape: false);
        // Heading and column count follow what is actually rendered: the lone
        // teaser takes the row's own heading and a single column. The grid is
        // matched in full because other rows on the page use sm:grid-cols-2.
        $response->assertSee('>'.e(__('navigation.stays')).'</h2>', escape: false);
        $response->assertSee('grid grid-cols-1 gap-5 sm:grid-cols-1', escape: false);
        $response->assertDontSee('grid grid-cols-1 gap-5 sm:grid-cols-2', escape: false);
    }

    public function test_homepage_drops_the_teaser_row_when_both_are_off(): void
    {
        $this->setFlags('off', 'off');
        $this->makeGlobalEsim();

        $response = $this->get('/')->assertOk();

        $response->assertDontSee('Travel smarter with secure bookings', escape: false);
        $response->assertDontSee('Turn digital currency into real-world travel', escape: false);
        // No teaser heading and no teaser grid at all, not an empty
        // single-column grid: the row has nothing to head or to lay out.
        foreach (['home.teaser_flights_and_stays', 'navigation.flights', 'navigation.stays'] as $key) {
            $response->assertDontSee('>'.e(__($key)).'</h2>', escape: false);
        }
        $response->assertDontSee('grid grid-cols-1 gap-5 sm:grid-cols-', escape: false);
        // The eSIM row is its own thing and stays: its heading, its carousel
        // track and the family card the catalogue gave it. Only the teasers
        // are gated.
        $response->assertSee(e(__('home.esim_row_heading', ['count' => 190])), escape: false);
        $response->assertSee('grid w-max grid-flow-col grid-rows-[repeat(3,auto)]', escape: false);
        $response->assertSee('esim-ww-discover-global', escape: false);
    }

    /**
     * One global eSIM in the catalogue, which the home row's presenter turns
     * into its Global card. The catalogue summary and the card family are both
     * cached for an hour, so the cache is cleared after seeding.
     */
    private function makeGlobalEsim(): Product
    {
        $category = Category::create(['name' => 'eSIMs', 'slug' => 'esims', 'type' => 'digital']);

        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'airalo',
            'country_code' => 'WW',
            'currency_code' => 'USD',
            'name' => 'Global Data eSIM',
            'slug' => 'esim-ww-discover-global',
            'is_active' => true,
        ]);

        ProductVariant::factory()->for($product)->create([
            'currency' => 'USD',
            'face_value' => 5,
            'retail_price' => 5,
            'is_available' => true,
            'is_variable' => false,
            'metadata' => ['data_limit' => '1 GB', 'duration_days' => 7],
        ]);

        Cache::flush();

        return $product;
    }

    public function test_the_admin_sidebar_links_to_the_catalogue_once_whatever_the_flags_say(): void
    {
        // The sidebar used to expand a "Products" group with a sub-link per
        // category, and Flights and Stays came and went with their flags. It
        // is one Catalogue link now: the catalogue page carries its own
        // category picker, so the group only repeated it one level up. What
        // still follows the flags on the admin side is the command bar, which
        // the next test covers.
        //
        // Any admin page renders the shared layout that holds the sidebar.
        // Deliberately NOT the products page: it filters variants with a raw
        // JSON_UNQUOTE, which SQLite has no function for.
        foreach ([['off', 'off'], ['on', 'on']] as [$flights, $stays]) {
            $this->setFlags($flights, $stays);

            $html = $this->actingAs($this->admin(), 'admin')
                ->get(route('admin.customers'))
                ->assertOk()
                ->getContent();

            $this->assertTrue(str_contains($html, 'href="'.route('admin.products').'" wire:navigate data-tip="Catalogue"'), 'the one Catalogue link is missing');
            foreach (['flights', 'stays', 'gift-cards', 'esims'] as $slug) {
                $this->assertFalse(str_contains($html, route('admin.products', ['category' => $slug])), "the sidebar still links to the {$slug} category");
            }
        }
    }

    /**
     * A page hidden from the sidebar must not stay reachable through the
     * command bar instead.
     */
    public function test_the_admin_command_bar_hides_the_pages_when_off(): void
    {
        $this->setFlags('off', 'off');

        $rows = $this->actingAs($this->admin(), 'admin')
            ->getJson(route('admin.search-suggest', ['q' => 'flights']))
            ->assertOk()
            ->json();

        $this->assertEmpty(
            collect($rows)->where('type', 'page')->filter(
                fn (array $row) => str_contains($row['title'], 'Flights')
            )
        );

        $this->setFlags('on', 'on');

        $rows = $this->actingAs($this->admin(), 'admin')
            ->getJson(route('admin.search-suggest', ['q' => 'flights']))
            ->assertOk()
            ->json();

        $this->assertNotEmpty(
            collect($rows)->where('type', 'page')->filter(
                fn (array $row) => str_contains($row['title'], 'Flights')
            )
        );
    }
}
