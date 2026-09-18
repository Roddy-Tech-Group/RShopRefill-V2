<?php

namespace Tests\Feature\Nav;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The "Refer friends, earn Rcoin" panel, shared by the dashboard top bar and the
 * storefront nav's "Invite a Friend" pill.
 *
 * From md up the storefront pill drops this panel instead of navigating to the
 * earn-points page. Below md it stays a plain link - the phone nav is already
 * three rows deep before the page starts.
 */
class ReferralPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_gets_a_sign_in_cta_instead_of_a_referral_link(): void
    {
        $html = Blade::render('<x-nav.referral-panel />');

        // livewire:referral-code writes a code onto the signed-in user, so it
        // cannot render for a guest at all - it would fatal on a null user.
        $this->assertStringNotContainsString('wire:id', $html);
        $this->assertStringContainsString('Sign in to get your link', $html);
        $this->assertStringContainsString('Create an account', $html);

        // The offer is still explained, or the panel is just a login wall.
        $this->assertStringContainsString('Refer friends, earn up to', $html);
        $this->assertStringContainsString('Signing up alone earns nothing.', $html);
    }

    public function test_signed_in_customer_gets_the_referral_link_box(): void
    {
        $this->actingAs(User::factory()->create());

        $html = Blade::render('<x-nav.referral-panel />');

        $this->assertStringContainsString('See your Rcoin rewards', $html);
        $this->assertStringNotContainsString('Sign in to get your link', $html);
    }

    public function test_panel_uses_bg_white_so_dark_mode_reaches_the_storefront(): void
    {
        // Rendered, not the source file: the component's own comment explains
        // this trap by naming the class, which a source-level assertion trips on.
        $html = Blade::render('<x-nav.referral-panel />');

        // app.css repaints bg-[#ffffff] for dark mode under `html.dark
        // body.dashboard` ONLY, deliberately, so the storefront can handle its
        // own dark surfaces. `.dark .bg-white` is unscoped and carries the same
        // ash treatment. Swapping this back would ship a white card on the dark
        // storefront, which is exactly what it looked like before.
        $this->assertStringContainsString('bg-white', $html);
        $this->assertStringNotContainsString('bg-[#ffffff]', $html);
    }

    public function test_desktop_pill_opens_the_panel_and_mobile_stays_a_link(): void
    {
        $markup = file_get_contents(resource_path('views/components/nav/main-nav.blade.php'));

        // The md-and-up copy is the trigger: it announces the popup and handles
        // its own click, so Livewire must not navigate out from under it.
        $this->assertStringContainsString('aria-haspopup="dialog"', $markup);
        $this->assertStringContainsString('x-on:click.prevent="referOpen = ! referOpen"', $markup);
        $this->assertStringContainsString(':navigate="false"', $markup);

        // It stays an <a href> so middle-click and a JS-less visit still work.
        $link = file_get_contents(resource_path('views/components/nav/invite-link.blade.php'));
        $this->assertStringContainsString("route('shop.earn-points')", $link);
        $this->assertStringContainsString('<a', $link);
    }

    public function test_dashboard_and_storefront_share_one_panel(): void
    {
        // Two copies of this drifted once already in the row headers. Asserting
        // delegation is what keeps them the same panel rather than two that
        // merely look alike today.
        foreach ([
            'components/layouts/dashboard.blade.php',
            'components/nav/main-nav.blade.php',
        ] as $view) {
            $markup = file_get_contents(resource_path('views/'.$view));

            $this->assertStringContainsString('<x-nav.referral-panel', $markup, $view.' should use the shared panel.');
            $this->assertStringNotContainsString('Signing up alone earns nothing.', $markup, $view.' still has its own copy of the panel copy.');
        }
    }

    public function test_storefront_home_renders_the_panel_for_a_guest(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Refer friends, earn up to', false)
            ->assertSee('Sign in to get your link', false);
    }
}
