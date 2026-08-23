<?php

namespace Tests\Feature\Dashboard;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dashboard Quick Actions grids are sized for exactly eight tiles (2x4 on
 * mobile, one row of 8 on desktop). Hiding Flights and Stays used to leave a
 * visible two-tile hole, so the grid backfills to eight: Fund Wallet takes
 * the Flights slot, and an Electronics subcategory tile slots in between
 * Gaming and More when Stays is hidden.
 */
class QuickActionBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The overview is a #[Lazy] component: a plain GET renders only its
        // skeleton, so the quick-actions grid never reaches the HTML (the
        // Flights/Stays strings the on-test used to match came from the
        // sidebar nav). Render lazily-loaded components eagerly so these
        // tests exercise the real grid.
        Livewire::withoutLazyLoading();
    }

    private function setFlags(string $flights, string $stays): void
    {
        SiteSetting::put('features.flights_enabled', $flights, 'features');
        SiteSetting::put('features.stays_enabled', $stays, 'features');
    }

    public function test_hidden_travel_tiles_are_backfilled_with_wallet_and_electronics(): void
    {
        $this->setFlags('off', 'off');

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            // Fund Wallet holds the Flights slot; Electronics sits between
            // Gaming and More.
            ->assertSeeInOrder(['eSIMs', 'Fund Wallet', 'Topups', 'Bills', 'Gaming', 'Electronics', 'More'])
            ->assertDontSee(route('dashboard.shop.flights'))
            ->assertDontSee(route('dashboard.shop.stays'));
    }

    public function test_travel_tiles_render_normally_while_their_flags_are_on(): void
    {
        $this->setFlags('on', 'on');

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['eSIMs', 'Flights', 'Stays', 'Topups'])
            ->assertDontSee('Fund Wallet')
            ->assertDontSee('Electronics');
    }

    public function test_a_single_hidden_tile_gets_a_single_backfill(): void
    {
        $this->setFlags('off', 'on');

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['eSIMs', 'Fund Wallet', 'Stays', 'Topups'])
            ->assertDontSee(route('dashboard.shop.flights'))
            // Electronics only backfills for Stays, not Flights.
            ->assertDontSee('Electronics');
    }
}
