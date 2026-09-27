<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard's four pull-downs (search, cart, account, notifications) share
 * one surface: 12px corners, frosted white, the frame's border, and in dark
 * mode the page ground with its grain. The refer pull-down wears the same
 * surface but hangs LEFT under its pill instead of pinning to the frame's right
 * corner with the other three. The search field's focus edge is the brand blue.
 */
class DashboardDropdownSurfacesTest extends TestCase
{
    use RefreshDatabase;

    /** Asserted token by token: the cart panel keeps its padding between them. */
    private const SURFACE = ['rounded-[12px]', 'bg-white/80', 'backdrop-blur-xl', 'shadow-xl', 'shadow-zinc-900/15'];

    private function page(): string
    {
        $this->withoutVite();

        return $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent();
    }

    private function layout(): string
    {
        // Blade comments stripped: the refer wrapper's note names the very
        // class it must not carry, and prose must not be able to fail this.
        return preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            file_get_contents(resource_path('views/components/layouts/dashboard.blade.php'))
        );
    }

    private function css(): string
    {
        return preg_replace('#/\*.*?\*/#s', '', file_get_contents(resource_path('css/app.css')));
    }

    /** The opening tag of the first element carrying the given class. */
    private function tagWith(string $html, string $class): string
    {
        preg_match('/<[a-z]+[^>]*class="[^"]*\b'.preg_quote($class, '/').'\b[^"]*"[^>]*>/', $html, $m);
        $this->assertNotEmpty($m, "No element carries {$class}.");

        return $m[0];
    }

    public function test_the_four_frame_pull_downs_share_one_surface(): void
    {
        $html = $this->page();

        foreach (['dashboard-search-panel', 'cart-panel', 'dashboard-account-menu', 'dashboard-notification-panel'] as $panel) {
            $tag = $this->tagWith($html, $panel);

            foreach (self::SURFACE as $token) {
                $this->assertStringContainsString($token, $tag, "{$panel} is missing {$token}.");
            }
            $this->assertStringContainsString('dashboard-frame-popup', $tag, "{$panel} does not pin inside the frame.");

            // The old kits: three different radii, a light-blue fill, and a ring
            // drawn on top of the border app.css already gives every popup.
            foreach (['rounded-[24px]', 'rounded-[26px]', 'rounded-[15px]', 'bg-[#eff6ff]', 'bg-[#ffffff]', 'ring-1'] as $old) {
                $this->assertStringNotContainsString($old, $tag, "{$panel} still carries {$old}.");
            }
        }
    }

    public function test_the_refer_pull_down_hangs_left_and_is_not_pinned_to_the_frame(): void
    {
        // Asserted on the source: the pill only renders when the promo config
        // hands it a panel, and the anchoring is the layout's regardless.
        $layout = $this->layout();

        preg_match('/<div[^>]*class="dashboard-refer-popup[^"]*"[^>]*>\s*<x-nav\.referral-panel/s', $layout, $m);
        $this->assertNotEmpty($m, 'The refer wrapper lost its dashboard-refer-popup class or its card.');

        $this->assertStringContainsString('left-0', $m[0]);
        $this->assertStringNotContainsString('right-0', $m[0]);
        $this->assertStringNotContainsString('dashboard-frame-popup', $m[0], 'dashboard-frame-popup would pin it to the right corner.');
    }

    public function test_the_refer_card_takes_the_shared_surface_on_the_dashboard_only(): void
    {
        $css = $this->css();

        // The 12px frosted surface is keyed on the dashboard wrapper, so the
        // shared card keeps its own look in the storefront nav.
        preg_match('/body\.dashboard \.dashboard-refer-popup > div \{([^}]*)\}/s', $css, $m);
        $this->assertNotEmpty($m, 'No dashboard rule for the refer card.');
        $this->assertStringContainsString('border-radius: 12px', $m[1]);
        $this->assertStringContainsString('rgb(255 255 255 / .8)', $m[1]);
        $this->assertStringContainsString('backdrop-filter: blur(24px)', $m[1]);

        $this->assertStringNotContainsString(
            'dashboard-refer-popup',
            file_get_contents(resource_path('views/components/nav/referral-panel.blade.php')),
            'The shared card must not know about the dashboard wrapper.'
        );
    }

    public function test_all_four_wear_the_page_ground_in_dark_mode(): void
    {
        $css = $this->css();

        preg_match(
            '/html\.dark body\.dashboard \.dashboard-account-menu,\s*'
            .'html\.dark body\.dashboard \.dashboard-search-panel,\s*'
            .'html\.dark body\.dashboard \.dashboard-notification-panel,\s*'
            .'html\.dark body\.dashboard \.dashboard-refer-popup > div \{([^}]*)\}/s',
            $css,
            $m
        );
        $this->assertNotEmpty($m, 'The four dark pull-down surfaces are no longer one rule.');

        // Same declaration the cart panel already carries: ground + grain,
        // opaque, blur off. Not the flat off-token grey it used to be.
        $this->assertStringContainsString('background-color: var(--ground)', $m[1]);
        $this->assertStringContainsString('background-image: var(--grain)', $m[1]);
        $this->assertStringContainsString('backdrop-filter: none', $m[1]);
        $this->assertStringNotContainsString('#1d1d1f', $m[1]);
    }

    public function test_the_search_field_focus_edge_is_the_brand_blue(): void
    {
        $css = $this->css();

        preg_match('/body\.dashboard \.dashboard-search-field\.border-blue-500 \{([^}]*)\}/s', $css, $light);
        $this->assertNotEmpty($light, 'No focus rule for the search field.');
        $this->assertStringContainsString('border-color: var(--color-brand)', $light[1]);
        $this->assertStringContainsString('rgb(0 68 255 / .28)', $light[1]);

        preg_match('/html\.dark body\.dashboard \.dashboard-search-field\.border-blue-500 \{([^}]*)\}/s', $css, $dark);
        $this->assertNotEmpty($dark, 'No dark focus rule for the search field.');
        // blue-500, what the class already names; the paler steps are banned.
        $this->assertStringContainsString('border-color: #3b82f6', $dark[1]);

        // The violet it used to paint, in either theme, is gone from the sheet.
        foreach (['#8f83ef', '#9586f7', '124 97 237'] as $violet) {
            $this->assertStringNotContainsString($violet, $css, "{$violet} is still in app.css.");
        }
    }
}
