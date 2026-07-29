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
 * Mobile chrome visibility. Both bars are now identical on every dashboard page,
 * so a user who learns the nav on the overview sees the same thing everywhere:
 *   - The floating bottom nav bar (its centre Menu button dispatches
 *     'open-mobile-menu') stays on EVERY dashboard page - it is the primary
 *     navigation and must always be reachable.
 *   - The top chrome (cart / country switcher / notification) also rides on every
 *     page. It used to be limited to the browse hub (overview + shop), which left
 *     task pages with a different top bar to the one users learned on the overview.
 *
 * The one thing that still varies is the back button, which only task/inner pages
 * need - the browse hub is a top-level destination with nothing above it.
 */
class MobileNavChromeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** Bottom nav bar's Menu FAB - present on every page. */
    private const NAV_BAR_MARKER = "\$dispatch('open-mobile-menu')";

    /** Country switcher trigger - a SHOPPING control, so overview + shop only. */
    private const TOP_CHROME_MARKER = "\$dispatch('open-locale-modal')";

    /** The notification bell, which is never route-scoped. */
    private const BELL_MARKER = 'floating-notif-inner';

    /** The cart chip - the other shopping-only control. */
    private const CART_MARKER = 'aria-label="Cart"';

    public function test_overview_shows_the_bottom_nav_bar_and_the_top_chrome(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk();

        $response->assertSee(self::NAV_BAR_MARKER, false);
        $response->assertSee(self::TOP_CHROME_MARKER, false);
    }

    public function test_shop_pages_show_the_bottom_nav_bar_and_the_top_chrome(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::factory()->create())
            ->get('/dashboard/shop/cart')
            ->assertOk();

        $response->assertSee(self::NAV_BAR_MARKER, false);
        $response->assertSee(self::TOP_CHROME_MARKER, false);
    }

    public function test_inner_pages_keep_the_nav_bars_but_drop_the_shopping_controls(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::factory()->create())
            ->get('/dashboard/orders')
            ->assertOk();

        // Both bars still frame the page, so the nav reads the same everywhere...
        $response->assertSee(self::NAV_BAR_MARKER, false);
        $response->assertSee(self::BELL_MARKER, false);

        // ...but the cart cannot be acted on here, and the country switcher only
        // changes prices you are about to pay: order history renders each order's
        // own snapshotted display_currency, so switching would change nothing.
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
