<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\Languages::direction() }}">
    <head>
        @include('partials.head')
    </head>
    {{-- Fold the rail before first paint, so a reload never shows the full
         sidebar snapping shut. Mirrors the customer dashboard, which reads its
         own key the same way; the store in app.js takes over once Alpine
         starts and owns every write to this key. --}}
    <script>
        (function () {
            var saved = false;
            try { saved = localStorage.getItem('admin.sidebar.collapsed') === '1'; } catch (e) {}
            if (saved) { document.documentElement.classList.add('admin-sidebar-collapsed'); }
        })();
    </script>
    {{-- Desktop sidebar-collapse styling. The 'admin-sidebar-collapsed' class
         on <html> is toggled by the round chevron in the header — when set,
         the Flux sidebar shrinks to an icon-only rail and every text label
         hides. We zero out font-size on the nav items to hide raw text nodes
         without rewriting the markup; <svg> and <img> have explicit sizes so
         they keep rendering. Mobile is unaffected (stash already covers it). --}}
    <style>
        /* Slide-out drawers (e.g. the product details panel) dim the page content,
           but the desktop sidebar must stay bright and usable, so lift it above the
           drawer's z-50 backdrop. Mobile is unaffected (the sidebar stashes away). */
        @media (min-width: 1024px) {
            /* `html` prefix raises specificity above Flux's own `z-20!` so this
               actually wins — otherwise the z-40 header covers the collapse arrow
               that straddles the sidebar's right edge. */
            html [data-flux-sidebar] { z-index: 60 !important; }
        }

        @media (min-width: 1024px) {
            html.admin-sidebar-collapsed [data-flux-sidebar] {
                width: 72px !important;
            }
            /* Nav itself stays scrollable when expanded (long lists need it),
               but in collapsed mode we force overflow visible — otherwise the
               flyout submenu popups get clipped at the nav's right edge.
               Icon-only rail never needs to scroll the labels anyway. */
            html.admin-sidebar-collapsed [data-flux-sidebar] nav {
                overflow: visible !important;
            }
            html.admin-sidebar-collapsed [data-flux-sidebar] nav a,
            html.admin-sidebar-collapsed [data-flux-sidebar] nav button {
                font-size: 0 !important;
                justify-content: center !important;
                padding-left: 0.5rem !important;
                padding-right: 0.5rem !important;
                gap: 0 !important;
            }
            /* Group items have an inner <span> wrapping the icon + label.
               That wrapper has its own gap/flex layout — zero it out so the
               icon sits dead-centre when the label is hidden, matching the
               alignment of single-link items. */
            html.admin-sidebar-collapsed [data-flux-sidebar] nav button > span,
            html.admin-sidebar-collapsed [data-flux-sidebar] nav a > span {
                gap: 0 !important;
                justify-content: center !important;
                margin: 0 auto !important;
            }
            html.admin-sidebar-collapsed [data-flux-sidebar] nav svg,
            html.admin-sidebar-collapsed [data-flux-sidebar] nav img {
                margin: 0 auto !important;
            }
            /* Expandable groups — hide chevron in collapsed mode. */
            html.admin-sidebar-collapsed [data-flux-sidebar] nav button > svg:last-child {
                display: none !important;
            }

            /* Collapsed-only: grey hover instead of the loud blue from the
               project's primary nav-item class. Keeps icons readable and the
               rail calm. Icons aren't whitened on hover (filter: none) since
               the bg is grey now, not blue. */
            html.admin-sidebar-collapsed [data-flux-sidebar] nav a:hover,
            html.admin-sidebar-collapsed [data-flux-sidebar] nav button:hover {
                background-color: rgba(15, 23, 42, 0.06) !important;
                color: #18181b !important;
            }
            html.dark.admin-sidebar-collapsed [data-flux-sidebar] nav a:hover,
            html.dark.admin-sidebar-collapsed [data-flux-sidebar] nav button:hover {
                background-color: rgba(255, 255, 255, 0.06) !important;
                color: #ffffff !important;
            }
            html.admin-sidebar-collapsed [data-flux-sidebar] nav a:hover img,
            html.admin-sidebar-collapsed [data-flux-sidebar] nav button:hover img {
                filter: none !important;
            }

            /* Label tooltip — floats to the right of the icon on hover when
               collapsed. Single nav items only; group items (Products, Content)
               already show their own popup via .nav-submenu so we skip them. */
            html.admin-sidebar-collapsed [data-flux-sidebar] nav a {
                position: relative;
            }
            html.admin-sidebar-collapsed [data-flux-sidebar] nav a[data-tip]:hover::after {
                content: attr(data-tip);
                position: absolute;
                left: calc(100% + 0.625rem);
                top: 50%;
                transform: translateY(-50%);
                background: #18181b;
                color: #ffffff;
                font-size: 0.75rem;
                font-weight: 500;
                padding: 0.5rem 0.75rem;
                border-radius: 8px;
                white-space: nowrap;
                pointer-events: none;
                box-shadow: 0 10px 25px -8px rgba(0, 0, 0, 0.4);
                z-index: 70;
            }
            html.dark.admin-sidebar-collapsed [data-flux-sidebar] nav a[data-tip]:hover::after {
                background: #ffffff;
                color: #18181b;
            }

            /* Brand swap: full wordmark hidden, favicon shown, when collapsed.
               Inline Tailwind `hidden` on .brand-mark needs an explicit
               !important override since it's `display: none`. */
            html:not(.admin-sidebar-collapsed) [data-flux-sidebar] .brand-mark { display: none !important; }
            html.admin-sidebar-collapsed [data-flux-sidebar] .brand-full { display: none !important; }
            html.admin-sidebar-collapsed [data-flux-sidebar] .brand-mark { display: flex !important; }
            html.admin-sidebar-collapsed [data-flux-sidebar] > a { margin-right: 0 !important; }

            /* Collapsed brand favicon ships blue (correct on the light sidebar);
               recolor it to white in dark mode for contrast on the dark sidebar. */
            html.dark [data-flux-sidebar] .brand-mark img { filter: brightness(0) invert(1); }

            /* Sidebar collapse arrow: hidden until the sidebar is hovered (or the
               button is keyboard-focused), so it only appears when wanted. */
            @media (min-width: 1024px) {
                .sidebar-collapse-toggle { opacity: 0; }
                [data-flux-sidebar]:hover .sidebar-collapse-toggle,
                .sidebar-collapse-toggle:focus-visible { opacity: 1; }
            }

            /* Collapsed: inline sub-menus disappear inline AND reappear as a
               polished floating popup on hover, positioned to the right of
               the icon with a 12px radius matching the project standard for
               larger surfaces. Soft drop shadow + 1px hairline ring. */
            html.admin-sidebar-collapsed [data-flux-sidebar] .nav-group {
                position: relative;
            }
            html.admin-sidebar-collapsed [data-flux-sidebar] .nav-submenu {
                position: absolute !important;
                left: 100% !important;
                top: 0 !important;
                margin-left: 0.875rem !important;
                width: 220px !important;
                padding: 0.5rem !important;
                background: white !important;
                border-radius: 12px !important;
                box-shadow:
                    0 20px 45px -12px rgba(15, 23, 42, 0.28),
                    0 0 0 1px rgba(15, 23, 42, 0.06) !important;
                z-index: 60 !important;
                display: none !important;
                margin-top: 0 !important;
                border-left: 0 !important;
                padding-left: 0.5rem !important;
                gap: 0.125rem !important;
            }
            /* Dark-mode popup: deeper than the sidebar so it visually lifts,
               with a thin highlight ring + rich shadow for depth. */
            html.dark.admin-sidebar-collapsed [data-flux-sidebar] .nav-submenu {
                background: #0c1a36 !important;
                box-shadow:
                    0 24px 50px -12px rgba(0, 0, 0, 0.75),
                    0 0 0 1px rgba(255, 255, 255, 0.07) !important;
            }
            html.admin-sidebar-collapsed [data-flux-sidebar] .nav-submenu::before {
                /* Invisible bridge between the icon and the popup so the
                   pointer can travel without losing :hover state mid-gap. */
                content: '';
                position: absolute;
                left: -0.875rem;
                top: 0;
                width: 0.875rem;
                height: 100%;
            }
            html.admin-sidebar-collapsed [data-flux-sidebar] .nav-submenu a,
            html.admin-sidebar-collapsed [data-flux-sidebar] .nav-submenu button {
                font-size: 0.8125rem !important;
                padding: 0.625rem 0.875rem !important;
                justify-content: flex-start !important;
                text-align: left !important;
                gap: 0.75rem !important;
                border-radius: 12px !important;
            }
            /* Parent rail rule auto-margins every nav img/svg to centre them
               in the icon-only column; that leaks into the submenu and pushes
               the label to the opposite side. Pin it back to 0 here. */
            html.admin-sidebar-collapsed [data-flux-sidebar] .nav-submenu img,
            html.admin-sidebar-collapsed [data-flux-sidebar] .nav-submenu svg {
                margin: 0 !important;
            }
            /* Dark mode: muted white labels, hover lifts subtly. */
            html.dark.admin-sidebar-collapsed [data-flux-sidebar] .nav-submenu a,
            html.dark.admin-sidebar-collapsed [data-flux-sidebar] .nav-submenu button {
                color: rgba(255, 255, 255, 0.7) !important;
                background: transparent !important;
            }
            html.dark.admin-sidebar-collapsed [data-flux-sidebar] .nav-submenu a:hover,
            html.dark.admin-sidebar-collapsed [data-flux-sidebar] .nav-submenu button:hover {
                background: #070f1c !important;
                color: #ffffff !important;
                font-weight: 600 !important;
            }
            /* Active sub-item: rose accent matches the project's secondary accent.
               Matches whatever class chain the sub-item used for "active" in light mode. */
            html.dark.admin-sidebar-collapsed [data-flux-sidebar] .nav-submenu a.bg-blue-50,
            html.dark.admin-sidebar-collapsed [data-flux-sidebar] .nav-submenu a[class*="text-blue-700"],
            html.dark.admin-sidebar-collapsed [data-flux-sidebar] .nav-submenu .bg-blue-50 {
                background: transparent !important;
                color: #f43f5e !important;
            }
            html.admin-sidebar-collapsed [data-flux-sidebar] .nav-group:hover .nav-submenu,
            html.admin-sidebar-collapsed [data-flux-sidebar] .nav-group:focus-within .nav-submenu {
                /* Override Alpine x-collapse's inline height: 0 + overflow: hidden
                   so the popup can render at its natural size. Without these
                   resets the hover rule only flips `display` but leaves the
                   group height at 0, so nothing visibly appears. */
                display: flex !important;
                height: auto !important;
                overflow: visible !important;
            }

            /* Soften the resize so the rail glides rather than snaps. */
            [data-flux-sidebar] {
                transition: width 220ms cubic-bezier(0.22, 1, 0.36, 1);
            }
            /* Let the collapse toggle button overflow the sidebar's right edge
               so it can sit ON the seam between sidebar and page (half-in,
               half-out). Default Flux overflow clips children. */
            [data-flux-sidebar] {
                overflow: visible !important;
            }
        }

    </style>
    {{-- The `admin` class is the hook the light-mode ink scale hangs off, the
         way `storefront` and `dashboard` mark their own surfaces. Without it
         admin was the only area still painting Tailwind's stock zinc greys
         while the rest of the product used the brand navy. --}}
    <body data-page-transition="micro-fade-shift" class="admin min-h-screen bg-[#eff6ff] text-zinc-900 dark:bg-[#0c1a36] dark:text-white">

        {{-- Same shell as the customer dashboard: the warm grey rail, a fixed
             256px, and desktop-only. Mobile never sees this one - the hamburger
             in the header opens the card-grid sheet lower down instead, which
             is why hiding it here costs nothing. --}}
        <flux:sidebar sticky stashable class="relative hidden w-[256px] bg-[#eaeeec] dark:bg-[#0c1a36] lg:flex">

            {{-- Collapse toggle attached to the right edge of the sidebar.
                 Was previously in the header — moved here so the toggle lives
                 visually adjacent to what it toggles. Rotates 180° when the
                 sidebar is collapsed so the arrow always points the direction
                 it will move on click. Desktop-only (lg:flex). --}}
            <button
                type="button"
                x-data
                @click="$store.adminSidebar.toggle()"
                :aria-label="$store.adminSidebar.collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                :aria-pressed="$store.adminSidebar.collapsed.toString()"
                class="sidebar-collapse-toggle absolute right-0 z-30 hidden h-6 w-6 translate-x-1/2 items-center justify-center rounded-full bg-white/25 text-blue-600 border-[2px] border-blue-500 backdrop-blur-2xl backdrop-saturate-200 transition-all hover:bg-white/40 hover:text-blue-700 hover:border-blue-600 active:scale-95 lg:flex dark:bg-transparent dark:text-blue-300 dark:border-blue-400/80 dark:hover:bg-transparent dark:hover:text-blue-200 dark:hover:border-blue-300"
                style="top: 22px; box-shadow: 0 8px 24px -8px rgba(15, 23, 42, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.55);"
            >
                <svg
                    class="h-3 w-3 transition-transform duration-200"
                    :class="$store.adminSidebar.collapsed ? 'rotate-180' : 'rotate-0'"
                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>

            {{-- Brand. Two stacked marks: the full wordmark when expanded,
                 the square favicon when collapsed (swap is CSS-driven via the
                 admin-sidebar-collapsed class on <html>). --}}
            <a href="{{ auth('admin')->user()?->canAccessAdminRoute('dashboard') ? route('admin.dashboard') : route('admin.account') }}" wire:navigate class="mr-5 -ml-1 flex flex-col rounded-[12px] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40">
                <span class="brand-full flex h-12 items-center">
                    <img
                        src="{{ asset('assets/logo.webp').'?v=4' }}"
                        alt="{{ $siteName }}"
                        class="h-full w-auto object-contain"
                    />
                </span>
                <span class="brand-mark hidden w-14 flex-col items-center justify-center">
                    <img
                        src="{{ asset('assets/favicon.ico') }}"
                        alt="{{ $siteName }}"
                        class="h-12 w-12 rounded-[12px] object-contain"
                    />
                </span>
            </a>

            {{--
                Admin navigation — plain HTML so it's guaranteed to render without Flux Pro.

                Wired:
                  - Overview, Catalogue, Orders, Customers, Transactions, Wallets

                Pending (no view shipped yet):
                  - admin.gift-cards, admin.esims, admin.mobile-topups, admin.bill-payments,
                    admin.flights, admin.stays, admin.reports, admin.marketing,
                    admin.support-tickets, admin.settings
            --}}
            @php
                $isCurrent = fn (...$patterns) => request()->routeIs(...$patterns);
                // Role-aware nav gate: only show links the current admin's role
                // can actually open (same logic the AdminAuth middleware enforces).
                $adminUser = auth('admin')->user();
                $can = fn (string $routeName) => $adminUser?->canAccessAdminRoute($routeName) ?? false;
                $navItemClass = fn (bool $active) => $active
                    ? 'group flex items-center gap-2.5 rounded-[8px] px-2.5 py-2 text-sm font-medium nav-item-active'
                    : 'nav-item group flex items-center gap-2.5 rounded-[8px] px-2.5 py-2 text-sm font-medium text-zinc-700 transition-colors dark:text-zinc-300';
                $iconClass = fn (bool $active) => $active
                    ? 'h-5 w-5 shrink-0 text-black dark:text-white'
                    : 'h-5 w-5 shrink-0 text-zinc-600 transition-colors';
                // For <img>-based icons (file SVGs): natural colour everywhere.
                // No invert filter — active state is a soft blue tint now, not
                // a solid blue panel, so the icons stay readable as-is.
                $imgIconClass = fn (bool $active) => 'h-5 w-5 shrink-0 transition';
            @endphp

            {{-- px-1 is load-bearing. The active row's ring is a box-shadow,
                 which paints OUTSIDE the element's box, and `overflow-y-auto`
                 forces overflow-x to compute to `auto` as well - so a row that
                 ran the full content width had the left and right sides of its
                 ring clipped away while the top and bottom survived. The gutter
                 gives that 1px somewhere to land, and does the same for any
                 focus ring these rows take. --}}
            {{-- Scrolls without a bar: the site-wide hide applies, the rail is
                 short enough to wheel through and a bar down the rail read as
                 clutter. --}}
            <nav class="mt-4 flex flex-1 flex-col gap-1 overflow-y-auto px-1 pb-4" aria-label="Admin">
                {{-- Overview --}}
                @if ($can('admin.dashboard'))
                    @php $active = $isCurrent('admin.dashboard'); @endphp
                    <a href="{{ route('admin.dashboard') }}" wire:navigate data-tip="Overview" class="{{ $navItemClass($active) }}">
                        <svg class="{{ $iconClass($active) }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25zM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25z"/>
                        </svg>
                        Overview
                    </a>
                @endif

                {{-- Operations --}}
                @if ($can('admin.operations'))
                    @php $active = $isCurrent('admin.operations'); @endphp
                    <a href="{{ route('admin.operations') }}" wire:navigate data-tip="Operations" class="{{ $navItemClass($active) }}">
                        <svg class="{{ $iconClass($active) }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 1 0 7.5 7.5h-7.5V6Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0 0 13.5 3v7.5Z" />
                        </svg>
                        Operations
                    </a>
                @endif

                {{-- Catalogue: one link. It used to be an expandable "Products"
                     group with a sub-link per product category, but the catalogue
                     page carries its own category picker, so the group only
                     repeated it one level up. --}}
                @if ($can('admin.products'))
                @php $active = $isCurrent('admin.products*', 'admin.gift-cards*', 'admin.esims*', 'admin.mobile-topups*', 'admin.bill-payments*', 'admin.flights*', 'admin.stays*'); @endphp
                <a href="{{ route('admin.products') }}" wire:navigate data-tip="Catalogue" class="{{ $navItemClass($active) }}">
                    <img src="{{ asset('assets/' . rawurlencode('Shop.svg')) }}" alt="" class="{{ $imgIconClass($active) }}" loading="lazy">
                    Catalogue
                </a>
                @endif

                {{-- Orders --}}
                @if ($can('admin.orders'))
                @php $active = $isCurrent('admin.orders*'); @endphp
                <a href="{{ route('admin.orders') }}" wire:navigate data-tip="Orders" class="{{ $navItemClass($active) }}">
                    <img src="{{ asset('assets/' . rawurlencode('order.svg')) }}" alt="" class="{{ $imgIconClass($active) }}" loading="lazy">
                    Orders
                </a>
                @endif

                {{-- User Management (expandable group — customers list plus the
                     account-lifecycle pages: moderation, closures, exit feedback,
                     country insights, retention health) --}}
                @if ($can('admin.customers'))
                @php
                    $usersActive = $isCurrent('admin.customers*', 'admin.users.*');
                @endphp
                <div
                    x-data="{
                        open: {{ $usersActive ? 'true' : 'false' }},
                        active() { return ['/admin/customers', '/admin/users'].some(p => window.location.pathname.includes(p)); },
                        sync() { this.open = this.$store.adminSidebar?.collapsed ? true : this.active(); },
                    }"
                    x-effect="sync()"
                    x-on:livewire:navigated.window="sync()"
                    @click.outside="if (! $store.adminSidebar?.collapsed) { open = false }"
                    class="nav-group flex flex-col gap-1"
                >
                    <button
                        type="button"
                        @click.stop="open = ! open"
                        :aria-expanded="open.toString()"
                        class="{{ $navItemClass($usersActive) }} w-full justify-between"
                    >
                        <span class="flex items-center gap-3">
                            <img src="{{ asset('assets/' . rawurlencode('customer.svg')) }}" alt="" class="{{ $imgIconClass($usersActive) }}" loading="lazy">
                            User Management
                        </span>
                        <svg :class="open && 'rotate-180'" class="h-4 w-4 shrink-0 transition-transform {{ $usersActive ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    @php
                        $usersSubItemClass = fn (bool $subActive) => $subActive
                            ? 'flex items-center rounded-[8px] px-2.5 py-2 text-sm font-medium nav-item-active'
                            : 'nav-item flex items-center rounded-[8px] px-2.5 py-2 text-sm font-medium text-zinc-700 transition-colors dark:text-zinc-300';
                    @endphp
                    <div x-show="open" x-collapse class="nav-submenu ml-5 flex flex-col gap-1 border-l border-zinc-200 pl-4">
                        <a href="{{ route('admin.customers') }}" wire:navigate class="{{ $usersSubItemClass($isCurrent('admin.customers*')) }}">Customers</a>
                        <a href="{{ route('admin.users.blocked') }}" wire:navigate class="{{ $usersSubItemClass($isCurrent('admin.users.blocked')) }}">Blocked &amp; Restricted</a>
                        <a href="{{ route('admin.users.closures') }}" wire:navigate class="{{ $usersSubItemClass($isCurrent('admin.users.closures')) }}">Closures</a>
                        <a href="{{ route('admin.users.feedback') }}" wire:navigate class="{{ $usersSubItemClass($isCurrent('admin.users.feedback')) }}">Exit Feedback</a>
                        <a href="{{ route('admin.users.insights') }}" wire:navigate class="{{ $usersSubItemClass($isCurrent('admin.users.insights')) }}">Audience Insights</a>
                        <a href="{{ route('admin.users.leaderboards') }}" wire:navigate class="{{ $usersSubItemClass($isCurrent('admin.users.leaderboards')) }}">Leaderboards</a>
                    </div>
                </div>
                @endif

                {{-- Risk (fraud/risk engine: metrics + review queue) --}}
                @if ($can('admin.risk.dashboard'))
                @php $active = $isCurrent('admin.risk*'); @endphp
                <a href="{{ route('admin.risk.dashboard') }}" wire:navigate data-tip="Risk" class="{{ $navItemClass($active) }}">
                    <svg class="{{ $imgIconClass($active) }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 4.5-3 7.9-7 9-4-1.1-7-4.5-7-9V6l7-3z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.5 12l1.8 1.8L15 10"/>
                    </svg>
                    Risk
                    @php
                        // Never let the badge (a count on every admin page) take the
                        // panel down - a missing table pre-migration, or any DB hiccup,
                        // falls back to no badge.
                        $pendingReviews = \Illuminate\Support\Facades\Schema::hasTable('risk_reviews')
                            ? rescue(fn () => \App\Models\RiskReview::where('status', 'pending')->count(), 0, false)
                            : 0;
                    @endphp
                    @if ($pendingReviews > 0)
                        <span class="ml-auto inline-flex items-center justify-center rounded-full bg-red-500 px-1.5 text-[10px] font-bold text-white">{{ $pendingReviews }}</span>
                    @endif
                </a>
                @endif

                {{-- Transactions --}}
                @if ($can('admin.transactions'))
                @php $active = $isCurrent('admin.transactions*'); @endphp
                <a href="{{ route('admin.transactions') }}" wire:navigate data-tip="Transactions" class="{{ $navItemClass($active) }}">
                    <img src="{{ asset('assets/' . rawurlencode('transactions.svg')) }}" alt="" class="{{ $imgIconClass($active) }}" loading="lazy">
                    Transactions
                </a>
                @endif


                {{-- Wallets --}}
                @if ($can('admin.wallets'))
                @php $active = $isCurrent('admin.wallets*'); @endphp
                <a href="{{ route('admin.wallets') }}" wire:navigate data-tip="Wallets" class="{{ $navItemClass($active) }}">
                    <img src="{{ asset('assets/' . rawurlencode('Wallet.svg')) }}" alt="" class="{{ $imgIconClass($active) }}" loading="lazy">
                    Wallets
                </a>
                @endif

                {{-- Reports --}}
                @if ($can('admin.reports'))
                @php $active = $isCurrent('admin.reports*'); @endphp
                <a href="{{ route('admin.reports') }}" wire:navigate data-tip="Reports" class="{{ $navItemClass($active) }}">
                    <img src="{{ asset('assets/report.svg') }}" alt="" class="{{ $imgIconClass($active) }}" loading="lazy">
                    Reports
                </a>

                @endif

                {{-- Management (expandable group — CTO-level control: monitoring,
                     payments, finances, leaderboards, runbooks) --}}
                @if ($can('admin.management.monitoring'))
                @php
                    $mgmtActive = $isCurrent('admin.management.*', 'admin.payment-insights*');
                @endphp
                <div
                    x-data="{
                        open: {{ $mgmtActive ? 'true' : 'false' }},
                        active() { return ['/admin/management', '/admin/payment-insights'].some(p => window.location.pathname.includes(p)); },
                        sync() { this.open = this.$store.adminSidebar?.collapsed ? true : this.active(); },
                    }"
                    x-effect="sync()"
                    x-on:livewire:navigated.window="sync()"
                    @click.outside="if (! $store.adminSidebar?.collapsed) { open = false }"
                    class="nav-group flex flex-col gap-1"
                >
                    <button
                        type="button"
                        @click.stop="open = ! open"
                        :aria-expanded="open.toString()"
                        class="{{ $navItemClass($mgmtActive) }} w-full justify-between"
                    >
                        <span class="flex items-center gap-3">
                            <svg class="{{ $iconClass($mgmtActive) }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
                            </svg>
                            Management
                        </span>
                        <svg :class="open && 'rotate-180'" class="h-4 w-4 shrink-0 transition-transform {{ $mgmtActive ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    @php
                        $mgmtSubItemClass = fn (bool $active) => $active
                            ? 'flex items-center rounded-[8px] px-2.5 py-2 text-sm font-medium nav-item-active'
                            : 'nav-item flex items-center rounded-[8px] px-2.5 py-2 text-sm font-medium text-zinc-700 transition-colors dark:text-zinc-300';
                    @endphp
                    <div x-show="open" x-collapse class="nav-submenu ml-5 flex flex-col gap-1 border-l border-zinc-200 pl-4">
                        <a href="{{ route('admin.management.monitoring') }}" wire:navigate class="{{ $mgmtSubItemClass($isCurrent('admin.management.monitoring')) }}">Monitoring</a>
                        <a href="{{ route('admin.payment-insights') }}" wire:navigate class="{{ $mgmtSubItemClass($isCurrent('admin.payment-insights*')) }}">Payment Insights</a>
                        <a href="{{ route('admin.management.finances') }}" wire:navigate class="{{ $mgmtSubItemClass($isCurrent('admin.management.finances')) }}">Finances</a>
                        <a href="{{ route('admin.management.runbooks') }}" wire:navigate class="{{ $mgmtSubItemClass($isCurrent('admin.management.runbooks')) }}">Runbooks</a>
                    </div>
                </div>
                @endif

                {{-- Newsletter --}}
                @if ($can('admin.newsletter'))
                @php $active = $isCurrent('admin.newsletter*'); @endphp
                <a href="{{ route('admin.newsletter') }}" wire:navigate data-tip="Newsletter" class="{{ $navItemClass($active) }}">
                    <img src="{{ asset('assets/' . rawurlencode('marketing.svg')) }}" alt="" class="{{ $imgIconClass($active) }}" loading="lazy">
                    Newsletter
                </a>
                @endif

                {{-- Campaigns / Push --}}
                @if ($can('admin.campaigns'))
                @php $active = $isCurrent('admin.campaigns*'); @endphp
                <a href="{{ route('admin.campaigns') }}" wire:navigate data-tip="Push Campaigns" class="{{ $navItemClass($active) }}">
                    <img src="{{ asset('assets/' . rawurlencode('notification 2.svg')) }}" alt="" class="{{ $imgIconClass($active) }}" loading="lazy">
                    Push Campaigns
                </a>
                @endif

                {{-- Pricing Rules --}}
                @if ($can('admin.pricing-rules'))
                @php $active = $isCurrent('admin.pricing-rules*'); @endphp
                <a href="{{ route('admin.pricing-rules') }}" wire:navigate data-tip="Pricing Rules" class="{{ $navItemClass($active) }}">
                    <svg class="{{ $iconClass($active) }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
                    </svg>
                    Pricing Rules
                </a>
                @endif

                {{-- Content — CMS-managed marketing copy (blog / press / reviews / FAQs).
                     Sub-items expand inline so the rail stays scannable when collapsed
                     they hide behind the parent label. --}}
                @if ($can('admin.content.blog'))
                @php $contentActive = $isCurrent('admin.content.*'); @endphp
                <div
                    x-data="{
                        open: {{ $contentActive ? 'true' : 'false' }},
                        active() { return window.location.pathname.includes('/admin/content'); },
                        sync() { this.open = this.$store.adminSidebar?.collapsed ? true : this.active(); },
                    }"
                    {{-- Open state is derived from the live URL and re-synced on every
                         wire:navigate, so a group can't stay stuck open after you move to
                         another page. Collapsed sidebar forces open for the hover flyout. --}}
                    x-effect="sync()"
                    x-on:livewire:navigated.window="sync()"
                    class="nav-group flex flex-col"
                >
                    <button
                        type="button"
                        @click="open = ! open"
                        :aria-expanded="open.toString()"
                        class="{{ $navItemClass($contentActive) }} w-full justify-between"
                    >
                        <span class="flex items-center gap-3">
                            <svg class="{{ $iconClass($contentActive) }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M4 4h16v4H4z"/>
                                <path d="M4 12h10v8H4z"/>
                                <path d="M16 12h4v8h-4z"/>
                            </svg>
                            Content
                        </span>
                        <svg class="h-4 w-4 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div x-show="open" x-collapse class="nav-submenu mt-1 ml-3 flex flex-col gap-0.5 border-l border-zinc-200 pl-3">
                        @php
                            $subItem = fn (bool $active) => $active
                                ? 'block rounded-[8px] px-2.5 py-2 text-xs font-medium nav-item-active'
                                : 'nav-item block rounded-[8px] px-2.5 py-2 text-xs font-medium text-zinc-700 transition-colors dark:text-zinc-300';
                        @endphp
                        <a href="{{ route('admin.content.hero-sections') }}" wire:navigate class="{{ $subItem($isCurrent('admin.content.hero-sections')) }}">Hero Sections</a>
                        <a href="{{ route('admin.content.blog') }}" wire:navigate class="{{ $subItem($isCurrent('admin.content.blog')) }}">Blog Posts</a>
                        <a href="{{ route('admin.content.press') }}" wire:navigate class="{{ $subItem($isCurrent('admin.content.press')) }}">Press Articles</a>
                        <a href="{{ route('admin.content.reviews') }}" wire:navigate class="{{ $subItem($isCurrent('admin.content.reviews')) }}">Reviews</a>
                        <a href="{{ route('admin.content.faqs') }}" wire:navigate class="{{ $subItem($isCurrent('admin.content.faqs')) }}">FAQs</a>
                        <a href="{{ route('admin.content.rewards') }}" wire:navigate class="{{ $subItem($isCurrent('admin.content.rewards')) }}">Rcoin Rewards</a>
                        <a href="{{ route('admin.content.rewards.analytics') }}" wire:navigate class="{{ $subItem($isCurrent('admin.content.rewards.analytics')) }}">Rcoin Analytics</a>
                        <a href="{{ route('admin.content.rewards.withdrawals') }}" wire:navigate class="{{ $subItem($isCurrent('admin.content.rewards.withdrawals')) }}">Rcoin Withdrawals</a>
                    </div>
                </div>
                @endif

                {{-- Audit Log --}}
                @php $active = $isCurrent('admin.account-activity*'); @endphp
                <a href="{{ route('admin.account-activity') }}" wire:navigate data-tip="Audit Log" class="{{ $navItemClass($active) }}">
                    <svg class="{{ $iconClass($active) }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/>
                    </svg>
                    Audit Log
                </a>

                {{-- Support Tickets --}}
                @if ($can('admin.support-tickets'))
                @php $active = $isCurrent('admin.support-tickets*'); @endphp
                <a href="{{ route('admin.support-tickets') }}" wire:navigate data-tip="Support Tickets" class="{{ $navItemClass($active) }}">
                    <img src="{{ asset('assets/support.svg') }}" alt="" class="{{ $imgIconClass($active) }}" loading="lazy">
                    Support Tickets
                </a>
                @endif

                {{-- Admins --}}
                @if ($can('admin.admins'))
                @php $active = $isCurrent('admin.admins*'); @endphp
                <a href="{{ route('admin.admins') }}" wire:navigate data-tip="Admins" class="{{ $navItemClass($active) }}">
                    <img src="{{ asset('assets/' . rawurlencode('admin access.svg')) }}" alt="" class="{{ $imgIconClass($active) }}" loading="lazy">
                    Admins
                </a>
                @endif

                {{-- Rate Management --}}
                @if ($can('admin.rates'))
                @php $active = $isCurrent('admin.rates*'); @endphp
                <a href="{{ route('admin.rates') }}" wire:navigate data-tip="Rate Management" class="{{ $navItemClass($active) }}">
                    <svg class="{{ $iconClass($active) }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9"/>
                        <path d="M9.5 8h4.25a2 2 0 010 4H9.5m0 0h4.5a2 2 0 010 4H9.5m0-8v8m0-8h-1m1 8h-1m2-10v2m0 8v2"/>
                    </svg>
                    Rate Management
                </a>
                @endif

                {{-- System Settings --}}
                @if ($can('admin.settings'))
                @php $active = $isCurrent('admin.settings*'); @endphp
                <a href="{{ route('admin.settings') }}" wire:navigate data-tip="System Settings" class="{{ $navItemClass($active) }}">
                    <img src="{{ asset('assets/' . rawurlencode('system setting.svg')) }}" alt="" class="{{ $imgIconClass($active) }}" loading="lazy">
                    System Settings
                </a>
                @endif

                {{-- API & Integrations - partner API keys + provider credentials --}}
                @if ($can('admin.api-settings'))
                @php $active = $isCurrent('admin.api-settings*'); @endphp
                <a href="{{ route('admin.api-settings') }}" wire:navigate data-tip="API & Integrations" class="{{ $navItemClass($active) }}">
                    <img src="{{ asset('assets/' . rawurlencode('system setting.svg')) }}" alt="" class="{{ $imgIconClass($active) }}" loading="lazy">
                    API & Integrations
                </a>
                @endif

            </nav>

        </flux:sidebar>

        {{-- Top header (sticky). Notifications + profile wired to admin guard. --}}
        {{-- NOTE: do NOT add Flux's `sticky` prop here. It injects an Alpine binding that
             freezes `top` to the header's offsetTop read once at init; if that fires before
             the body grid settles (wire:navigate morph, slow paint) it captures the header's
             block-flow position below the sidebar (~478px) and the bar floats mid-page.
             The `sticky top-0` classes below are pure CSS — no race. --}}
        {{-- pt-[env(...)]: the page is viewport-fit=cover, so this bar reserves
             the status-bar strip itself when admin is opened from a home-screen
             icon. Resolves to 0 in a browser. --}}
        <flux:header class="sticky top-0 z-40 min-h-[60px] items-center gap-2 !border-b-0 bg-[#eff6ff] dark:bg-[#0c1a36] px-3 py-2 pt-[max(0.5rem,env(safe-area-inset-top))] sm:gap-3 sm:px-6">
            {{-- Plain button toggle. Bypasses <flux:sidebar.toggle>, which is a self-closing wrapper
                 around <flux:button square /> and doesn't render slot children. Dispatching
                 'flux-sidebar-toggle' is exactly what Flux's own toggle does internally. --}}
            <button
                type="button"
                x-data
                x-on:click="$dispatch('open-mobile-menu')"
                aria-label="Open menu"
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] transition-colors hover:bg-zinc-100 active:scale-95 lg:hidden"
            >
                <img src="{{ asset('assets/' . rawurlencode('Hamburger menu.svg')) }}" alt="" class="h-6 w-6" style="filter: brightness(0) saturate(100%);" loading="lazy">
            </button>

            {{-- Sidebar collapse toggle now lives ON the sidebar itself (see
                 the absolute-positioned button inside <flux:sidebar> above)
                 instead of in this header. --}}

            {{-- Page heading is rendered inside the content area (see <flux:main> below),
                 not in this top bar — so every admin page title sits within the page. --}}
            <flux:spacer />

            {{-- Spacer pair flanks the command bar so it sits true-centre in
                 the header without pushing the right-side icons further right. --}}

            {{-- Admin command bar. Mirrors the storefront nav search exactly:
                 live-search dropdown against admin.products.search-suggest with a
                 Ctrl+K hint chip that swaps to a clear button while typing.
                 Hidden on small screens — the mobile menu carries its own search. --}}
            <div
                x-data="adminCommandBar()"
                @click.outside="open = false"
                @keydown.escape.window="open = false"
                @keydown.window.ctrl.k.prevent="$refs.search.focus()"
                @keydown.window.meta.k.prevent="$refs.search.focus()"
                class="relative hidden md:block w-[360px] lg:w-[420px]"
            >
                {{-- Search bar — matches the user dashboard's storefront search
                     style (heavier border, larger icon, base text size, neutral
                     kbd chip) so admin and customer surfaces share the same
                     search affordance. --}}
                <form
                    role="search"
                    method="GET"
                    action="{{ route('admin.products') }}"
                    @submit.prevent="openActive()"
                    @click="$refs.search.focus()"
                    :class="open ? 'border-blue-500 ring-2 ring-blue-500/15' : 'border-zinc-400 hover:border-zinc-500 dark:border-white/10 dark:hover:border-white/20'"
                    class="group flex items-center gap-3 cursor-text rounded-[12px] border-2 bg-white px-4 py-2 transition-all duration-200 dark:bg-[#162a4a]"
                >
                    <svg class="h-5 w-5 shrink-0 text-zinc-900 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input
                        x-ref="search"
                        x-model="query"
                        @input="suggest()"
                        @focus="if (results.length > 0) open = true"
                        @keydown.down.prevent="move(1)"
                        @keydown.up.prevent="move(-1)"
                        name="q"
                        type="search"
                        placeholder="Search orders, ICCID, transactions, customers…"
                        aria-label="Search admin"
                        autocomplete="off"
                        spellcheck="false"
                        class="flex-1 min-w-0 bg-transparent text-base text-zinc-800 placeholder:text-zinc-600 outline-none dark:text-white dark:placeholder:text-zinc-400"
                    />
                    {{-- Ctrl+K hint chip — shown while empty, swaps to a clear
                         button once the user starts typing. Matches the dashboard
                         search style: neutral zinc surround on light, deep navy
                         in dark mode. --}}
                    <span x-show="query.length === 0" class="hidden items-center gap-1 rounded-[12px] border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 text-[10px] font-semibold text-zinc-600 sm:inline-flex dark:border-white/10 dark:bg-[#0c1a36] dark:text-zinc-400" aria-hidden="true">
                        CTRL <span class="text-zinc-600 dark:text-zinc-500">+</span> K
                    </span>
                    <button type="button" x-show="query.length > 0" @click="clear()" class="flex h-6 w-6 shrink-0 items-center justify-center rounded-[12px] bg-zinc-200 transition-colors hover:bg-zinc-300 dark:bg-transparent dark:hover:bg-white/15" aria-label="Clear">
                        <svg class="h-3 w-3 text-zinc-600 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </form>

                {{-- Results dropdown. Every row type shares one template: the
                     endpoint normalises each hit to title / subtitle / meta /
                     badge, so adding a searchable record type server-side needs
                     no change here. Rows arrive pre-sorted and contiguous by
                     group, so the headers are derived by walking the flat list. --}}
                <div
                    x-show="open"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    class="absolute left-0 right-0 top-full z-40 mt-2 overflow-hidden rounded-[12px] bg-white/65 ring-1 ring-white/60 backdrop-blur-2xl backdrop-saturate-150 dark:bg-[#0c1a36]/70 dark:ring-white/15"
                    style="display:none; box-shadow: 0 24px 50px -12px rgba(15, 23, 42, 0.28), 0 0 0 1px rgba(15, 23, 42, 0.06), inset 0 1px 0 rgba(255, 255, 255, 0.55);"
                >
                    <div x-show="loading" class="flex items-center gap-2 px-4 py-3 text-[12px] text-zinc-500 dark:text-zinc-400">
                        <svg class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/>
                            <path d="M22 12a10 10 0 01-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                        Searching…
                    </div>

                    <div x-show="! loading && results.length > 0" class="max-h-96 overflow-y-auto p-1.5">
                        <template x-for="group in groups" :key="group.name">
                            <div>
                                <p class="px-3 pb-1 pt-2 text-[10px] font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400" x-text="group.name"></p>
                                <ul>
                                    <template x-for="row in group.rows" :key="row.id">
                                        <li>
                                            <a
                                                :href="row.url"
                                                wire:navigate
                                                @mouseenter="activeIndex = results.indexOf(row)"
                                                :class="results.indexOf(row) === activeIndex ? 'bg-blue-50 dark:bg-blue-500/10' : ''"
                                                class="flex items-center gap-3 rounded-[12px] px-3 py-2 transition-colors"
                                            >
                                                <template x-if="row.avatar">
                                                    <img :src="row.avatar" alt="" class="h-9 w-9 shrink-0 rounded-[12px] bg-white object-contain ring-1 ring-zinc-100 dark:ring-white/10" loading="lazy">
                                                </template>
                                                <template x-if="! row.avatar">
                                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] bg-blue-50 text-[11px] font-bold uppercase text-blue-700 ring-1 ring-blue-100 dark:bg-blue-500/15 dark:text-blue-200 dark:ring-blue-500/30" x-text="row.initials"></span>
                                                </template>

                                                <span class="min-w-0 flex-1 leading-tight">
                                                    <span class="block truncate text-[13px] font-semibold text-zinc-900 dark:text-white" x-text="row.title"></span>
                                                    <span class="block truncate text-[11px] text-zinc-500 dark:text-zinc-400" x-text="row.subtitle"></span>
                                                </span>

                                                <span class="shrink-0 text-right leading-tight">
                                                    <span class="block text-[10px] font-bold uppercase tracking-wide text-blue-700 dark:text-blue-200" x-text="row.badge"></span>
                                                    <span x-show="row.meta" class="block text-[11px] tabular-nums text-zinc-500 dark:text-zinc-400" x-text="row.meta"></span>
                                                </span>
                                            </a>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </template>
                    </div>

                    <div x-show="! loading && results.length === 0 && query.length >= 2" class="px-4 py-6 text-center text-[12px] text-zinc-500 dark:text-zinc-400">
                        No matches for "<span class="font-semibold text-zinc-900 dark:text-white" x-text="query"></span>"
                    </div>

                    <div x-show="results.length > 0" class="border-t border-zinc-200/60 px-4 py-2 text-[11px] text-zinc-500 dark:border-white/10 dark:text-zinc-400">
                        <kbd class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] text-zinc-700 dark:bg-transparent dark:text-zinc-200">↑↓</kbd>
                        to move,
                        <kbd class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] text-zinc-700 dark:bg-transparent dark:text-zinc-200">Enter</kbd>
                        to open
                    </div>
                </div>
            </div>

            <script>
                // Admin command-bar factory — shared shape with the storefront's
                // navBrandSearch but pointed at the global admin search endpoint.
                // Race-safe via an incrementing requestId.
                window.adminCommandBar = function () {
                    return {
                        query: '',
                        results: [],
                        loading: false,
                        open: false,
                        activeIndex: 0,
                        _seq: 0,
                        _timer: null,

                        // The endpoint now fans one query across a dozen tables,
                        // so firing on every keystroke would queue a burst of
                        // multi-table searches the user has already typed past.
                        // Wait for a pause; the request itself stays race-safe.
                        suggest() {
                            clearTimeout(this._timer);
                            const q = (this.query || '').trim();
                            if (q.length < 2) {
                                this.results = [];
                                this.loading = false;
                                this.open = false;
                                return;
                            }
                            this.loading = true;
                            this.open = true;
                            this._timer = setTimeout(() => this.fetchResults(q), 180);
                        },

                        async fetchResults(q) {
                            const mySeq = ++this._seq;
                            try {
                                const res = await fetch('{{ route('admin.search-suggest') }}?q=' + encodeURIComponent(q), {
                                    headers: { 'Accept': 'application/json' },
                                });
                                if (mySeq !== this._seq) { return; }
                                this.results = await res.json();
                                this.activeIndex = 0;
                            } catch (e) {
                                if (mySeq === this._seq) { this.results = []; }
                            } finally {
                                if (mySeq === this._seq) { this.loading = false; }
                            }
                        },

                        // Rows arrive sorted and contiguous by group, so headers
                        // come from walking the flat list rather than a second
                        // grouping pass that could reorder relevance.
                        get groups() {
                            const out = [];
                            for (const row of this.results) {
                                const last = out[out.length - 1];
                                if (last && last.name === row.group) {
                                    last.rows.push(row);
                                } else {
                                    out.push({ name: row.group, rows: [row] });
                                }
                            }
                            return out;
                        },

                        move(delta) {
                            if (! this.results.length) { return; }
                            const count = this.results.length;
                            this.activeIndex = (this.activeIndex + delta + count) % count;
                        },

                        openActive() {
                            const row = this.results[this.activeIndex] || this.results[0];
                            if (row) { window.location.href = row.url; }
                        },

                        clear() {
                            clearTimeout(this._timer);
                            this.query = '';
                            this.results = [];
                            this.activeIndex = 0;
                            this.open = false;
                            this.$refs.search?.focus();
                        },
                    };
                };
            </script>

            <flux:spacer />

            {{-- Theme toggle — admin's own light/dark/system control, saved per
                 admin account and kept separate from the customer side. --}}
            <x-theme-toggle class="h-10 w-10 rounded-[12px] bg-zinc-200 text-zinc-900 dark:text-zinc-200 transition-colors hover:bg-blue-600 hover:text-white" />

            {{-- Extra Dark now lives inside the theme toggle's popup (the
                 component renders an "Extra dark" row), so no separate admin
                 button is needed - it stays admin-only via the admin.theme key. --}}

            {{-- Admin notifications bell — real AdminNotification feed (KYC, orders, etc.). --}}
            <livewire:admin.notifications-menu />

            {{-- Profile dropdown — wired to admin auth guard. Hover opens; click locks open. --}}
            @php $admin = Auth::guard('admin')->user(); @endphp
            <div
                x-data="{ open: false, locked: false }"
                @mouseenter="if (!locked) open = true"
                @mouseleave="if (!locked) open = false"
                @click.outside="open = false; locked = false"
                @keydown.escape.window="open = false; locked = false"
                class="relative"
            >
                @php
                    // Initials avatar fallback when the admin hasn't uploaded a photo.
                    $adminDefaultAvatar = $admin?->initialsAvatar() ?? '';
                @endphp
                <button
                    type="button"
                    @click="locked = !locked; open = locked"
                    :aria-expanded="open.toString()"
                    aria-label="{{ $admin?->name ?? 'Admin' }}"
                    class="ml-1 relative flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-zinc-200 ring-1 ring-zinc-200 transition-all duration-150 hover:ring-2 hover:ring-blue-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 dark:ring-zinc-700/60"
                >
                    <img src="{{ $admin?->avatar_url ?: $adminDefaultAvatar }}" alt="{{ $admin?->name ?? 'Admin' }}" class="h-full w-full object-cover" loading="lazy">
                </button>

                <div
                    x-show="open"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1"
                    style="display:none;"
                    class="absolute right-0 top-full z-50 mt-2 w-[260px] overflow-hidden rounded-[12px] bg-white shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200 dark:ring-white/10"
                    role="menu"
                >
                    {{-- Admin info card --}}
                    <div class="border-b border-zinc-100 px-4 py-3">
                        <p class="truncate text-sm font-semibold text-zinc-900">{{ $admin?->name ?? 'Admin' }}</p>
                        <p class="truncate text-xs text-zinc-600">{{ $admin?->email ?? '' }}</p>
                    </div>

                    @php $iconBlack = 'filter: brightness(0) saturate(100%);'; @endphp
                    <div class="p-1.5">
                        <a href="{{ route('admin.account') }}" wire:navigate class="flex items-center gap-3 rounded-[12px] px-3 py-2 text-sm font-medium text-zinc-900 transition-colors hover:bg-blue-100" role="menuitem">
                            <img src="{{ asset('assets/' . rawurlencode('user.svg')) }}" alt="" class="h-5 w-5 shrink-0" style="{{ $iconBlack }}" loading="lazy">
                            Account information
                        </a>
                        <a href="{{ route('admin.account-activity') }}" wire:navigate class="flex items-center gap-3 rounded-[12px] px-3 py-2 text-sm font-medium text-zinc-900 transition-colors hover:bg-blue-100" role="menuitem">
                            <img src="{{ asset('assets/' . rawurlencode('account avtivities 3.svg')) }}" alt="" class="h-5 w-5 shrink-0" style="{{ $iconBlack }}" loading="lazy">
                            Audit log
                        </a>
                        <a href="{{ route('admin.notifications') }}" wire:navigate class="flex items-center gap-3 rounded-[12px] px-3 py-2 text-sm font-medium text-zinc-900 transition-colors hover:bg-blue-100" role="menuitem">
                            <img src="{{ asset('assets/' . rawurlencode('notification 2.svg')) }}" alt="" class="h-5 w-5 shrink-0" style="{{ $iconBlack }}" loading="lazy">
                            Notifications Log
                        </a>
                    </div>

                    <div class="border-t border-zinc-100 p-1.5">
                        <form method="POST" action="{{ route('admin.logout') }}" class="w-full">
                            @csrf
                            <button type="submit" class="flex w-full items-center gap-3 rounded-[12px] px-3 py-2 text-left text-sm font-medium text-red-600 transition-colors hover:bg-red-100 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-500/15 dark:hover:text-red-300">
                                <x-ui.logout-icon class="h-5 w-5" />
                                Log Out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </flux:header>

        {{-- Content area with rounded-[12px] top-left corner. Padding is provided here so pages just provide their content.
             Inner uses flex-column + min-h-full so the footer always pins to the bottom of the viewport, even on
             short pages — same footer renders on every admin page since it lives in this layout.
             The page bg lives on `<flux:main>` itself so the rounded-[12px] curve never "straightens" on scroll — the
             curve is a real edge between two same-colour panels, not an outline that can be clipped. --}}
        <flux:main class="!p-0 bg-[#eff6ff] dark:bg-[#0c1a36]">
            <div class="flex min-h-full flex-col rounded-tl-[15px] rounded-tr-[15px] bg-[#eff6ff] p-3 pb-2 sm:p-4 sm:pb-2 lg:rounded-tr-none lg:p-6 lg:pb-2 dark:bg-[#0c1a36]">
                <div class="w-full flex-1">
                    {{-- Page heading — moved out of the top nav bar into the page itself. --}}
                    {{-- Page heading. Subtext only renders when a page explicitly
                         passes one — no default copy beneath the title. --}}
                    <div class="mb-6">
                        <h1 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">{{ $heading ?? 'Overview' }}</h1>
                        @if (isset($subheading))
                            <p class="mt-1 text-sm text-zinc-600">{{ $subheading }}</p>
                        @endif
                    </div>
                    {{ $slot }}
                </div>

                {{-- Footer — Privacy Policy + version + copyright. Lives at
                     the layout level (after the flex-1 content) so flexbox
                     pins it to the viewport bottom on short pages and below
                     the scroll on long ones. Renders on every admin page. --}}
                <footer class="mt-auto pt-12 flex flex-wrap items-center justify-end gap-x-4 gap-y-1 text-[11px] font-semibold text-zinc-600 dark:text-zinc-300">
                    <a href="{{ route('shop.privacy') }}" class="hover:text-zinc-900 dark:hover:text-white">Privacy Policy</a>
                    <span class="text-zinc-300 dark:text-zinc-600">·</span>
                    <span>version 2.0.0</span>
                    <span class="text-zinc-300 dark:text-zinc-600">·</span>
                    <span>©{{ $siteName }} {{ date('Y') }}</span>
                </footer>
            </div>
        </flux:main>

        {{-- Global confirm modal — intercepts any form/button with `data-confirm`. --}}
        <x-confirm-modal />

        {{-- Mobile menu popup. Triggered by the mobile hamburger via 'open-mobile-menu' window event.
             Card grid of admin nav items instead of the Flux sidebar drawer. lg:hidden so it never shows on desktop. --}}
        @php
            // Every sidebar destination, in sidebar order, filtered by the
            // SAME $can() the sidebar uses so the mobile menu never shows a
            // page the signed-in admin cannot open. Content sub-pages get
            // their own tiles since the sheet has no expandable groups.
            $adminMenuItems = collect([
                ['label' => 'Home',          'route' => 'admin.dashboard',               'icon' => 'Home.svg',                    'tone' => 'bg-blue-500'],
                ['label' => 'Catalogue',     'route' => 'admin.products',                'icon' => 'Shop.svg',                    'tone' => 'bg-pink-500'],
                ['label' => 'Orders',        'route' => 'admin.orders',                  'icon' => 'order.svg',                   'tone' => 'bg-sky-500'],
                ['label' => 'Customers',     'route' => 'admin.customers',               'icon' => 'customer.svg',                'tone' => 'bg-emerald-500'],
                ['label' => 'Transactions',  'route' => 'admin.transactions',            'icon' => 'transactions.svg',            'tone' => 'bg-teal-500'],
                ['label' => 'Wallets',       'route' => 'admin.wallets',                 'icon' => 'Wallet.svg',                  'tone' => 'bg-indigo-500'],
                ['label' => 'Reports',       'route' => 'admin.reports',                 'icon' => 'report.svg',                  'tone' => 'bg-violet-500'],
                ['label' => 'Newsletter',    'route' => 'admin.newsletter',              'icon' => 'marketing.svg',               'tone' => 'bg-fuchsia-500'],
                ['label' => 'Campaigns',     'route' => 'admin.campaigns',               'icon' => 'notification 2.svg',          'tone' => 'bg-purple-500'],
                ['label' => 'Pricing Rules', 'route' => 'admin.pricing-rules',           'icon' => 'total revenue.svg',           'tone' => 'bg-orange-600'],
                ['label' => 'Hero Sections', 'route' => 'admin.content.hero-sections',   'icon' => 'Appearance.svg',              'tone' => 'bg-sky-600'],
                ['label' => 'Blog Posts',    'route' => 'admin.content.blog',            'icon' => 'new info.svg',                'tone' => 'bg-blue-600'],
                ['label' => 'Press',         'route' => 'admin.content.press',           'icon' => 'About svg 1.svg',             'tone' => 'bg-slate-500'],
                ['label' => 'Reviews',       'route' => 'admin.content.reviews',         'icon' => 'trusted by millions.svg',     'tone' => 'bg-yellow-500'],
                ['label' => 'FAQs',          'route' => 'admin.content.faqs',            'icon' => 'other.svg',                   'tone' => 'bg-stone-500'],
                ['label' => 'Rcoin Rewards', 'route' => 'admin.content.rewards',         'icon' => 'wallet 2.svg',                'tone' => 'bg-yellow-600'],
                ['label' => 'Rcoin Analytics', 'route' => 'admin.content.rewards.analytics', 'icon' => 'success rates admin.svg', 'tone' => 'bg-violet-600'],
                ['label' => 'Rcoin Payouts', 'route' => 'admin.content.rewards.withdrawals', 'icon' => 'total transactions.svg',  'tone' => 'bg-red-500'],
                ['label' => 'Support',       'route' => 'admin.support-tickets',         'icon' => 'support.svg',                 'tone' => 'bg-amber-500'],
                ['label' => 'Admins',        'route' => 'admin.admins',                  'icon' => 'admin access.svg',            'tone' => 'bg-rose-500'],
                ['label' => 'Rates',         'route' => 'admin.rates',                   'icon' => 'transactions.svg',            'tone' => 'bg-orange-500'],
                ['label' => 'API Settings',  'route' => 'admin.api-settings',            'icon' => 'Network Svg.svg',             'tone' => 'bg-slate-600'],
                ['label' => 'Settings',      'route' => 'admin.settings',                'icon' => 'system setting.svg',          'tone' => 'bg-cyan-500'],
                ['label' => 'Audit Log',     'route' => 'admin.account-activity',        'icon' => 'account avtivities 3.svg',    'tone' => 'bg-zinc-500'],
            ])
                ->filter(fn ($item) => $can($item['route']))
                ->map(fn ($item) => $item + ['href' => route($item['route'])])
                ->values()
                ->all();
        @endphp
        {{-- Mobile menu wrapper: `contents` removes the div from Flux's grid layout so it
             doesn't push flux:sidebar/header/main into an unexpected row. The fixed-position
             children inside still anchor correctly to the viewport. --}}
        <div
            x-data="{ menuOpen: false }"
            x-on:open-mobile-menu.window="$nextTick(() => menuOpen = true)"
            x-on:keydown.escape.window="menuOpen = false"
            x-effect="menuOpen ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
            class="contents lg:hidden"
        >
            <div
                x-show="menuOpen"
                x-transition:enter="transition-opacity ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                @click="menuOpen = false"
                style="display: none;"
                class="fixed inset-0 z-[60] bg-zinc-900/45"
                aria-hidden="true"
            ></div>

            <div
                x-show="menuOpen"
                x-transition:enter="transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
                x-transition:enter-start="translate-y-full"
                x-transition:enter-end="translate-y-0"
                x-transition:leave="transition-transform duration-250 ease-in"
                x-transition:leave-start="translate-y-0"
                x-transition:leave-end="translate-y-full"
                style="display: none;"
                class="modal-norise fixed inset-x-3 bottom-3 z-[70] max-h-[85vh] overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden rounded-3xl bg-white/70 ring-1 ring-white/40 backdrop-blur-2xl backdrop-saturate-150 shadow-2xl shadow-zinc-900/25 dark:bg-[#0c1a36]/70 dark:ring-white/10"
                role="dialog"
                aria-modal="true"
                aria-labelledby="admin-mobile-menu-title"
            >
                <div class="flex justify-center pt-3">
                    <span class="h-1.5 w-10 rounded-[12px] bg-zinc-300 dark:bg-white/25"></span>
                </div>

                <div class="px-5 pb-[max(20px,env(safe-area-inset-bottom))] pt-4">
                    {{-- No close X here — backdrop tap and the grab handle above
                         already dismiss the sheet, so the button was redundant. --}}
                    <h2 id="admin-mobile-menu-title" class="mb-5 text-lg font-bold text-zinc-900 dark:text-white">Admin menu</h2>

                    {{-- The whole sheet scrolls (max-h on the panel), matching
                         the customer dashboard menu. --}}
                    <div class="skeleton-stagger-fast grid grid-cols-4 gap-3">
                        @foreach ($adminMenuItems as $i => $item)
                            <a
                                href="{{ $item['href'] }}"
                                @if ($item['href'] !== '#') wire:navigate @endif
                                @click="menuOpen = false"
                                style="--i: {{ $i }}"
                                class="group flex flex-col items-center justify-center gap-2 rounded-[6px] px-2 py-3 text-center transition-transform duration-200 active:scale-95"
                            >
                                <span class="flex h-12 w-12 items-center justify-center rounded-[12px] {{ $item['tone'] }} shadow-sm transition-transform duration-200 group-hover:scale-105">
                                    <img src="{{ asset('assets/' . rawurlencode($item['icon'])) }}" alt="" class="h-6 w-6 brightness-0 invert" loading="lazy">
                                </span>
                                <span class="text-[11px] font-semibold leading-tight text-zinc-900 dark:text-white">{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </div>

                    <form method="POST" action="{{ route('admin.logout') }}" class="mt-5 border-t border-zinc-100 pt-4">
                        @csrf
                        <button
                            type="submit"
                            class="flex w-full items-center justify-center gap-2 rounded-[12px] bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-600 transition-colors hover:bg-red-100 dark:bg-red-500/10 dark:text-red-400 dark:hover:bg-red-500/20"
                        >
                            <x-ui.logout-icon class="h-4 w-4" />
                            Log out
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Floating action toasts (session flashes) - auto-dismiss top-right.
             The same pill the customer dashboard uses, so both surfaces share
             one notification style. (The old SweetAlert modal bridge was dead
             code - nothing dispatches 'swal' - and is gone with its CDN.) --}}
        @php
            $adminUser = auth()->guard('admin')->user();
            $needsSecurityQuestion = $adminUser && empty($adminUser->security_question) && \App\Support\FeatureFlag::on('admin_security_question') && !session()->has('has_seen_security_prompt');
            if ($needsSecurityQuestion) {
                session()->put('has_seen_security_prompt', true);
            }
        @endphp
        @if ($needsSecurityQuestion)
            <div x-data="{ show: true }" x-show="show" class="fixed bottom-6 right-6 z-[100] flex max-w-sm items-start gap-4 rounded-[12px] bg-white p-4 shadow-2xl dark:bg-[#0c1a36]" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-4">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-600 dark:bg-blue-500/20 dark:text-blue-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Action Required</h3>
                    <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">Please set up your security question in your account settings to secure your login.</p>
                    <div class="mt-3 flex items-center gap-3">
                        <a href="{{ route('admin.account') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Set up now</a>
                        <button @click="show = false" class="text-xs font-medium text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300">Dismiss</button>
                    </div>
                </div>
            </div>
        @endif

        <x-flash-toast />

        @if (session()->has('impersonator_admin_id'))
            <form method="POST" action="{{ route('admin.revert-impersonation') }}" class="fixed bottom-6 left-1/2 z-[100] flex -translate-x-1/2 items-center gap-3 rounded-[12px] bg-purple-500 p-2 pr-3 text-sm shadow-xl shadow-purple-900/20 ring-4 ring-purple-500/30">
                @csrf
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[8px] bg-purple-950 text-purple-400">
                    <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                </div>
                <div>
                    <p class="font-bold text-white">Viewing as {{ auth()->guard('admin')->user()?->name }}</p>
                    <p class="text-[11px] font-semibold text-purple-100/80">Any actions taken are logged as this admin.</p>
                </div>
                <div class="ml-2 h-6 w-px bg-purple-400/50"></div>
                <button type="submit" class="rounded-[5px] bg-purple-950 px-3 py-1 text-xs font-bold text-purple-50 transition-colors hover:bg-purple-900">Return to super admin</button>
            </form>
        @endif

        @fluxScripts
    </body>
</html>
