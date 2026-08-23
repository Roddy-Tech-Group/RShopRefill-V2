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
 * Second, the embedded-finance features - virtual cards, P2P transfers, fiat
 * withdrawals and FX - have been removed entirely, so their dashboard URLs must
 * stay unreachable (404); a typed URL must never reach a deleted feature.
 *
 * The generic feature gate must still fail CLOSED: FeatureFlag::on() defaults to
 * true for a missing settings row, so EnsureFeatureEnabled passes an explicit
 * false. A regression there would silently expose any gated feature, which is
 * the case worth pinning down (the demo_gate probes below).
 */
class FeatureGateRoutesTest extends TestCase
{
    use RefreshDatabase;

    /** Embedded-finance dashboard routes that were removed; their URLs must 404. */
    private const REMOVED_DASHBOARD_PATHS = [
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

    public function test_removed_embedded_finance_routes_are_not_found(): void
    {
        $user = User::factory()->create();

        foreach (self::REMOVED_DASHBOARD_PATHS as $path) {
            $this->actingAs($user)
                ->get($path)
                ->assertNotFound();
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
