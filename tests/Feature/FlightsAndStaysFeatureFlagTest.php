<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use App\Models\SiteSetting;
use App\Support\FeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('eSIMs, flights &amp; stays', escape: false)
            ->assertSee('Travel smarter with secure bookings', escape: false)
            ->assertSee('Turn digital currency into real-world travel', escape: false);
    }

    public function test_homepage_hides_a_card_when_its_flag_is_off(): void
    {
        $this->setFlags('off', 'on');

        $response = $this->get('/')->assertOk();

        $response->assertDontSee('Travel smarter with secure bookings', escape: false);
        $response->assertSee('Turn digital currency into real-world travel', escape: false);
        // Heading and column count follow what is actually rendered.
        $response->assertSee('eSIMs &amp; stays', escape: false);
        $response->assertSee('sm:grid-cols-2', escape: false);
    }

    public function test_homepage_drops_to_a_single_column_when_both_are_off(): void
    {
        $this->setFlags('off', 'off');

        $response = $this->get('/')->assertOk();

        $response->assertDontSee('Travel smarter with secure bookings', escape: false);
        $response->assertDontSee('Turn digital currency into real-world travel', escape: false);
        $response->assertSee('sm:grid-cols-1', escape: false);
        // The lone eSIM card is still there - only the teasers are gated.
        //
        // This used to prove that by looking for the card's caption, "Ditch
        // physical SIM cards...". The homepage redesign deliberately dropped
        // that caption from the eSIM tile: the dark backdrop-blur strip it sat
        // on was built to sit over the Flights and Stays photographs, and on
        // the eSIM tile it covered the bottom third of an animated inline-SVG
        // scene instead. Flights and Stays keep their captions, because they
        // are coming-soon teasers whose copy is the whole point, while the eSIM
        // tile is a plain link into the eSIM shop and takes its name from the
        // section heading above it.
        //
        // So the proof moved to the two things the tile cannot render without:
        // the illustration that fills it, and the full-bleed link into /esims.
        // Neither string appears anywhere else on the homepage, so neither can
        // be satisfied by some other mention of eSIMs on the page.
        $response->assertSee('aria-label="Three people using their phones with eSIM connectivity"', escape: false);
        $response->assertSee('aria-label="Browse eSIMs"', escape: false);
    }

    public function test_admin_sidebar_links_follow_the_flags(): void
    {
        // Any admin page renders the shared layout that holds the sidebar.
        // Deliberately NOT the products page: it filters variants with a raw
        // JSON_UNQUOTE, which SQLite has no function for.
        $this->setFlags('off', 'off');

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.customers'))
            ->assertOk()
            ->assertDontSee(route('admin.products', ['category' => 'flights']))
            ->assertDontSee(route('admin.products', ['category' => 'stays']));

        $this->setFlags('on', 'on');

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.customers'))
            ->assertOk()
            ->assertSee(route('admin.products', ['category' => 'flights']))
            ->assertSee(route('admin.products', ['category' => 'stays']));
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
