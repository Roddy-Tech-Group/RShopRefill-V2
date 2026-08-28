<?php

namespace Tests\Feature\Nav;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WalletNotInStorefrontTest extends TestCase
{
    use RefreshDatabase;

    /** Wallet is a dashboard feature; the storefront nav must not carry it. */
    public function test_the_storefront_nav_has_no_wallet(): void
    {
        $nav = File::get(resource_path('views/components/nav/main-nav.blade.php'));
        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $nav);

        $this->assertStringNotContainsString('navWalletSummary', $markup);
        $this->assertStringNotContainsString('compactUsd', $markup);
        $this->assertStringNotContainsString('dashboard.wallet', $markup);
    }

    public function test_a_signed_in_shopper_sees_no_balance_on_the_storefront(): void
    {
        // The guard that matters: the block was inside @auth, so only a signed-in
        // request would ever have rendered it.
        $this->actingAs(User::factory()->create())
            ->withoutVite()
            ->get('/')
            ->assertOk()
            ->assertDontSee('Wallet balance in USD', false)
            ->assertDontSee('Total wallet balance in USD', false);
    }

    public function test_the_dashboard_still_offers_it(): void
    {
        // Removing it from the storefront must not remove the feature.
        $dashboard = File::get(resource_path('views/components/layouts/dashboard.blade.php'));

        $this->assertStringContainsString("route('dashboard.wallet')", $dashboard);
    }
}
