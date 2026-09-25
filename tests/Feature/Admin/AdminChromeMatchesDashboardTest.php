<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The admin chrome wears the customer dashboard's visual system.
 *
 * The two layouts drifted: the admin rail was white with a grey active pill,
 * while the dashboard used a warm grey rail with a white one. This pins the
 * shared system so the next edit to either does not quietly split them again.
 *
 * Deliberately NOT mirrored, and asserted as such below: the admin keeps its
 * own mobile nav (a card-grid sheet rather than the dashboard's fixed nav band)
 * and carries no locale switcher.
 */
class AdminChromeMatchesDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function adminLayout(): string
    {
        return File::get(resource_path('views/components/layouts/admin.blade.php'));
    }

    private function dashboardLayout(): string
    {
        return File::get(resource_path('views/components/layouts/dashboard.blade.php'));
    }

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Chrome Tester',
            'email' => 'chrome@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    /** The rail shell: warm grey, fixed width, desktop only - same as the dashboard. */
    public function test_the_sidebar_shell_matches_the_dashboard(): void
    {
        foreach ([$this->adminLayout(), $this->dashboardLayout()] as $layout) {
            $this->assertMatchesRegularExpression(
                '/<flux:sidebar[^>]*class="[^"]*hidden[^"]*w-\[256px\][^"]*bg-\[#eaeeec\][^"]*lg:flex/',
                $layout,
                'both layouts should carry the same sidebar shell'
            );
        }
    }

    /**
     * Active and hover are one treatment, owned entirely by the stylesheet.
     *
     * The active row used to be a filled pill with a 1px ring while hover was
     * a translucent wash, so the row you were pointing at and the page you
     * were on looked like two different components. They now share the hover
     * values, per theme. The markup must therefore set no fill and no ring of
     * its own - a leftover utility there is the tie that app.css avoids by
     * keeping one rule per theme.
     */
    public function test_active_and_hover_share_one_treatment(): void
    {
        foreach (['admin' => $this->adminLayout(), 'dashboard' => $this->dashboardLayout()] as $name => $layout) {
            preg_match_all("/'[^']*\bnav-item-active\b[^']*'/", $layout, $matches);

            $this->assertNotEmpty($matches[0], "{$name} should have an active row");

            foreach ($matches[0] as $classString) {
                foreach (['bg-[#ffffff]', 'dark:bg-black', 'ring-1', 'ring-white'] as $banned) {
                    $this->assertStringNotContainsString(
                        $banned,
                        $classString,
                        "{$name}: the active row still sets {$banned} itself; app.css owns this"
                    );
                }
            }
        }

        // Each theme's active fill is the same value as that theme's hover.
        $css = File::get(resource_path('css/app.css'));

        foreach ([
            ['.nav-item-active', '.nav-item:hover', 'rgba(255, 255, 255, 0.7)'],
            ['html.dark .nav-item-active', 'html.dark .nav-item:hover', 'rgba(255, 255, 255, 0.10)'],
            ['html.dark.pure-dark .nav-item-active', 'html.dark.pure-dark .nav-item:hover', 'rgba(255, 255, 255, 0.14)'],
        ] as [$activeSelector, $hoverSelector, $value]) {
            foreach ([$activeSelector, $hoverSelector] as $selector) {
                $this->assertMatchesRegularExpression(
                    '/'.preg_quote($selector, '/').'\s*\{[^}]*'.preg_quote($value, '/').'/',
                    $css,
                    "{$selector} should use {$value} so active and hover match"
                );
            }
        }
    }

    /**
     * The pale-blue utilities must NOT be swapped for the grey they paint.
     *
     * They look dead in light mode, because --color-blue-50/100 are overridden
     * to neutrals. They are not: the dark theme remaps the same utilities to
     * the ash surface. Replacing them with the literal light-mode hex drops
     * that half, and the account dropdown's hover row turned into a pale pill
     * with pale text in dark mode. The pairing is what keeps both themes right.
     */
    public function test_pale_blue_surfaces_keep_their_dark_mode_remap(): void
    {
        $css = File::get(resource_path('css/app.css'));

        foreach (['.dark .bg-blue-50', '.dark .hover\:bg-blue-100:hover'] as $rule) {
            $this->assertStringContainsString(
                $rule,
                $css,
                'the dark remap this markup depends on is gone'
            );
        }

        // The literal light-mode hexes carry no dark rule, so they must not
        // stand in for the utility inside the admin chrome.
        $markup = (string) preg_replace('/<style>.*?<\/style>/s', '', $this->adminLayout());

        $this->assertStringNotContainsString('hover:bg-[#e8ecea]', $markup);
        $this->assertStringContainsString('hover:bg-blue-100', $markup);
    }

    /**
     * A .nav-item row must not also carry hover utilities.
     *
     * app.css owns the hover for these rows, one rule per theme, precisely so
     * the two cannot tie. A row that keeps `hover:bg-*` as well re-creates the
     * tie: whichever Tailwind emits last wins, which showed up as a sidebar
     * whose hover did nothing in one theme and washed out in the other.
     */
    public function test_nav_item_rows_leave_the_hover_to_the_stylesheet(): void
    {
        foreach (['admin' => $this->adminLayout(), 'dashboard' => $this->dashboardLayout()] as $name => $layout) {
            preg_match_all("/'[^']*\bnav-item\b[^']*'/", $layout, $matches);

            $this->assertNotEmpty($matches[0], "{$name} should have nav-item rows");

            foreach ($matches[0] as $classString) {
                $this->assertStringNotContainsString(
                    'hover:bg-',
                    $classString,
                    "{$name} has a nav-item row that also sets its own hover background"
                );
            }
        }
    }

    /** Every sidebar row uses the white active pill, not the old grey one. */
    public function test_no_sidebar_row_keeps_the_old_grey_active_pill(): void
    {
        $layout = $this->adminLayout();

        $this->assertStringNotContainsString('bg-zinc-200 px-3 py-3', $layout);
        $this->assertStringNotContainsString('hover:bg-zinc-150', $layout);
    }

    /**
     * The nav keeps a horizontal gutter so outside-drawn rings survive.
     *
     * The active row's ring is a box-shadow, painted outside the element's
     * box. The nav scrolls, and `overflow-y-auto` makes overflow-x compute to
     * `auto` as well, so a row spanning the full content width had the left
     * and right of its ring clipped while the top and bottom still drew - a
     * border that showed on two sides only. The gutter gives that 1px room.
     */
    public function test_the_sidebar_nav_keeps_a_gutter_for_the_active_ring(): void
    {
        $layout = $this->adminLayout();

        $this->assertMatchesRegularExpression(
            '/<nav class="[^"]*overflow-y-auto[^"]*\bpx-1\b[^"]*"[^>]*aria-label="Admin"/',
            $layout,
            'the scrolling nav must keep horizontal padding or it clips the active ring'
        );
    }

    /** Radii follow the project's 12px standard; no strays left behind. */
    public function test_the_admin_layout_uses_the_standard_radius(): void
    {
        $layout = $this->adminLayout();

        $this->assertStringNotContainsString('rounded-[10px]', $layout);
        $this->assertStringNotContainsString('rounded-2xl', $layout);
    }

    /** Admin keeps its own mobile sheet rather than the dashboard's nav band. */
    public function test_admin_keeps_its_own_mobile_menu(): void
    {
        $layout = $this->adminLayout();

        $this->assertStringContainsString("\$dispatch('open-mobile-menu')", $layout);
        $this->assertStringNotContainsString('nav-band-top', $layout);
    }

    /** Admin is not translated, so it must not gain the locale switcher. */
    public function test_admin_has_no_locale_switcher(): void
    {
        $layout = $this->adminLayout();

        $this->assertStringNotContainsString('<x-nav.locale-modal', $layout);
        $this->assertStringContainsString('<x-nav.locale-modal', $this->dashboardLayout());
    }

    /** The whole thing still renders for a signed-in admin. */
    public function test_the_admin_dashboard_renders_with_the_new_chrome(): void
    {
        $page = $this->actingAs($this->admin(), 'admin')
            ->withoutVite()
            ->get('/admin/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('bg-[#eaeeec]', $page);
        $this->assertStringContainsString('w-[256px]', $page);
    }
}
