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
 * Mobile chrome visibility:
 *   - The floating bottom nav bar (its centre Menu button dispatches
 *     'open-mobile-menu') stays on EVERY dashboard page - it is the primary
 *     navigation and must always be reachable.
 *   - The top chrome (cart / country switcher / notification, plus the hamburger
 *     that dispatches 'open-connect-panel') rides along ONLY on the browse hub -
 *     the overview and the dashboard shop pages - and is hidden on task/inner
 *     pages (orders, wallet, settings, ...) so they stay clean.
 */
class MobileNavChromeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** Bottom nav bar's Menu FAB - present on every page. */
    private const NAV_BAR_MARKER = "\$dispatch('open-mobile-menu')";

    /** Top-chrome hamburger - present only on overview + shop pages. */
    private const TOP_CHROME_MARKER = "\$dispatch('open-connect-panel')";

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

    public function test_inner_pages_keep_the_bottom_nav_bar_but_hide_the_top_chrome(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::factory()->create())
            ->get('/dashboard/orders')
            ->assertOk();

        // The bottom nav bar (navigation) stays...
        $response->assertSee(self::NAV_BAR_MARKER, false);
        // ...but the top cart / country switcher / notification + hamburger are gone.
        $response->assertDontSee(self::TOP_CHROME_MARKER, false);
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

    /** Back button - present only on task/inner pages (which dropped the top chrome). */
    private const BACK_BUTTON_MARKER = 'aria-label="Go back"';

    /** Orders-tab count badge's distinctive glass classes. */
    private const BADGE_MARKER = 'dark:min-w-[20px] dark:bg-white/15';

    public function test_inner_pages_show_a_back_button(): void
    {
        $this->withoutVite();

        $this->actingAs(User::factory()->create())
            ->get('/dashboard/orders')
            ->assertOk()
            ->assertSee(self::BACK_BUTTON_MARKER, false);
    }

    public function test_overview_and_shop_pages_have_no_back_button(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee(self::BACK_BUTTON_MARKER, false);
        $this->actingAs($user)->get('/dashboard/shop/cart')->assertOk()->assertDontSee(self::BACK_BUTTON_MARKER, false);
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
