<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The phone dashboard's chrome restyle (2026-09-23).
 *
 *   - The bar drops the language pill on phones (the account menu's Language
 *     row opens the same sheet) and the referral gift chip takes its slot,
 *     first in the right-hand group. Desktop keeps both, the brand first.
 *   - The account menu is a compact 300px list: 40px rows, 14px labels, 18px
 *     icons, edge-to-edge hairlines, a solid lifted panel in dark mode. On a
 *     phone it hangs under the avatar instead of spreading as a sheet.
 *   - The sidebar drawer wears the bar's colour (navy, black in dark mode),
 *     is 270px wide, has the logo level with the bar and its rows in three
 *     groups, the current page on a soft white tile.
 *   - The PURCHASE cards: 10px corners, the drawn illustrations at 40px,
 *     14px text, and a 14px heading at DM Sans 499, black in light mode.
 */
class MobileChromeRestyleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Livewire::withoutLazyLoading();
    }

    private function layout(): string
    {
        return preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents(resource_path('views/components/layouts/dashboard.blade.php')));
    }

    private function css(): string
    {
        return preg_replace('#/\*.*?\*/#s', '', file_get_contents(resource_path('css/app.css')));
    }

    private function page(): string
    {
        $this->withoutVite();

        return $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->getContent();
    }

    public function test_the_phone_bar_swaps_the_language_pill_for_the_referral_chip(): void
    {
        $layout = $this->layout();

        // The brand group (mark + promo pill + gift chip) follows the spacer in
        // the flow and is sent back to the front on desktop only.
        $spacer = strpos($layout, '<flux:spacer />');
        $brand = strpos($layout, '<div class="flex shrink-0 items-center lg:order-first">');
        $chip = strpos($layout, 'aria-label="Refer & earn"');
        $pill = strpos($layout, "@click=\"\$dispatch('open-locale-modal')\"");
        $this->assertNotFalse($spacer);
        $this->assertNotFalse($brand);
        $this->assertGreaterThan($spacer, $brand, 'the brand group sits after the spacer');
        $this->assertGreaterThan($brand, $chip, 'the gift chip is inside the brand group');
        $this->assertGreaterThan($chip, $pill, 'the language pill comes after the chip');

        // The language pill is desktop-only; its label no longer needs its own breakpoint.
        $this->assertStringContainsString('class="hidden h-9 shrink-0 items-center gap-2 rounded-full border-2 border-white/30 pl-1 pr-3 text-[13px] font-semibold text-white transition-colors hover:bg-white/15 lg:flex', $layout);
        $this->assertStringNotContainsString('<span class="hidden lg:inline">{{ strtoupper(', $layout);

        $html = $this->page();
        $this->assertLessThan(strpos($html, 'x-ref="searchInput"'), strpos($html, 'aria-label="Refer & earn"'), 'the chip renders before the search chip');
    }

    public function test_the_account_menu_is_a_compact_list(): void
    {
        $html = $this->page();
        $menu = substr($html, strpos($html, 'class="dashboard-frame-popup dashboard-account-menu'));
        $menu = substr($menu, 0, strpos($menu, '</header>'));

        $this->assertStringContainsString('w-[300px] max-w-[calc(100vw-1rem)]', $menu);
        $this->assertStringContainsString('<p class="truncate text-[15px] font-semibold text-zinc-900 dark:text-white">', $menu);
        $this->assertStringContainsString('<p class="mt-0.5 truncate text-[12px] text-zinc-500 dark:text-zinc-400">', $menu);
        // Flat rows: no tile corners, the label 14px, the icon 18px beside it.
        $this->assertStringContainsString('dashboard-account-row flex w-full items-center justify-between gap-3 px-4 py-2 text-left text-[14px] font-medium', $menu);
        $this->assertStringNotContainsString('rounded-[12px] px-3 py-2.5', $menu);
        $this->assertSame(0, substr_count($menu, 'class="h-5 w-5"'), 'menu icons are 18px, not 20px');
        $this->assertGreaterThanOrEqual(10, substr_count($menu, 'h-[18px] w-[18px]'));
        // Five edge-to-edge hairlines between six groups.
        $this->assertSame(5, substr_count($menu, '<div class="border-t border-zinc-200/80 dark:border-white/10"></div>'));
        $this->assertSame(5, substr_count($menu, '<div class="py-1.5">'));

        $css = $this->css();
        $this->assertStringContainsString('body.dashboard .dashboard-account-row { min-height: 40px; }', $css);
        $this->assertStringContainsString('body.dashboard .dashboard-account-row-icon .anim-icon { flex: 0 0 18px; }', $css);
        // Dark: a solid lifted panel, no grain, a soft hairline.
        preg_match('/html\.dark body\.dashboard \.dashboard-account-menu \{([^}]*)\}/s', $css, $dark);
        $this->assertNotEmpty($dark, 'no dark override for the account menu');
        $this->assertStringContainsString('background-color: #1b1b1f', $dark[1]);
        $this->assertStringContainsString('background-image: none', $dark[1]);
        // Phone: 300px under the avatar, not the full-width sheet the other popups use.
        preg_match('/body\.dashboard \.dashboard-account-menu \{\s*left: auto;\s*right: 0\.75rem;\s*width: 300px;/s', $css, $phone);
        $this->assertNotEmpty($phone, 'the phone account menu should hang under the avatar at 300px');
    }

    public function test_the_drawer_wears_the_bar_colour_with_grouped_rows(): void
    {
        $html = $this->page();
        $aside = substr($html, strpos($html, 'class="dash-drawer'));
        $aside = substr($aside, 0, strpos($aside, '</aside>'));

        // No surface utility on the panel: app.css paints it the bar's colour.
        $this->assertStringContainsString('class="dash-drawer fixed inset-y-0 left-0 z-[70] flex w-[min(69vw,270px)] flex-col"', $aside);
        $this->assertStringNotContainsString('bg-[#eaeeec]', $aside);
        $this->assertStringNotContainsString('shadow-2xl', $aside);
        // A rule under the logo separates the brand from the destinations.
        // white/20, not the usual white/10: this panel is navy in light and
        // pure black in dark, and at 10 percent a hairline disappears on black
        // the way the header chips did. It is mobile only for free, because the
        // whole drawer is lg:hidden.
        $this->assertStringContainsString('class="dash-drawer-head flex shrink-0 items-center border-b border-white/20 px-4">', $aside);
        $this->assertStringContainsString('class="site-logo h-12 w-auto object-contain"', $aside);

        // Three groups: the main rows, then Account, then More; the current page tiled.
        $this->assertSame(2, substr_count($aside, 'class="dash-drawer-label'));
        $this->assertStringContainsString('>Account</p>', $aside);
        $this->assertStringContainsString('>More</p>', $aside);
        $this->assertLessThan(strpos($aside, '>More</p>'), strpos($aside, '>Account</p>'));
        $this->assertLessThan(strpos($aside, '>Account</p>'), strpos($aside, '>Transactions<'));
        $this->assertGreaterThan(strpos($aside, '>Account</p>'), strpos($aside, '>Settings<'));
        $this->assertGreaterThan(strpos($aside, '>More</p>'), strpos($aside, '>For Partners<'));
        $this->assertSame(13, substr_count($aside, 'dash-drawer-row flex items-center justify-between gap-3 rounded-[10px] px-3 py-2 text-[15px] font-semibold'));
        $this->assertSame(1, substr_count($aside, 'dash-drawer-row-active'), 'only the current page is tiled');
        $this->assertMatchesRegularExpression('/dash-drawer-row-active"\s+aria-current="page"/', $aside);

        // The close button sits beside the 270px panel, level with the bar's chips.
        $this->assertStringContainsString('left: calc(min(69vw, 270px) + 0.75rem); top: calc(env(safe-area-inset-top) + 0.75rem);', $html);

        $css = $this->css();
        $this->assertStringContainsString(':is(body.dashboard, body.admin) .dash-drawer { background-color: #0c1337; color: #fff; }', $css);
        // Not pure black: the drawer runs flush to the top behind the status
        // bar, and #000000 read as switched-off glass rather than a panel.
        $this->assertStringContainsString('html.dark :is(body.dashboard, body.admin) .dash-drawer { background-color: #1e1e25; }', $css);
        $this->assertStringContainsString(':is(body.dashboard, body.admin) .dash-drawer .site-logo { filter: brightness(0) invert(1) !important; }', $css);
        $this->assertStringContainsString(':is(body.dashboard, body.admin) .dash-drawer-head { min-height: calc(3.75rem + env(safe-area-inset-top));', $css);
        $this->assertStringContainsString(':is(body.dashboard, body.admin) .dash-drawer-row-active { background-color: rgb(255 255 255 / .12); color: #fff; }', $css);
    }

    public function test_the_purchase_cards_carry_the_drawn_illustrations_at_10px_corners(): void
    {
        $overview = file_get_contents(resource_path('views/livewire/dashboard/overview.blade.php'));
        $mobile = substr($overview, strpos($overview, 'MOBILE VIEW'), strpos($overview, 'lg:grid-cols-12') - strpos($overview, 'MOBILE VIEW'));

        $this->assertStringContainsString('class="group flex flex-col rounded-[10px] bg-[#ffffff] p-5 dash-shimmer', $mobile);
        $this->assertStringContainsString('class="no-dark-invert h-10 w-10 object-contain" loading="lazy">', $mobile);
        $this->assertStringContainsString('block text-[14px] font-bold leading-tight text-zinc-900 dark:text-white">{{ $label }}', $mobile);
        $this->assertStringContainsString('block text-[14px] leading-snug text-zinc-500 dark:text-zinc-400">{{ $blurb }}', $mobile);
        // The heading: black in light mode and a literal 499 (every weight
        // utility resolves to 399 in app.css, so font-bold would not do).
        // relative z-10: the heading paints above the wallet band that runs
        // under it in the dark theme.
        $this->assertStringContainsString('<h3 id="purchase-heading" class="relative z-10 px-1 text-[14px] font-[499] uppercase tracking-[0.16em] text-black dark:text-zinc-400">Purchase</h3>', $mobile);
        // The old coloured icon tiles are gone with their line icons.
        $this->assertStringNotContainsString('brightness-0 invert', $mobile);
        $this->assertStringNotContainsString('bg-teal-500', $mobile);

        foreach (['bills', 'airtime', 'esim', 'giftcards'] as $file) {
            $this->assertStringContainsString("'purchase/{$file}.svg'", $mobile);
            $this->assertFileExists(public_path("assets/purchase/{$file}.svg"));
        }

        $skeleton = file_get_contents(resource_path('views/components/skeletons/dashboard-overview.blade.php'));
        $this->assertStringContainsString('<div class="{{ $card }} rounded-[10px]" style="--i: {{ $i }}">', $skeleton);
        $this->assertStringContainsString('<x-skeleton class="h-10 w-10" />', $skeleton);

        $html = $this->page();
        foreach (['bills', 'airtime', 'esim', 'giftcards'] as $file) {
            $this->assertStringContainsString("assets/purchase/{$file}.svg", $html);
        }
    }
}
