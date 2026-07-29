<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Unreleased surface must not be reachable.
 *
 * Two things are covered here. First, the throwaway /debug-login-browser route
 * (which logged any visitor in as User::first()) is gone and must stay gone.
 * Second, the financial features still under construction - virtual cards, P2P
 * transfers, fiat withdrawals and FX - are hidden in the dashboard nav behind
 * ef_* flags, and the routes themselves now carry the same gate so a typed URL
 * cannot reach a half-built page.
 *
 * The gate has to fail CLOSED: FeatureFlag::on() defaults to true for a missing
 * settings row, so EnsureFeatureEnabled passes an explicit false. A regression
 * there would silently expose every unfinished feature at once, which is the
 * case worth pinning down.
 */
class FeatureGateRoutesTest extends TestCase
{
    use RefreshDatabase;

    /** Dashboard routes gated while their backing services are unfinished. */
    private const GATED_DASHBOARD_PATHS = [
        '/dashboard/virtual-cards',
        '/dashboard/transfers',
        '/dashboard/withdrawals',
        '/dashboard/exchange',
    ];

    public function test_debug_login_route_is_removed_and_authenticates_nobody(): void
    {
        User::factory()->create();

        $this->get('/debug-login-browser')->assertNotFound();

        $this->assertGuest();
    }

    public function test_unfinished_dashboard_routes_are_closed_by_default(): void
    {
        $user = User::factory()->create();

        foreach (self::GATED_DASHBOARD_PATHS as $path) {
            $this->actingAs($user)
                ->get($path)
                ->assertNotFound("{$path} should be gated off while the feature is unfinished");
        }
    }

    public function test_gate_fails_closed_when_the_setting_row_is_missing(): void
    {
        Route::middleware('feature:demo_gate')->get('/__gate-probe', fn () => response('reached'));

        $this->get('/__gate-probe')->assertNotFound();
    }

    public function test_gate_opens_once_an_admin_turns_the_flag_on(): void
    {
        Route::middleware('feature:demo_gate')->get('/__gate-probe', fn () => response('reached'));

        SiteSetting::put('features.demo_gate_enabled', 'on');

        $this->get('/__gate-probe')
            ->assertOk()
            ->assertSee('reached');
    }
}
