<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mobile chrome visibility, per the 2026-07-29 spec:
 *   - The floating bottom nav bar (its centre Menu button dispatches
 *     'open-mobile-menu') stays on EVERY dashboard page - it is the primary
 *     navigation and must always be reachable.
 *   - The glass nav band and the notification pill belong to the OVERVIEW alone.
 *   - The two shopping pills - cart and country switcher - ride on the overview
 *     and the shop pages, floating without a band on the latter.
 *   - Task/inner pages (orders, wallet, settings, ...) carry no top chrome at
 *     all; the bottom nav bar is their navigation.
 */
class MobileNavChromeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** Bottom nav bar's Menu FAB - present on every page. */
    private const NAV_BAR_MARKER = "\$dispatch('open-mobile-menu')";

    /** Country switcher trigger - a SHOPPING control, so overview + shop only. */
    private const TOP_CHROME_MARKER = "\$dispatch('open-locale-modal')";

    /** The notification bell - overview-only furniture, like the band. */
    private const BELL_MARKER = 'floating-notif-inner';

    /** The cart chip - the other shopping-only control. */
    private const CART_MARKER = 'aria-label="Cart"';

    /** The glass nav band the overview's identity strip sits in. */
    private const BAND_MARKER = 'nav-glass-top';

    public function test_overview_shows_the_nav_band_bell_and_shopping_pills(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk();

        $response->assertSee(self::NAV_BAR_MARKER, false);
        $response->assertSee(self::BAND_MARKER, false);
        $response->assertSee(self::BELL_MARKER, false);
        $response->assertSee(self::TOP_CHROME_MARKER, false);
        $response->assertSee(self::CART_MARKER, false);
    }

    public function test_shop_pages_show_only_the_shopping_pills(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::factory()->create())
            ->get('/dashboard/shop/cart')
            ->assertOk();

        // Cart + country switcher ride along - this is a shopping context...
        $response->assertSee(self::NAV_BAR_MARKER, false);
        $response->assertSee(self::TOP_CHROME_MARKER, false);
        $response->assertSee(self::CART_MARKER, false);

        // ...but the nav band and the bell are the overview's furniture.
        $response->assertDontSee(self::BAND_MARKER, false);
        $response->assertDontSee(self::BELL_MARKER, false);
    }

    public function test_inner_pages_carry_no_top_chrome_at_all(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::factory()->create())
            ->get('/dashboard/orders')
            ->assertOk();

        // The bottom nav bar is the navigation on task pages...
        $response->assertSee(self::NAV_BAR_MARKER, false);

        // ...and nothing floats on top: no band, no bell, no shopping pills.
        $response->assertDontSee(self::BAND_MARKER, false);
        $response->assertDontSee(self::BELL_MARKER, false);
        $response->assertDontSee(self::TOP_CHROME_MARKER, false);
        $response->assertDontSee(self::CART_MARKER, false);
    }

    /**
     * The country / language / currency chip, unique to the mobile top chrome.
     * It must render exactly ONCE: it used to be emitted by both an in-flow bar
     * and the fixed pinned chrome, which made the top pills (country switcher,
     * cart, notification) visibly duplicate as the page scrolled.
     */
    private const LOCALE_CHIP_MARKER = 'aria-label="Region, language and currency"';

    public function test_shop_pages_render_the_top_chrome_pills_only_once(): void
    {
        $this->withoutVite();

        $html = $this->actingAs(User::factory()->create())
            ->get('/dashboard/shop/cart')
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, self::LOCALE_CHIP_MARKER));
    }

    public function test_overview_renders_the_top_chrome_pills_only_once(): void
    {
        $this->withoutVite();

        $html = $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, self::LOCALE_CHIP_MARKER));
    }

    /** Back button - removed entirely; the bottom nav bar covers navigation. */
    private const BACK_BUTTON_MARKER = 'aria-label="Go back"';

    /** The greeting + storefront chip, which belong to the overview alone. */
    private const GREETING_MARKER = 'aria-label="Go to the storefront"';

    /** Orders-tab count badge's distinctive glass classes. */
    private const BADGE_MARKER = 'dark:min-w-[20px] dark:bg-white/15';

    public function test_no_page_shows_a_back_button(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();

        // The bottom nav bar is on every page and its centre Menu FAB opens the
        // full sidebar nav, so a back affordance is redundant. Removing it also
        // keeps the top band identical everywhere rather than swapping its left
        // slot per route.
        foreach (['/dashboard', '/dashboard/shop/cart', '/dashboard/orders'] as $path) {
            $this->actingAs($user)
                ->get($path)
                ->assertOk()
                ->assertDontSee(self::BACK_BUTTON_MARKER, false);
        }
    }

    public function test_the_greeting_belongs_to_the_overview_alone(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();

        // "Hi {name} / Visit site" is the overview's own furniture. Task pages
        // keep the band (so the nav looks identical) but leave that slot empty.
        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertSee(self::GREETING_MARKER, false);

        foreach (['/dashboard/orders', '/dashboard/shop/cart'] as $path) {
            $this->actingAs($user)->get($path)->assertOk()
                ->assertDontSee(self::GREETING_MARKER, false);
        }
    }

    /** The rewards page is the Rcoin programme; its menu tile says so. */
    public function test_the_menu_names_rcoin_not_the_old_referrals_alias(): void
    {
        $this->withoutVite();

        $html = $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>Rcoin<', $html);
        $this->assertStringContainsString('dashboard/rewards', $html);
        $this->assertStringNotContainsString('Referrals', $html);
    }

    public function test_orders_tab_shows_the_completed_order_count_badge(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();

        $this->completedOrder($user);
        $this->completedOrder($user);
        $this->completedOrder($user, OrderStatus::Failed); // not counted

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString(self::BADGE_MARKER, $html);
        // Only the two Completed orders are counted, not the Failed one.
        $this->assertStringContainsString('dark:backdrop-blur-md">2<', $html);
    }

    public function test_orders_tab_has_no_badge_without_completed_orders(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $this->completedOrder($user, OrderStatus::Failed); // a non-successful order

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee(self::BADGE_MARKER, false);
    }

    private function completedOrder(User $user, OrderStatus $status = OrderStatus::Completed): void
    {
        Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-'.uniqid(),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 0,
            'markup_amount' => 0,
            'total_amount' => 0,
            'payment_method' => 'wallet',
            'payment_status' => PaymentStatus::Paid,
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'order_status' => $status,
        ]);
    }
}
