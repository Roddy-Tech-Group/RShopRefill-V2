<?php

namespace Tests\Feature\Shop;

use App\Models\SiteSetting;
use App\Models\User;
use App\Support\CatalogFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The customers headline on the public pages can be set from the admin, and
 * counts real accounts when nothing is set.
 *
 * The counted figure deliberately leaves out guest shadow accounts from the
 * retired guest-checkout flow: the label says registered customers, and a
 * shadow account is precisely someone who never registered. That is why the
 * admin's all-rows total reads higher, and why an admin may want to publish a
 * number of their own instead of explaining the difference.
 */
class CustomerCountOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function customers(): int
    {
        Cache::forget('home:counts:v2');

        return CatalogFigures::headlineCounts()['customers'];
    }

    private function override(string $value): void
    {
        SiteSetting::put(CatalogFigures::CUSTOMERS_OVERRIDE_KEY, $value, 'trust');
    }

    public function test_with_nothing_set_the_figure_is_the_real_count(): void
    {
        User::factory()->count(4)->create();
        $this->override('');

        $this->assertSame(4, $this->customers());
        $this->assertNull(CatalogFigures::customersOverride());
    }

    public function test_a_number_in_the_setting_is_published_instead(): void
    {
        User::factory()->count(4)->create();
        $this->override('483');

        $this->assertSame(483, $this->customers());
        $this->assertSame(483, CatalogFigures::customersOverride());
    }

    /** Clearing the box hands the figure back to the database. */
    public function test_clearing_the_setting_returns_to_counting(): void
    {
        User::factory()->count(4)->create();

        $this->override('9000');
        $this->assertSame(9000, $this->customers());

        $this->override('   ');
        $this->assertSame(4, $this->customers());
    }

    /**
     * A typo in a settings box must not wipe the figure off the public pages,
     * so anything that is not a number is treated as no answer at all.
     */
    public function test_a_value_that_is_not_a_number_is_ignored(): void
    {
        User::factory()->count(4)->create();

        foreach (['many', 'lots!', '12abc', '-50'] as $nonsense) {
            $this->override($nonsense);

            $this->assertSame(4, $this->customers(), "[{$nonsense}] should have been ignored");
        }
    }

    /** Zero is a real answer, and the pages hide the figure rather than print it. */
    public function test_zero_is_honoured(): void
    {
        User::factory()->count(4)->create();
        $this->override('0');

        $this->assertSame(0, $this->customers());
    }

    /**
     * Saving takes effect at once. The counted figures are cached for fifteen
     * minutes, and an admin who types a number is about to go and look at the
     * page, so the override is read outside that cache.
     */
    public function test_a_change_shows_without_waiting_for_the_cache_to_lapse(): void
    {
        User::factory()->count(4)->create();
        $this->override('');

        // Warm the cache with the counted figure, then change the setting only.
        $this->assertSame(4, CatalogFigures::headlineCounts()['customers']);
        $this->override('777');

        $this->assertSame(777, CatalogFigures::headlineCounts()['customers'], 'the override waited for the counts cache');
    }

    /** Guests are not registered customers, which is the gap the admin sees. */
    public function test_guest_shadow_accounts_are_not_counted(): void
    {
        User::factory()->count(4)->create();
        User::factory()->count(2)->create(['is_guest' => true]);
        $this->override('');

        $this->assertSame(4, $this->customers());
    }

    /** The home page prints whatever the figure resolves to. */
    public function test_the_home_page_shows_the_overridden_figure(): void
    {
        User::factory()->count(4)->create();
        $this->override('12345');
        Cache::forget('home:counts:v2');

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertTrue(str_contains($html, '12,345+'), 'the home page did not publish the override');
    }
}
