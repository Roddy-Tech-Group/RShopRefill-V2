<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DashboardShopFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_mirrors_the_transactional_flow_routes(): void
    {
        $this->assertTrue(Route::has('dashboard.shop.cart'));
        $this->assertTrue(Route::has('dashboard.shop.checkout'));
        $this->assertTrue(Route::has('dashboard.shop.checkout.process'));
        $this->assertTrue(Route::has('dashboard.shop.checkout.return'));
        $this->assertTrue(Route::has('dashboard.shop.order'));
    }

    public function test_checkout_carries_the_esim_coverage_notice(): void
    {
        $this->withoutVite();

        // The eSIM coverage notice ships in the checkout markup (Alpine shows it
        // only when the cart holds an eSIM) - the blind-buy guard. The gift-card
        // region-locked notice was intentionally removed, so it must NOT appear.
        $response = $this->actingAs(User::factory()->create())
            ->get('/checkout')
            ->assertOk();

        $response->assertSee('eSIMs are region-based and only work inside their coverage area', false);
        $response->assertDontSee('Gift cards are region-locked', false);
        $response->assertSee("i.category_slug === 'esims'", false);
    }

    public function test_dashboard_cart_keeps_its_links_inside_the_dashboard(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::factory()->create())
            ->get('/dashboard/shop/cart')
            ->assertOk();

        // Checkout button and continue-shopping link stay under /dashboard/shop/*.
        $response->assertSee('/dashboard/shop/checkout', false);
        $response->assertSee('/dashboard/shop/gift-cards', false);
    }

    public function test_dashboard_checkout_keeps_its_links_inside_the_dashboard(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::factory()->create())
            ->get('/dashboard/shop/checkout')
            ->assertOk();

        // The POST endpoint and continue-shopping link stay in the dashboard.
        $response->assertSee('/dashboard/shop/checkout', false);
        $response->assertSee('/dashboard/shop/gift-cards', false);
    }

    public function test_storefront_cart_is_unchanged_and_links_stay_on_the_storefront(): void
    {
        $this->withoutVite();

        $response = $this->get('/cart')->assertOk();

        // Storefront context must not leak dashboard URLs into its links.
        $response->assertSee(route('shop.checkout'), false);
        $response->assertDontSee('/dashboard/shop/checkout', false);
    }

    public function test_dashboard_shop_pages_require_authentication(): void
    {
        $this->get('/dashboard/shop/cart')->assertRedirect(route('login'));
        $this->get('/dashboard/shop/checkout')->assertRedirect(route('login'));
    }
}
