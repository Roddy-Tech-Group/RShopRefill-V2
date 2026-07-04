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
                        @foreach ([
                            ['Gift Cards',     route('dashboard.shop.gift-cards'), true],
                            ['eSIMs',          route('dashboard.shop.esims'),      true],
                            ['Flights',        route('dashboard.shop.flights'),    true],
                            ['Stays',          route('dashboard.shop.stays'),      true],
                            ['Topups & Bills', route('dashboard.shop.topups'),     true],
                        ] as [$label, $href, $live])
                            <a href="{{ $href }}" @if ($live) wire:navigate.hover @endif class="{{ $subItem(false) }}">
                                {{ $label }}
                            </a>
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

                @php $active = $isCurrent('dashboard.password'); @endphp
                <a href="{{ route('dashboard.password') }}" wire:navigate.hover data-tip="Security" class="{{ $navItem($active) }}">
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

                <a href="{{ route('dashboard.rewards') }}" wire:navigate.hover data-tip="Referrals" class="{{ $navItem(request()->routeIs('dashboard.rewards')) }}">
                    <span class="flex items-center gap-3">
                        <img src="{{ asset('assets/referals.webp') }}" alt="" class="h-5 w-5 shrink-0 object-contain" loading="lazy">
                        Referrals
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
                                ['Security',        'Password & sessions',    'admin access.svg',   route('dashboard.password')],
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
                    class="relative flex h-11 w-11 items-center justify-center rounded-full text-zinc-600 transition-colors hover:bg-zinc-50"
                    aria-label="Shopping cart"
                >
                    <img src="{{ asset('assets/' . rawurlencode('new cart.svg')) }}" alt="" class="h-7 w-7" loading="lazy">
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
                 up with the mobile hero bell on small screens. --}}
            <div class="hidden lg:block">
                <livewire:notifications-menu tone="dark" />
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
                <button type="button" @click="locked = !locked; open = locked" :aria-expanded="open.toString()" aria-label="{{ $user?->name ?? 'Account' }}" class="relative flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-blue-100 ring-1 ring-blue-200 transition-all hover:ring-2 hover:ring-blue-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40">
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
                        <a href="{{ route('dashboard.password') }}" wire:navigate.hover class="flex items-center gap-3 rounded-[12px] px-3 py-2 text-sm font-medium text-zinc-900 transition-colors hover:bg-blue-100" role="menuitem">
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

            // The floating TOP chrome (cart / country switcher / notification, plus the
            // hamburger) only rides along on the browse hub (overview) and the shop pages.
            // Task/inner pages (orders, wallet, history, settings, ...) stay clean. The
            // bottom floating nav bar is NOT gated by this - it stays on every page.
            $showMobileTopChrome = request()->routeIs('dashboard') || request()->routeIs('dashboard.shop.*');
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
        {{-- Blue hero — overview only. Scrolls away with the page; the floating
             wallet chip + Top Up button inside <x-slot:mobileHero> take over once
             the hero leaves the viewport. --}}
        <header class="relative z-10 bg-transparent px-5 pb-6 lg:hidden" style="padding-top: max(1rem, env(safe-area-inset-top));">
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
                         chip stays visible permanently so PWA users always have
                         an obvious door back to the storefront. --}}
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
                <div class="flex items-center gap-2" x-data>
                    {{-- Language + currency — opens the locale modal so mobile
                         users can switch country/currency/language without the
                         desktop top bar. Matches the cart / notification pills. --}}
                    <button
                        type="button"
                        x-data="{ code: (localStorage.getItem('locale.countryCode') || 'US').toLowerCase() }"
                        @locale-updated.window="code = ($event.detail.countryCode || code).toLowerCase()"
                        x-on:click="$dispatch('open-locale-modal')"
                        aria-label="Region, language and currency"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-[#eff6ff] border border-zinc-200 transition-colors dark:border-zinc-700"
                    >
                        <img :src="'https://flagcdn.com/w80/' + code + '.png'" alt="" class="h-6 w-6 rounded-full object-cover ring-1 ring-black/10 dark:ring-white/25" loading="lazy">
                    </button>

                    {{-- Cart — links to the cart page with a live count from the
                         global $store.cart (kept in sync with the shop pages). --}}
                    <a
                        href="{{ route('dashboard.shop.cart') }}"
                        wire:navigate.hover
                        aria-label="Cart"
                        class="relative inline-flex h-10 w-10 items-center justify-center rounded-full bg-[#eff6ff] border border-zinc-200 transition-colors dark:border-zinc-700"
                    >
                        <img src="{{ asset('assets/' . rawurlencode('new cart.svg')) }}" alt="" class="h-5 w-5 brightness-0 dark:invert" loading="lazy">
                        <span
                            x-show="$store.cart && $store.cart.count > 0"
                            x-text="$store.cart ? $store.cart.count : ''"
                            x-cloak
                            class="absolute -top-1 -right-1 inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-white px-1 text-[10px] font-bold leading-none text-blue-700"
                        ></span>
                    </a>
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#eff6ff] border border-zinc-200 dark:border-zinc-700">
                        <livewire:notifications-menu tone="dark" wire:key="overview-header-notif" />
                    </div>
                </div>
            </div>

            {{-- Wallet card slot - sits directly under the compact identity strip
                 so the wallet info hits the customer immediately on page load,
                 leaving the rest of the viewport for product categories. --}}
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
                    request()->routeIs('dashboard.password')      => 'Security',
                    request()->routeIs('dashboard.appearance')    => 'Appearance',
                    default                                       => 'Settings',
                };
            @endphp
            {{-- Inner-page top bar (top cart / country switcher / notification).
                 Shown on the shop pages only; hidden on task/inner pages so they
                 stay clean. The bottom nav bar stays on every page for navigation. --}}
            @if ($showMobileTopChrome)
            <div class="relative z-10 flex items-center justify-between gap-2 px-3 py-2.5 lg:hidden">
                {{-- Hamburger opens the Connect panel (social + contact channels).
                     The app menu itself is the bottom-bar centre FAB. --}}
                <button
                    type="button"
                    x-data
                    x-on:click="$dispatch('open-connect-panel')"
                    aria-label="Connect with us"
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#eff6ff] border border-zinc-200 transition-colors active:scale-95"
                >
                    <img src="{{ asset('assets/' . rawurlencode('Hamburger menu.svg')) }}" alt="" class="h-5 w-5 dark:brightness-0 dark:invert" style="filter: brightness(0) saturate(100%);" loading="lazy">
                </button>
                <div class="flex items-center gap-1" x-data>
                    {{-- Language + currency toggle (opens the locale modal). --}}
                    <button
                        type="button"
                        x-data="{ code: (localStorage.getItem('locale.countryCode') || 'US').toLowerCase() }"
                        @locale-updated.window="code = ($event.detail.countryCode || code).toLowerCase()"
                        x-on:click="$dispatch('open-locale-modal')"
                        aria-label="Region, language and currency"
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#eff6ff] border border-zinc-200 transition-colors active:scale-95"
                    >
                        <img :src="'https://flagcdn.com/w80/' + code + '.png'" alt="" class="h-6 w-6 rounded-full object-cover ring-1 ring-black/10 dark:ring-white/25" loading="lazy">
                    </button>

                    {{-- Cart — live count from the global $store.cart, taps through to the cart page. --}}
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
                            class="absolute -top-0.5 -right-0.5 inline-flex h-[16px] min-w-[16px] items-center justify-center rounded-full bg-blue-600 px-1 text-[9px] font-bold leading-none text-white"
                        ></span>
                    </a>
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#eff6ff] border border-zinc-200">
                        <livewire:notifications-menu tone="dark" wire:key="inner-bar-notif" />
                    </div>
                </div>
            </div>
            @else
            {{-- Back button (task/inner pages) - a STICKY top-left affordance (these
                 pages dropped the top chrome). A frosted page-colour strip that stays
                 pinned as the page scrolls; goes back, falling back to the overview. --}}
            <div class="sticky top-0 z-30 px-3 py-2.5 lg:hidden bg-[#eff6ff]/90 backdrop-blur-md dark:bg-[#0c1a36]/90">
                <button
                    type="button"
                    x-data
                    @click="window.history.length > 1 ? window.history.back() : (window.location.href = '{{ route('dashboard') }}')"
                    aria-label="Go back"
                    class="flex h-11 w-11 items-center justify-center rounded-full bg-[#eff6ff] border border-zinc-200 text-zinc-900 transition-transform active:scale-95 dark:border-white/10 dark:text-zinc-200"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                    </svg>
                </button>
            </div>
            @endif
        @endif

            {{-- ─────────────────────────────────────────────────────── --}}
            {{-- FLOATING TOP CHROME (top cart / country switcher / bell) --}}
            {{-- Pins to the viewport once the page scrolls past the      --}}
            {{-- header. Overview + shop pages only; hidden elsewhere.     --}}
            {{-- ─────────────────────────────────────────────────────── --}}
            @if ($showMobileTopChrome)
            <div
                x-data="{
                    scrolled: false,
                    country: 'United States',
                    countryCode: 'US',
                    language: 'English',
                    onScroll() { this.scrolled = window.scrollY > 80; },
                    init() {
                        this.onScroll();
                        window.addEventListener('scroll', () => this.onScroll(), { passive: true });
                    },
                }"
                @locale-updated.window="country = $event.detail.country; countryCode = $event.detail.countryCode; language = $event.detail.language"
                x-show="scrolled"
                x-cloak
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 -translate-y-2"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-2"
                class="pointer-events-none fixed inset-x-0 z-[55] flex items-center justify-between gap-2 px-3 lg:hidden"
                style="top: max(0.5rem, env(safe-area-inset-top));"
            >
                {{-- Hamburger chip (left) - glass treatment so the page bleeds
                     through (works on light + dark surfaces). --}}
                <button
                    type="button"
                    @click="$dispatch('open-connect-panel')"
                    aria-label="Connect with us"
                    class="pointer-events-auto flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#eff6ff] border border-zinc-200 transition-colors active:scale-95"
                >
                    <img src="{{ asset('assets/' . rawurlencode('Hamburger menu.svg')) }}" alt="" class="h-5 w-5 dark:brightness-0 dark:invert" style="filter: brightness(0) saturate(100%);" loading="lazy">
                </button>

                {{-- Locale + cart + notification chips (right) - same glass treatment, pinned. --}}
                <div class="pointer-events-auto flex items-center gap-2" x-data>
                    {{-- Language + currency toggle (opens the locale modal). --}}
                    <button
                        type="button"
                        x-data="{ code: (localStorage.getItem('locale.countryCode') || 'US').toLowerCase() }"
                        @locale-updated.window="code = ($event.detail.countryCode || code).toLowerCase()"
                        x-on:click="$dispatch('open-locale-modal')"
                        aria-label="Region, language and currency"
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#eff6ff] border border-zinc-200 transition-colors active:scale-95"
                    >
                        <img :src="'https://flagcdn.com/w80/' + code + '.png'" alt="" class="h-6 w-6 rounded-full object-cover ring-1 ring-black/10 dark:ring-white/25" loading="lazy">
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
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#eff6ff] border border-zinc-200">
                        <livewire:notifications-menu tone="dark" wire:key="floating-notif-inner" />
                    </div>
                </div>
            </div>
            @endif

            <div data-page-content class="flex flex-col bg-[#eff6ff] px-4 pt-5 pb-28 sm:px-6 sm:pt-6 lg:min-h-full lg:px-10 lg:py-8 dark:bg-[#0c1a36]">
                <div class="w-full min-w-0 lg:flex-1">
                    {{-- Suspension banner: visible on every dashboard page when the
                         account is suspended. Carries the admin-authored reason +
                         the Request Review button (idempotent — re-clicks are safe). --}}
                    @auth
                        @if (auth()->user()->isSuspended())
                            @include('partials.suspension-banner')
                        @endif
                    @endauth

                    {{ $slot }}
                </div>

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
                request()->routeIs('dashboard.password')      => 4,
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
            class="fixed inset-x-0 bottom-0 z-50 px-3 pb-[max(12px,env(safe-area-inset-bottom))] lg:hidden"
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
            $mobileMenuItems = [
                ['label' => 'Home',          'href' => route('dashboard'),           'icon' => 'Home.svg',           'tone' => 'bg-blue-500',     'nav' => true],
                ['label' => 'Shop',          'href' => route('dashboard.shop.gift-cards'), 'icon' => 'Shop.svg',           'tone' => 'bg-pink-500',     'nav' => true],
                ['label' => 'Orders',        'href' => route('dashboard.orders'),    'icon' => 'order.svg',          'tone' => 'bg-sky-500',      'nav' => true],
                ['label' => 'Wallet',        'href' => route('dashboard.wallet'),    'icon' => 'Wallet.svg',         'tone' => 'bg-emerald-500',  'nav' => true],
                ['label' => 'Transactions',  'href' => route('dashboard.transactions'), 'icon' => 'transactions 1.svg', 'tone' => 'bg-teal-500',  'nav' => true],
                ['label' => 'Settings',      'href' => route('dashboard.profile'),   'icon' => 'system setting.svg', 'tone' => 'bg-indigo-500',   'nav' => true],
                ['label' => 'Verify (KYC)',  'href' => route('dashboard.kyc'),       'icon' => 'customer.svg',       'tone' => 'bg-amber-500',    'nav' => true],
                ['label' => 'Security',      'href' => route('dashboard.password'),  'icon' => 'admin access.svg',   'tone' => 'bg-violet-500',   'nav' => true],
                ['label' => 'Appearance',    'href' => route('dashboard.appearance'),'icon' => 'Appearance.svg',     'tone' => 'bg-fuchsia-500',  'nav' => true],
                ['label' => 'Notifications', 'href' => route('dashboard.notifications'), 'icon' => 'notification 2.svg', 'tone' => 'bg-amber-500',    'nav' => true],
                // Saved Cards hidden until the card-vault backend ships (see routes/web.php).
                // ['label' => 'Saved Cards',   'href' => route('dashboard.saved-cards'),   'icon' => 'savedcard.svg',      'tone' => 'bg-rose-500',     'nav' => true],
                ['label' => 'Trade Card',    'href' => route('dashboard.gift-cards.history'), 'icon' => 'gift cards.svg', 'tone' => 'bg-orange-500', 'nav' => true],
                ['label' => 'Referrals',     'href' => route('dashboard.rewards'),    'icon' => 'referals.webp',       'tone' => 'bg-yellow-500',   'nav' => true],
                ['label' => 'Support',       'href' => 'https://wa.me/19402386229?text=Hello%20Rshoprefill%20can%20i%20get%20help%3F', 'icon' => 'support.svg', 'tone' => 'bg-cyan-500', 'nav' => false],
            ];
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

        {{-- ─────────────────────────────────────────────────────── --}}
        {{-- Connect panel — slim-bar hamburger slides this out (the app    --}}
        {{-- menu itself lives on the bottom-bar FAB). Social + contact     --}}
        {{-- channel cards.                                                --}}
        {{-- ─────────────────────────────────────────────────────── --}}
        @php
            // Each entry renders as a branded promo card in the Connect panel. Discord
            // invite link is a placeholder until the real one lands.
            $connectChannels = [
                [
                    'type' => 'instagram', 'url' => \App\Models\SiteSetting::get('social.instagram', 'https://instagram.com/rshoprefills'), 'bg' => 'bg-pink-500', 'external' => true,
                    'heading' => 'Follow us on Instagram',
                    'tagline' => 'Reels, behind-the-scenes, and weekly deal drops.',
                    'cta' => 'Follow on Instagram',
                ],
                [
                    'type' => 'tiktok', 'url' => \App\Models\SiteSetting::get('social.tiktok', 'https://tiktok.com/@rshoprefills'), 'bg' => 'bg-zinc-800', 'external' => true,
                    'badge' => 'New',
                    'heading' => 'Follow us on TikTok',
                    'tagline' => 'Daily drops, how-tos, and giveaway alerts. Straight from @rshoprefills.',
                    'cta' => 'Follow on TikTok',
                ],
                [
                    'type' => 'discord', 'url' => '#', 'bg' => 'bg-indigo-500', 'external' => true,
                    'heading' => 'Join our Discord',
                    'tagline' => 'Live community, channel drops, and quick support from the team.',
                    'cta' => 'Join Discord',
                ],
                [
                    'type' => 'whatsapp', 'url' => 'https://wa.me/19402386229?text=Hello%20Rshoprefill%20can%20i%20get%20help%3F', 'bg' => 'bg-emerald-500', 'external' => true,
                    'heading' => 'Chat on WhatsApp',
                    'tagline' => 'Direct help on +1 (940) 238-6229. Most replies in under 5 minutes.',
                    'cta' => 'Open WhatsApp',
                ],
                [
                    'type' => 'facebook', 'url' => \App\Models\SiteSetting::get('social.facebook', 'https://facebook.com/rshoprefills'), 'bg' => 'bg-blue-500', 'external' => true,
                    'heading' => 'Like us on Facebook',
                    'tagline' => 'News, deals, and community posts at /rshoprefills.',
                    'cta' => 'Open Facebook',
                ],
                [
                    'type' => 'email', 'url' => 'mailto:support@rshoprefill.com', 'bg' => 'bg-amber-500', 'external' => false,
                    'heading' => 'Email our team',
                    'tagline' => 'Reach support@rshoprefill.com. Support is available 24/7.',
                    'cta' => 'Send email',
                ],
                [
                    'type' => 'phone', 'url' => 'tel:+19402386229', 'bg' => 'bg-sky-500', 'external' => false,
                    'heading' => 'Give us a call',
                    'tagline' => 'Talk to a human on +1 (940) 238-6229, Monday to Saturday.',
                    'cta' => 'Call now',
                ],
            ];
        @endphp
        <div
            x-data="{ connectOpen: false }"
            x-on:open-connect-panel.window="$nextTick(() => connectOpen = true)"
            x-on:keydown.escape.window="connectOpen = false"
            class="contents lg:hidden"
        >
            {{-- Backdrop --}}
            <div
                x-show="connectOpen"
                x-transition:enter="transition-opacity ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                @click="connectOpen = false"
                style="display: none;"
                class="fixed inset-0 z-[60] bg-zinc-900/45"
                aria-hidden="true"
            ></div>

            {{-- Left slide-out drawer --}}
            <div
                x-show="connectOpen"
                x-transition:enter="transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
                x-transition:enter-start="-translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition-transform duration-250 ease-in"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="-translate-x-full"
                style="display: none;"
                class="modal-norise fixed inset-y-0 left-0 z-[70] w-[86%] max-w-sm overflow-y-auto bg-white shadow-2xl shadow-zinc-900/25 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                role="dialog"
                aria-modal="true"
                aria-labelledby="connect-title"
            >
                <div class="px-5 pb-[max(20px,env(safe-area-inset-bottom))]" style="padding-top: max(1.25rem, env(safe-area-inset-top));">
                    {{-- Header --}}
                    <div class="mb-5 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 id="connect-title" class="text-lg font-bold text-zinc-900">Connect with us</h2>
                            <p class="mt-0.5 text-xs text-zinc-600">Reach {{ $siteName }} on your favourite channel.</p>
                        </div>
                        {{-- Glass grey close button — same recipe as the admin sidebar's
                             collapse toggle. Translucent fill + backdrop blur picks up the
                             panel bg behind it; a frosted ring + inset top sheen give the
                             glass edge. --}}
                        <button
                            type="button"
                            @click="connectOpen = false"
                            aria-label="Close"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-zinc-500/30 text-zinc-700 ring-1 ring-zinc-400/40 backdrop-blur-xl backdrop-saturate-150 transition-all hover:bg-zinc-500/40 hover:text-zinc-900 active:scale-95 dark:bg-transparent dark:text-white dark:ring-white/15 dark:hover:bg-white/15"
                            style="box-shadow: 0 6px 18px -6px rgba(15, 23, 42, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.45);"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    {{-- Branded promo cards — one per channel. Each is its own platform
                         hero card (brand bg, big logo tile, copy, white CTA button). The
                         theme-static class keeps the brand colours identical in light and
                         dark mode so they always look like the official brand. --}}
                    <div class="skeleton-stagger-fast flex flex-col gap-3">
                        @foreach ($connectChannels as $i => $channel)
                            <a
                                href="{{ $channel['url'] }}"
                                @if ($channel['external']) target="_blank" rel="noopener" @endif
                                @click="connectOpen = false"
                                style="--i: {{ $i }}"
                                class="theme-static block overflow-hidden rounded-[15px] {{ $channel['bg'] }} p-5 text-white ring-1 ring-zinc-900/40 transition-transform active:scale-[0.99]"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    {{-- Inline style locks the white tile + dark icon against
                                         dark-mode CSS remaps that would otherwise turn the
                                         tile navy and the icon invisible (see app.css
                                         .dark .bg-white / .dark .text-zinc-950 mappings). --}}
                                    <span class="no-dark-invert flex h-12 w-12 shrink-0 items-center justify-center rounded-[12px]" style="background: #ffffff; color: #09090b;">
                                        @switch ($channel['type'])
                                            @case ('instagram')
                                                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="currentColor" aria-hidden="true"><path d="M12 2.163c3.204 0 3.584.012 4.849.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.07 1.644.07 4.849 0 3.205-.012 3.584-.07 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.849.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
                                                @break
                                            @case ('tiktok')
                                                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="currentColor" aria-hidden="true"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>
                                                @break
                                            @case ('discord')
                                                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="currentColor" aria-hidden="true"><path d="M20.317 4.3698a19.7913 19.7913 0 00-4.8851-1.5152.0741.0741 0 00-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 00-.0785-.037 19.7363 19.7363 0 00-4.8852 1.515.0699.0699 0 00-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 00.0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 00.0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 00-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 01-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 01.0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 01.0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 01-.0066.1276 12.2986 12.2986 0 01-1.873.8914.0766.0766 0 00-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 00.0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 00.0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 00-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189Z"/></svg>
                                                @break
                                            @case ('whatsapp')
                                                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="currentColor" aria-hidden="true"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.612-.916-2.207-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.29.173-1.414z"/></svg>
                                                @break
                                            @case ('facebook')
                                                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="currentColor" aria-hidden="true"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                                @break
                                            @case ('email')
                                                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                                                @break
                                            @case ('phone')
                                                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                                                @break
                                        @endswitch
                                    </span>
                                    @if (! empty($channel['badge']))
                                        <span class="rounded-[12px] bg-white/15 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-white">{{ $channel['badge'] }}</span>
                                    @endif
                                </div>

                                <h3 class="mt-4 text-lg font-bold leading-tight tracking-tight">{{ $channel['heading'] }}</h3>
                                <p class="mt-1 text-xs text-white/80">{{ $channel['tagline'] }}</p>

                                {{-- Inline bg + text colour force a white pill with dark text
                                     in BOTH themes. Without the inline `background`, the
                                     `bg-white` class hits the `.dark .bg-white` -> navy remap
                                     and the button vanishes into the brand card in dark mode.
                                     hover:brightness-95 gives a press affordance that survives
                                     the locked inline background. --}}
                                <span class="mt-4 inline-flex items-center rounded-[12px] px-4 py-2 text-sm font-bold transition-[filter] hover:brightness-95" style="background: #ffffff; color: #09090b;">
                                    {{ $channel['cta'] }}
                                </span>
                            </a>
                        @endforeach
                    </div>
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
