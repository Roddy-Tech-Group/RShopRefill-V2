<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Desktop dashboard shell: a full-width top bar on the shell colour carrying
 * the logo, and one rounded panel beneath it holding the sidebar and the page,
 * with the page scrolling inside the panel. Style only; the sidebar and header
 * content are unchanged.
 */
class DashboardFrameLayoutTest extends TestCase
{
    use RefreshDatabase;

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
        return file_get_contents(resource_path('views/components/layouts/dashboard.blade.php'));
    }

    /** The rendered top bar, from its tag to its close. */
    private function header(string $html): string
    {
        // Tag marker, not the bare attribute name, which also appears in the
        // layout's <style> block ahead of the element.
        $start = strpos($html, 'data-flux-header>');
        $this->assertNotFalse($start, 'The top bar did not render.');

        return substr($html, $start, strpos($html, '</header>', $start) - $start);
    }

    public function test_the_logo_sits_in_the_top_bar_not_the_sidebar(): void
    {
        $html = $this->page();

        $sidebarStart = strpos($html, '<ui-sidebar');
        $this->assertNotFalse($sidebarStart, 'The sidebar did not render.');
        $sidebar = substr($html, $sidebarStart, strpos($html, '</ui-sidebar>', $sidebarStart) - $sidebarStart);

        $header = $this->header($html);

        // Only the R mark: no wordmark, no "Est. 2024" tagline.
        $this->assertStringNotContainsString('assets/PWAicon.webp', $sidebar);
        $this->assertStringContainsString('assets/PWAicon.webp', $header);
        $this->assertStringNotContainsString('assets/logo.webp', $header);
        $this->assertStringNotContainsString('Est. 2024', $header);
    }

    public function test_the_page_scrolls_inside_the_panel_not_the_window(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString('grid-template-areas: "header header header header" "sidebar subnav main aside" "sidebar subnav footer aside" !important;', $layout);
        $this->assertStringContainsString('grid-template-rows: 72px minmax(0, 1fr) 0 !important;', $layout);
        $this->assertMatchesRegularExpression('/html body\.dashboard > \[data-flux-sidebar\] \{[^}]*border-start-start-radius: 24px;/', $layout);

        // The rounded pane clips and never scrolls; the page wrapper inside it
        // scrolls, so its bar cannot poke past the pane's rounded corners.
        $this->assertMatchesRegularExpression('/body\.dashboard > \[data-flux-main\] \{[^}]*overflow: hidden;[^}]*border-start-end-radius: 24px;/', $layout);
        $this->assertMatchesRegularExpression('/body\.dashboard > \[data-flux-main\] \[data-page-content\] \{[^}]*min-height: 0 !important;[^}]*overflow-y: auto;/', $layout);

        // The ring-and-mask version is gone.
        $this->assertStringNotContainsString('dash-frame', $layout);

        // Dropdowns still close when the page scrolls: the scrolling wrapper re-announces its scroll on window.
        $this->assertStringContainsString('<div data-page-content x-on:scroll.passive="window.dispatchEvent(new Event(\'scroll\'))"', $layout);
        $this->assertStringNotContainsString('<flux:main x-data x-on:scroll', $layout);
    }

    /** Pages sit in a centred column capped at 1400px, so only very wide screens show side space. */
    public function test_pages_are_a_centred_column_not_full_width(): void
    {
        $this->assertStringContainsString('<div class="w-full min-w-0 lg:mx-auto lg:max-w-[1400px] lg:flex-1">', $this->layout());
    }

    public function test_the_sidebar_is_a_grey_pane_with_compact_rows(): void
    {
        $layout = $this->layout();

        preg_match('/<flux:sidebar[^>]*>/', $layout, $m);
        $this->assertNotEmpty($m, 'Could not find the sidebar tag.');

        $this->assertStringContainsString('bg-[#eaeeec]', $m[0]);
        $this->assertStringNotContainsString('shadow-md', $m[0]);
        $this->assertStringNotContainsString('style=', $m[0]);
        // Compact rows (~32px, 14px labels, 18px icons, 8px corners).
        //
        // The active row carried `bg-[#ffffff] … dark:bg-black!` here until the
        // active and hover states were merged into one treatment. Both now take
        // their fill from `.nav-item-active` / `.nav-item:hover` in app.css, so
        // the geometry is asserted and the colour is left to the stylesheet.
        $this->assertStringContainsString('rounded-[8px] px-2.5 py-2 text-sm font-medium', $layout);
        $this->assertStringNotContainsString('bg-[#ffffff] px-2.5 py-2', $layout);
        $this->assertStringContainsString('<x-ui.anim-icon file="order.svg" tone="inherit" class="h-[18px] w-[18px] text-[#0c1337] dark:text-zinc-200" />', $layout);
        $this->assertStringContainsString('w-[256px]', $m[0]);
    }

    public function test_light_mode_shell_is_deep_blue_behind_the_panel(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString('body.dashboard { --dash-shell: #0c1337;', $layout);
        $this->assertStringContainsString('html.dark body.dashboard { --dash-shell: #000000;', $layout);
        $this->assertMatchesRegularExpression('/html\.dark:root body\.dashboard,\s*html\.dark:root body\.dashboard > \[data-flux-header\] \{\s*background-color: #000000 !important;/', $layout);
        $this->assertMatchesRegularExpression('/<flux:header class="[^"]*\bbg-\[#0c1337\][^"]*dark:bg-\[#0c1a36\]/', $layout);
    }

    public function test_search_is_a_round_ringed_button_that_opens_the_panel(): void
    {
        $header = $this->header($this->page());

        // Same outline as the cart beside it.
        $this->assertStringContainsString('h-9 w-9 items-center justify-center rounded-full border-2 border-white/30 text-white', $header);
        $this->assertStringNotContainsString('border-[#a5b4fc]', $header);
        $this->assertStringContainsString('aria-label="Search products, brands or categories"', $header);
        // The field now lives inside the dropdown panel, and the panel still carries its sections.
        $this->assertStringContainsString('name="dashboard_search"', $header);
        $this->assertStringContainsString('Most used', $header);
        $this->assertStringNotContainsString('max-w-2xl', $header);

        // Search sits in the right-hand group, before the cart.
        $this->assertLessThan(strpos($header, 'aria-label="Shopping cart"'), strpos($header, 'name="dashboard_search"'));
    }

    public function test_top_bar_buttons_are_round_white_outlines(): void
    {
        $layout = $this->layout();
        $bell = file_get_contents(resource_path('views/livewire/notifications-menu.blade.php'));

        $this->assertStringContainsString('rounded-full border-2 border-white/30 text-white transition-colors hover:bg-white/15', $layout);
        $this->assertStringContainsString("'dashboard-notification-chip' => \$tone === 'chip'", $bell);
        // The bell carries the same ring in both themes. It used to drop to
        // white/10 in dark, but the bar it sits on is navy in light and forced
        // to pure black in dark, so that override ran the wrong way and the
        // ring all but vanished on black.
        $this->assertStringContainsString('rounded-full border-2 border-white/30 text-white hover:bg-white/15 dark:border-white/30', $bell);
        $this->assertStringNotContainsString('hover:bg-blue-600 hover:text-white', $bell);
    }

    /** Search, bell and avatar match the cart's 36px footprint. */
    public function test_top_bar_buttons_are_36px(): void
    {
        $header = $this->header($this->page());
        $bell = file_get_contents(resource_path('views/livewire/notifications-menu.blade.php'));

        $this->assertStringContainsString('dashboard-avatar-button relative flex h-9 w-9', $header);
        $this->assertStringContainsString('h-9 w-9 rounded-full border-2 border-white/30', $bell);
        $this->assertStringContainsString("'h-10 w-10' => \$tone !== 'chip'", $bell);
    }

    /** The wallpaper paints the page pane only, under a readability veil; the sidebar is untouched. */
    public function test_the_content_pane_wears_the_wallpaper_not_the_sidebar(): void
    {
        $layout = $this->layout();

        $this->assertMatchesRegularExpression("/html:root:not\(\.dark\) body\.dashboard > \[data-flux-main\] \{\s*background-image: url\('\{\{ \\\$wallpaperUrl\(\\\$dashWallpaperLight\)/", $layout);
        // Dark wears no photograph: the dotted world map in its own navy, over
        // the grain. It is drawn once, never tiled, and larger than it was so the
        // dots read as dots rather than as a texture.
        $this->assertMatchesRegularExpression(
            '/html:root\.dark body\.dashboard > \[data-flux-main\] \{\s*background-color: #1e1e25 !important;\s*'
            .'background-image: url\(\'\{\{ \$wallpaperUrl\(\'assets\/dotted-world-map\.svg\'\) \}\}\'\), var\(--grain\) !important;\s*'
            .'background-size: min\(2000px, 125%\) auto, 220px 220px !important;\s*'
            .'background-position: center center, 0 0 !important;\s*'
            .'background-repeat: no-repeat, repeat !important;/',
            $layout
        );
        $this->assertFileExists(public_path('assets/dotted-world-map.svg'));
        // Phones carry the same map on the panel, since every pane rule above
        // sits inside the 1024px query and did not exist below it.
        $this->assertMatchesRegularExpression('/html\.dark \.dash-mobile-panel \{[^}]*dotted-world-map\.svg/s', $layout);
        $this->assertStringNotContainsString('dashWallpaperDark', $layout);
        $this->assertMatchesRegularExpression('/html:not\(\.dark\) body\.dashboard > \[data-flux-main\] \[data-page-content\] \{\s*background-color: var\(--dash-wallpaper-veil\) !important;/', $layout);
        $this->assertDoesNotMatchRegularExpression('/\[data-flux-sidebar\][^{]*\{[^}]*wallpaper/', $layout);
    }

    /**
     * Each theme renders its own file when it exists, and nothing when it does not
     * (so no request for a missing image).
     */
    public function test_each_theme_uses_its_own_wallpaper_file_when_present(): void
    {
        $find = fn (array $names) => collect($names)
            ->crossJoin(['webp', 'jpg', 'jpeg', 'png', 'avif'])
            ->map(fn ($pair) => 'assets/'.$pair[0].'.'.$pair[1])
            ->first(fn ($path) => is_file(public_path($path)));

        $html = $this->page();

        // Light only: dark mode takes no wallpaper file any more.
        foreach ([
            'light' => [['dashboard-wallpaper-light-mode', 'dashboard-wallpaper'], 'html:root:not(.dark) body.dashboard > [data-flux-main]'],
        ] as $theme => [$names, $selector]) {
            $file = $find($names);

            if ($file) {
                $this->assertStringContainsString($selector, $html, "The {$theme} wallpaper rule did not render.");
                $this->assertStringContainsString($file.'?v=', $html);
            } else {
                $this->assertStringNotContainsString($selector, $html, "A {$theme} wallpaper rule rendered with no file.");
            }
        }
    }

    /** Every text badge on a sidebar row wears the top bar's dark blue. */
    public function test_sidebar_badges_are_dark_blue(): void
    {
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);
        $this->withoutVite();
        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $sidebarStart = strpos($html, '<ui-sidebar');
        $sidebar = substr($html, $sidebarStart, strpos($html, '</ui-sidebar>', $sidebarStart) - $sidebarStart);

        $tone = 'bg-[#26358c] text-white';
        $this->assertMatchesRegularExpression('/class="dash-row-badge [^"]*'.preg_quote($tone, '/').'">KYC<\/span>/', $sidebar);
        $this->assertMatchesRegularExpression('/class="dash-row-badge [^"]*'.preg_quote($tone, '/').'">Earn<\/span>/', $sidebar);

        // The old per-badge tints (the Need Help modal in the sidebar keeps its own emerald/blue pills).
        foreach (['text-amber-700 ring-1 ring-amber-200', 'text-emerald-700 ring-1 ring-emerald-200', 'text-red-700 ring-1 ring-red-200'] as $old) {
            $this->assertStringNotContainsString($old, $this->layout());
        }
    }

    /** Overview "View all" links wear the Edit profile button's grey pill. */
    public function test_view_all_links_match_the_edit_profile_button(): void
    {
        $pill = 'rounded-[10px] bg-zinc-100 px-4 py-1.5 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-200 active:scale-95 dark:bg-white/10 dark:text-zinc-200 dark:hover:bg-white/15';

        $overview = file_get_contents(resource_path('views/livewire/dashboard/overview.blade.php'));
        $profile = file_get_contents(resource_path('views/livewire/settings/profile.blade.php'));

        $this->assertStringContainsString($pill, $profile, 'The Edit profile button changed; update the View all pills to match.');
        $this->assertSame(3, substr_count($overview, $pill.'">View all</a>'));
        $this->assertStringNotContainsString('ring-inset ring-blue-200 transition-colors hover:bg-[#e8ecea]', $overview);
    }

    /**
     * The scrollbar belongs to the page wrapper, whose own background (the
     * wallpaper veil) paints under it, and its track stops short of the corners.
     */
    public function test_the_scrollbar_sits_on_the_veiled_page_wrapper(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString('body.dashboard > [data-flux-main] [data-page-content]::-webkit-scrollbar { display: block; width: 8px; background: transparent; }', $layout);
        $this->assertStringContainsString('margin-top: 24px; margin-bottom: 24px;', $layout);
        $this->assertStringContainsString('html:not(.dark) body.dashboard { --dash-wallpaper-veil:', $layout);
        $this->assertStringNotContainsString('body.dashboard > [data-flux-main]::-webkit-scrollbar', $layout);
    }

    public function test_the_overview_greeting_subtitle_is_dark_blue(): void
    {
        $overview = file_get_contents(resource_path('views/livewire/dashboard/overview.blade.php'));

        $this->assertStringContainsString('<p class="mt-1 text-sm text-[#0c1337] dark:text-zinc-300">Happy shopping, great Rcoin cashback rewards.</p>', $overview);
    }

    /** The desktop bell remains available beside the account control. */
    public function test_the_top_bar_has_a_notification_bell(): void
    {
        $this->assertStringContainsString('<livewire:notifications-menu tone="chip" wire:key="header-notif" />', $this->layout());
        $bell = file_get_contents(resource_path('views/livewire/notifications-menu.blade.php'));
        $this->assertStringNotContainsString("'hidden' => \$this->unreadCount < 1", $bell);
    }

    /**
     * The avatar opens a grouped account menu: identity header, account rows,
     * settings rows (Notifications with its count), help, the preference
     * switches that replaced the overview's Customize panel, then Log out.
     */
    public function test_the_account_menu_is_grouped_with_preference_switches(): void
    {
        $user = User::factory()->create(['name' => 'Menu Person', 'email' => 'menu.person@example.com', 'kyc_status' => 'unsubmitted']);
        $this->withoutVite();
        $header = $this->header($this->actingAs($user)->get('/dashboard')->assertOk()->getContent());

        $avatarStart = strpos($header, 'dashboard-avatar-button');
        $this->assertNotFalse($avatarStart);
        $avatar = substr($header, $avatarStart, strpos($header, '</button>', $avatarStart) - $avatarStart);
        $this->assertStringContainsString('data-flux-icon', $avatar);
        $this->assertStringNotContainsString('<img', $avatar);

        $menuStart = strpos($header, 'dashboard-account-menu');
        $this->assertNotFalse($menuStart, 'The account menu did not render.');
        $menu = substr($header, $menuStart);

        $this->assertStringContainsString('Menu Person', $menu);
        $this->assertStringContainsString('menu.person@example.com', $menu);

        $last = -1;
        foreach (['>Profile<', '>Verify Identity<', '>For Partners<', '>Security<', '>Notifications<', '>Appearance<', '>Preferences<', 'Help &amp; support', '>Back to store<', '>Dark mode<', 'Log out'] as $row) {
            $at = strpos($menu, $row);
            $this->assertNotFalse($at, $row.' is missing from the account menu.');
            $this->assertGreaterThan($last, $at, $row.' is out of order.');
            $last = $at;
        }

        $this->assertStringContainsString("window.rshopThemeReveal(\$event, dark ? 'light' : 'dark')", $menu);
        // Hide balances and Compact mode left the menu on request (2026-09-23).
        $this->assertStringNotContainsString('$store.dashPrefs.setHideBalance(', $menu);
        $this->assertStringNotContainsString('$store.dashPrefs.setCompactMode(', $menu);
        $this->assertSame(5, substr_count($menu, '<div class="border-t border-zinc-200/80'), 'Five edge-to-edge dividers split the six groups.');

        // The identity and grouped rows retain their original order and links.
        $this->assertStringContainsString('<div class="px-4 pb-3 pt-4">', $menu);
        $this->assertSame(5, substr_count($menu, '<div class="py-1.5">'));
        $this->assertStringContainsString('dashboard-account-row flex w-full items-center justify-between', $menu);
    }

    /** Sidebar and account menu icons wear the dark blue in light mode. */
    public function test_sidebar_and_menu_icons_are_dark_blue(): void
    {
        $layout = $this->layout();

        foreach (['Shop.svg', 'order.svg', 'Wallet.svg', 'transactions.svg', 'admin access.svg', 'customer.svg', 'for-partners.svg'] as $file) {
            $this->assertStringContainsString('<x-ui.anim-icon file="'.$file.'" tone="inherit" class="h-[18px] w-[18px] text-[#0c1337] dark:text-zinc-200" />', $layout, $file.' is not dark blue in the sidebar.');
        }

        $this->assertStringContainsString("\$menuIcon = 'dashboard-account-row-icon flex items-center gap-3 text-[#0c1337] dark:text-zinc-300';", $layout);
        $this->assertStringContainsString("? 'h-[18px] w-[18px] shrink-0 text-[#0c1337] dark:text-white'", $layout);
        $this->assertStringContainsString('<x-ui.anim-icon file="support.svg" tone="inherit" class="h-[18px] w-[18px]" />', $layout);
    }

    /**
     * Beside the R mark, a pill rotates every 5 seconds between "Refer & earn up
     * to $N" (figure from config) and "Earn extra as a partner".
     */
    public function test_the_top_bar_promo_pill_rotates_rewards_and_partner(): void
    {
        config()->set('dashboard.earn_up_to_usd', 100);
        $header = $this->header($this->page());

        $this->assertStringContainsString('Refer &amp; earn up to $100', $header);
        $this->assertStringContainsString('href="'.route('dashboard.rewards').'"', $header);
        $this->assertStringContainsString('Become a partner', $header);
        // The pill opens the Partnership program tab of For Partners, not the public affiliates page.
        $this->assertStringContainsString('href="'.route('dashboard.rewards', ['tab' => 'partners']).'"', $header);
        $this->assertStringNotContainsString('href="'.route('shop.affiliates').'"', $header);
        $this->assertStringContainsString('5000', $header);

        // The swap is a slow, smooth swipe up inside the clipped pill: the old
        // message leaves through the top, the next rises from below.
        // hidden below lg: phones show the gift chip instead of the rotating pill.
        $this->assertStringContainsString('dash-promo-pill relative hidden h-8 items-center overflow-hidden rounded-full lg:grid', $header);
        $this->assertStringContainsString('x-transition:enter="transition duration-700 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none"', $header);
        $this->assertStringContainsString('x-transition:enter-start="translate-y-full opacity-0"', $header);

        // The referral pill is blue, the partner pill green.
        $this->assertStringContainsString('border-[#3d6bff] bg-[#0044ff]/30 group-hover:bg-[#0044ff]/45 inline-flex', $header);
        $this->assertStringContainsString('border-emerald-500 bg-emerald-600/30 group-hover:bg-emerald-600/45 inline-flex', $header);
        $this->assertStringContainsString('x-transition:leave-end="-translate-y-full opacity-0"', $header);
        $this->assertLessThan(strpos($header, 'Become a partner'), strpos($header, 'Refer &amp; earn up to $100'));
    }

    /** Clicking "Refer & earn" pulls down a panel with the referral link and its Copy button. */
    public function test_the_refer_pill_opens_a_referral_link_panel(): void
    {
        config()->set('dashboard.earn_up_to_usd', 100);
        $user = User::factory()->create(['name' => 'Ada Referrer', 'referral_code' => 'adaref']);
        $this->withoutVite();
        $header = $this->header($this->actingAs($user)->get('/dashboard')->assertOk()->getContent());

        $this->assertStringContainsString('@click="referOpen = ! referOpen"', $header);
        $this->assertStringContainsString('x-show="referOpen"', $header);
        $this->assertStringContainsString('Refer friends, earn up to $100 in Rcoin', $header);
        // The shared referral-code component: the link and its Copy button.
        $this->assertStringContainsString(rtrim(url('/'), '/').'/?ref=adaref', $header);
        $this->assertStringContainsString("copied ? 'Copied' : 'Copy'", $header);
        // Rotation holds while the panel is open.
        $this->assertStringContainsString('if (! this.paused && ! this.referOpen)', $header);
    }

    /** With the figure set to 0 the rewards message drops out and only the partner one remains. */
    public function test_the_promo_pill_hides_the_rewards_message_without_a_figure(): void
    {
        config()->set('dashboard.earn_up_to_usd', 0);
        $header = $this->header($this->page());

        // Without a campaign figure the pill drops the dollar claim, it does not vanish.
        $this->assertStringContainsString('Refer &amp; earn Rcoin', $header);
        $this->assertStringNotContainsString('earn up to $', $header);
        $this->assertStringContainsString('Become a partner', $header);
    }

    /** A language + currency pill sits right before the search button and opens the locale sheet. */
    public function test_the_top_bar_has_a_language_and_currency_pill_before_search(): void
    {
        $header = $this->header($this->page());

        $pill = strpos($header, 'x-text="currency"');
        $search = strpos($header, 'name="dashboard_search"');

        $this->assertNotFalse($pill, 'The language and currency pill is missing.');
        $this->assertLessThan($search, $pill);
        $this->assertStringContainsString("@click=\"\$dispatch('open-locale-modal')\"", $header);
        $this->assertStringContainsString('EN / <span x-text="currency">USD</span>', $header);
        $this->assertStringContainsString("localStorage.getItem('locale.currency')", $header);
    }

    /** Collapsed rail: hovering an icon shows its name in a fixed label the rail cannot clip. */
    public function test_the_collapsed_rail_shows_link_names_on_hover(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString('.dash-rail-tip.is-visible', $layout);
        // Speech-bubble pointer aimed at the icon.
        $this->assertMatchesRegularExpression('/\.dash-rail-tip::before \{[^}]*left: -4px;[^}]*background-color: inherit;[^}]*rotate\(45deg\)/', $layout);
        // Links and the Shop button alike: the Shop row became a button when it
        // learned to drop its categories down.
        $this->assertStringContainsString("target.closest('[data-flux-sidebar] nav :is(a, button)[data-tip]')", $layout);
        $this->assertStringContainsString("classList.contains('dashboard-sidebar-collapsed')", $layout);
        // The clipped ::after version is gone.
        $this->assertStringNotContainsString('a[data-tip]:hover::after', $layout);
    }

    /**
     * Theme switch from the account menu opens the new theme as a circle from
     * the switch, the same way for dark and light.
     */
    public function test_the_theme_switch_reveals_as_a_circle_from_the_switch(): void
    {
        $app = file_get_contents(resource_path('js/app.js'));
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('window.rshopThemeReveal = function (event, choice)', $app);
        $this->assertStringContainsString('document.startViewTransition(apply)', $app);
        $this->assertStringContainsString("pseudoElement: '::view-transition-new(root)'", $app);
        $this->assertStringContainsString('circle(0px at ${x}px ${y}px)', $app);
        $this->assertMatchesRegularExpression('/::view-transition-old\(root\),\s*::view-transition-new\(root\) \{\s*animation: none;/', $css);
        $this->assertStringContainsString('html.theme-switching *', $css);
    }

    /**
     * The overview desktop grid is plain rows in reading order: the heading, then
     * wallet | Recent Orders | Rcoin in one row (three across from xl, one shared
     * height), then Quick Actions beside promo + Recent Transactions, where the
     * right stack adds no height so Recent Transactions ends with that card.
     */
    public function test_the_overview_cards_sit_in_plain_ordered_rows(): void
    {
        $overview = file_get_contents(resource_path('views/livewire/dashboard/overview.blade.php'));

        $this->assertStringContainsString('sm:justify-between lg:col-span-12">', $overview);
        $this->assertStringContainsString('<div class="grid grid-cols-1 gap-6 lg:col-span-12 lg:grid-cols-3">', $overview);
        // The row never reflows as the window narrows: no breakpoint changes its column count or spans.
        $this->assertStringNotContainsString('lg:grid-cols-2 xl:grid-cols-3', $overview);
        $this->assertStringContainsString('<div class="flex min-w-0 flex-col rounded-[15px]', $overview);
        $this->assertStringContainsString('shadow-md shadow-zinc-900/[0.06] lg:col-span-8 dark:shadow-none', $overview);
        $this->assertStringContainsString('<div class="flex flex-col gap-6 lg:col-span-4 lg:min-h-0 lg:[contain:size]">', $overview);
        $this->assertStringContainsString('lg:max-h-none lg:min-h-0 lg:flex-1', $overview);

        // No shared-track (subgrid) layout any more.
        $this->assertStringNotContainsString('grid-rows-subgrid', $overview);

        // Reading order: wallet, Recent Orders, Rcoin, Quick Actions, promo, Recent Transactions.
        // Checked on the markup alone: a Blade comment naming a card must not
        // count as the card.
        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $overview);
        $last = -1;
        foreach (['Total Wallet Balance', 'Recent Orders', 'RShop Rcoin', 'Quick Actions', 'Give the Perfect Gift', 'Recent Transactions'] as $label) {
            $at = strpos($markup, $label, strpos($markup, 'lg:grid-cols-12'));
            $this->assertNotFalse($at, $label.' is missing from the desktop overview.');
            $this->assertGreaterThan($last, $at, $label.' is out of order.');
            $last = $at;
        }

        // Balanced markup.
        $template = preg_replace('/\{\{--.*?--\}\}/s', '', $overview);
        $this->assertSame(substr_count($template, '<div'), substr_count($template, '</div>'));
    }

    /** The account menu opens on click only, never on hover. */
    public function test_the_account_menu_opens_on_click_not_hover(): void
    {
        $header = $this->header($this->page());

        $this->assertStringContainsString('@click="locked = !locked; open = locked"', $header);
        $this->assertStringNotContainsString('@mouseenter="if (!locked) open = true"', $header);
        $this->assertStringNotContainsString('@mouseleave="if (!locked) open = false"', $header);
    }

    /** The overview's Customize button (and its skeleton stand-in) is gone. */
    public function test_the_overview_has_no_customize_button(): void
    {
        $overview = file_get_contents(resource_path('views/livewire/dashboard/overview.blade.php'));
        $skeleton = file_get_contents(resource_path('views/components/skeletons/dashboard-overview.blade.php'));

        $this->assertStringNotContainsString('Customize', $overview);
        $this->assertStringNotContainsString('window.setTheme', $overview);
        $this->assertStringNotContainsString('<x-skeleton class="h-9 w-28" rounded="rounded-[12px]" />', $skeleton);
    }

    /** The legal / version / copyright footer line was removed from the dashboard. */
    public function test_the_dashboard_has_no_footer_line(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString('version 2.0.0', $html);
        $this->assertStringNotContainsString('href="'.route('shop.refund-policy').'"', $html);
    }

    /**
     * No empty strip left of the sidebar: the gutter is reserved on the right
     * only. That is the global rule now (the whole site dropped both-edges), so
     * the dashboard needs no rule of its own and none may bring both-edges back.
     */
    public function test_the_dashboard_drops_the_left_scrollbar_gutter(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\nhtml \{[^}]*scrollbar-gutter: stable;/', $css);
        $this->assertStringNotContainsString('both-edges;', $css);
    }

    /**
     * Chromium draws arrow buttons on its native bar whenever scrollbar-width or
     * scrollbar-color is set, so those stay auto outside the Firefox-only block.
     */
    public function test_scrollbars_have_no_arrow_buttons(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $layout = $this->layout();

        $this->assertMatchesRegularExpression('/html,\s*body \{\s*scrollbar-width: auto;\s*scrollbar-color: auto;\s*\}/', $css);
        $this->assertMatchesRegularExpression('/@supports not selector\(::-webkit-scrollbar\) \{\s*html,\s*body \{\s*scrollbar-width: thin;/', $css);
        $this->assertStringContainsString('body::-webkit-scrollbar-button', $css);
        $this->assertStringContainsString('body.dashboard > [data-flux-main] [data-page-content]::-webkit-scrollbar-button { display: none; }', $layout);
    }
}
