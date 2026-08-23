<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    {{-- Customer sidebar collapse styling. Mirrors the admin sidebar's CSS
         contract — when the html element carries `dashboard-sidebar-collapsed`,
         the rail shrinks to icons only, labels hide, single items get a
         tooltip on hover, and group items show a floating submenu popup.
         The CSS targets `[data-flux-sidebar]` which on this layout is the
         customer sidebar (the admin layout is never on the same page so
         there's no selector collision). --}}
    <style>
        @media (min-width: 1024px) {
            html.dashboard-sidebar-collapsed [data-flux-sidebar] {
                width: 72px !important;
            }
            /* Collapsed: the scroll wrapper keeps scrolling vertically (so every
               icon stays reachable on short viewports) but clips horizontally;
               the flyout submenus are position:fixed (placed per-hover by the
               flyout positioner script below) so they escape that clip instead
               of forcing the whole rail to overflow:visible, which used to
               disable scrolling. nav itself stays visible — only the wrapper
               scrolls. */
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav {
                overflow: visible !important;
            }
            html.dashboard-sidebar-collapsed [data-flux-sidebar] > div {
                overflow-y: auto !important;
                overflow-x: hidden !important;
            }
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav a,
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav button {
                font-size: 0 !important;
                justify-content: center !important;
                padding-left: 0.5rem !important;
                padding-right: 0.5rem !important;
                gap: 0 !important;
            }
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav button > span,
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav a > span {
                gap: 0 !important;
                justify-content: center !important;
                margin: 0 auto !important;
            }
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav svg,
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav img {
                margin: 0 auto !important;
            }
            /* Expandable groups — hide chevron + right-side badges in collapsed
               mode regardless of how deeply they're nested. The earlier
               `nav a > span.dash-row-badge` selector missed badges wrapped in
               an extra <span class="flex"> (the Shop button's NEW pill sits
               inside a row wrapper, not directly under <a>/<button>). */
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav button > span:last-child > svg,
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav .dash-row-badge {
                display: none !important;
            }
            /* Section headings (Shop, Account) collapse to a thin divider so
               the sections still read as grouped without their labels. */
            html.dashboard-sidebar-collapsed [data-flux-sidebar] .dash-section-label {
                font-size: 0 !important;
                margin-top: 1rem !important;
                margin-bottom: 0 !important;
                padding: 0 !important;
                height: 1px !important;
                background: rgba(15, 23, 42, 0.08) !important;
            }
            html.dark.dashboard-sidebar-collapsed [data-flux-sidebar] .dash-section-label {
                background: rgba(255, 255, 255, 0.08) !important;
            }
            /* Newsletter + Need-Help cards at the bottom — hide entirely when
               collapsed, they need horizontal space to be readable. */
            html.dashboard-sidebar-collapsed [data-flux-sidebar] .dash-footer-card {
                display: none !important;
            }

            /* Grey hover — calmer than the loud blue pill in the expanded
               rail. Icons keep their natural colour (no whitening) so they
               read against the soft grey bg. */
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav a:hover,
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav button:hover {
                background-color: rgba(15, 23, 42, 0.06) !important;
                color: #18181b !important;
            }
            html.dark.dashboard-sidebar-collapsed [data-flux-sidebar] nav a:hover,
            html.dark.dashboard-sidebar-collapsed [data-flux-sidebar] nav button:hover {
                background-color: #070f1c !important;
                color: #60a5fa !important;
                font-weight: 600 !important;
            }

            /* Tooltip — floats to the right of the icon on hover. Applied to
               single nav links that carry `data-tip`; group items are handled
               by the popup rules below. */
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav a {
                position: relative;
            }
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav a[data-tip]:hover::after {
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
            html.dark.dashboard-sidebar-collapsed [data-flux-sidebar] nav a[data-tip]:hover::after {
                background: #ffffff;
                color: #18181b;
            }

            /* Brand swap: full wordmark hidden, favicon shown, when collapsed. */
            html:not(.dashboard-sidebar-collapsed) [data-flux-sidebar] .brand-mark { display: none !important; }
            html.dashboard-sidebar-collapsed [data-flux-sidebar] .brand-full { display: none !important; }
            html.dashboard-sidebar-collapsed [data-flux-sidebar] .brand-mark { display: flex !important; }
            html.dashboard-sidebar-collapsed [data-flux-sidebar] > a { margin-right: 0 !important; }

            /* Collapsed brand favicon ships blue (correct on the light sidebar);
               recolor it to white in dark mode for contrast on the dark sidebar. */
            html.dark [data-flux-sidebar] .brand-mark img { filter: brightness(0) invert(1); }

            /* Sidebar collapse arrow: hidden until the sidebar is hovered (or the
               button is keyboard-focused), so it only appears when wanted. */
            @media (min-width: 1024px) {
                /* `html` prefix raises specificity above Flux's `z-20!` so the
                   sidebar (and the arrow straddling its right edge) sits above the
                   page header instead of being covered by it. */
                html [data-flux-sidebar] { z-index: 60 !important; }
                .sidebar-collapse-toggle { opacity: 0; }
                [data-flux-sidebar]:hover .sidebar-collapse-toggle,
                .sidebar-collapse-toggle:focus-visible { opacity: 1; }
            }

            /* Collapsed: inline sub-menus disappear inline AND reappear as a
               polished floating popup on hover, positioned to the right of
               the icon with a 10px radius matching the project standard. */
            html.dashboard-sidebar-collapsed [data-flux-sidebar] .nav-group {
                position: relative;
            }
            html.dashboard-sidebar-collapsed [data-flux-sidebar] .nav-submenu {
                /* Fixed so it escapes the scroll wrapper's overflow clip. left/top
                   are set on hover by the flyout positioner script (the gap to the
                   rail is baked into the JS left, replacing the old margin-left). */
                position: fixed !important;
                width: 220px !important;
                padding: 0.5rem !important;
                background: white !important;
                border-radius: 10px !important;
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
            html.dark.dashboard-sidebar-collapsed [data-flux-sidebar] .nav-submenu {
                background: #0c1a36 !important;
                box-shadow:
                    0 24px 50px -12px rgba(0, 0, 0, 0.75),
                    0 0 0 1px rgba(255, 255, 255, 0.07) !important;
            }
            html.dashboard-sidebar-collapsed [data-flux-sidebar] .nav-submenu::before {
                /* Invisible bridge so the pointer can travel without losing :hover. */
                content: '';
                position: absolute;
                left: -0.875rem;
                top: 0;
                width: 0.875rem;
                height: 100%;
            }
            html.dashboard-sidebar-collapsed [data-flux-sidebar] .nav-submenu a,
            html.dashboard-sidebar-collapsed [data-flux-sidebar] .nav-submenu button {
                font-size: 0.8125rem !important;
                padding: 0.625rem 0.875rem !important;
                justify-content: flex-start !important;
                text-align: left !important;
                gap: 0.75rem !important;
                border-radius: 10px !important;
            }
            /* The parent rail rule sets `margin: 0 auto` on every nav img/svg
               to centre it inside the icon-only rail. That auto-margin leaks
               into the submenu and pushes the label to the opposite side. Pin
               it back to 0 so icon + label sit tight on the left. */
            html.dashboard-sidebar-collapsed [data-flux-sidebar] .nav-submenu img,
            html.dashboard-sidebar-collapsed [data-flux-sidebar] .nav-submenu svg {
                margin: 0 !important;
            }
            html.dark.dashboard-sidebar-collapsed [data-flux-sidebar] .nav-submenu a,
            html.dark.dashboard-sidebar-collapsed [data-flux-sidebar] .nav-submenu button {
                color: rgba(255, 255, 255, 0.7) !important;
                background: transparent !important;
            }
            html.dark.dashboard-sidebar-collapsed [data-flux-sidebar] .nav-submenu a:hover,
            html.dark.dashboard-sidebar-collapsed [data-flux-sidebar] .nav-submenu button:hover {
                background: #070f1c !important;
                color: #60a5fa !important;
                font-weight: 600 !important;
            }
            html.dashboard-sidebar-collapsed [data-flux-sidebar] .nav-group:hover .nav-submenu,
            html.dashboard-sidebar-collapsed [data-flux-sidebar] .nav-group:focus-within .nav-submenu {
                display: flex !important;
                height: auto !important;
                overflow: visible !important;
            }

            /* Soft width transition so the rail glides between states. */
            [data-flux-sidebar] {
                transition: width 220ms cubic-bezier(0.22, 1, 0.36, 1);
                overflow: visible !important;
            }
        }
    </style>
    <body class="min-h-screen bg-[#eff6ff] text-zinc-900 dark:bg-[#0c1a36] dark:text-white">

        <x-ui.app-loader />

        {{-- Floating "Install app" dock (PWA). iOS-aware; hides when installed. --}}
        <x-ui.install-app />

        {{-- Impersonation banner: shown only when an admin is signed in as this
             customer (admin guard still authenticated). Floating bottom pill so
             it never disrupts the layout; sits above the mobile bottom nav. --}}
        @auth('admin')
            <div class="fixed bottom-20 left-1/2 z-[80] flex -translate-x-1/2 items-center gap-3 rounded-[12px] bg-amber-500 px-4 py-2.5 text-sm font-semibold text-amber-950 shadow-lg shadow-amber-900/30 lg:bottom-4">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.644C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span>Viewing as {{ Auth::user()?->name }}</span>
                <form method="POST" action="{{ route('impersonation.leave') }}">
                    @csrf
                    <button type="submit" class="rounded-[5px] bg-amber-950 px-3 py-1 text-xs font-bold text-amber-50 transition-colors hover:bg-amber-900">Return to admin</button>
                </form>
            </div>
        @endauth

        {{-- Translation engine (auto-detect + manual switching from the locale modal) --}}
        @include('partials.translate-engine')

        @php
            $user = Auth::user();
            $isCurrent = fn (...$patterns) => request()->routeIs(...$patterns);

            // Customer sidebar nav styles mirror the admin sidebar exactly so
            // both shells read as one product family. Active = soft blue tint
            // (not the loud bg-blue-600 pill that used to live here); hover =
            // subtle zinc on light, deep-charcoal seam on dark; sub-items use
            // the same shape one indent deeper.
            $navItem = fn (bool $active) => $active
                ? 'group flex items-center justify-between gap-3 rounded-[20px] bg-zinc-200 px-3 py-3 text-sm font-semibold text-black dark:bg-black dark:text-white dark:ring-1 dark:ring-white/10 nav-item-active'
                : 'group flex items-center justify-between gap-3 rounded-[20px] px-3 py-3 text-sm font-medium text-zinc-700 transition-colors hover:bg-blue-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-transparent! dark:hover:text-white dark:hover:ring-1 dark:hover:ring-[#24364f]';
            $iconCls = fn (bool $active) => $active
                ? 'h-5 w-5 shrink-0 text-black dark:text-white'
                : 'h-5 w-5 shrink-0 text-zinc-600 transition-colors';
            $subItem = fn (bool $active) => $active
                ? 'flex items-center gap-3 rounded-[20px] bg-zinc-200 px-3 py-2.5 text-sm font-semibold text-black dark:bg-black dark:text-white dark:ring-1 dark:ring-white/10 nav-item-active'
                : 'flex items-center gap-3 rounded-[20px] px-3 py-2.5 text-sm font-medium text-zinc-600 transition-colors hover:bg-blue-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-transparent! dark:hover:text-white dark:hover:ring-1 dark:hover:ring-[#24364f]';
            // Active no longer needs a white invert filter — the soft-blue
            // tint background keeps icons readable in their natural colour.
            // Black filter for the idle state keeps mixed-source SVGs visually
            // uniform across the rail.
            $imgIconBlack = 'filter: brightness(0) saturate(100%);';
            $imgIconStyle = fn (bool $active) => $active ? '' : $imgIconBlack;

            $ordersCount = $user?->orders()->count() ?? 0;
            // Unread notification count — drives the sidebar/avatar/menu badges.
            // The bell dropdown (<livewire:notifications-menu />) computes its own.
            $notificationCount = $user?->notifications()->whereNull('read_at')->count() ?? 0;

            // Initials avatar fallback when the user has no real photo (Google
            // picture / uploaded avatar). Deterministic per user, works for
            // everyone - no gender needed.
            $defaultAvatar = $user?->initialsAvatar() ?? '';
        @endphp

        <flux:sidebar sticky stashable class="relative hidden w-[256px] m-3 rounded-[14px] border border-zinc-200 bg-[#eff6ff] dark:border-[#24364f] dark:bg-[#0c1a36] lg:flex" style="height: calc(100dvh - 1.5rem); top: 0.75rem;">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            {{-- Glass round toggle on the seam — mirrors the admin sidebar.
                 Grey-tinted glass on light mode, white-glass on dark.
                 Desktop-only (lg:flex) — mobile uses the stash. --}}
            <button
                type="button"
                x-data
                @click="$store.dashboardSidebar.toggle()"
                :aria-label="$store.dashboardSidebar.collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                :aria-pressed="$store.dashboardSidebar.collapsed.toString()"
                class="sidebar-collapse-toggle absolute right-0 z-30 hidden h-6 w-6 translate-x-1/2 items-center justify-center rounded-full bg-white/25 text-blue-600 border-[2px] border-blue-500 backdrop-blur-2xl backdrop-saturate-200 transition-all hover:bg-white/40 hover:text-blue-700 hover:border-blue-600 active:scale-95 lg:flex dark:bg-transparent dark:text-blue-300 dark:border-blue-400/80 dark:hover:bg-transparent dark:hover:text-blue-200 dark:hover:border-blue-300"
                style="top: 22px; box-shadow: 0 8px 24px -8px rgba(15, 23, 42, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.55);"
            >
                <svg
                    class="h-3 w-3 transition-transform duration-200"
                    :class="$store.dashboardSidebar.collapsed ? 'rotate-180' : 'rotate-0'"
                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>

            {{-- Brand — full wordmark when expanded, square favicon when collapsed. --}}
            <a href="{{ route('dashboard') }}" wire:navigate.hover class="mr-5 -ml-1 flex flex-col rounded-[12px] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 shrink-0">
                <span class="brand-full flex h-10 items-center">
                    <img
                        src="{{ asset('assets/Rshoprefillslogo.webp') }}"
                        alt="{{ $siteName }}"
                        class="h-full w-auto object-contain"
                    />
                </span>
                <span class="brand-mark hidden w-14 flex-col items-center justify-center">
                    <img
                        src="{{ asset('assets/favicon.ico') }}"
                        alt="{{ $siteName }}"
                        class="h-12 w-12 rounded-full object-contain"
                    />
                    <span class="mt-0.5 text-[9px] font-medium italic leading-none text-zinc-600 dark:text-zinc-400">Est. 2024</span>
                </span>
                <span class="brand-full mt-0.5 pl-1 text-[10px] font-medium italic leading-none text-zinc-600 dark:text-zinc-400">Est. 2024</span>
            </a>

            {{-- Scrollable middle: only the nav links scroll. Logo above + Newsletter/Need-Help
                 below stay fixed. min-h-0 lets flex-1 actually shrink and trigger overflow.
                 -mr-2 pr-2 hides the scrollbar gutter; the project hides scrollbars site-wide. --}}
            <div class="-mx-2 flex min-h-0 flex-1 flex-col overflow-y-auto px-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">

            {{-- Primary nav --}}
            <nav class="mt-6 flex flex-col gap-1" aria-label="Account">
                @php $active = $isCurrent('dashboard'); @endphp
                <a href="{{ route('dashboard') }}" wire:navigate.hover data-tip="Overview" class="{{ $navItem($active) }}">
                    <span class="flex items-center gap-3">
                        <svg class="{{ $iconCls($active) }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/>
                        </svg>
                        Overview
                    </span>
                </a>
            </nav>

            {{-- SHOP section (expandable, mirrors admin Products dropdown).
                 nav-group + nav-submenu classes opt into the collapse-mode
                 popup CSS so hovering the icon shows the submenu when the
                 sidebar is collapsed to icons-only. --}}
            <p class="dash-section-label mt-6 px-3 text-base font-bold text-zinc-900 dark:text-white">Shop</p>
            <nav class="mt-2 flex flex-col gap-1" aria-label="Shop">
                <div
                    x-data="{ expanded: false, locked: false }"
                    {{-- Force open while collapsed (popup), but restore to closed when
                         expanded so re-opening the sidebar doesn't leave it stuck open. --}}
                    x-effect="expanded = $store.dashboardSidebar?.collapsed ? true : false"
                    @mouseenter="if (! $store.dashboardSidebar?.collapsed) expanded = true"
                    @mouseleave="if (! locked && ! $store.dashboardSidebar?.collapsed) expanded = false"
                    @click.outside="if (! $store.dashboardSidebar?.collapsed) { locked = false; expanded = false }"
                    class="nav-group flex flex-col gap-1"
                >
                    <button type="button" @click.stop="locked = ! locked; expanded = locked" :aria-expanded="expanded.toString()" class="{{ $navItem(false) }} w-full">
                        <span class="flex items-center gap-3">
                            <img src="{{ asset('assets/' . rawurlencode('Shop.svg')) }}" alt="" class="h-5 w-5 shrink-0" style="{{ $imgIconBlack }}" loading="lazy">
                            Shop
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="dash-row-badge inline-flex items-center whitespace-nowrap rounded-[5px] bg-blue-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-blue-700 ring-1 ring-blue-200 dark:bg-blue-500/15 dark:text-blue-300 dark:ring-blue-500/30">New</span>
                            <svg :class="expanded && 'rotate-180'" class="h-4 w-4 shrink-0 text-zinc-600 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </span>
                    </button>

                    {{-- Submenu rows are text-only: the parent Shop item carries
                         the icon, repeating one per child just adds noise. --}}
                    <div x-show="expanded" x-collapse class="nav-submenu ml-5 flex flex-col gap-1 border-l border-zinc-200 pl-3">
                        <a href="{{ route('dashboard.shop.gift-cards') }}" wire:navigate.hover class="{{ $subItem(false) }}">
                            All Categories
                        </a>
                        @php
                            // Sell Gift Cards + GoMarket are hidden entirely (not
                            // greyed) until their feature flag is switched on.
                            $shopSubItems = array_values(array_filter([
                                ['Buy Gift Cards',  route('dashboard.shop.gift-cards'),      'gift_cards'],
                                \App\Support\FeatureFlag::on('sell_gift_cards', false) ? ['Sell Gift Cards', route('dashboard.shop.sell-gift-cards'), null] : null,
                                \App\Support\FeatureFlag::on('gomarket', false) ? ['GoMarket', route('dashboard.shop.gomarket'), null] : null,
                                ['eSIMs',           route('dashboard.shop.esims'),           'esims'],
                                \App\Support\FeatureFlag::categoryVisible('flights') ? ['Flights', route('dashboard.shop.flights'), null] : null,
                                \App\Support\FeatureFlag::categoryVisible('stays') ? ['Stays', route('dashboard.shop.stays'), null] : null,
                                ['Topups & Bills',  route('dashboard.shop.topups'),          'topups'],
                            ]));
                        @endphp
                        @foreach ($shopSubItems as [$label, $href, $flag])
                            @php $catEnabled = $flag === null || \App\Support\FeatureFlag::on($flag); @endphp
                            @if ($catEnabled)
                                <a href="{{ $href }}" wire:navigate.hover class="{{ $subItem(false) }}">
                                    {{ $label }}
                                </a>
                            @else
                                {{-- Feature off: visible but greyed and non-clickable. --}}
                                <span class="{{ $subItem(false) }} cursor-not-allowed opacity-50" aria-disabled="true" title="Temporarily unavailable">
                                    {{ $label }}
                                </span>
                            @endif
                        @endforeach
                    </div>
                </div>
            </nav>

            {{-- ACCOUNT section. dash-section-label class collapses the
                 heading to a thin divider when the sidebar is icon-only. --}}
            <p class="dash-section-label mt-6 px-3 text-base font-bold text-zinc-900 dark:text-white">Account</p>
            <nav class="mt-2 flex flex-col gap-1" aria-label="Account management">
                @php $active = $isCurrent('dashboard.orders*'); @endphp
                <a href="{{ route('dashboard.orders') }}" wire:navigate.hover data-tip="Orders" class="{{ $navItem($active) }}">
                    <span class="flex items-center gap-3">
                        <img src="{{ asset('assets/' . rawurlencode('order.svg')) }}" alt="" class="h-5 w-5 shrink-0" style="{{ $imgIconStyle($active) }}" loading="lazy">
                        Orders
                    </span>
                    @if ($ordersCount > 0)
                        <span class="dash-row-badge inline-flex items-center whitespace-nowrap rounded-[5px] bg-blue-50 px-2 py-0.5 text-[10px] font-bold tabular-nums text-blue-700 ring-1 ring-blue-200 dark:bg-blue-500/15 dark:text-blue-300 dark:ring-blue-500/30">{{ $ordersCount > 9 ? '9+' : $ordersCount }}</span>
                    @endif
                </a>

                @php $active = request()->routeIs('dashboard.wallet'); @endphp
                <a href="{{ route('dashboard.wallet') }}" wire:navigate.hover data-tip="Wallet" class="{{ $navItem($active) }}">
                    <span class="flex items-center gap-3">
                        <img src="{{ asset('assets/' . rawurlencode('Wallet.svg')) }}" alt="" class="h-5 w-5 shrink-0" style="{{ $imgIconStyle($active) }}" loading="lazy">
                        Wallet
                    </span>
                </a>

                @php $active = $isCurrent('dashboard.transactions*'); @endphp
                <a href="{{ route('dashboard.transactions') }}" wire:navigate.hover data-tip="Transactions" class="{{ $navItem($active) }}">
                    <span class="flex items-center gap-3">
                        <img src="{{ asset('assets/' . rawurlencode('transactions.svg')) }}" alt="" class="h-5 w-5 shrink-0" style="{{ $imgIconStyle($active) }}" loading="lazy">
                        Transactions
                    </span>
                </a>

                {{-- Gift card trading is hidden entirely (not greyed) while its
                     flag is off - same contract as the storefront surfaces. --}}
                @if (\App\Support\FeatureFlag::on('sell_gift_cards', false))
                @php $active = $isCurrent('dashboard.gift-cards*'); @endphp
                <a href="{{ route('dashboard.gift-cards.history') }}" wire:navigate.hover data-tip="Trade Gift Cards" class="{{ $navItem($active) }}">
                    <span class="flex items-center gap-3">
                        <svg class="h-5 w-5 shrink-0 {{ $active ? 'text-black dark:text-white' : 'text-zinc-600' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="2" y="5" width="20" height="14" rx="2" ry="2"/>
                            <line x1="2" y1="10" x2="22" y2="10"/>
                        </svg>
                        Trade Gift Cards
                    </span>
                </a>
                @endif

                {{-- Profile intentionally omitted from the desktop sidebar — it's
                     reachable via the avatar dropdown (and the mobile menu). --}}

                @php $active = $isCurrent('dashboard.kyc'); @endphp
                <a href="{{ route('dashboard.kyc') }}" wire:navigate.hover data-tip="Verify Identity" class="{{ $navItem($active) }}">
                    <span class="flex items-center gap-3">
                        <img src="{{ asset('assets/customer.svg') }}" alt="" class="h-5 w-5 shrink-0" style="{{ $imgIconStyle($active) }}" loading="lazy">
                        Verify Identity
                    </span>
                    <span class="dash-row-badge inline-flex items-center whitespace-nowrap rounded-[5px] bg-amber-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-700 ring-1 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/30">KYC</span>
                </a>

                @php $active = $isCurrent('dashboard.security'); @endphp
                <a href="{{ route('dashboard.security') }}" wire:navigate.hover data-tip="Security" class="{{ $navItem($active) }}">
                    <span class="flex items-center gap-3">
                        <img src="{{ asset('assets/' . rawurlencode('admin access.svg')) }}" alt="" class="h-5 w-5 shrink-0" style="{{ $imgIconStyle($active) }}" loading="lazy">
                        Security
                    </span>
                </a>

                @php $active = request()->routeIs('dashboard.notifications'); @endphp
                <a href="{{ route('dashboard.notifications') }}" wire:navigate.hover data-tip="Notifications" class="{{ $navItem($active) }}">
                    <span class="flex items-center gap-3">
                        <img src="{{ asset('assets/' . rawurlencode('notification 2.svg')) }}" alt="" class="h-5 w-5 shrink-0" style="{{ $imgIconStyle($active) }}" loading="lazy">
                        Notifications
                    </span>
                    @if ($notificationCount > 0)
                        <span class="dash-row-badge inline-flex items-center whitespace-nowrap rounded-[5px] bg-red-50 px-2 py-0.5 text-[10px] font-bold tabular-nums text-red-700 ring-1 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/30">{{ $notificationCount > 9 ? '9+' : $notificationCount }}</span>
                    @endif
                </a>

                {{-- Saved Cards hidden until the card-vault / gateway tokenisation
                     backend ships (the page is only an empty-state shell today).
                     Restore this block + the route in routes/web.php to re-enable.
                @php $active = request()->routeIs('dashboard.saved-cards'); @endphp
                <a href="{{ route('dashboard.saved-cards') }}" wire:navigate.hover data-tip="Saved Cards" class="{{ $navItem($active) }}">
                    <span class="flex items-center gap-3">
                        <img src="{{ asset('assets/' . 'savedcard.svg') }}" alt="" class="h-5 w-5 shrink-0" style="{{ $imgIconStyle($active) }}" loading="lazy">
                        Saved Cards
                    </span>
                </a>
                --}}

                <a href="{{ route('dashboard.rewards') }}" wire:navigate.hover data-tip="Rcoin Rewards" class="{{ $navItem(request()->routeIs('dashboard.rewards')) }}">
                    <span class="flex items-center gap-3">
                        <img src="{{ asset('assets/' . rawurlencode('wallet 2.svg')) }}" alt="" class="h-5 w-5 shrink-0 object-contain" loading="lazy">
                        Rcoin Rewards
                    </span>
                    <span class="dash-row-badge inline-flex items-center whitespace-nowrap rounded-[5px] bg-emerald-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30">Earn</span>
                </a>
            </nav>

            </div> {{-- /scrollable middle --}}

            {{-- Newsletter signup card removed from the sidebar; its WhatsApp
                 shortcut now lives inside the Need Help card below. --}}

            {{-- Need Help card. Opens a small support modal with two paths:
                 immediate WhatsApp chat, or a formal support ticket via the
                 storefront contact page. shrink-0 keeps it anchored at the
                 very bottom alongside the newsletter card. --}}
            <div class="dash-footer-card shrink-0 pt-3"
                x-data="{ helpOpen: false }"
                @keydown.escape.window="helpOpen = false"
            >
                <div class="overflow-hidden rounded-[12px] bg-white ring-1 ring-zinc-200 dark:ring-zinc-700/60">
                    <button
                        type="button"
                        @click="helpOpen = true"
                        class="flex w-full items-center gap-3 p-3 text-left transition-colors hover:bg-zinc-50 dark:hover:bg-white/5"
                    >
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-blue-50 text-blue-600 dark:bg-blue-500/15 dark:text-blue-300">
                            <img src="{{ asset('assets/support.svg') }}" alt="" class="h-5 w-5" loading="lazy">
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">Need help?</p>
                            <p class="truncate text-xs text-zinc-600 dark:text-zinc-300">Chat with support</p>
                        </div>
                        {{-- Shortcut hint: the global Ctrl+F shortcut opens a support
                             ticket from anywhere on the site (see partials/shortcuts). --}}
                        <span class="inline-flex shrink-0 items-center gap-1 rounded-[12px] border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 text-[10px] font-semibold text-zinc-500 dark:border-zinc-700/60 dark:bg-white/5 dark:text-zinc-300">
                            CTRL <span class="opacity-70">+</span> F
                        </span>
                    </button>

                    {{-- Direct WhatsApp shortcut (moved here from the removed newsletter
                         card). Mirrors the global Ctrl+J shortcut for a quick support chat. --}}
                    <a
                        href="https://wa.me/19402386229?text=Hello%20Rshoprefill%20can%20i%20get%20help%3F"
                        target="_blank"
                        rel="noopener"
                        class="flex items-center justify-between gap-2 border-t border-zinc-100 px-3 py-2.5 text-[11px] font-medium text-zinc-600 transition-colors hover:bg-zinc-50 hover:text-emerald-600 dark:border-white/10 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-emerald-400"
                    >
                        <span class="inline-flex items-center gap-1.5">
                            <svg class="h-4 w-4 text-emerald-500" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.966-.273-.099-.471-.149-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.611-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479s1.065 2.875 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413"/>
                            </svg>
                            Chat on WhatsApp
                        </span>
                        <span class="inline-flex shrink-0 items-center gap-1 rounded-[12px] border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 text-[10px] font-semibold text-zinc-500 dark:border-zinc-700/60 dark:bg-white/5 dark:text-zinc-300">
                            CTRL <span class="opacity-70">+</span> J
                        </span>
                    </a>
                </div>

                {{-- Support picker modal. Lives inside the same x-data so the
                     button's click toggles it without a global event. Backdrop
                     click + escape close. --}}
                <div
                    x-show="helpOpen"
                    x-cloak
                    @click="helpOpen = false"
                    x-transition:enter="transition-opacity duration-200"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition-opacity duration-150"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    class="fixed inset-0 z-[100] flex items-center justify-center bg-zinc-900/45 p-4"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="support-modal-title"
                >
                    <div
                        @click.stop
                        x-show="helpOpen"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 translate-y-4 scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                        x-transition:leave-end="opacity-0 translate-y-2 scale-95"
                        class="w-full max-w-sm rounded-[12px] bg-white p-6 shadow-2xl ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-zinc-700/60"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 id="support-modal-title" class="text-base font-bold text-zinc-900 dark:text-white">How can we help?</h3>
                                <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">Pick the channel that suits you best.</p>
                            </div>
                            <button
                                type="button"
                                @click="helpOpen = false"
                                aria-label="Close"
                                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[12px] text-zinc-600 transition-colors hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-white/10"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <div class="mt-5 flex flex-col gap-2.5">
                            {{-- WhatsApp pill: same number / pre-filled message that
                                 the previous direct link used so existing routing
                                 inside the support team stays unchanged. --}}
                            <a
                                href="https://wa.me/19402386229?text=Hello%20Rshoprefill%20can%20i%20get%20help%3F"
                                target="_blank"
                                rel="noopener"
                                @click="helpOpen = false"
                                class="group flex items-center gap-3 rounded-[12px] bg-emerald-50 px-4 py-3 ring-1 ring-emerald-200 transition-colors hover:bg-emerald-100 dark:bg-emerald-500/15 dark:ring-emerald-500/30 dark:hover:bg-emerald-500/25"
                            >
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] bg-white text-emerald-600 dark:bg-[#0c1a36]">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.966-.273-.099-.471-.149-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.611-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479s1.065 2.875 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413"/>
                                    </svg>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold text-emerald-900 dark:text-emerald-200">Chat on WhatsApp</span>
                                    <span class="mt-0.5 block text-[11px] text-emerald-800/80 dark:text-emerald-300/80">Usually replies in a few minutes</span>
                                </span>
                            </a>

                            {{-- Support ticket pill: routes to the storefront
                                 contact page which already persists tickets +
                                 emails the support team. --}}
                            <a
                                href="{{ route('shop.contact') }}"
                                wire:navigate.hover
                                @click="helpOpen = false"
                                class="group flex items-center gap-3 rounded-[12px] bg-blue-50 px-4 py-3 ring-1 ring-blue-200 transition-colors hover:bg-blue-100 dark:bg-blue-500/15 dark:ring-blue-500/30 dark:hover:bg-blue-500/25"
                            >
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] bg-white text-blue-600 dark:bg-[#0c1a36] dark:text-blue-300">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/>
                                    </svg>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold text-blue-900 dark:text-blue-200">Create a support ticket</span>
                                    <span class="mt-0.5 block text-[11px] text-blue-800/80 dark:text-blue-300/80">Best for billing or order issues</span>
                                </span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </flux:sidebar>

        {{-- Top header (desktop only). Search is absolutely centered horizontally so it stays middle regardless of cart/profile width on the right. --}}
        {{-- NOTE: no Flux `sticky` prop — it freezes `top` to a JS-read offsetTop and floats
             the bar mid-page when the read races the body grid. `sticky top-0` below is pure CSS. --}}
        <flux:header class="sticky top-0 z-40 hidden min-h-[64px] items-center gap-3 !border-b-0 bg-[#eff6ff] dark:bg-[#0c1a36] px-4 py-2 sm:px-6 lg:flex">

            {{-- Search bar (matches storefront home-nav style) with results panel --}}
            <div
                x-data="dashboardSearch()"
                @click.outside="open = false"
                @keydown.escape.window="open = false"
                @keydown.window.prevent.ctrl.k="open = true; $nextTick(() => $refs.searchInput.focus())"
                @keydown.window.prevent.meta.k="open = true; $nextTick(() => $refs.searchInput.focus())"
                class="relative min-w-0 flex-1 max-w-2xl"
            >
                <div
                    role="search"
                    @click="$refs.searchInput.focus(); open = true"
                    :class="open ? 'border-blue-500 ring-2 ring-blue-500/15' : 'border-zinc-400 hover:border-zinc-500'"
                    class="group flex w-full items-center gap-3 cursor-text rounded-[15px] border-2 bg-[#eff6ff] px-4 py-2 transition-all duration-200"
                >
                    <svg class="h-5 w-5 shrink-0 text-zinc-900" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    {{-- `readonly` until focus defeats Chrome/password-manager autofill,
                         which otherwise ignores autocomplete="off" and injects the
                         user's saved login email here — that auto-filled value tripped
                         @focus and made the dropdown spring open on every page load. The
                         field becomes editable the moment the user actually focuses it. --}}
                    <input
                        x-ref="searchInput"
                        x-model="query"
                        @input="onInput()"
                        @focus="$el.removeAttribute('readonly'); open = true"
                        type="search"
                        name="dashboard_search"
                        placeholder="Search products, brands or categories"
                        aria-label="Search products, brands or categories"
                        readonly
                        autocomplete="off"
                        autocorrect="off"
                        autocapitalize="off"
                        spellcheck="false"
                        data-1p-ignore
                        data-lpignore="true"
                        class="flex-1 min-w-0 bg-transparent text-base text-zinc-800 placeholder:text-zinc-600 outline-none"
                    />
                    <span class="hidden items-center gap-1 rounded-[12px] border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 text-[10px] font-semibold text-zinc-600 sm:inline-flex">
                        CTRL <span class="text-zinc-600">+</span> K
                    </span>
                </div>

                {{-- Results panel --}}
                <div
                    x-show="open"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 translate-y-1"
                    style="display:none;"
                    class="absolute left-0 right-0 top-full z-50 mt-2 overflow-hidden rounded-[12px] bg-[#eff6ff] shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200 dark:ring-[#24364f]"
                >
                    {{-- Filter tabs --}}
                    <div class="border-b border-zinc-100 p-3">
                        <div class="flex flex-wrap gap-2">
                            <template x-for="tab in tabs" :key="tab">
                                <button
                                    type="button"
                                    @click="activeTab = tab"
                                    :class="activeTab === tab ? 'bg-zinc-900 text-white' : 'bg-zinc-50 text-zinc-700 hover:bg-blue-100 hover:text-blue-700'"
                                    class="rounded-[12px] px-3 py-1.5 text-xs font-semibold transition-colors"
                                    x-text="tab"
                                ></button>
                            </template>
                        </div>
                    </div>

                    {{-- Product & brand results — live catalogue search (2+ characters),
                         same /api/search/brands endpoint as the storefront nav search. --}}
                    <div x-show="searching" class="p-2">
                        <p class="px-3 pb-1 pt-2 text-xs font-semibold text-zinc-600">Products &amp; brands</p>

                        {{-- Loading --}}
                        <div x-show="loading" class="flex items-center gap-2 px-3 py-6 text-sm text-zinc-500">
                            <svg class="h-4 w-4 animate-spin text-blue-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-30" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"/>
                                <path class="opacity-90" fill="currentColor" d="M12 3a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6V3z"/>
                            </svg>
                            Searching the catalogue&hellip;
                        </div>

                        {{-- Matches --}}
                        <template x-for="r in results" :key="r.url">
                            <a :href="r.url" class="flex items-center justify-between gap-3 rounded-[12px] px-3 py-2.5 transition-colors hover:bg-blue-100">
                                <span class="flex min-w-0 items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-white ring-1 ring-zinc-200">
                                        <template x-if="r.logo"><img :src="r.logo" alt="" class="h-full w-full object-cover"></template>
                                        <template x-if="! r.logo"><span class="text-[10px] font-bold uppercase text-zinc-500" x-text="r.name.slice(0, 2)"></span></template>
                                    </span>
                                    <span class="min-w-0 leading-tight">
                                        <span class="block truncate text-sm font-semibold text-zinc-900" x-text="r.name"></span>
                                        <span class="block text-xs text-zinc-600" x-text="r.type"></span>
                                    </span>
                                </span>
                                <svg class="h-4 w-4 shrink-0 text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                                </svg>
                            </a>
                        </template>

                        {{-- No matches --}}
                        <div x-show="! loading && results.length === 0" class="px-3 py-6 text-center text-sm text-zinc-500">
                            No products match &ldquo;<span x-text="query"></span>&rdquo;.
                        </div>

                        {{-- See all results --}}
                        <a
                            x-show="! loading && results.length > 0"
                            :href="'/gift-cards?q=' + encodeURIComponent(query)"
                            class="mt-1 flex items-center justify-center gap-1.5 rounded-[12px] px-3 py-2.5 text-sm font-semibold text-blue-600 transition-colors hover:bg-blue-50"
                        >
                            See all results
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                            </svg>
                        </a>
                    </div>

                    {{-- Most used — shown until the user starts a product search. --}}
                    <div x-show="! searching" class="p-2">
                        <p class="px-3 pb-1 pt-2 text-xs font-semibold text-zinc-600">Most used</p>

                        @php
                            $searchItems = [
                                ['Wallet',          'Top up & balance',       'Wallet.svg',         route('dashboard.wallet')],
                                ['Orders',          'Recent purchases',       'order.svg',          route('dashboard.orders')],
                                ['Transactions',    'Activity history',       'transactions.svg',   route('dashboard.transactions')],
                                ['Settings',        'Account information',    'system setting.svg', route('dashboard.profile')],
                                ['Security',        'Password & sessions',    'admin access.svg',   route('dashboard.security')],
                            ];
                        @endphp

                        @foreach ($searchItems as [$title, $subtitle, $icon, $href])
                            <a href="{{ $href }}" wire:navigate.hover class="flex items-center justify-between gap-3 rounded-[12px] px-3 py-2.5 transition-colors hover:bg-blue-100">
                                <span class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] bg-zinc-100 ring-1 ring-zinc-200">
                                        <img src="{{ asset('assets/' . rawurlencode($icon)) }}" alt="" class="h-4 w-4" loading="lazy">
                                    </span>
                                    <span class="leading-tight">
                                        <span class="block text-sm font-semibold text-zinc-900">{{ $title }}</span>
                                        <span class="block text-xs text-zinc-600">{{ $subtitle }}</span>
                                    </span>
                                </span>
                                <svg class="h-4 w-4 text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                                </svg>
                            </a>
                        @endforeach
                    </div>

                    {{-- Help footer --}}
                    <div class="border-t border-zinc-100 px-4 py-3">
                        <p class="text-xs text-zinc-600">Type above to search products, brands, orders or account settings.</p>
                    </div>
                </div>
            </div>

            <flux:spacer />

            {{-- Cart. State lives in the global Alpine cart store ($store.cart) — the same
                 store the storefront nav uses. The popup drops open automatically when an
                 item is added (store.add sets open = true). --}}
            <div
                x-data="{ locked: false }"
                @mouseenter="if (!locked) $store.cart.open = true"
                @mouseleave="if (!locked) $store.cart.open = false"
                @click.outside="$store.cart.open = false; locked = false"
                @keydown.escape.window="$store.cart.open = false; locked = false"
                class="relative"
            >
                <button
                    type="button"
                    @click="locked = !locked; $store.cart.open = locked"
                    :aria-expanded="$store.cart.open.toString()"
                    aria-haspopup="menu"
                    class="relative flex h-10 w-10 items-center justify-center rounded-[10px] bg-zinc-200 text-zinc-900 dark:text-zinc-200 transition-colors hover:bg-blue-600 hover:text-white"
                    aria-label="Shopping cart"
                >
                    {{-- Masked so the cart glyph inherits the chip's text colour (dark on grey, white on blue hover) - matches the admin header chips. --}}
                    <span class="h-6 w-6 shrink-0 bg-current" style="-webkit-mask: url('{{ asset('assets/'.rawurlencode('new cart.svg')) }}') center / contain no-repeat; mask: url('{{ asset('assets/'.rawurlencode('new cart.svg')) }}') center / contain no-repeat;"></span>
                    <span
                        x-show="$store.cart.count > 0"
                        x-text="$store.cart.count"
                        x-cloak
                        class="absolute -top-0.5 -right-0.5 inline-flex h-5 min-w-[20px] items-center justify-center rounded-[5px] bg-blue-600 px-1 text-[10px] font-bold text-white"
                    ></span>
                </button>

                {{-- Cart popup — mirrors the storefront nav popup --}}
                <div
                    x-show="$store.cart.open"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1"
                    style="display:none;"
                    class="absolute right-0 top-full z-50 mt-2 w-[340px] overflow-hidden rounded-[12px] bg-white/80 px-3 py-2 backdrop-blur-xl shadow-xl shadow-zinc-900/15 ring-1 ring-zinc-200"
                    role="menu"
                >
                    {{-- Empty state --}}
                    <div x-show="$store.cart.count === 0" class="flex flex-col items-center px-3 py-5 text-center">
                        <h3 class="text-xl font-bold text-zinc-900">Your cart is empty</h3>
                        <x-illo name="emptyCart" class="mx-auto mt-4 w-full max-w-[230px]" />
                        <p class="mt-3 text-sm text-zinc-600">Your cart needs items</p>
                    </div>

                    {{-- Populated state --}}
                    <div x-show="$store.cart.count > 0" x-cloak>
                        <div class="flex items-center justify-between px-3 pt-3">
                            <h3 class="text-lg font-bold text-zinc-900">Your cart</h3>
                            <span class="text-sm text-zinc-600" x-text="$store.cart.count + ' item' + ($store.cart.count === 1 ? '' : 's')"></span>
                        </div>

                        <ul class="mt-3 max-h-72 space-y-1 overflow-y-auto px-0 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                            <template x-for="item in $store.cart.items" :key="item.id">
                                <li class="flex items-center gap-3 rounded-[12px] px-3 py-2.5">
                                    <span class="flex h-16 w-24 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-white shadow-sm ring-1 ring-zinc-200">
                                        <template x-if="item.logo">
                                            <img :src="item.logo" alt="" class="h-full w-full object-cover">
                                        </template>
                                        <template x-if="!item.logo">
                                            <span class="text-sm font-black uppercase text-zinc-700" x-text="item.name.substring(0,2).toUpperCase()"></span>
                                        </template>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-bold text-zinc-900" x-text="item.name"></span>
                                        <span class="mt-0.5 block text-xs font-semibold text-zinc-700" x-text="$store.cart.pay(item.unit_price)"></span>
                                    </span>
                                    <span class="flex shrink-0 items-center gap-1.5">
                                        <button type="button" @click="$store.cart.setQty(item.id, item.quantity - 1)" class="flex h-7 w-7 items-center justify-center rounded-[12px] text-zinc-600 ring-1 ring-zinc-200 transition-colors hover:bg-zinc-100 hover:text-zinc-900" aria-label="Decrease">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M5 12h14"/></svg>
                                        </button>
                                        <span class="w-5 text-center text-sm font-bold tabular-nums text-zinc-900" x-text="item.quantity"></span>
                                        <button type="button" @click="$store.cart.setQty(item.id, item.quantity + 1)" class="flex h-7 w-7 items-center justify-center rounded-[12px] text-zinc-600 ring-1 ring-zinc-200 transition-colors hover:bg-zinc-100 hover:text-zinc-900" aria-label="Increase">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/></svg>
                                        </button>
                                    </span>
                                </li>
                            </template>
                        </ul>

                        <div class="mt-3 flex gap-2 rounded-[12px] bg-zinc-50 px-3 py-3">
                            <a href="{{ route('dashboard.shop.cart') }}" wire:navigate.hover @click="$store.cart.open = false; locked = false" class="flex-1 inline-flex items-center justify-center rounded-[12px] bg-white px-4 py-3.5 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-200 transition-colors hover:bg-zinc-100">
                                View cart
                            </a>
                            <a :href="'{{ route('dashboard.shop.checkout') }}' + ($store.cart.showUsd ? '?currency=' + $store.cart.currency : '')" wire:navigate.hover @click="$store.cart.open = false" class="flex-1 inline-flex items-center justify-center rounded-[12px] bg-blue-600 px-4 py-3.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                                Checkout
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Regional country/language switcher intentionally lives only on the
                 shop/category pages (storefront layout), not the dashboard - it
                 changes the catalog region, which doesn't apply to account pages.
                 The user's own account country is set in profile settings. --}}

            {{-- Notification bell (desktop) — hidden lg:block so it never doubles
                 up with the mobile hero bell on small screens. 'chip' tone matches
                 the admin header (square grey chip, fills blue on hover). --}}
            <div class="hidden lg:block">
                <livewire:notifications-menu tone="chip" />
            </div>

            {{-- Profile dropdown.
                 Owns its own dropdown state + a small inline currency picker.
                 Hovers open the dropdown; clicks lock it open until clicked elsewhere.
                 Locale state lives on a separate body-root wrapper around the modal
                 so the modal can render outside flux:header (avoids broken centering
                 from transformed sticky ancestors). The locale chip values here listen
                 to a 'locale-updated' window event dispatched by the modal wrapper. --}}
            <div
                x-data="{
                    open: false,
                    locked: false,
                    country: 'United States',
                    countryFlag: '🇺🇸',
                    countryCode: 'US',
                    language: 'English',
                }"
                @mouseenter="if (!locked) open = true"
                @mouseleave="if (!locked) open = false"
                @click.outside="open = false; locked = false"
                @keydown.escape.window="open = false; locked = false"
                @locale-updated.window="country = $event.detail.country; countryFlag = $event.detail.countryFlag; countryCode = $event.detail.countryCode; language = $event.detail.language"
                class="relative"
            >
                {{-- Avatar button. No notification dot here on purpose - the
                     bell icon to the left of this button already surfaces the
                     unread count, and doubling the indicator made the chrome
                     read noisier than the data warranted. --}}
                <button type="button" @click="locked = !locked; open = locked" :aria-expanded="open.toString()" aria-label="{{ $user?->name ?? 'Account' }}" class="relative flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-[10px] bg-zinc-200 ring-1 ring-zinc-200 transition-all hover:ring-2 hover:ring-blue-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 dark:ring-zinc-700/60">
                    <img src="{{ $user?->avatar_url ?: $defaultAvatar }}" alt="{{ $user?->name ?? 'Account' }}" class="h-full w-full object-cover">
                </button>

                {{-- KYC verified tick on the desktop header avatar. --}}
                @if (($user?->kyc_status ?? null) === 'verified')
                    <x-ui.verified-badge class="pointer-events-none absolute -bottom-1.5 -right-1.5 h-3.5 w-3.5 drop-shadow-sm" />
                @endif

                <div
                    x-show="open"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1"
                    style="display:none;"
                    class="absolute right-0 top-full z-50 mt-2 w-[260px] overflow-hidden rounded-2xl bg-[#eff6ff] shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200 dark:ring-[#24364f]"
                    role="menu"
                >
                    <div class="border-b border-zinc-200 px-4 py-3">
                        <p class="truncate text-sm font-semibold text-zinc-900">{{ $user?->name ?? 'Account' }}</p>
                        <p class="truncate text-xs text-zinc-600">{{ $user?->email ?? '' }}</p>
                    </div>

                    @php
                        // Inline style filter that forces any colored SVG (loaded as <img>) to render as pure black,
                        // so all menu icons share the same monochrome treatment regardless of their source colors.
                        $iconBlack = 'filter: brightness(0) saturate(100%);';
                    @endphp
                    <div class="p-1.5">
                        <a href="{{ route('dashboard.profile') }}" wire:navigate.hover class="flex items-center gap-3 rounded-[12px] px-3 py-2 text-sm font-medium text-zinc-900 transition-colors hover:bg-blue-100" role="menuitem">
                            <img src="{{ asset('assets/user.svg') }}" alt="" class="h-5 w-5 shrink-0" style="{{ $iconBlack }}" loading="lazy">
                            Profile
                        </a>
                        <a href="{{ route('dashboard.security') }}" wire:navigate.hover class="flex items-center gap-3 rounded-[12px] px-3 py-2 text-sm font-medium text-zinc-900 transition-colors hover:bg-blue-100" role="menuitem">
                            <img src="{{ asset('assets/' . rawurlencode('admin access.svg')) }}" alt="" class="h-5 w-5 shrink-0" style="{{ $iconBlack }}" loading="lazy">
                            Security
                        </a>
                        <a href="{{ route('dashboard.appearance') }}" wire:navigate.hover class="flex items-center gap-3 rounded-[12px] px-3 py-2 text-sm font-medium text-zinc-900 transition-colors hover:bg-blue-100" role="menuitem">
                            <img src="{{ asset('assets/' . rawurlencode('Appearance.svg')) }}" alt="" class="h-5 w-5 shrink-0" style="{{ $iconBlack }}" loading="lazy">
                            Appearance
                        </a>

                        {{-- (Regional country/language switcher removed from the
                             dashboard - it belongs on shop/category pages only.) --}}

                        <a href="{{ route('dashboard.notifications') }}" wire:navigate.hover class="flex items-center justify-between gap-3 rounded-[12px] px-3 py-2 text-sm font-medium text-zinc-900 transition-colors hover:bg-blue-100" role="menuitem">
                            <span class="flex items-center gap-3">
                                <img src="{{ asset('assets/' . rawurlencode('notification 2.svg')) }}" alt="" class="h-5 w-5 shrink-0" style="{{ $iconBlack }}" loading="lazy">
                                Notifications
                            </span>
                            @if ($notificationCount > 0)
                                <span class="inline-flex h-5 min-w-[20px] items-center justify-center rounded-[5px] bg-red-500 px-1 text-[10px] font-bold text-white">{{ $notificationCount }}</span>
                            @endif
                        </a>
                        <a href="{{ route('home') }}" wire:navigate.hover class="flex items-center gap-3 rounded-[12px] px-3 py-2 text-sm font-medium text-zinc-900 transition-colors hover:bg-blue-100" role="menuitem">
                            <img src="{{ asset('assets/' . rawurlencode('Back to shop.svg')) }}" alt="" class="h-5 w-5 shrink-0" style="{{ $iconBlack }}" loading="lazy">
                            Back to store
                        </a>
                    </div>

                    <div class="border-t border-zinc-200 p-1.5">
                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                            @csrf
                            <button type="submit" class="flex w-full items-center gap-3 rounded-[12px] px-3 py-2 text-left text-sm font-medium text-red-600 transition-colors hover:bg-red-100 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-500/15 dark:hover:text-red-300">
                                <x-ui.logout-icon class="h-5 w-5" />
                                Log out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </flux:header>

        @php
            // The blue hero shows ONLY on the dashboard overview. Every other dashboard
            // page uses the slim white inner top bar (hamburger + title + bell) below.
            $skipMobileHero = ! request()->routeIs('dashboard');

            // Top chrome placement: the glass nav band and the notification pill
            // belong to the OVERVIEW alone. Store pages keep only the two shopping
            // pills - cart and country switcher - floating without a band, because
            // that is where the cart can be acted on and where the country switcher
            // changes prices you are about to pay. Task/inner pages (orders,
            // wallet, settings, ...) get no top chrome at all; the bottom nav bar
            // is their navigation.
            $showMobileTopChrome = request()->routeIs('dashboard') || request()->routeIs('dashboard.shop.*');

            // The bell is overview-only furniture, same as the identity strip.
            $showNotificationChrome = ! $skipMobileHero;

            // No back affordance anywhere: the bottom nav bar is on every page and its
            // centre Menu FAB opens the full sidebar nav, so every destination is one
            // tap away without one. Removing it also keeps the top band identical on
            // every page instead of swapping its left slot per route.
        @endphp

        {{-- flux:main is the single main-axis child of Flux's layout container.
             ANYTHING that should stack vertically with the page content (mobile blue hero,
             slim inner-page bar, the page slot) goes INSIDE it — otherwise Flux's flex
             container puts them side-by-side with flux:main on mobile. --}}
        <flux:main class="!p-0 bg-[#eff6ff] lg:bg-white dark:bg-[#0c1a36] dark:lg:bg-[#0c1a36]">

        {{-- Mobile header (blue hero, visible only on mobile).
             Pages can extend the blue area by filling the $mobileHero slot
             (e.g. wallet card on the overview). Inner pages with their own sticky header
             (settings, password, appearance) skip this hero entirely. --}}
        @unless ($skipMobileHero)
        {{-- Identity strip - OVERVIEW ONLY, a fixed frosted-glass nav; the page
             scrolls beneath it. Fixed instead of sticky because the
             pull-to-refresh transform moves sticky ancestors and makes them
             bounce. The z-[55] chrome pills (flag / cart / bell) float on top
             of this band, composing one nav. Store and task pages render no
             band at all - store pages carry only the floating shopping pills. --}}
        <div class="nav-glass-top fixed inset-x-0 top-0 z-40 px-5 pb-3 lg:hidden" style="padding-top: max(0.75rem, env(safe-area-inset-top));">
            {{-- Compact identity strip - avatar + small online dot on the left,
                 notification bell on the right. Replaces the old "Hello /
                 Welcome back" greeting so the wallet card sits closer to the
                 top and the product categories get more vertical room. --}}
            <div class="flex items-center justify-between gap-3 text-zinc-900 dark:text-white">
                @php $mobileFirstName = strtok(trim((string) ($user?->name ?? '')), ' ') ?: ''; @endphp
                <div class="flex min-w-0 items-center gap-2.5">
                    {{-- The avatar taps through to Settings. Pull-to-refresh already
                         handles reloading fresh wallet / order data, so the tap is
                         free for the more useful destination. --}}
                    <a href="{{ route('dashboard.profile') }}" wire:navigate.hover aria-label="Open settings" class="relative inline-flex shrink-0 items-center transition-transform active:scale-95">
                        <span class="flex h-10 w-10 items-center justify-center overflow-hidden rounded-full bg-blue-100 text-sm font-bold uppercase text-blue-700 ring-2 ring-blue-200 dark:bg-white/15 dark:text-white dark:ring-white/30">
                            @if ($user?->avatar_url)
                                <img src="{{ $user->avatar_url }}" alt="{{ $user->name }}" class="h-full w-full object-cover" loading="lazy">
                            @else
                                {{ str($user?->name ?? '?')->substr(0, 1) }}
                            @endif
                        </span>
                        @if (($user?->kyc_status ?? null) === 'verified')
                            {{-- KYC verified: small blue tick over the avatar. The badge
                                 has its own white edge so it pops on the blue hero. --}}
                            <x-ui.verified-badge class="absolute -bottom-1 -right-1 h-4 w-4 drop-shadow-sm" />
                        @else
                            <span class="absolute -bottom-1 right-0 h-2.5 w-2.5 rounded-full bg-emerald-500 ring-2 ring-[#eff6ff] dark:ring-[#0c1a36]" aria-label="Online"></span>
                        @endif
                    </a>
                    {{-- The greeting doubles as a storefront shortcut. The tiny
                         chip keeps an obvious door back to the storefront for PWA
                         users, who have no browser address bar to type into. --}}
                    <a
                        href="{{ route('home') }}"
                        wire:navigate.hover
                        class="flex min-w-0 items-center gap-2 transition-transform active:scale-95"
                        aria-label="Go to the storefront"
                    >
                        <span class="truncate text-base font-semibold">Hi {{ $mobileFirstName }}</span>
                        <span class="shrink-0 rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-blue-700 ring-1 ring-blue-200 dark:bg-white/20 dark:text-white dark:ring-white/30">Visit site</span>
                    </a>
                </div>
                {{-- The locale / cart / notification chips render in the pinned
                     floating row (see the mobile top chrome below), so the hero
                     carries no copy of its own. This spacer keeps the identity
                     strip clear of the fixed pills above it. --}}
                <span class="h-10 w-[8.5rem] shrink-0" aria-hidden="true"></span>
            </div>
        </div>

        {{-- Spacer holding the glass nav's room in normal flow. --}}
        <div class="lg:hidden" style="height: calc(max(0.75rem, env(safe-area-inset-top)) + 3.25rem);" aria-hidden="true"></div>

        {{-- Blue hero - overview only, same @unless as the identity strip above.
             Scrolls away with the page; the floating wallet chip + Top Up button
             inside <x-slot:mobileHero> take over once the hero leaves the viewport. --}}
        {{-- Wallet card slot - scrolls with the page (under the glass nav),
             so the wallet info hits the customer immediately on page load,
             leaving the rest of the viewport for product categories. --}}
        <header class="relative z-10 bg-transparent px-5 pb-6 lg:hidden">
            @isset($mobileHero)
                <div class="mt-3">
                    {{ $mobileHero }}
                </div>
            @endisset
        </header>
        @endunless

        {{-- Mobile cart sheet. The desktop cart popup lives in the lg:flex header
             (hidden on phones), so on mobile the shared $store.cart.open state needs
             its own surface. Teleported to <body> and pinned above the mobile bottom
             nav, it drops open the moment an item is added - mirroring the storefront
             nav so adding to cart on a phone gives the same feedback. --}}
        <div x-data>
            <template x-teleport="body">
                <div class="lg:hidden">
                    <div
                        x-show="$store.cart.open"
                        x-transition.opacity.duration.150ms
                        @click="$store.cart.open = false"
                        style="display:none;"
                        class="fixed inset-0 z-[80]"
                    ></div>
                    <div
                        x-show="$store.cart.open"
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 translate-y-2"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 translate-y-2"
                        style="display:none;"
                        class="fixed inset-x-3 bottom-20 z-[80] max-w-[calc(100vw-1.5rem)] overflow-hidden rounded-[14px] bg-white/80 px-3 py-2 backdrop-blur-2xl backdrop-saturate-150 shadow-xl shadow-zinc-900/15 ring-1 ring-zinc-200 dark:bg-[#0c1a36]/80 dark:ring-white/10"
                        role="menu"
                    >
                        {{-- Empty state --}}
                        <div x-show="$store.cart.count === 0" class="flex flex-col items-center px-3 py-5 text-center">
                            <h3 class="text-xl font-bold text-zinc-900 dark:text-white">Your cart is empty</h3>
                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Your cart needs items</p>
                        </div>

                        {{-- Populated state --}}
                        <div x-show="$store.cart.count > 0" x-cloak>
                            <div class="flex items-center justify-between px-3 pt-3">
                                <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Your cart</h3>
                                <span class="text-sm text-zinc-600 dark:text-zinc-400" x-text="$store.cart.count + ' item' + ($store.cart.count === 1 ? '' : 's')"></span>
                            </div>

                            <ul class="mt-3 max-h-72 space-y-1 overflow-y-auto px-0 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                                <template x-for="item in $store.cart.items" :key="item.id">
                                    <li class="flex items-center gap-3 rounded-[12px] px-3 py-2.5">
                                        <span class="flex h-16 w-24 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-white shadow-sm ring-1 ring-zinc-200">
                                            <template x-if="item.logo">
                                                <img :src="item.logo" alt="" class="h-full w-full object-cover">
                                            </template>
                                            <template x-if="!item.logo">
                                                <span class="text-sm font-black uppercase text-zinc-700" x-text="item.name.substring(0,2).toUpperCase()"></span>
                                            </template>
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm font-bold text-zinc-900 dark:text-white" x-text="item.name"></span>
                                            <span class="mt-0.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300" x-text="$store.cart.pay(item.unit_price)"></span>
                                        </span>
                                        <span class="flex shrink-0 items-center gap-1.5">
                                            <button type="button" @click="$store.cart.setQty(item.id, item.quantity - 1)" class="flex h-7 w-7 items-center justify-center rounded-[12px] text-zinc-600 dark:text-zinc-300 ring-1 ring-zinc-200 dark:ring-white/15 transition-colors hover:bg-zinc-100 dark:hover:bg-white/10 hover:text-zinc-900 dark:hover:text-white" aria-label="Decrease">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M5 12h14"/></svg>
                                            </button>
                                            <span class="w-5 text-center text-sm font-bold tabular-nums text-zinc-900 dark:text-white" x-text="item.quantity"></span>
                                            <button type="button" @click="$store.cart.setQty(item.id, item.quantity + 1)" class="flex h-7 w-7 items-center justify-center rounded-[12px] text-zinc-600 dark:text-zinc-300 ring-1 ring-zinc-200 dark:ring-white/15 transition-colors hover:bg-zinc-100 dark:hover:bg-white/10 hover:text-zinc-900 dark:hover:text-white" aria-label="Increase">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/></svg>
                                            </button>
                                        </span>
                                    </li>
                                </template>
                            </ul>

                            <div class="mt-3 flex gap-2 rounded-[12px] bg-zinc-50 dark:bg-white/5 px-3 py-3">
                                <a href="{{ route('dashboard.shop.cart') }}" wire:navigate.hover @click="$store.cart.open = false" class="flex-1 inline-flex items-center justify-center rounded-[12px] bg-white dark:bg-white/10 px-4 py-3.5 text-sm font-semibold text-zinc-700 dark:text-zinc-200 ring-1 ring-zinc-200 dark:ring-white/15 transition-colors hover:bg-zinc-100 dark:hover:bg-white/15">
                                    View cart
                                </a>
                                <a :href="'{{ route('dashboard.shop.checkout') }}' + ($store.cart.showUsd ? '?currency=' + $store.cart.currency : '')" wire:navigate.hover @click="$store.cart.open = false" class="flex-1 inline-flex items-center justify-center rounded-[12px] bg-blue-600 px-4 py-3.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                                    Checkout
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        {{-- Sticky mobile top bar for inner pages (settings, password, appearance).
             Acts as the single mobile chrome on those pages — title derived from the route
             so individual pages don't need to render their own sticky header. --}}
        @if ($skipMobileHero)
            @php
                $innerTitle = match (true) {
                    request()->routeIs('dashboard.orders')        => 'Orders',
                    request()->routeIs('dashboard.transactions')  => 'Transactions',
                    request()->routeIs('dashboard.wallet')        => 'Wallet',
                    request()->routeIs('dashboard.notifications') => 'Notifications',
                    request()->routeIs('dashboard.saved-cards')   => 'Saved Cards',
                    request()->routeIs('dashboard.kyc')           => 'Verify Identity',
                    request()->routeIs('dashboard.rewards')       => 'Rewards',
                    request()->routeIs('dashboard.security')      => 'Security',
                    request()->routeIs('dashboard.appearance')    => 'Appearance',
                    default                                       => 'Settings',
                };
            @endphp
            {{-- Nothing renders in flow here. The back button now lives in the
                 identity band at the top of this file (it swaps with the avatar),
                 and the locale / cart / notification pills live in the FIXED chrome
                 below. Both used to be duplicated here, which made the pills appear
                 twice on scroll. --}}
        @endif

            {{-- ─────────────────────────────────────────────────────── --}}
            {{-- FLOATING TOP CHROME (top cart / country switcher / bell) --}}
            {{-- Pins to the viewport once the page scrolls past the      --}}
            {{-- header. Overview + shop pages only; hidden elsewhere.     --}}
            {{-- ─────────────────────────────────────────────────────── --}}
            @if ($showMobileTopChrome)
            {{-- ALWAYS pinned - no scroll-triggered show/hide, so the pills can
                 never flicker or shake. This row IS the chips: the overview gets
                 flag + cart + bell floating over its glass band; shop pages get
                 flag + cart alone, with no band underneath. --}}
            <div
                x-data="{
                    scrolled: false,
                    country: 'United States',
                    countryCode: 'US',
                    language: 'English',
                    // Hysteresis for the hamburger only: appears past 96px, leaves
                    // under 40px, so boundary jitter can't toggle it repeatedly.
                    onScroll() {
                        const y = window.scrollY;
                        if (! this.scrolled && y > 96) { this.scrolled = true; }
                        else if (this.scrolled && y < 40) { this.scrolled = false; }
                    },
                    init() {
                        this.onScroll();
                        window.addEventListener('scroll', () => this.onScroll(), { passive: true });
                    },
                }"
                @locale-updated.window="country = $event.detail.country; countryCode = $event.detail.countryCode; language = $event.detail.language"
                class="pointer-events-none fixed inset-x-0 z-[55] flex items-center justify-end gap-2 px-3 lg:hidden"
                style="top: max(0.5rem, env(safe-area-inset-top));"
            >
                {{-- Locale + cart chips (right) - same glass treatment, pinned.
                     They ride wherever this row renders (overview + shop). The
                     bell below is additionally scoped to the overview, where the
                     nav band it visually belongs to lives. --}}
                <div class="pointer-events-auto flex items-center gap-2" x-data>
                    {{-- Language + currency toggle (opens the locale modal). --}}
                    <button
                        type="button"
                        x-data="{ code: (localStorage.getItem('locale.countryCode') || 'US').toLowerCase() }"
                        @locale-updated.window="code = ($event.detail.countryCode || code).toLowerCase()"
                        x-on:click="$dispatch('open-locale-modal')"
                        aria-label="Region, language and currency"
                        class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#eff6ff] border border-zinc-200 transition-colors active:scale-95"
                    >
                        <img :src="'https://flagcdn.com/w80/' + code + '.png'" alt="" class="h-full w-full object-cover" loading="lazy">
                    </button>

                    <a
                        href="{{ route('dashboard.shop.cart') }}"
                        wire:navigate.hover
                        aria-label="Cart"
                        class="relative flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#eff6ff] border border-zinc-200 transition-colors active:scale-95"
                    >
                        <img src="{{ asset('assets/' . rawurlencode('new cart.svg')) }}" alt="" class="h-5 w-5 dark:brightness-0 dark:invert" style="filter: brightness(0) saturate(100%);" loading="lazy">
                        <span
                            x-show="$store.cart && $store.cart.count > 0"
                            x-text="$store.cart ? $store.cart.count : ''"
                            x-cloak
                            class="absolute -top-1 -right-1 inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-blue-600 px-1 text-[10px] font-bold leading-none text-white ring-2 ring-white/60 dark:ring-[#0c1a36]"
                        ></span>
                    </a>
                    @if ($showNotificationChrome)
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#eff6ff] border border-zinc-200">
                            <livewire:notifications-menu tone="dark" wire:key="floating-notif-inner" />
                        </div>
                    @endif
                </div>
            </div>
            @endif

            {{-- Status-bar / floating-pill spacer for every page that is NOT the
                 overview. The overview has its own spacer above (it holds the
                 glass band's room); nothing held room here, so once
                 viewport-fit=cover made env(safe-area-inset-top) resolve to a
                 real number the viewport grew upward under the status bar and
                 these pages had only pt-5 to survive on - the whole page read as
                 shifted up. Shop pages additionally float the 2.75rem pill row
                 (flag + cart) over the page with no band beneath it, so they
                 reserve its height too; task pages only owe the inset. --}}
            @if ($skipMobileHero)
                <div
                    class="lg:hidden"
                    style="height: {{ $showMobileTopChrome ? 'calc(max(0.5rem, env(safe-area-inset-top)) + 3.25rem)' : 'env(safe-area-inset-top)' }};"
                    aria-hidden="true"
                ></div>
            @endif

            <div data-page-content class="flex flex-col bg-[#eff6ff] px-4 pt-5 pb-28 sm:px-6 sm:pt-6 lg:min-h-full lg:px-10 lg:py-8 dark:bg-[#0c1a36]">
                <div class="w-full min-w-0 lg:flex-1">
                    {{-- Suspension banner: visible on every dashboard page when the
                         account is suspended. Carries the admin-authored reason +
                         the Request Review button (idempotent — re-clicks are safe). --}}
                    @auth
                        @if (auth()->user()->isSuspended())
                            @include('partials.suspension-banner')
                        @elseif (auth()->user()->isRestricted())
                            @include('partials.protected-mode-banner')
                        @endif
                    @endauth

                    {{ $slot }}
                </div>

                {{-- Smile ID verification modal (window.rshopKyc). Shared across
                     dashboard pages so the wallet-funding step-up flow can launch
                     identity verification, exactly like checkout. --}}
                @auth
                    @include('partials.kyc-verification-modal')
                @endauth

                {{-- Footer — Privacy Policy + version + copyright. Mirrors the
                     admin layout. Full-width so the legal line sits flush with
                     the page edges. `mt-auto` pins it to the bottom. --}}
                <footer class="mt-auto hidden w-full pt-12 flex-wrap items-center justify-end gap-x-4 gap-y-1 text-[11px] font-semibold text-zinc-600 lg:flex dark:text-zinc-300">
                    <a href="{{ route('shop.privacy') }}" wire:navigate.hover class="hover:text-zinc-900 dark:hover:text-white">Privacy Policy</a>
                    <span class="text-zinc-300 dark:text-zinc-600">·</span>
                    <span>version 2.0.0</span>
                    <span class="text-zinc-300 dark:text-zinc-600">·</span>
                    <span>©{{ $siteName }} {{ date('Y') }}</span>
                </footer>
            </div>
        </flux:main>

        {{-- Global confirm modal — intercepts any form/button with `data-confirm`. --}}
        <x-confirm-modal />

        {{-- Mobile bottom tab bar (mobile + tablet). Five equal inline tabs:
             Home, Orders, Menu, Transactions, Profile. The active tab is derived
             from the current route so the right one highlights on first paint. --}}
        @php
            $activeIndex = match (true) {
                request()->routeIs('dashboard.orders*')       => 1,
                request()->routeIs('dashboard.transactions*') => 3,
                request()->routeIs('dashboard.profile')       => 4,
                request()->routeIs('dashboard.security')      => 4,
                request()->routeIs('dashboard.appearance')    => 4,
                default                                       => 0, // dashboard / overview
            };
        @endphp
        @php
            $tabs = [
                ['idx' => 0, 'href' => route('dashboard'),              'icon' => 'Home.svg',           'label' => 'Home',         'nav' => true],
                ['idx' => 1, 'href' => route('dashboard.orders'),       'icon' => 'order.svg',          'label' => 'Orders',       'nav' => true],
                // index 2 is the Menu button, rendered between the two loops
                ['idx' => 3, 'href' => route('dashboard.transactions'), 'icon' => 'transactions 1.svg', 'label' => 'History',      'nav' => true],
                ['idx' => 4, 'href' => route('dashboard.profile'),      'icon' => 'system setting.svg', 'label' => 'Settings',     'nav' => true],
            ];

            // Successful (completed) order count - drives the Orders tab badge.
            $successfulOrderCount = auth()->user()
                ? (int) auth()->user()->orders()->where('order_status', \App\Domain\Order\Enums\OrderStatus::Completed)->count()
                : 0;
        @endphp
        {{-- Frosted scrim behind the floating nav: as the page scrolls, content
             blurs + fades into the bottom-bar area instead of showing sharply behind
             it. Fixed strip below the nav (z-40), masked so the blur eases in from
             nothing at the top to full at the bottom; never blocks taps. --}}
        <div
            aria-hidden="true"
            class="pointer-events-none fixed inset-x-0 bottom-0 z-40 h-28 backdrop-blur-md lg:hidden"
            style="-webkit-mask-image: linear-gradient(to top, #000 38%, transparent); mask-image: linear-gradient(to top, #000 38%, transparent);"
        ></div>

        {{-- Floating rounded nav bar (neobank style): detached from the edges with
             a pill highlight behind the active tab. The wrapper's bottom padding
             keeps it clear of the iOS home indicator. Shown on every page. --}}
        <div
            x-data="{
                active: {{ $activeIndex }},
                shaking: false,
                tap() {
                    // Haptic feedback on supported devices (Android); iOS Safari has
                    // no Vibration API, so it just no-ops there. The shake gives a
                    // visual tap response on every device.
                    try { navigator.vibrate && navigator.vibrate(12); } catch (e) {}
                    this.shaking = false;
                    requestAnimationFrame(() => { this.shaking = true; });
                },
            }"
            {{-- The bar is already detached from the edge by design, so it does
                 not owe the full inset on top of that gap - taking the float's
                 own 12px back out of the inset keeps it clear of the home
                 indicator without sitting as high as a flush bar would have to.
                 The max() floor keeps the original 12px on devices with no
                 inset at all. --}}
            class="fixed inset-x-0 bottom-0 z-50 px-3 pb-[max(12px,calc(env(safe-area-inset-bottom)_-_12px))] lg:hidden"
        >
            <nav
                class="nav-glass mx-auto max-w-md rounded-full p-1.5"
                aria-label="Primary"
                :class="shaking && 'pill-shake'"
                @animationend="shaking = false"
            >
                <div class="relative flex items-stretch">
                    {{-- Apple-glass sliding pill: one frosted bubble that fills the
                         bar height and glides to the active tab. Width = one of
                         five equal columns; translateX(active * 100%) lands it on
                         the active slot (Menu is slot 2, never an active route). --}}
                    <span
                        aria-hidden="true"
                        class="liquid-pill pointer-events-none absolute -inset-y-1 left-0 w-1/5 rounded-full transition-transform duration-[420ms] ease-[cubic-bezier(0.22,1,0.36,1)]"
                        :style="'transform: translateX(' + (active * 100) + '%)'"
                    ></span>

                    {{-- Tabs 0 + 1 --}}
                    @foreach (array_slice($tabs, 0, 2) as $t)
                        <a
                            href="{{ $t['href'] }}"
                            @if ($t['nav'] && $t['href'] !== '#') wire:navigate.hover @endif
                            @click="active = {{ $t['idx'] }}; tap()"
                            aria-label="{{ $t['label'] }}"
                            class="relative z-10 flex flex-1 flex-col items-center gap-1 py-1.5 transition-transform duration-200 active:scale-95"
                        >
                            <span class="relative inline-flex">
                                <span
                                    aria-hidden="true"
                                    class="h-6 w-6 transition-colors duration-200"
                                    :class="active === {{ $t['idx'] }} ? 'bg-blue-600' : 'bg-zinc-900 dark:bg-zinc-300'"
                                    style="-webkit-mask: url('{{ asset('assets/' . rawurlencode($t['icon'])) }}') center / contain no-repeat; mask: url('{{ asset('assets/' . rawurlencode($t['icon'])) }}') center / contain no-repeat;"
                                ></span>
                                {{-- Successful-order count on the Orders tab. Light: small
                                     pill, dark number. Dark: bigger frosted-glass pill, light text. --}}
                                @if ($t['idx'] === 1 && $successfulOrderCount > 0)
                                    <span class="pointer-events-none absolute -top-2 -right-3 inline-flex h-4 min-w-[16px] items-center justify-center rounded-full bg-white px-1 text-[10px] font-bold leading-none text-zinc-900 ring-1 ring-zinc-200 shadow-sm dark:h-5 dark:min-w-[20px] dark:bg-white/15 dark:px-1.5 dark:text-[11px] dark:text-white dark:shadow-none dark:ring-white/25 dark:backdrop-blur-md">{{ $successfulOrderCount > 99 ? '99+' : $successfulOrderCount }}</span>
                                @endif
                            </span>
                            <span class="text-[10px] leading-none transition-colors duration-200" :class="active === {{ $t['idx'] }} ? 'text-blue-600 font-semibold' : 'font-medium text-zinc-700 dark:text-zinc-300'">{{ $t['label'] }}</span>
                        </a>
                    @endforeach

                    {{-- Menu — opens the bottom-sheet menu (slot 2). --}}
                    <button
                        type="button"
                        x-on:click="tap(); $dispatch('open-mobile-menu')"
                        aria-label="Open menu"
                        class="relative z-10 flex flex-1 flex-col items-center gap-1 py-1.5 transition-transform duration-200 active:scale-95"
                    >
                        <span
                            aria-hidden="true"
                            class="h-6 w-6 bg-zinc-900 transition-colors duration-200 dark:bg-zinc-300"
                            style="-webkit-mask: url('{{ asset('assets/' . rawurlencode('Hamburger menu.svg')) }}') center / contain no-repeat; mask: url('{{ asset('assets/' . rawurlencode('Hamburger menu.svg')) }}') center / contain no-repeat;"
                        ></span>
                        <span class="text-[10px] font-medium leading-none text-zinc-700 dark:text-zinc-300">Menu</span>
                    </button>

                    {{-- Tabs 2 + 3 --}}
                    @foreach (array_slice($tabs, 2, 2) as $t)
                        <a
                            href="{{ $t['href'] }}"
                            @if ($t['nav'] && $t['href'] !== '#') wire:navigate.hover @endif
                            @click="active = {{ $t['idx'] }}; tap()"
                            aria-label="{{ $t['label'] }}"
                            class="relative z-10 flex flex-1 flex-col items-center gap-1 py-1.5 transition-transform duration-200 active:scale-95"
                        >
                            <span
                                aria-hidden="true"
                                class="h-6 w-6 transition-colors duration-200"
                                :class="active === {{ $t['idx'] }} ? 'bg-blue-600' : 'bg-zinc-900 dark:bg-zinc-300'"
                                style="-webkit-mask: url('{{ asset('assets/' . rawurlencode($t['icon'])) }}') center / contain no-repeat; mask: url('{{ asset('assets/' . rawurlencode($t['icon'])) }}') center / contain no-repeat;"
                            ></span>
                            <span class="text-[10px] leading-none transition-colors duration-200" :class="active === {{ $t['idx'] }} ? 'text-blue-600 font-semibold' : 'font-medium text-zinc-700 dark:text-zinc-300'">{{ $t['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </nav>
        </div>

        {{-- Action flashes pop as a floating pill toast (status / success / error /
             wallet_created), so pages don't need their own inline banners. --}}
        <x-flash-toast />

        {{-- Locale modal at body root.
             Mounted outside flux:header so its position:fixed centers against the viewport
             rather than against a transformed sticky ancestor. Profile dropdown menu items
             dispatch 'open-locale-modal' to open it; we dispatch 'locale-updated' back
             whenever country / language change so the profile chip values stay in sync. --}}
        <div
            x-data="storefrontLocale()"
            x-init="
                init();
                // The floating chips, desktop switcher and profile chip are separate
                // Alpine scopes that default to United States/English and only sync via
                // this event. Broadcast the persisted values once on load so they show
                // the saved country/language instead of reverting to USA on every reload.
                $nextTick(() => $dispatch('locale-updated', { country: country, countryFlag: countryFlag, countryCode: countryCode, language: language }));
                $watch('country',  v => $dispatch('locale-updated', { country: country, countryFlag: countryFlag, countryCode: countryCode, language: language }));
                $watch('language', v => $dispatch('locale-updated', { country: country, countryFlag: countryFlag, countryCode: countryCode, language: language }));
            "
            x-on:open-locale-modal.window="localeModalOpen = true"
            @keydown.escape.window="localeModalOpen = false"
            x-effect="localeModalOpen ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
        >
            <x-nav.locale-modal />
        </div>

        {{-- Mobile menu popup. Triggered by every mobile hamburger / Menu FAB via the
             'open-mobile-menu' window event. Renders the sidebar nav as a card grid
             instead of sliding the Flux sidebar drawer out. lg:hidden so it never shows on desktop. --}}
        @php
            $mobileMenuItems = array_values(array_filter([
                ['label' => 'Home',          'href' => route('dashboard'),           'icon' => 'Home.svg',           'tone' => 'bg-blue-500',     'nav' => true],
                ['label' => 'Shop',          'href' => route('dashboard.shop.gift-cards'), 'icon' => 'Shop.svg',           'tone' => 'bg-pink-500',     'nav' => true],
                ['label' => 'Orders',        'href' => route('dashboard.orders'),    'icon' => 'order.svg',          'tone' => 'bg-sky-500',      'nav' => true],
                ['label' => 'Wallet',        'href' => route('dashboard.wallet'),    'icon' => 'Wallet.svg',         'tone' => 'bg-emerald-500',  'nav' => true],
                ['label' => 'Transactions',  'href' => route('dashboard.transactions'), 'icon' => 'transactions 1.svg', 'tone' => 'bg-teal-500',  'nav' => true],
                ['label' => 'Settings',      'href' => route('dashboard.profile'),   'icon' => 'system setting.svg', 'tone' => 'bg-indigo-500',   'nav' => true],
                ['label' => 'Verify (KYC)',  'href' => route('dashboard.kyc'),       'icon' => 'customer.svg',       'tone' => 'bg-amber-500',    'nav' => true],
                ['label' => 'Security',      'href' => route('dashboard.security'),  'icon' => 'admin access.svg',   'tone' => 'bg-violet-500',   'nav' => true],
                ['label' => 'Appearance',    'href' => route('dashboard.appearance'),'icon' => 'Appearance.svg',     'tone' => 'bg-fuchsia-500',  'nav' => true],
                ['label' => 'Notifications', 'href' => route('dashboard.notifications'), 'icon' => 'notification 2.svg', 'tone' => 'bg-amber-500',    'nav' => true],
                // Saved Cards hidden until the card-vault backend ships (see routes/web.php).
                // ['label' => 'Saved Cards',   'href' => route('dashboard.saved-cards'),   'icon' => 'savedcard.svg',      'tone' => 'bg-rose-500',     'nav' => true],
                \App\Support\FeatureFlag::on('sell_gift_cards', false) ? ['label' => 'Trade Card', 'href' => route('dashboard.gift-cards.history'), 'icon' => 'gift cards.svg', 'tone' => 'bg-orange-500', 'nav' => true] : null,
                // The rewards page is the Rcoin programme (cashback, referrals,
                // withdrawals, conversion) - the tile carries the Rcoin name,
                // not the old "Referrals" alias that hid it.
                ['label' => 'Rcoin',         'href' => route('dashboard.rewards'),    'icon' => 'wallet 2.svg',        'tone' => 'bg-yellow-500',   'nav' => true],
                ['label' => 'Support',       'href' => 'https://wa.me/19402386229?text=Hello%20Rshoprefill%20can%20i%20get%20help%3F', 'icon' => 'support.svg', 'tone' => 'bg-cyan-500', 'nav' => false],
            ]));
        @endphp
        {{-- Mobile menu wrapper: `contents` removes the div from Flux's grid layout so it
             doesn't push flux:sidebar/header/main into an unexpected row. --}}
        <div
            x-data="{ menuOpen: false }"
            x-on:open-mobile-menu.window="$nextTick(() => menuOpen = true)"
            x-on:keydown.escape.window="menuOpen = false"
            x-effect="menuOpen ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
            class="contents lg:hidden"
        >
            {{-- Backdrop --}}
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

            {{-- Bottom-sheet panel (full width, slides up from below) --}}
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
                aria-labelledby="mobile-menu-title"
            >
                {{-- Drag handle (visual affordance) --}}
                <div class="flex justify-center pt-3">
                    <span class="h-1.5 w-10 rounded-[12px] bg-zinc-300 dark:bg-white/25"></span>
                </div>

                <div class="px-5 pb-[max(20px,env(safe-area-inset-bottom))] pt-4">
                    {{-- Header — app-native pattern: title alone, no X button. Dismissed
                         via tap-outside (backdrop), Esc key, or the drag handle above. --}}
                    <h2 id="mobile-menu-title" class="mb-5 text-lg font-bold text-zinc-900 dark:text-white">Menu</h2>

                    {{-- Card grid, staggered entrance --}}
                    <div class="skeleton-stagger-fast grid grid-cols-4 gap-3">
                        @foreach ($mobileMenuItems as $i => $item)
                            <a
                                href="{{ $item['href'] }}"
                                @if ($item['nav'] && $item['href'] !== '#') wire:navigate.hover @endif
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

                    {{-- Logout footer --}}
                    <form method="POST" action="{{ route('logout') }}" class="mt-5 border-t border-zinc-100 pt-4">
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

        {{-- PWA pull-to-refresh (standalone mode only). --}}
        <x-pull-to-refresh />

        {{-- Collapsed-sidebar flyout positioner. In collapsed mode the submenu
             popups are position:fixed so they escape the now-scrollable rail's
             overflow clip; on hover we pin each .nav-submenu beside its icon
             using live viewport coords (so it lands correctly even after the
             rail has been scrolled). Delegated + guarded so wire:navigate never
             double-binds. --}}
        <script>
            (function () {
                if (window.__sidebarFlyoutInit) { return; }
                window.__sidebarFlyoutInit = true;
                const GAP = 14; // px gap from the rail edge (~0.875rem bridge)
                document.addEventListener('pointerover', function (e) {
                    if (! document.documentElement.classList.contains('dashboard-sidebar-collapsed')) { return; }
                    const group = e.target.closest && e.target.closest('[data-flux-sidebar] .nav-group');
                    if (! group) { return; }
                    const sub = group.querySelector('.nav-submenu');
                    if (! sub) { return; }
                    const r = group.getBoundingClientRect();
                    sub.style.top = r.top + 'px';
                    sub.style.left = (r.right + GAP) + 'px';
                });
            })();
        </script>

        {{-- Global Web Push Notification Prompt --}}
        <x-push-prompt />

        @fluxScripts
    </body>
</html>
