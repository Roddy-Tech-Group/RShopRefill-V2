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
 * Mobile chrome, per the 2026-09-23 redesign:
 *   - The desktop header IS the phone bar (app.css fixes it below lg): the
 *     hamburger on the left, then the desktop's own pills - refer & earn (a
 *     gift chip standing in for the rotating text pill), region, search,
 *     cart, notifications, account - with the same dropdowns, which drop as
 *     full-width sheets under the bar.
 *   - The hamburger opens a sidebar drawer that slides in from the left in
 *     the bar's colour, with the app logo at its top, then one row per
 *     destination in three groups. Region and log out live in the account menu.
 *   - Gone: the bottom tab bar, the popup card-grid menu, the overview's
 *     identity band ("Hi name / Visit site") and the floating pill row.
 */
class MobileNavChromeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const BAR_MARKER = 'data-flux-header';

    private const HAMBURGER_MARKER = "\$dispatch('open-mobile-menu')";

    private const REFER_MARKER = 'aria-label="Refer & earn"';

    private const SEARCH_MARKER = 'x-ref="searchInput"';

    private const CART_MARKER = 'aria-label="Shopping cart"';

    private const BELL_MARKER = 'wire:key="header-notif"';

    private const ACCOUNT_MARKER = 'dashboard-avatar-button';

    private const DRAWER_MARKER = 'class="dash-drawer';

    private const DRAWER_CLOSE_MARKER = 'aria-label="Close menu"';

    private const DRAWER_ROWS_MARKER = 'aria-label="Menu">';

    private const REGION_MARKER = "\$dispatch('open-locale-modal')";

    /** The drawer's Orders badge - distinct from the desktop sidebar's. */
    private const BADGE_MARKER = 'rounded-[5px] bg-white/15 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">';

    private const PAGES = ['/dashboard', '/dashboard/shop/cart', '/dashboard/orders'];

    public function test_every_page_carries_the_same_top_bar_and_drawer(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();

        foreach (self::PAGES as $path) {
            $html = $this->actingAs($user)->get($path)->assertOk()->getContent();

            foreach ([
                self::BAR_MARKER, self::HAMBURGER_MARKER, self::REFER_MARKER, self::SEARCH_MARKER,
                self::CART_MARKER, self::BELL_MARKER, self::ACCOUNT_MARKER,
                self::DRAWER_MARKER, self::DRAWER_CLOSE_MARKER,
            ] as $marker) {
                $this->assertStringContainsString($marker, $html, "{$path} is missing {$marker}");
            }

            // One hamburger, one bar: the old bottom bar carried a second "Open menu".
            $this->assertSame(1, substr_count($html, 'aria-label="Open menu"'), "{$path} should have exactly one hamburger");
            // The attribute also appears in stylesheet selectors, so count the element.
            $this->assertSame(1, preg_match_all('/<header[^>]*data-flux-header/', $html), "{$path} should render the header once: it is the phone bar too");
        }
    }

    public function test_the_drawer_opens_with_the_app_logo_at_the_top(): void
    {
        $this->withoutVite();

        $html = $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->getContent();
        $drawer = substr($html, strpos($html, self::DRAWER_MARKER));

        // The full logo (mark + wordmark), the same file the storefront header shows.
        $aside = substr($drawer, 0, strpos($drawer, '</aside>'));
        $logo = strpos($aside, 'assets/logo.webp');
        $rows = strpos($aside, self::DRAWER_ROWS_MARKER);

        $this->assertNotFalse($logo, 'the drawer should show the app logo');
        $this->assertNotFalse($rows, 'the drawer should list the navigation rows');
        $this->assertLessThan($rows, $logo, 'the logo sits first, above the rows');

        // The close button sits outside the panel, on the backdrop beside it.
        $this->assertStringNotContainsString(self::DRAWER_CLOSE_MARKER, $aside);
        $this->assertStringContainsString('dash-drawer-close', $drawer);

        // The rows are the sidebar's destinations. Region / currency and log
        // out are not among them: they live in the account menu.
        foreach (['Overview', 'Shop', 'Orders', 'My eSIMs', 'Wallet', 'Transactions', 'Settings', 'Security', 'Verify (KYC)', 'Appearance', 'Notifications', 'For Partners', 'Support'] as $label) {
            $this->assertStringContainsString($label, $aside, "the drawer should list {$label}");
        }
        $this->assertStringNotContainsString('Log out', $aside);
        $this->assertStringNotContainsString(self::REGION_MARKER, $aside);

        $menu = substr($html, strpos($html, 'dashboard-account-menu'));
        // The menu opens the same modal on its language pane.
        $this->assertStringContainsString("\$dispatch('open-locale-modal'", $menu);
        $this->assertStringContainsString(route('logout'), $menu);
    }

    public function test_the_old_mobile_chrome_is_gone(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();

        foreach (self::PAGES as $path) {
            $this->actingAs($user)->get($path)->assertOk()
                ->assertDontSee('dash-mobile-bar', false)                   // the interim custom bar
                ->assertDontSee('mobileSearchInput', false)                 // and its own search sheet
                ->assertDontSee('nav-glass', false)                         // the floating bottom bar
                ->assertDontSee('liquid-pill', false)                       // its sliding active pill
                ->assertDontSee('nav-band-top', false)                      // the overview's identity band
                ->assertDontSee('aria-label="Go to the storefront"', false) // "Hi name / Visit site"
                ->assertDontSee('floating-notif-inner', false)              // the floating bell pill
                ->assertDontSee('aria-label="Go back"', false)              // no back affordance either
                ->assertDontSee('id="mobile-menu-title"', false);           // the popup card-grid menu
        }
    }

    /** The rewards page is the partner programme; nothing calls it Referrals. */
    public function test_the_menu_names_the_partner_programme_not_the_old_referrals_alias(): void
    {
        $this->withoutVite();

        $html = $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $drawer = substr($html, strpos($html, self::DRAWER_MARKER));

        $this->assertStringContainsString('For Partners', $drawer);
        $this->assertStringContainsString('dashboard/rewards', $drawer);
        $this->assertStringNotContainsString('Referrals', $html);
    }

    public function test_the_orders_row_shows_the_completed_order_count_badge(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();

        $this->completedOrder($user);
        $this->completedOrder($user);
        $this->completedOrder($user, OrderStatus::Failed); // not counted

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        // Only the two Completed orders are counted, not the Failed one.
        $this->assertStringContainsString(self::BADGE_MARKER.'2<', $html);
    }

    public function test_the_orders_row_has_no_badge_without_completed_orders(): void
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
