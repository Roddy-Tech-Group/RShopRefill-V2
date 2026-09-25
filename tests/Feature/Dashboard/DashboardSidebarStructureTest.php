<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Tests\TestCase;

/**
 * The customer sidebar: Overview; a Shop section whose Shop row drops the
 * services down when pressed anywhere along it (and opens the shop when the
 * rail is folded to icons); an Account section; the Need Help card at the
 * bottom. Settings and Logout live in the avatar menu.
 */
class DashboardSidebarStructureTest extends TestCase
{
    use RefreshDatabase;

    private function sidebar(?User $user = null): string
    {
        $this->withoutVite();

        $html = $this->actingAs($user ?? User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $start = strpos($html, 'data-flux-sidebar');
        $this->assertNotFalse($start, 'The sidebar did not render.');

        return substr($html, $start, strpos($html, '</nav>', strpos($html, 'aria-label="Account management"')) - $start);
    }

    public function test_the_whole_shop_row_drops_the_services_down(): void
    {
        $sidebar = $this->sidebar();

        // The row itself is the toggle, as in the phone drawer: a button that
        // controls the list, with the chevron inside it. No separate arrow.
        $this->assertMatchesRegularExpression('/<button\s+type="button"\s+@click="if \(\$store\.dashboardSidebar\?\.isCollapsed\) \{ Livewire\.navigate\([^)]*\) \} else \{ locked = ! locked; expanded = locked \}"\s+:aria-expanded="expanded\.toString\(\)"\s+aria-controls="dash-shop-services"\s+data-tip="Shop"/s', $sidebar);
        $this->assertStringContainsString('id="dash-shop-services"', $sidebar);
        $this->assertStringNotContainsString('aria-label="Show shop services"', $sidebar);

        // Folded to icons the list cannot show, so the same press opens the shop.
        $this->assertStringContainsString('Livewire.navigate('.Js::from(route('dashboard.shop.gift-cards')).')', $sidebar);

        // Gift Cards leads the list, so the shop is still one press away.
        $this->assertStringContainsString('href="'.route('dashboard.shop.gift-cards').'"', $sidebar);

        $last = -1;
        foreach (['Gift Cards', 'eSIMs', 'Mobile Top-Ups', 'Bill Payments', 'Gaming'] as $label) {
            $at = strpos($sidebar, $label);
            $this->assertNotFalse($at, $label.' is missing from the Shop menu.');
            $this->assertGreaterThan($last, $at, $label.' is out of order.');
            $last = $at;
        }

        $this->assertStringContainsString('subcategory=gaming-entertainment', $sidebar);
        $this->assertStringNotContainsString('All Categories', $sidebar);
        $this->assertStringNotContainsString('Topups &amp; Bills', $sidebar);
    }

    public function test_the_new_badge_only_shows_for_a_listed_service(): void
    {
        config()->set('dashboard.new_services', []);
        $this->assertStringNotContainsString('>New</span>', $this->sidebar());

        config()->set('dashboard.new_services', ['esims']);
        $this->assertSame(2, substr_count($this->sidebar(), '>New</span>'), 'Shop row and the eSIMs link carry the badge.');
    }

    public function test_the_account_section_order(): void
    {
        $sidebar = $this->sidebar();

        $last = -1;
        foreach (['data-tip="Orders"', 'data-tip="My eSIMs"', 'data-tip="Wallet"', 'data-tip="Transactions"', 'data-tip="Security"', 'data-tip="Verify Identity"', 'data-tip="For Partners"'] as $row) {
            $at = strpos($sidebar, $row);
            $this->assertNotFalse($at, $row.' is missing.');
            $this->assertGreaterThan($last, $at, $row.' is out of order.');
            $last = $at;
        }

        // Notifications lives in the account menu under the avatar, not the sidebar.
        $this->assertStringNotContainsString('data-tip="Notifications"', $sidebar);

        // The rewards row is named For Partners (was Referral Rcoin, before that Rcoin Rewards + Referrals).
        $this->assertStringNotContainsString('data-tip="Referrals"', $sidebar);
        $this->assertStringNotContainsString('data-tip="Rcoin Rewards"', $sidebar);
        $this->assertStringNotContainsString('data-tip="Referral Rcoin"', $sidebar);
    }

    public function test_verify_identity_turns_into_identity_verified_after_approval(): void
    {
        $pending = $this->sidebar(User::factory()->create(['kyc_status' => 'unsubmitted']));
        $this->assertStringContainsString('Verify Identity', $pending);
        $this->assertStringContainsString('>KYC</span>', $pending);

        $verified = $this->sidebar(User::factory()->create(['kyc_status' => 'verified']));
        $this->assertStringContainsString('Identity Verified', $verified);
        $this->assertStringNotContainsString('>KYC</span>', $verified);
    }

    /** Settings and Logout belong to the avatar menu, not the sidebar. */
    public function test_the_sidebar_carries_no_settings_or_logout_rows(): void
    {
        $sidebar = $this->sidebar();

        $this->assertStringNotContainsString('data-tip="Settings"', $sidebar);
        $this->assertStringNotContainsString(route('logout'), $sidebar);
    }
}
