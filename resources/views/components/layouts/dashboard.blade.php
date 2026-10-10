<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\Languages::direction() }}">
    <head>
        @include('partials.head')
    </head>
    {{-- Fold the rail before first paint, so a reload never shows the full sidebar
         snapping shut: the customer's saved collapse, or a shop page whose own
         category panel sits beside the rail. The store in app.js takes over once
         Alpine starts (and re-checks on every wire:navigate). --}}
    <script>
        (function () {
            var folded = @json(request()->routeIs('dashboard.shop.gift-cards', 'dashboard.shop.topups', 'dashboard.shop.bills'));
            var saved = false;
            try { saved = localStorage.getItem('dashboard.sidebar.collapsed') === '1'; } catch (e) {}
            if (folded || saved) { document.documentElement.classList.add('dashboard-sidebar-collapsed'); }
        })();
    </script>
    {{-- Customer sidebar collapse styling. Mirrors the admin sidebar's CSS
         contract — when the html element carries `dashboard-sidebar-collapsed`,
         the rail shrinks to icons only, labels hide, single items get a
         tooltip on hover, and group submenus (Shop services) stay hidden.
         The CSS targets `[data-flux-sidebar]` which on this layout is the
         customer sidebar (the admin layout is never on the same page so
         there's no selector collision). --}}
    <style>
        @media (min-width: 1024px) {
            html.dashboard-sidebar-collapsed [data-flux-sidebar] {
                width: 72px !important;
            }
            /* Collapsed: the scroll wrapper keeps scrolling vertically (so every
               icon stays reachable on short viewports) but clips horizontally.
               nav itself stays visible — only the wrapper scrolls. */
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav {
                overflow: visible !important;
            }
            html.dashboard-sidebar-collapsed [data-flux-sidebar] > div {
                overflow-y: auto !important;
                overflow-x: hidden !important;
            }
            /* Collapsed, the rows lose their labels and become a column of bare
               18px icons, where the expanded list's 2px gap reads as one solid
               strip. Space them so each icon is its own target. */
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav,
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav .nav-group {
                gap: 0.375rem !important;
            }
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav a,
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav button {
                font-size: 0 !important;
                justify-content: center !important;
                padding-left: 0.5rem !important;
                padding-right: 0.5rem !important;
                padding-top: 0.5rem !important;
                padding-bottom: 0.5rem !important;
                gap: 0 !important;
            }
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav button > span,
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav a > span {
                gap: 0 !important;
                justify-content: center !important;
                margin: 0 auto !important;
            }
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav svg,
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav img,
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav .anim-icon {
                margin: 0 auto !important;
            }
            /* Expandable groups — hide chevron + right-side badges in collapsed
               mode regardless of how deeply they're nested. The earlier
               `nav a > span.dash-row-badge` selector missed badges wrapped in
               an extra <span class="flex"> (the Shop button's NEW pill sits
               inside a row wrapper, not directly under <a>/<button>). */
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav button > span:last-child > svg,
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav .dash-row-badge,
            html.dashboard-sidebar-collapsed [data-flux-sidebar] nav .dash-nav-toggle {
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
                color: #ffffff !important;
                font-weight: 600 !important;
            }

            /* Collapsed rail: hovering (or focusing) an icon shows its name in a
               label beside it. The label is one fixed element on <body>, placed
               by the rail tooltip script at the foot of this layout. A CSS
               ::after on the link never showed: the rail's scroll wrapper clips
               horizontally, so anything past the 72px rail was cut off. */
            .dash-rail-tip {
                position: fixed;
                z-index: 90;
                opacity: 0;
                transform: translate(-4px, -50%);
                pointer-events: none;
                background: #0c1337;
                color: #ffffff;
                font-size: 0.75rem;
                font-weight: 500;
                line-height: 1rem;
                padding: 0.4rem 0.7rem;
                border-radius: 8px;
                white-space: nowrap;
                box-shadow: 0 10px 25px -8px rgba(0, 0, 0, 0.4);
                transition: opacity 120ms ease, transform 120ms ease;
            }
            /* Speech-bubble pointer on the left edge, aimed at the icon: a small
               square in the bubble's own colour, turned 45 degrees and half
               tucked under the bubble. */
            .dash-rail-tip::before {
                content: '';
                position: absolute;
                top: 50%;
                left: -4px;
                width: 9px;
                height: 9px;
                background-color: inherit;
                border-radius: 2px;
                transform: translateY(-50%) rotate(45deg);
            }
            .dash-rail-tip.is-visible {
                opacity: 1;
                transform: translate(0, -50%);
            }
            html.dark .dash-rail-tip {
                background: #ffffff;
                color: #18181b;
            }

            /* Panel layout. A full-width top bar on the shell colour carries
               the logo; below it the sidebar and the page form one rounded
               panel inset 8px from the window edges. The window never scrolls:
               the page scrolls inside the panel (flux:main), so content can
               never slide past the panel's corners. Light mode's shell is the
               deep blue #0c1337; dark keeps the black ground, where hairlines
               outline the panel instead. */
            body.dashboard { --dash-shell: #0c1337; --dash-pane-line: transparent; }
            html.dark body.dashboard { --dash-shell: #000000; --dash-pane-line: rgba(255, 255, 255, 0.08); }

            html:root:has(> body.dashboard) {
                overflow: hidden;
                scrollbar-gutter: auto;
            }
            html:not(.dark):has(> body.dashboard),
            html:not(.dark) body.dashboard { background-color: var(--dash-shell); }
            /* Dark mode: the ground behind the panel and the top bar are pure
               black, with no grain. Out-ranks app.css's !important grainy
               ground on the body and on [data-flux-header]. */
            html.dark:root:has(> body.dashboard) { background-color: #000000; }
            html.dark:root body.dashboard,
            html.dark:root body.dashboard > [data-flux-header] {
                background-color: #000000 !important;
                background-image: none !important;
            }

            /* Flux puts the sidebar beside the header when it comes first; the
               header spans the full width here instead, and the rows are pinned
               so the body is exactly one screen tall. */
            html body.dashboard {
                height: 100dvh;
                min-height: 0;
                overflow: hidden;
                grid-template-rows: 72px minmax(0, 1fr) 0 !important;
                grid-template-columns: min-content min-content minmax(0, 1fr) min-content !important;
                grid-template-areas: "header header header header" "sidebar subnav main aside" "sidebar subnav footer aside" !important;
            }
            body.dashboard > [data-flux-header] { height: 72px; }

            /* Sub-navigation slot, between the rail and the page. Empty (zero
               wide) on most pages. A page with its own sub-navigation (the shop
               category panel) teleports a panel in: the rail folds to icons,
               the slot opens to 248px in the sidebar's grey with no seam, so
               rail and panel read as one sidebar, and the panel slides in. */
            body.dashboard > [data-dash-subnav] {
                grid-area: subnav;
                min-height: 0;
                width: 0;
                margin-bottom: 8px;
                overflow: hidden;
                transition: width 280ms cubic-bezier(0.22, 1, 0.36, 1);
            }
            body.dashboard > [data-dash-subnav]:has(> *) {
                width: 248px;
                background-color: #eaeeec;
                border-block: 1px solid var(--dash-pane-line);
            }
            html.dark body.dashboard > [data-dash-subnav]:has(> *) {
                background-color: var(--ash);
                background-image: var(--grain);
                background-size: 220px 220px;
            }
            body.dashboard > [data-dash-subnav] > .dash-subnav-panel {
                width: 248px;
                animation: dash-subnav-in 320ms cubic-bezier(0.22, 1, 0.36, 1) 60ms both;
            }
            @keyframes dash-subnav-in {
                from { opacity: 0; transform: translateX(-16px); }
                to { opacity: 1; transform: none; }
            }
            @media (prefers-reduced-motion: reduce) {
                body.dashboard > [data-dash-subnav] { transition: none; }
                body.dashboard > [data-dash-subnav] > .dash-subnav-panel { animation: none; }
            }
            /* The page grid that held the panel as its first column gives the
               whole width to the catalogue once the panel has moved out. */
            body.dashboard [data-page-content] .grid:has(> template[data-subnav-teleport]) {
                grid-template-columns: minmax(0, 1fr) !important;
            }

            /* Left pane. Flux writes sticky top/max-height inline for sticky
               sidebars; the window no longer scrolls, so the grid sizes it. */
            html body.dashboard > [data-flux-sidebar] {
                position: relative !important;
                top: auto !important;
                height: auto !important;
                min-height: 0 !important;
                max-height: none !important;
                margin-inline-start: 8px;
                margin-bottom: 8px;
                border: 1px solid var(--dash-pane-line);
                border-inline-end: 0;
                border-start-start-radius: 24px;
                border-end-start-radius: 24px;
            }

            /* Right pane: a rounded, clipping frame that does not scroll itself.
               Chromium does not clip an element's own scrollbar to that
               element's rounded corners, so when the pane scrolled, its bar
               poked out past the top-right curve. The page wrapper inside does
               the scrolling instead, and the pane's rounded overflow clip keeps
               that wrapper's bar inside the panel. */
            body.dashboard > [data-flux-main] {
                margin-inline-end: 8px;
                margin-bottom: 8px;
                min-height: 0;
                overflow: hidden;
                display: flex;
                flex-direction: column;
                border: 1px solid var(--dash-pane-line);
                border-inline-start: 0;
                border-start-end-radius: 24px;
                border-end-end-radius: 24px;
            }
            body.dashboard > [data-flux-main] [data-page-content] {
                flex: 1 1 0%;
                min-height: 0 !important;
                overflow-y: auto;
                /* Clip sideways, never scroll: the overview's wallet band bleeds
                   past the page padding to the panel's edges, and auto would
                   turn that bleed into a horizontal scrollbar. */
                overflow-x: clip;
                overscroll-behavior: contain;
                /* The one in-page scroller that keeps a visible bar, since it
                   replaces the window's (the global rule hides all others).
                   auto, not thin: a set scrollbar-width/color makes Chromium
                   draw its native bar with arrow buttons; auto lets the slim
                   arrow-less ::-webkit-scrollbar styling below apply. */
                scrollbar-width: auto;
                scrollbar-color: auto;
            }
            @supports not selector(::-webkit-scrollbar) {
                body.dashboard > [data-flux-main] [data-page-content] {
                    scrollbar-width: thin;
                    scrollbar-color: #c4cad6 transparent;
                }
            }
            /* The track stops short of the 24px corners, and the 8px bar paints
               only a 4px thumb in its middle (transparent border + content-box
               clip), so it stays slim and floats clear of the panel's edge. The
               wrapper's own background (the wallpaper veil) paints under the bar. */
            body.dashboard > [data-flux-main] [data-page-content]::-webkit-scrollbar { display: block; width: 8px; background: transparent; }
            body.dashboard > [data-flux-main] [data-page-content]::-webkit-scrollbar-button { display: none; }
            body.dashboard > [data-flux-main] [data-page-content]::-webkit-scrollbar-corner { background: transparent; }
            body.dashboard > [data-flux-main] [data-page-content]::-webkit-scrollbar-track { background: transparent; margin-top: 24px; margin-bottom: 24px; }
            body.dashboard > [data-flux-main] [data-page-content]::-webkit-scrollbar-thumb {
                background-color: #c4cad6;
                border-radius: 9999px;
                border: 2px solid transparent;
                background-clip: content-box;
            }
            body.dashboard > [data-flux-main] [data-page-content]::-webkit-scrollbar-thumb:hover { background-color: #a0a8b8; }

            /* Content-pane wallpaper (the sidebar keeps its plain grey). The
               image sits on the pane itself, which never scrolls, so it holds
               still while the page wrapper scrolls over it. That wrapper lays a
               solid veil on top (under its own scrollbar too) so text
               written straight onto the page (titles, subtitles, section
               headings) stays readable; raise or lower the alpha in
               --dash-wallpaper-veil to show more or less of the image.
               Selectors out-rank the dark-mode page-tone rules in app.css,
               which are !important. */
            @php
                // One image per theme, in public/assets, in whichever format it was
                // saved. No file, no rule: that theme keeps its plain page tone
                // instead of requesting a missing image. The mtime query busts the
                // browser cache when a file is replaced.
                $findWallpaper = fn (array $names) => collect($names)
                    ->crossJoin(['webp', 'jpg', 'jpeg', 'png', 'avif'])
                    ->map(fn ($pair) => 'assets/'.$pair[0].'.'.$pair[1])
                    ->first(fn ($path) => is_file(public_path($path)));
                $wallpaperUrl = fn (string $path) => asset($path).'?v='.filemtime(public_path($path));

                // Light only. Dark mode keeps the plain dark page tone: a wallpaper
                // there is a near-black photograph stretched over the whole pane,
                // which bands and softens under any compression, and it was adding
                // nothing the flat surface does not already give.
                $dashWallpaperLight = $findWallpaper(['dashboard-wallpaper-light-mode', 'dashboard-wallpaper']);
            @endphp
            @if ($dashWallpaperLight)
            /* Light: the image is mid-to-dark grey, so a 75% light veil keeps
               dark page text readable over it. */
            html:not(.dark) body.dashboard { --dash-wallpaper-veil: rgba(244, 246, 245, 0.75); }
            html:root:not(.dark) body.dashboard > [data-flux-main] {
                background-image: url('{{ $wallpaperUrl($dashWallpaperLight) }}') !important;
                background-size: cover !important;
                background-position: center !important;
                background-repeat: no-repeat !important;
            }
            html:not(.dark) body.dashboard > [data-flux-main] [data-page-content] {
                background-color: var(--dash-wallpaper-veil) !important;
                background-image: none !important;
            }
            @endif

            /* The supplied dotted world map sits on the stationary dark pane,
               behind the scrolling content. Its transparent gaps reveal the
               existing near-black ground and grain. Keep the full map visible
               without repeating it, including when the pane is narrower. */
            html:root.dark body.dashboard > [data-flux-main] {
                background-color: #1e1e25 !important;
                background-image: url('{{ $wallpaperUrl('assets/dotted-world-map.svg') }}'), var(--grain) !important;
                background-size: min(2000px, 125%) auto, 220px 220px !important;
                background-position: center center, 0 0 !important;
                background-repeat: no-repeat, repeat !important;
            }
            html.dark body.dashboard > [data-flux-main] [data-page-content] {
                background-color: transparent !important;
                background-image: none !important;
            }
            /* Sidebar collapse arrow: hidden until the sidebar is hovered (or the
               button is keyboard-focused), so it only appears when wanted. */
            @media (min-width: 1024px) {
                /* Above the page pane so the arrow on the seam paints over it;
                   below the top bar (40) so the cart, bell, search and profile
                   menus drop over the sidebar. */
                html [data-flux-sidebar] { z-index: 35 !important; }
                .sidebar-collapse-toggle { opacity: 0; }
                [data-flux-sidebar]:hover .sidebar-collapse-toggle,
                .sidebar-collapse-toggle:focus-visible { opacity: 1; }
            }

            /* Collapsed: the Shop services list does not show at all, neither
               inline nor as a hover popup beside the icon (that flyout was
               removed). Pressing the Shop icon opens the shop instead; the
               list comes back under it once the rail is expanded. */
            html.dashboard-sidebar-collapsed [data-flux-sidebar] .nav-submenu {
                display: none !important;
            }

            /* Soft width transition so the rail glides between states. */
            [data-flux-sidebar] {
                transition: width 220ms cubic-bezier(0.22, 1, 0.36, 1);
                overflow: visible !important;
            }
        }

        /* The same map on a phone.
        
           Every pane rule above sits inside the 1024px query, so below that
           width the map did not exist at all rather than being hidden: there
           was nothing behind the content but the flat surface. On a phone the
           scrolling surface is the panel, not the pane, so it carries the map
           instead, sized to the panel's width so the whole world shows rather
           than a cropped middle band of ocean.
        
           Both use the light-filled copy of the file. The original's dots are
           a single navy fill, near invisible on any dark surface, and an SVG
           loaded as a background cannot take its colour from CSS. */
        html.dark .dash-mobile-panel {
            background-image: url('{{ $wallpaperUrl('assets/dotted-world-map.svg') }}'), var(--grain) !important;
            background-size: 240% auto, 220px 220px !important;
            background-position: center 88%, 0 0 !important;
            background-repeat: no-repeat, repeat !important;
            /* scroll, not local: the map is attached to the panel's own box, so it
               stays put while the content scrolls over it, the way the desktop
               pane holds it. `local` had it riding along with the content. */
            background-attachment: scroll, scroll !important;
        }
    </style>
    <body class="dashboard min-h-screen bg-[#f4f6f5] text-zinc-900 dark:bg-[#0c1a36] dark:text-white">

        {{-- Impersonation banner: shown only while a Super Admin is signed in
             as this customer (the impersonation they started, not merely an
             admin session in the same browser). Floating bottom pill so it
             never disrupts the layout. --}}
        @if (\App\Domain\Admin\Support\CustomerImpersonation::active())
            <div class="fixed bottom-4 left-1/2 z-[80] flex -translate-x-1/2 items-center gap-3 rounded-[12px] bg-amber-500 px-4 py-2.5 text-sm font-semibold text-amber-950 shadow-lg shadow-amber-900/30">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.644C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span>Viewing as {{ Auth::user()?->name }}</span>
                <form method="POST" action="{{ route('impersonation.leave') }}">
                    @csrf
                    <button type="submit" class="rounded-[5px] bg-amber-950 px-3 py-1 text-xs font-bold text-amber-50 transition-colors hover:bg-amber-900">Return to admin</button>
                </form>
            </div>
        @endif

        {{-- Translation engine (auto-detect + manual switching from the locale modal) --}}

        @php
            $user = Auth::user();
            $isCurrent = fn (...$patterns) => request()->routeIs(...$patterns);

            // Customer sidebar nav styles mirror the admin sidebar exactly so
            // both shells read as one product family. Active = soft blue tint
            // (not the loud bg-blue-600 pill that used to live here); hover =
            // subtle zinc on light, deep-charcoal seam on dark; sub-items use
            // the same shape one indent deeper.
            // Panel layout rows on the grey sidebar, compact like the reference:
            // 14px medium labels, 18px icons, ~32px rows, 8px corners, the active
            // row a white tile. The white is bg-[#ffffff], which dark mode turns
            // into the card grey the sidebar already is, so the dark active tile
            // has to force its black (the `!`).
            $navItem = fn (bool $active) => $active
                ? 'group flex items-center justify-between gap-2.5 rounded-[8px] px-2.5 py-2 text-sm font-medium nav-item-active'
                : 'nav-item group flex items-center justify-between gap-2.5 rounded-[8px] px-2.5 py-2 text-sm font-medium text-zinc-800 transition-colors dark:text-zinc-300';
            $iconCls = fn (bool $active) => $active
                ? 'h-[18px] w-[18px] shrink-0 text-[#0c1337] dark:text-white'
                : 'h-[18px] w-[18px] shrink-0 text-[#0c1337] transition-colors dark:text-zinc-200';
            $subItem = fn (bool $active) => $active
                ? 'flex items-center gap-2.5 rounded-[8px] px-2.5 py-2 text-sm font-medium nav-item-active'
                : 'nav-item flex items-center gap-2.5 rounded-[8px] px-2.5 py-2 text-sm font-medium text-zinc-700 transition-colors dark:text-zinc-300';

            // One colour for every text badge on a sidebar row (New, the orders
            // and notifications counts, KYC, Earn): the top bar's dark blue.
            // A lighter shade of the top bar's dark blue (#0c1337), solid, with
            // white text: clearly blue on the grey sidebar (the earlier 10% navy
            // tint read as grey), and still navy rather than a pale light blue.
            // The same fill reads on the dark sidebar too.
            $rowBadgeTone = 'bg-[#26358c] text-white';

            $ordersCount = $user?->orders()->count() ?? 0;
            // Unread notification count — drives the sidebar/avatar/menu badges.
            // The bell dropdown (<livewire:notifications-menu />) computes its own.
            $notificationCount = $user?->notifications()->whereNull('read_at')->count() ?? 0;

        @endphp

        {{-- Geometry (pane position, corners) lives in the panel layout rules
             in the <style> block above. Light grey, a step below the page pane. --}}
        <flux:sidebar sticky stashable class="relative hidden w-[256px] bg-[#eaeeec] dark:bg-[#0c1a36] lg:flex">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            {{-- Glass round toggle on the seam — mirrors the admin sidebar.
                 Grey-tinted glass on light mode, white-glass on dark.
                 Desktop-only (lg:flex) — mobile uses the stash. --}}
            <button
                type="button"
                x-data
                @click="$store.dashboardSidebar.toggle()"
                :aria-label="$store.dashboardSidebar.isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                :aria-pressed="$store.dashboardSidebar.isCollapsed.toString()"
                class="sidebar-collapse-toggle absolute right-0 z-30 hidden h-6 w-6 translate-x-1/2 items-center justify-center rounded-full bg-white/25 text-blue-600 border-[2px] border-blue-500 backdrop-blur-2xl backdrop-saturate-200 transition-all hover:bg-white/40 hover:text-blue-700 hover:border-blue-600 active:scale-95 lg:flex dark:bg-transparent dark:text-blue-300 dark:border-blue-400/80 dark:hover:bg-transparent dark:hover:text-blue-200 dark:hover:border-blue-300"
                style="top: 22px; box-shadow: 0 8px 24px -8px rgba(15, 23, 42, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.55);"
            >
                <svg
                    class="h-3 w-3 transition-transform duration-200"
                    :class="$store.dashboardSidebar.isCollapsed ? 'rotate-180' : 'rotate-0'"
                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>

            {{-- The brand now sits at the left of the full-width top bar. --}}

            {{-- Scrollable middle: only the nav links scroll. The Need-Help card
                 below stays fixed. min-h-0 lets flex-1 actually shrink and trigger overflow.
                 -mr-2 pr-2 hides the scrollbar gutter; the project hides scrollbars site-wide. --}}
            <div class="-mx-2 flex min-h-0 flex-1 flex-col overflow-y-auto px-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">

            {{-- Primary nav --}}
            <nav class="mt-1 flex flex-col gap-1" aria-label="Account">
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
                 When the sidebar is collapsed to icons the services list stays
                 hidden (no hover popup); the Shop icon just opens the shop. --}}
            <p class="dash-section-label mt-5 px-2.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">Shop</p>
            <nav class="mt-1.5 flex flex-col gap-1" aria-label="Shop">
                @php
                    // Services that just launched, from config/dashboard.php. The
                    // NEW badge used to sit on the Shop row permanently; now it
                    // appears only while something is listed there.
                    $newServices = (array) config('dashboard.new_services', []);
                    $newBadge = 'inline-flex items-center whitespace-nowrap rounded-[5px] px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide '.$rowBadgeTone;

                    // More Services: the services that sit outside the four core
                    // categories. It only appears once one of them is switched on,
                    // and opens the first that is.
                    $moreServicesHref = collect([
                        \App\Support\FeatureFlag::on('gomarket', false) ? route('dashboard.shop.gomarket') : null,
                        \App\Support\FeatureFlag::categoryVisible('flights') ? route('dashboard.shop.flights') : null,
                        \App\Support\FeatureFlag::categoryVisible('stays') ? route('dashboard.shop.stays') : null,
                    ])->filter()->first();

                    // The four categories, and nothing below them.
                    //
                    // A Gaming row used to sit here, linking to the gift-card
                    // listing with a category preselected. That listing is the
                    // heaviest page we serve - every brand for the region, in
                    // every section - so the row took long enough to land that
                    // the menu felt broken, and the listing carries the same
                    // categories as pills where switching is a class toggle on
                    // markup the browser already holds.
                    //
                    // [label, href, feature flag (null = none), new-badge key, active]
                    $shopSubItems = array_values(array_filter([
                        ['Gift Cards',     route('dashboard.shop.gift-cards'), 'gift_cards', 'gift_cards', request()->routeIs('dashboard.shop.gift-cards', 'dashboard.shop.brand')],
                        ['eSIMs',          route('dashboard.shop.esims'),      'esims',      'esims',      request()->routeIs('dashboard.shop.esim*')],
                        ['Mobile Top-Ups', route('dashboard.shop.topups'),     'topups',     'topups',     request()->routeIs('dashboard.shop.topup*')],
                        ['Bill Payments',  route('dashboard.shop.bills'),      'bills',      'bills',      request()->routeIs('dashboard.shop.bill*')],
                        $moreServicesHref ? ['More Services', $moreServicesHref, null, 'more', request()->routeIs('dashboard.shop.gomarket', 'dashboard.shop.flights', 'dashboard.shop.stays')] : null,
                    ]));
                @endphp
                <div
                    x-data="{ expanded: false, locked: false }"
                    {{-- Close the list whenever the rail folds or unfolds, so it never
                         reopens on its own. --}}
                    x-effect="$store.dashboardSidebar?.isCollapsed; expanded = false; locked = false"
                    @click.outside="if (! $store.dashboardSidebar?.isCollapsed) { locked = false; expanded = false }"
                    class="nav-group flex flex-col gap-1"
                >
                    {{-- The whole Shop row is the toggle, as it is in the phone drawer:
                         pressing anywhere on it drops the services down, and the chevron
                         at its end turns to say so. Gift Cards leads that list, so the
                         row no longer needs to be a link itself. Folded to icons, the
                         list cannot show (see the collapse rules above), so the same
                         press opens the shop instead. --}}
                    @php $active = $isCurrent('dashboard.shop.*'); @endphp
                    <button
                        type="button"
                        @click="if ($store.dashboardSidebar?.isCollapsed) { Livewire.navigate(@js(route('dashboard.shop.gift-cards'))) } else { locked = ! locked; expanded = locked }"
                        :aria-expanded="expanded.toString()"
                        aria-controls="dash-shop-services"
                        data-tip="Shop"
                        class="{{ $navItem($active) }} w-full text-left"
                    >
                        <span class="flex items-center gap-3">
                            <x-ui.anim-icon file="Shop.svg" tone="inherit" class="h-[18px] w-[18px] text-[#0c1337] dark:text-zinc-200" />
                            Shop
                        </span>
                        <span class="flex items-center gap-2">
                            @if ($newServices)
                                <span class="dash-row-badge {{ $newBadge }}">New</span>
                            @endif
                            {{-- The round tinted chip is the chevron's resting look: it reads
                                 as a control at a glance. It hides with the labels when the
                                 rail folds to icons. --}}
                            <span class="dash-nav-toggle flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-black/5 text-[#0c1337] transition-colors dark:bg-white/10 dark:text-zinc-300" aria-hidden="true">
                                <svg :class="expanded && 'rotate-180'" class="h-4 w-4 shrink-0 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </span>
                        </span>
                    </button>

                    {{-- Submenu rows are text-only: the parent Shop item carries
                         the icon, repeating one per child just adds noise. --}}
                    <div id="dash-shop-services" x-show="expanded" x-collapse class="nav-submenu ml-5 flex flex-col gap-0.5 border-l border-zinc-200 pl-3 dark:border-white/10">
                        @foreach ($shopSubItems as [$label, $href, $flag, $newKey, $subActive])
                            @php $catEnabled = $flag === null || \App\Support\FeatureFlag::on($flag); @endphp
                            @if ($catEnabled)
                                <a href="{{ $href }}" wire:navigate.hover class="{{ $subItem($subActive) }} justify-between">
                                    {{ $label }}
                                    @if (in_array($newKey, $newServices, true))
                                        <span class="dash-row-badge {{ $newBadge }}">New</span>
                                    @endif
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
            <p class="dash-section-label mt-5 px-2.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">Account</p>
            <nav class="mt-1.5 flex flex-col gap-1" aria-label="Account management">
                @php $active = $isCurrent('dashboard.orders*'); @endphp
                <a href="{{ route('dashboard.orders') }}" wire:navigate.hover data-tip="Orders" class="{{ $navItem($active) }}">
                    <span class="flex items-center gap-3">
                        <x-ui.anim-icon file="order.svg" tone="inherit" class="h-[18px] w-[18px] text-[#0c1337] dark:text-zinc-200" />
                        Orders
                    </span>
                    @if ($ordersCount > 0)
                        <span class="dash-row-badge inline-flex items-center whitespace-nowrap rounded-[5px] px-2 py-0.5 text-[10px] font-bold tabular-nums {{ $rowBadgeTone }}">{{ $ordersCount > 9 ? '9+' : $ordersCount }}</span>
                    @endif
                </a>

                {{-- Every eSIM the customer bought, with install details and top-ups
                     (also active on an eSIM's top-up page). --}}
                @php $active = request()->routeIs('dashboard.esims', 'dashboard.esim.*'); @endphp
                <a href="{{ route('dashboard.esims') }}" wire:navigate.hover data-tip="My eSIMs" class="{{ $navItem($active) }}">
                    <span class="flex items-center gap-3">
                        <x-ui.anim-icon file="esim.svg" tone="inherit" class="h-[18px] w-[18px] text-[#0c1337] dark:text-zinc-200" />
                        My eSIMs
                    </span>
                </a>

                @php $active = request()->routeIs('dashboard.wallet'); @endphp
                <a href="{{ route('dashboard.wallet') }}" wire:navigate.hover data-tip="Wallet" class="{{ $navItem($active) }}">
                    <span class="flex items-center gap-3">
                        <x-ui.anim-icon file="Wallet.svg" tone="inherit" class="h-[18px] w-[18px] text-[#0c1337] dark:text-zinc-200" />
                        Wallet
                    </span>
                </a>

                @php $active = $isCurrent('dashboard.transactions*'); @endphp
                <a href="{{ route('dashboard.transactions') }}" wire:navigate.hover data-tip="Transactions" class="{{ $navItem($active) }}">
                    <span class="flex items-center gap-3">
                        <x-ui.anim-icon file="transactions.svg" tone="inherit" class="h-[18px] w-[18px] text-[#0c1337] dark:text-zinc-200" />
                        Transactions
                    </span>
                </a>

                {{-- Profile intentionally omitted from the desktop sidebar — it's
                     reachable via the avatar dropdown (and the mobile menu). --}}

                @php $active = $isCurrent('dashboard.security'); @endphp
                <a href="{{ route('dashboard.security') }}" wire:navigate.hover data-tip="Security" class="{{ $navItem($active) }}">
                    <span class="flex items-center gap-3">
                        <x-ui.anim-icon file="admin access.svg" tone="inherit" class="h-[18px] w-[18px] text-[#0c1337] dark:text-zinc-200" />
                        Security
                    </span>
                </a>

                {{-- Highlighted with the orange KYC badge only until identity is
                     verified. After approval the row reads Identity Verified with a
                     green check, so it stops asking for something already done. --}}
                @php
                    $active = $isCurrent('dashboard.kyc');
                    $kycVerified = $user?->isKycVerified() ?? false;
                    $kycLabel = $kycVerified ? 'Identity Verified' : 'Verify Identity';
                @endphp
                <a href="{{ route('dashboard.kyc') }}" wire:navigate.hover data-tip="{{ $kycLabel }}" class="{{ $navItem($active) }}">
                    <span class="flex items-center gap-3">
                        <x-ui.anim-icon file="customer.svg" tone="inherit" class="h-[18px] w-[18px] text-[#0c1337] dark:text-zinc-200" />
                        {{ $kycLabel }}
                    </span>
                    @if ($kycVerified)
                        <span class="dash-row-badge inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white" title="Verified">
                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            <span class="sr-only">Verified</span>
                        </span>
                    @else
                        <span class="dash-row-badge inline-flex items-center whitespace-nowrap rounded-[5px] px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide {{ $rowBadgeTone }}">KYC</span>
                    @endif
                </a>

                {{-- Notifications is not a sidebar row: it lives in the account menu
                     under the avatar (with the unread count). --}}

                {{-- Saved Cards hidden until the card-vault / gateway tokenisation
                     backend ships (the page is only an empty-state shell today).
                     Restore this block + the route in routes/web.php to re-enable.
                @php $active = request()->routeIs('dashboard.saved-cards'); @endphp
                <a href="{{ route('dashboard.saved-cards') }}" wire:navigate.hover data-tip="Saved Cards" class="{{ $navItem($active) }}">
                    <span class="flex items-center gap-3">
                        <x-ui.anim-icon file="savedcard.svg" tone="inherit" class="h-[18px] w-[18px] text-[#0c1337] dark:text-zinc-200" />
                        Saved Cards
                    </span>
                </a>
                --}}

                {{-- One row for the rewards page, which covers referrals and Rcoin
                     together; the separate Referrals link was removed. --}}
                <a href="{{ route('dashboard.rewards') }}" wire:navigate.hover data-tip="For Partners" class="{{ $navItem(request()->routeIs('dashboard.rewards')) }}">
                    <span class="flex items-center gap-3">
                        <x-ui.anim-icon file="for-partners.svg" tone="inherit" class="h-[18px] w-[18px] text-[#0c1337] dark:text-zinc-200" />
                        For Partners
                    </span>
                    <span class="dash-row-badge inline-flex items-center whitespace-nowrap rounded-[5px] px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide {{ $rowBadgeTone }}">Earn</span>
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
                <div class="overflow-hidden rounded-[12px] bg-[#ffffff] dark:ring-1 dark:ring-zinc-700/60">
                    <button
                        type="button"
                        @click="helpOpen = true"
                        class="flex w-full items-center gap-3 p-3 text-left transition-colors hover:bg-[#f4f6f5] dark:hover:bg-white/5"
                    >
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-[#f4f6f5] text-[#0c1337] dark:bg-blue-500/15 dark:text-blue-200">
                            <x-ui.anim-icon file="support.svg" tone="inherit" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">Need help?</p>
                            <p class="truncate text-xs text-zinc-600 dark:text-zinc-300">Chat with support</p>
                        </div>
                        {{-- Shortcut hint: the global Ctrl+F shortcut opens a support
                             ticket from anywhere on the site (see partials/shortcuts). --}}
                        <span class="inline-flex shrink-0 items-center gap-1 rounded-[12px] bg-[#f4f6f5] px-1.5 py-0.5 text-[10px] font-semibold text-zinc-500 dark:border-zinc-700/60 dark:bg-white/5 dark:text-zinc-300">
                            CTRL <span class="opacity-70">+</span> F
                        </span>
                    </button>

                    {{-- Direct WhatsApp shortcut (moved here from the removed newsletter
                         card). Mirrors the global Ctrl+J shortcut for a quick support chat. --}}
                    <a
                        href="https://wa.me/19402386229?text=Hello%20Rshoprefill%20can%20i%20get%20help%3F"
                        target="_blank"
                        rel="noopener"
                        class="flex items-center justify-between gap-2 border-t border-zinc-100 px-3 py-2.5 text-[11px] font-medium text-zinc-600 transition-colors hover:bg-[#f4f6f5] hover:text-emerald-600 dark:border-white/10 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-emerald-400"
                    >
                        <span class="inline-flex items-center gap-1.5">
                            <svg class="h-4 w-4 text-emerald-500" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.966-.273-.099-.471-.149-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.611-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479s1.065 2.875 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413"/>
                            </svg>
                            Chat on WhatsApp
                        </span>
                        <span class="inline-flex shrink-0 items-center gap-1 rounded-[12px] bg-[#f4f6f5] px-1.5 py-0.5 text-[10px] font-semibold text-zinc-500 dark:border-zinc-700/60 dark:bg-white/5 dark:text-zinc-300">
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
                        class="w-full max-w-sm rounded-[12px] bg-white p-6 shadow-2xl dark:bg-[#1d3252] dark:ring-1 dark:ring-zinc-700/60"
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
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#dde3ee] text-zinc-600 transition-colors hover:bg-[#cdd5e4] hover:text-zinc-900 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/20 dark:hover:text-white"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
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
                                class="group flex items-center gap-3 rounded-[12px] bg-emerald-50 px-4 py-3 transition-colors hover:bg-emerald-100 dark:bg-emerald-500/15 dark:ring-1 dark:ring-emerald-500/30 dark:hover:bg-emerald-500/25"
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
                                class="group flex items-center gap-3 rounded-[12px] bg-blue-50 px-4 py-3 transition-colors hover:bg-blue-100 dark:bg-blue-500/15 dark:ring-1 dark:ring-blue-500/30 dark:hover:bg-blue-500/25"
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
        <flux:header class="sticky top-0 z-40 flex items-center gap-2 !border-b-0 bg-[#0c1337] dark:bg-[#0c1a36] px-4 py-2 sm:px-6 lg:gap-3">

            {{-- Phones and tablets: this same header is the top bar, fixed by
                 app.css, and it leads with the hamburger that opens the sidebar
                 drawer (at the foot of this layout). The pills to its right are
                 the desktop ones, unchanged: same controls, same dropdowns.
                 The button wears their outline too (2px, white at 30%), so it
                 reads as a control rather than three bare lines, and its
                 outline sits on the gutter the way the chips on the right do. --}}
            <button
                type="button"
                x-data
                x-on:click="$dispatch('open-mobile-menu')"
                aria-label="Open menu"
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2 border-white/30 text-white transition-colors hover:bg-white/15 active:bg-white/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60 dark:border-white/30 dark:text-zinc-200 dark:hover:bg-white/5 dark:active:bg-white/15 lg:hidden"
            >
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                </svg>
            </button>

            <flux:spacer />

            {{-- Brand and the referral pull-down. On desktop lg:order-first puts
                 this group at the left of the bar, beside the (hidden) hamburger.
                 On phones only the gift chip inside it renders, and it sits here
                 in the flow: first in the right-hand group, in the slot the
                 language pill has on desktop. --}}
            <div class="flex shrink-0 items-center lg:order-first">
                {{-- The R mark alone (no wordmark, no tagline). The artwork is brand
                     blue on transparent, so it renders white on the dark bar. --}}
                <a href="{{ route('dashboard') }}" wire:navigate.hover class="hidden rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60 lg:flex" aria-label="{{ $siteName }} dashboard">
                    <img
                        src="{{ asset('assets/PWAicon.webp') }}"
                        alt="{{ $siteName }}"
                        class="h-9 w-9 object-contain brightness-0 invert"
                    />
                </a>

                {{-- Rotating promo pill beside the mark: "Refer & earn up to $N", then
                     "Earn extra as a partner" (to the partner page), swiping up every
                     5 seconds (held while hovered, focused, or while the referral
                     panel is open). Clicking the referral pill pulls down a panel with
                     the customer's referral link and its Copy button (the same
                     referral-code component the rewards page uses). The dollar figure
                     is config, not copy (config/dashboard.php earn_up_to_usd), and the
                     referral message only runs while Rcoin rewards are switched on. --}}
                @php
                    $promoEarnUsd = (int) config('dashboard.earn_up_to_usd', 0);
                    $promoSlides = array_values(array_filter([
                        \App\Models\Setting::get('rcoin_enabled', true) && \App\Models\Setting::get('referral_enabled', true)
                            ? ['label' => $promoEarnUsd > 0 ? 'Refer & earn up to $'.number_format($promoEarnUsd) : 'Refer & earn Rcoin', 'panel' => true, 'icon' => 'gift',
                               // Brand blue: a tinted pill with a solid blue edge.
                               'tone' => 'border-[#3d6bff] bg-[#0044ff]/30 group-hover:bg-[#0044ff]/45']
                            : null,
                        // Straight to the Partnership program tab of For Partners, not the public affiliates page.
                        ['label' => 'Become a partner', 'href' => route('dashboard.rewards', ['tab' => 'partners']), 'panel' => false, 'icon' => 'partner',
                         // Green: the same tinted pill in emerald.
                         'tone' => 'border-emerald-500 bg-emerald-600/30 group-hover:bg-emerald-600/45'],
                    ]));
                    $promoHasPanel = collect($promoSlides)->contains('panel', true);
                @endphp
                <div
                    x-data="{
                        slide: 0,
                        count: {{ count($promoSlides) }},
                        paused: false,
                        referOpen: false,
                        timer: null,
                        init() {
                            if (this.count < 2) { return; }
                            this.timer = setInterval(() => {
                                if (! this.paused && ! this.referOpen) { this.slide = (this.slide + 1) % this.count; }
                            }, 5000);
                        },
                        destroy() { clearInterval(this.timer); },
                    }"
                    @click.outside="referOpen = false"
                    @keydown.escape.window="referOpen = false"
                    class="relative lg:ml-4"
                >
                    @if ($promoHasPanel)
                        {{-- Phone: the rotating pill has no room, so a gift chip in
                             the same outline as the other pills pulls the same
                             referral panel down. It stands where the language
                             pill stands on desktop, first in the right-hand group. --}}
                        <button
                            type="button"
                            @click="referOpen = ! referOpen"
                            :aria-expanded="referOpen.toString()"
                            aria-haspopup="dialog"
                            aria-label="Refer & earn"
                            class="flex h-9 w-9 items-center justify-center rounded-full border-2 border-white/30 text-white transition-colors hover:bg-white/15 dark:border-white/30 dark:text-zinc-200 dark:hover:bg-white/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60 lg:hidden"
                        >
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
                            </svg>
                        </button>
                    @endif
                    <div
                        @mouseenter="paused = true"
                        @mouseleave="paused = false"
                        @focusin="paused = true"
                        @focusout="paused = false"
                        {{-- Clipped to the pill's own height and shape, so a message swiping
                             up disappears through the top edge while the next one rises in
                             from below, the two moving together. --}}
                        class="dash-promo-pill relative hidden h-8 items-center overflow-hidden rounded-full lg:grid"
                    >
                        @foreach ($promoSlides as $i => $promo)
                            {{-- The pill element only moves; its look (and hover colour) sits
                                 on the inner span, so the hover's colour transition can never
                                 override the swipe's transform transition. The referral pill
                                 is a button that opens the panel; the partner pill a link. --}}
                            @if ($promo['panel'])
                            <button
                                type="button"
                                @click="referOpen = ! referOpen"
                                :aria-expanded="referOpen.toString()"
                                aria-haspopup="dialog"
                            @else
                            <a
                                href="{{ $promo['href'] }}"
                            @endif
                                x-show="slide === {{ $i }}"
                                @if ($i > 0) style="display: none;" @endif
                                x-transition:enter="transition duration-700 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none"
                                x-transition:enter-start="translate-y-full opacity-0"
                                x-transition:enter-end="translate-y-0 opacity-100"
                                x-transition:leave="transition duration-700 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none"
                                x-transition:leave-start="translate-y-0 opacity-100"
                                x-transition:leave-end="-translate-y-full opacity-0"
                                class="group col-start-1 row-start-1 flex h-8 rounded-full focus:outline-none"
                            >
                                <span class="{{ $promo['tone'] }} inline-flex h-full items-center gap-2 whitespace-nowrap rounded-full border px-3.5 text-[13px] font-semibold text-white transition-colors group-focus-visible:ring-2 group-focus-visible:ring-inset group-focus-visible:ring-white/60">
                                @if ($promo['icon'] === 'gift')
                                    {{-- Gift --}}
                                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
                                @else
                                    {{-- Handshake / partner --}}
                                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                                @endif
                                {{ $promo['label'] }}
                                @if ($promo['panel'])
                                    <svg class="h-3.5 w-3.5 shrink-0 transition-transform" :class="referOpen && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                @endif
                                </span>
                            @if ($promo['panel'])
                            </button>
                            @else
                            </a>
                            @endif
                        @endforeach
                    </div>

                    @if ($promoHasPanel)
                        {{-- Referral panel, pulled down from the pill. The card
                             itself is x-nav.referral-panel, shared with the
                             storefront nav's "Invite a Friend" pill; only the
                             anchoring is ours. --}}
                        <div
                            x-show="referOpen"
                            x-cloak
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 -translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 -translate-y-1"
                            {{-- Stays anchored LEFT under the pill: it is not a
                                 dashboard-frame-popup, which would pin it to the
                                 frame's right corner with the other three. The
                                 class is what app.css keys on to give the shared
                                 card the same 12px frosted surface here without
                                 touching the storefront's copy. --}}
                            class="dashboard-refer-popup absolute left-0 top-full z-50 mt-3"
                        >
                            <x-nav.referral-panel
                                role="dialog"
                                aria-label="Refer friends, earn Rcoin"
                                close="referOpen = false"
                            />
                        </div>
                    @endif
                </div>
            </div>

            {{-- Language + currency (desktop only): flag, language code and
                 currency, opening the shared country / language / currency sheet
                 (the modal at the foot of this layout). The language half renders
                 server-side from the active locale, like the storefront's region
                 pill, so it can never name a language the page is not in; country
                 and currency are the saved locale choices, and the flag follows
                 locale-updated. Phones do without the pill: the account menu's
                 Language row opens the same sheet, and the referral chip takes
                 this slot in the bar. --}}
            <button
                type="button"
                x-data="{
                    code: 'us',
                    currency: 'USD',
                    init() {
                        try {
                            this.code = (localStorage.getItem('locale.countryCode') || 'US').toLowerCase();
                            this.currency = localStorage.getItem('locale.currency') || 'USD';
                        } catch (e) {}
                    },
                }"
                @locale-updated.window="code = ($event.detail.countryCode || code).toLowerCase()"
                @click="$dispatch('open-locale-modal')"
                aria-label="{{ __('common.language') }} / {{ __('common.payment_currency') }}"
                class="hidden h-9 shrink-0 items-center gap-2 rounded-full border-2 border-white/30 pl-1 pr-3 text-[13px] font-semibold text-white transition-colors hover:bg-white/15 lg:flex focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60 dark:border-white/30 dark:text-zinc-200 dark:hover:bg-white/5"
            >
                {{-- Eager: the desktop pill is in the bar on arrival. --}}
                <img :src="'https://flagcdn.com/w40/' + code + '.png'" alt="" width="28" height="28" decoding="async" class="h-7 w-7 shrink-0 rounded-full object-cover ring-1 ring-white/20">
                <span>{{ strtoupper((string) config('localization.supported.'.app()->getLocale().'.short', 'EN')) }} / <span x-text="currency">USD</span></span>
            </button>

            {{-- Search: a round outlined button (same outline as the cart) in the right-hand group. Clicking it
                 (or CTRL/CMD + K) drops the same search panel as before, with the
                 field now at the top of the panel. --}}
            <div
                x-data="dashboardSearch()"
                @click.outside="open = false"
                @keydown.escape.window="open = false"
                @keydown.window.prevent.ctrl.k="open = true; $nextTick(() => $refs.searchInput.focus())"
                @keydown.window.prevent.meta.k="open = true; $nextTick(() => $refs.searchInput.focus())"
                class="dashboard-search relative shrink-0"
            >
                <button
                    type="button"
                    @click="open = ! open; if (open) $nextTick(() => $refs.searchInput.focus())"
                    :aria-expanded="open.toString()"
                    aria-label="Search products, brands or categories"
                    class="flex h-9 w-9 items-center justify-center rounded-full border-2 border-white/30 text-white transition-colors hover:bg-white/15 dark:border-white/30 dark:text-zinc-200 dark:hover:bg-white/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60"
                >
                    <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </button>

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
                    class="dashboard-frame-popup dashboard-search-panel absolute right-0 top-full z-50 mt-2 w-[860px] max-w-[calc(100vw-2rem)] overflow-hidden rounded-[12px] bg-white/80 backdrop-blur-xl shadow-xl shadow-zinc-900/15"
                >
                    {{-- Search field --}}
                    <div role="search" class="px-3 pb-2 pt-3">
                        <div
                            @click="$refs.searchInput.focus()"
                            :class="open ? 'border-blue-500 ring-2 ring-blue-500/15' : 'border-zinc-300 hover:border-zinc-400 dark:border-zinc-700'"
                            class="dashboard-search-field flex cursor-text items-center gap-3 rounded-[15px] border bg-[#f4f6f5] px-4 py-2.5 transition-all duration-200 dark:bg-white/[0.06]"
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
                            class="min-w-0 flex-1 bg-transparent text-sm text-zinc-800 placeholder:text-zinc-500 outline-none dark:text-white"
                        />
                        <span class="hidden items-center gap-1 rounded-[6px] bg-[#fdfdfd] px-1.5 py-0.5 text-[10px] font-semibold text-zinc-600 ring-1 ring-zinc-200 sm:inline-flex dark:bg-white/10 dark:text-zinc-300 dark:ring-white/10">
                                CTRL <span class="opacity-70">+</span> K
                            </span>
                        </div>
                    </div>

                    {{-- Filter tabs --}}
                    <div class="dashboard-search-tabs border-b border-zinc-100 p-3">
                        <div class="flex flex-wrap gap-2">
                            <template x-for="tab in tabs" :key="tab">
                                <button
                                    type="button"
                                    @click="activeTab = tab"
                                    :class="activeTab === tab ? 'bg-zinc-900 text-white' : 'bg-[#f4f6f5] text-zinc-700 hover:text-blue-700'"
                                    class="rounded-[12px] px-3 py-1.5 text-xs font-semibold transition-colors"
                                    x-text="tab"
                                ></button>
                            </template>
                        </div>
                    </div>

                    {{-- Product & brand results — live catalogue search (2+ characters),
                         same /api/search/brands endpoint as the storefront nav search. --}}
                    <div x-show="searching" class="dashboard-search-results p-2">
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
                            <a :href="r.url" class="flex items-center justify-between gap-3 rounded-[12px] px-3 py-2.5 transition-colors hover:bg-[#f4f6f5]">
                                <span class="flex min-w-0 items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-[#f4f6f5]">
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
                            class="mt-1 flex items-center justify-center gap-1.5 rounded-[12px] px-3 py-2.5 text-sm font-semibold text-blue-600 transition-colors hover:bg-[#f4f6f5]"
                        >
                            See all results
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                            </svg>
                        </a>
                    </div>

                    {{-- Most used — shown until the user starts a product search. --}}
                    <div x-show="! searching" class="dashboard-search-results p-2">
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
                            <a href="{{ $href }}" wire:navigate.hover class="flex items-center justify-between gap-3 rounded-[12px] px-3 py-2.5 transition-colors hover:bg-[#f4f6f5]">
                                <span class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] bg-[#f4f6f5]">
                                        <x-ui.anim-icon :file="$icon" class="h-4 w-4" />
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
                    <div class="dashboard-search-footer border-t border-zinc-100 px-4 py-3">
                        <p class="text-xs text-zinc-600">Type above to search products, brands, orders or account settings.</p>
                    </div>
                </div>
            </div>


            {{-- Cart. State lives in the global Alpine cart store ($store.cart) — the same
                 store the storefront nav uses. The popup drops open automatically when an
                 item is added (store.add sets open = true). --}}
            <div
                x-data="{ locked: false, closeTimer: null }"
                @mouseenter="clearTimeout(closeTimer); if (!locked) $store.cart.open = true"
                @mouseleave="if (!locked) closeTimer = setTimeout(() => { if (!locked) $store.cart.open = false }, 250)"
                @click.outside="$store.cart.open = false; locked = false"
                @keydown.escape.window="$store.cart.open = false; locked = false"
                class="relative"
            >
                <button
                    type="button"
                    @click="locked = !locked; $store.cart.open = locked"
                    :aria-expanded="$store.cart.open.toString()"
                    aria-haspopup="menu"
                    class="relative flex h-9 w-9 items-center justify-center rounded-full border-2 border-white/30 text-white transition-colors hover:bg-white/15 dark:border-white/30 dark:text-zinc-200 dark:hover:bg-white/5"
                    aria-label="Shopping cart"
                >
                    {{-- Inline so the cart rolls on hover, in the button's text colour. --}}
                    {{-- The storefront's cart glyph (x-icons.cart), inside the same
                         hover-animation wrapper x-ui.anim-icon uses. --}}
                    <span class="anim-icon anim-icon--cart inline-flex h-6 w-6 shrink-0" aria-hidden="true"><x-icons.cart /></span>
                    <span
                        x-show="$store.cart.count > 0"
                        x-text="$store.cart.count"
                        x-cloak
                        class="absolute -top-1 -right-1 inline-flex h-4 min-w-[16px] items-center justify-center rounded-[5px] bg-blue-600 px-1 text-[9px] font-bold text-white ring-2 ring-white dark:ring-0"
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
                    class="dashboard-frame-popup cart-panel absolute right-0 top-full z-50 mt-2 w-[340px] overflow-hidden rounded-[12px] bg-white/80 px-3 py-2 backdrop-blur-xl shadow-xl shadow-zinc-900/15"
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
                                    <span class="flex h-16 w-24 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-[#f4f6f5] shadow-sm">
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
                                        <button type="button" @click="$store.cart.setQty(item.id, item.quantity - 1)" class="flex h-7 w-7 items-center justify-center rounded-[12px] text-zinc-600 ring-1 ring-zinc-200 transition-colors hover:bg-[#f4f6f5] hover:text-zinc-900" aria-label="Decrease">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M5 12h14"/></svg>
                                        </button>
                                        <span class="w-5 text-center text-sm font-bold tabular-nums text-zinc-900" x-text="item.quantity"></span>
                                        <button type="button" @click="$store.cart.setQty(item.id, item.quantity + 1)" class="flex h-7 w-7 items-center justify-center rounded-[12px] text-zinc-600 ring-1 ring-zinc-200 transition-colors hover:bg-[#f4f6f5] hover:text-zinc-900" aria-label="Increase">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/></svg>
                                        </button>
                                    </span>
                                </li>
                            </template>
                        </ul>

                        <div class="mt-3 flex gap-2 rounded-[12px] bg-[#f4f6f5] px-3 py-3">
                            <a href="{{ route('dashboard.shop.cart') }}" wire:navigate.hover @click="$store.cart.open = false; locked = false" class="flex-1 inline-flex items-center justify-center rounded-[12px] bg-white px-4 py-3.5 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-200 transition-colors hover:bg-[#f4f6f5]">
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

            {{-- The desktop bar carries a bell beside the avatar. Notifications
                 also stays as a row inside the account menu. --}}

            {{-- Profile dropdown.
                 Owns its own dropdown state + a small inline currency picker.
                 Opens and closes on click only (no hover), closes on outside click or Escape.
                 Locale state lives on a separate body-root wrapper around the modal
                 so the modal can render outside flux:header (avoids broken centering
                 from transformed sticky ancestors). The locale chip values here listen
                 to a 'locale-updated' window event dispatched by the modal wrapper. --}}
            {{-- Separate outlined bell and account controls match the reference. --}}
            <div class="dashboard-header-controls flex items-center gap-3">
                <livewire:notifications-menu tone="chip" wire:key="header-notif" />
            <div
                x-data="{
                    open: false,
                    locked: false,
                    country: 'United States',
                    countryFlag: '🇺🇸',
                    countryCode: 'US',
                }"
                {{-- Click to open, click again (or outside, or Escape) to close. No
                     hover-open: the menu holds switches, and a hover menu slipped
                     shut when the pointer drifted off it mid-toggle. --}}
                @click.outside="open = false; locked = false"
                @keydown.escape.window="open = false; locked = false"
                @locale-updated.window="country = $event.detail.country; countryFlag = $event.detail.countryFlag; countryCode = $event.detail.countryCode"
                class="relative"
            >
                {{-- Avatar button opens the account menu; the unread count also
                     appears on that menu's Notifications row. --}}
                <button type="button" @click="locked = !locked; open = locked" :aria-expanded="open.toString()" aria-label="{{ $user?->name ?? 'Account' }}" class="dashboard-avatar-button relative flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-white/30 text-white transition-colors hover:bg-white/15 dark:border-white/30 dark:text-zinc-200 dark:hover:bg-white/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60">
                    @if ($user?->avatar_url)
                        <img src="{{ $user->avatar_url }}" alt="{{ $user->name }}" class="h-full w-full object-cover">
                    @else
                        <flux:icon.user class="h-6 w-6" />
                    @endif
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
                    class="dashboard-frame-popup dashboard-account-menu absolute right-0 top-full z-50 mt-3 w-[300px] max-w-[calc(100vw-1rem)] overflow-y-auto rounded-[12px] bg-white/80 backdrop-blur-xl shadow-xl shadow-zinc-900/15"
                    role="menu"
                >
                    {{-- Account menu: identity header, then groups split by
                         edge-to-edge hairlines (account, settings, help,
                         preferences, log out). A compact list: 40px rows, 14px
                         labels, 18px muted icons, no tiles, the row's value or
                         switch at its right edge. --}}
                    @php
                        $menuRow = 'dashboard-account-row flex w-full items-center justify-between gap-3 px-4 py-2 text-left text-[14px] font-medium text-zinc-800 transition-colors hover:bg-[#f4f6f5] dark:text-zinc-200';
                        $menuIcon = 'dashboard-account-row-icon flex items-center gap-3 text-[#0c1337] dark:text-zinc-300';
                        $menuDivider = 'border-t border-zinc-200/80 dark:border-white/10';
                        $menuSwitchTrack = 'relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors';
                        $menuSwitchKnob = 'inline-block h-4 w-4 rounded-full bg-white shadow transition-transform';
                        $menuKycVerified = $user?->isKycVerified() ?? false;
                    @endphp

                    <div class="px-4 pb-3 pt-4">
                        <p class="truncate text-[15px] font-semibold text-zinc-900 dark:text-white">{{ $user?->name ?? 'Account' }}</p>
                        <p class="mt-0.5 truncate text-[12px] text-zinc-500 dark:text-zinc-400">{{ $user?->email ?? '' }}</p>
                    </div>

                    <div class="{{ $menuDivider }}"></div>

                    {{-- Account --}}
                    <div class="py-1.5">
                        <a href="{{ route('dashboard.profile') }}" wire:navigate.hover class="{{ $menuRow }}" role="menuitem">
                            <span class="{{ $menuIcon }}"><x-ui.anim-icon file="user.svg" tone="inherit" class="h-[18px] w-[18px]" /><span class="text-zinc-800 dark:text-zinc-200">Profile</span></span>
                        </a>
                        <a href="{{ route('dashboard.kyc') }}" wire:navigate.hover class="{{ $menuRow }}" role="menuitem">
                            <span class="{{ $menuIcon }}"><x-ui.anim-icon file="customer.svg" tone="inherit" class="h-[18px] w-[18px]" /><span class="text-zinc-800 dark:text-zinc-200">{{ $menuKycVerified ? 'Identity Verified' : 'Verify Identity' }}</span></span>
                            @if ($menuKycVerified)
                                <svg class="h-4 w-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-label="Verified"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            @endif
                        </a>
                        <a href="{{ route('dashboard.rewards') }}" wire:navigate.hover class="{{ $menuRow }}" role="menuitem">
                            <span class="{{ $menuIcon }}"><x-ui.anim-icon file="for-partners.svg" tone="inherit" class="h-[18px] w-[18px]" /><span class="text-zinc-800 dark:text-zinc-200">For Partners</span></span>
                        </a>
                    </div>

                    <div class="{{ $menuDivider }}"></div>

                    {{-- Settings --}}
                    <div class="py-1.5">
                        <a href="{{ route('dashboard.security') }}" wire:navigate.hover class="{{ $menuRow }}" role="menuitem">
                            <span class="{{ $menuIcon }}"><x-ui.anim-icon file="admin access.svg" tone="inherit" class="h-[18px] w-[18px]" /><span class="text-zinc-800 dark:text-zinc-200">Security</span></span>
                        </a>
                        <a href="{{ route('dashboard.notifications') }}" wire:navigate.hover class="{{ $menuRow }}" role="menuitem">
                            <span class="{{ $menuIcon }}"><x-ui.anim-icon file="notification 2.svg" tone="inherit" class="h-[18px] w-[18px]" /><span class="text-zinc-800 dark:text-zinc-200">Notifications</span></span>
                            @if ($notificationCount > 0)
                                <span class="inline-flex h-5 min-w-[20px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">{{ $notificationCount > 9 ? '9+' : $notificationCount }}</span>
                            @endif
                        </a>
                        <a href="{{ route('dashboard.appearance') }}" wire:navigate.hover class="{{ $menuRow }}" role="menuitem">
                            <span class="{{ $menuIcon }}"><x-ui.anim-icon file="Appearance.svg" tone="inherit" class="h-[18px] w-[18px]" /><span class="text-zinc-800 dark:text-zinc-200">Appearance</span></span>
                        </a>
                        {{-- Preferences: country, language and currency live together in
                             the sheet this opens, so the row is named for all three, with
                             the language in force shown beside it. --}}
                        <button
                            type="button"
                            {{-- Opens the preferences modal on its root pane, where
                                 language, currency and country sit together, rather
                                 than deep-linking to the language list alone. --}}
                            @click="open = false; locked = false; $dispatch('open-locale-modal')"
                            class="{{ $menuRow }}"
                            role="menuitem"
                        >
                            <span class="{{ $menuIcon }}">
                                <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 21l5.25-11.25L21 21m-9-3h7.5M3 5.621a48.474 48.474 0 016-.371m0 0c1.12 0 2.233.038 3.334.114M9 5.25V3m3.334 2.364C11.176 10.658 7.69 15.08 3 17.502m9.334-12.138c.896.061 1.785.147 2.666.257m-4.589 8.495a18.023 18.023 0 01-3.827-5.802"/></svg>
                                <span class="text-zinc-800 dark:text-zinc-200">{{ __('common.preferences') }}</span>
                            </span>
                            <span class="text-[13px] font-normal text-zinc-500 dark:text-zinc-400">{{ \App\Support\Languages::nameFor(app()->getLocale()) ?? \App\Support\Languages::defaultName() }}</span>
                        </button>
                    </div>

                    <div class="{{ $menuDivider }}"></div>

                    {{-- Help + leaving the dashboard. The arrow marks rows that take you
                         out of the account area. --}}
                    <div class="py-1.5">
                        <a href="{{ route('shop.contact') }}" wire:navigate.hover class="{{ $menuRow }}" role="menuitem">
                            <span class="{{ $menuIcon }}"><x-ui.anim-icon file="support.svg" tone="inherit" class="h-[18px] w-[18px]" /><span class="text-zinc-800 dark:text-zinc-200">Help &amp; support</span></span>
                            <svg class="h-4 w-4 shrink-0 text-[#0c1337] dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                        </a>
                        <a href="{{ route('home') }}" wire:navigate.hover class="{{ $menuRow }}" role="menuitem">
                            <span class="{{ $menuIcon }}"><x-ui.anim-icon file="Back to shop.svg" tone="inherit" class="h-[18px] w-[18px]" /><span class="text-zinc-800 dark:text-zinc-200">Back to store</span></span>
                            <svg class="h-4 w-4 shrink-0 text-[#0c1337] dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                        </a>
                    </div>

                    <div class="{{ $menuDivider }}"></div>

                    {{-- Preferences. Dark mode goes through the theme engine
                         (window.setTheme, partials/theme-engine), which saves the
                         choice and fires theme-changed so this switch re-syncs.
                         Hide balances and Compact mode used to sit here as well
                         and were removed on request; the wallet figures keep
                         their own eye toggle. --}}
                    <div class="py-1.5">
                        <button
                            type="button"
                            x-data="{ dark: window.themeIsDark ? window.themeIsDark() : document.documentElement.classList.contains('dark') }"
                            x-on:theme-changed.window="dark = $event.detail.dark"
                            @click="window.rshopThemeReveal($event, dark ? 'light' : 'dark')"
                            role="menuitemcheckbox"
                            :aria-checked="dark.toString()"
                            class="{{ $menuRow }}"
                        >
                            <span class="{{ $menuIcon }}">
                                <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 010 18z" fill="currentColor"/></svg>
                                <span class="text-zinc-800 dark:text-zinc-200">Dark mode</span>
                            </span>
                            <span :class="dark ? 'bg-[#0c1337]' : 'bg-zinc-300 dark:bg-white/15'" class="theme-static {{ $menuSwitchTrack }}">
                                <span :class="dark ? 'translate-x-[18px]' : 'translate-x-0.5'" class="{{ $menuSwitchKnob }}"></span>
                            </span>
                        </button>
                    </div>

                    <div class="{{ $menuDivider }}"></div>

                    {{-- Log out --}}
                    <div class="py-1.5">
                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                            @csrf
                            <button type="submit" class="dashboard-account-row flex w-full items-center gap-3 px-4 py-2 text-left text-[14px] font-medium text-zinc-800 transition-colors hover:bg-[#f4f6f5] dark:text-zinc-200">
                                <x-ui.logout-icon class="h-[18px] w-[18px]" />
                                Log out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            </div>
        </flux:header>

        {{-- Sub-navigation slot beside the rail (see the sub-navigation rules in the
             <style> block). Pages teleport their own panel in; no whitespace inside,
             so :has(> *) only matches once a panel has actually arrived. --}}
        <div data-dash-subnav class="hidden lg:block"></div>

        @php
            // The blue hero (wallet carousel) shows ONLY on the dashboard overview.
            // Every page shares the same fixed top bar above it: the hamburger on
            // the left, the round action chips on the right. The hamburger opens
            // the sidebar drawer, which is the whole mobile navigation - there is
            // no bottom tab bar and no popup menu any more, and no back affordance:
            // every destination is one tap away inside the drawer.
            $skipMobileHero = ! request()->routeIs('dashboard');
        @endphp

        {{-- flux:main is the single main-axis child of Flux's layout container.
             ANYTHING that should stack vertically with the page content (mobile blue hero,
             slim inner-page bar, the page slot) goes INSIDE it — otherwise Flux's flex
             container puts them side-by-side with flux:main on mobile. --}}
        {{-- On desktop this pane is the panel's rounded frame; the page wrapper
             ([data-page-content]) inside it is the scroll area, and re-announces
             its scroll on window: app.js closes open dropdowns on window scroll,
             and the window itself no longer scrolls there. The x-data here gives
             that listener an Alpine scope. The inner column is capped at 1400px
             and centred, so pages do not run the full width of wide screens. --}}
        <flux:main x-data class="!p-0 bg-[#f4f6f5] dark:bg-[#0c1a36] dark:lg:bg-[#0c1a36]">

        {{-- Spacer holding the fixed header's room in normal flow (its height
             is set in app.css with the header's mobile padding). --}}
        <div class="lg:hidden" style="height: calc(env(safe-area-inset-top) + 3.75rem);" aria-hidden="true"></div>

        {{-- Below lg the bar's colour runs behind the page and everything from
             here down sits on it as a panel with rounded top corners, the way
             the desktop frame lifts its pane off the header (.dash-mobile-panel
             in app.css). lg:contents removes the wrapper from the desktop
             layout entirely. --}}
        <div class="dash-curve-fill lg:hidden" aria-hidden="true"></div>
        <div class="dash-mobile-panel lg:contents" x-data x-on:scroll.passive="window.dispatchEvent(new Event('scroll'))">
        @unless ($skipMobileHero)
        {{-- The balance area, its own band under the bar, clipped by the
             panel's curve.

             It is a coloured band in both themes now. Dark keeps the navy it
             always had. Light used to be transparent, so the balance sat on the
             plain panel and the band did not read as a band at all; it now
             carries a wash from deep blue into a bright cyan-blue.

             bg-none in dark is what makes that work: the gradient is a
             background-IMAGE, and a dark background-colour alone would sit
             under it rather than replace it.

             It runs deeper than its content needs and curves at the bottom so
             the first row of cards rises into it, rather than the colour
             stopping on a flat edge the moment the balance text ends. The
             content below pulls up over that curve. --}}
        <header class="dash-hero-wash relative z-10 rounded-b-[28px] px-5 pt-5 pb-14 lg:hidden">
            @isset($mobileHero)
                {{-- Padding on the header, not a margin on the hero: a margin
                     would collapse through the header's top edge and leave the
                     panel's curve unpainted above the band. --}}
                <div>
                    {{ $mobileHero }}
                </div>
            @endisset
        </header>
        @endunless

            <div data-page-content x-on:scroll.passive="window.dispatchEvent(new Event('scroll'))" class="flex min-h-full flex-col bg-[#f4f6f5] px-4 pt-5 pb-10 sm:px-6 sm:pt-6 lg:px-10 lg:py-8 dark:bg-[#0c1a36]">
                <div class="w-full min-w-0 lg:mx-auto lg:max-w-[1400px] lg:flex-1">
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

                    @if (session('account_closure_cancelled'))
                        <div class="mb-4 rounded-[12px] bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200" role="status">
                            {{ session('account_closure_cancelled') }}
                        </div>
                    @endif

                    {{ $slot }}
                </div>

                {{-- Smile ID verification modal (window.rshopKyc). Shared across
                     dashboard pages so the wallet-funding step-up flow can launch
                     identity verification, exactly like checkout. --}}
                @auth
                    @include('partials.kyc-verification-modal')
                @endauth

                {{-- The Terms / Refund Policy / Privacy Policy / version / copyright
                     footer line was removed from the dashboard at the user's request. --}}
            </div>
        </div>
        </flux:main>

        {{-- Global confirm modal — intercepts any form/button with `data-confirm`. --}}
        <x-confirm-modal />

        {{-- Action flashes pop as a floating pill toast (status / success / error /
             wallet_created), so pages don't need their own inline banners. --}}
        <x-flash-toast />

        {{-- Locale modal at body root.
             Mounted outside flux:header so its position:fixed centers against the viewport
             rather than against a transformed sticky ancestor. Profile dropdown menu items
             dispatch 'open-locale-modal' to open it; we dispatch 'locale-updated' back
             whenever the country changes so the profile chip values stay in sync. --}}
        <div
            x-data="storefrontLocale()"
            x-init="
                init();
                // The floating chips, desktop switcher and profile chip are separate
                // Alpine scopes that default to United States and only sync via this
                // event. Broadcast the persisted values once on load so they show the
                // saved country instead of reverting to USA on every reload. Language
                // is server state and travels with the page, not with this event.
                $nextTick(() => $dispatch('locale-updated', { country: country, countryFlag: countryFlag, countryCode: countryCode }));
                $watch('country',  v => $dispatch('locale-updated', { country: country, countryFlag: countryFlag, countryCode: countryCode }));
            "
            x-on:open-locale-modal.window="localeModalOpen = true"
            @keydown.escape.window="localeModalOpen = false"
            x-effect="localeModalOpen ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
        >
            <x-nav.locale-modal />
        </div>

        {{-- Mobile sidebar drawer. Opened by the top bar's hamburger (and any
             other trigger that dispatches 'open-mobile-menu'), it slides in from
             the left wearing the bar's own colour (navy, black in dark mode),
             the app logo level with the bar, then the desktop sidebar's rows in
             three groups. It covers about two thirds of the screen, the page
             dimmed beside it. lg:hidden so it never shows on desktop, where the
             real sidebar is. --}}
        @php
            // Completed orders drive the badge on the Orders row, as on the
            // desktop sidebar.
            $completedOrderCount = auth()->user()
                ? (int) auth()->user()->orders()->where('order_status', \App\Domain\Order\Enums\OrderStatus::Completed)->count()
                : 0;

            $drawerItems = [
                ['label' => 'Overview',      'href' => route('dashboard'),                 'icon' => 'Home.svg',           'active' => request()->routeIs('dashboard'),               'nav' => true],
                // Shop does not navigate: tapping it drops the categories down
                // beneath it (the desktop sidebar's $shopSubItems, same order
                // and gating), open already when the customer is on one.
                ['label' => 'Shop',          'href' => route('dashboard.shop.gift-cards'), 'icon' => 'Shop.svg',           'active' => request()->routeIs('dashboard.shop.*'),        'nav' => true, 'expands' => true],
                ['label' => 'Orders',        'href' => route('dashboard.orders'),          'icon' => 'order.svg',          'active' => request()->routeIs('dashboard.orders*'),       'nav' => true, 'badge' => $completedOrderCount],
                ['label' => 'My eSIMs',      'href' => route('dashboard.esims'),           'icon' => 'esim.svg',           'active' => request()->routeIs('dashboard.esims'),         'nav' => true],
                ['label' => 'Wallet',        'href' => route('dashboard.wallet'),          'icon' => 'Wallet.svg',         'active' => request()->routeIs('dashboard.wallet'),        'nav' => true],
                ['label' => 'Transactions',  'href' => route('dashboard.transactions'),    'icon' => 'transactions 1.svg', 'active' => request()->routeIs('dashboard.transactions*'), 'nav' => true],
                // The account rows sit under an "Account" label, the last two
                // under "More"; the label is drawn when the group changes.
                ['label' => 'Settings',      'href' => route('dashboard.profile'),         'icon' => 'system setting.svg', 'active' => request()->routeIs('dashboard.profile'),       'nav' => true, 'group' => 'Account'],
                ['label' => 'Security',      'href' => route('dashboard.security'),        'icon' => 'admin access.svg',   'active' => request()->routeIs('dashboard.security'),      'nav' => true, 'group' => 'Account'],
                ['label' => 'Verify (KYC)',  'href' => route('dashboard.kyc'),             'icon' => 'customer.svg',       'active' => request()->routeIs('dashboard.kyc'),           'nav' => true, 'group' => 'Account'],
                ['label' => 'Appearance',    'href' => route('dashboard.appearance'),      'icon' => 'Appearance.svg',     'active' => request()->routeIs('dashboard.appearance'),    'nav' => true, 'group' => 'Account'],
                ['label' => 'Notifications', 'href' => route('dashboard.notifications'),   'icon' => 'notification 2.svg', 'active' => request()->routeIs('dashboard.notifications'), 'nav' => true, 'group' => 'Account'],
                // The rewards page is the Rcoin programme (cashback, referrals,
                // withdrawals, conversion); the row carries the partner name.
                ['label' => 'For Partners',  'href' => route('dashboard.rewards'),         'icon' => 'for-partners.svg',   'active' => request()->routeIs('dashboard.rewards'),       'nav' => true, 'group' => 'More'],
                ['label' => 'Support',       'href' => 'https://wa.me/19402386229?text=Hello%20Rshoprefill%20can%20i%20get%20help%3F', 'icon' => 'support.svg', 'active' => false, 'nav' => false, 'group' => 'More'],
            ];

            // Drawer rows: 14px semibold on the bar's colour, 18px icons, 10px
            // corners, the current page on a soft white tile. The colours are
            // app.css's (.dash-drawer), so dark mode's remaps cannot touch them.
            $drawerRow = fn (bool $active) => 'dash-drawer-row flex items-center justify-between gap-3 rounded-[10px] px-3 py-2 text-[15px] font-semibold transition-colors'.($active ? ' dash-drawer-row-active' : '');
            $drawerIcon = 'h-5 w-5 shrink-0';
            // The categories under Shop: a size down, text only (the Shop row
            // carries the icon), the current one on the same soft tile.
            $drawerSubRow = fn (bool $active) => 'dash-drawer-subrow flex items-center justify-between gap-3 rounded-[10px] px-3 py-2 text-sm font-medium transition-colors'.($active ? ' dash-drawer-subrow-active' : '');
        @endphp
        {{-- `contents` keeps the wrapper out of Flux's grid so it cannot push
             flux:sidebar/header/main into an unexpected row. --}}
        <div
            x-data="{ menuOpen: false }"
            x-on:open-mobile-menu.window="$nextTick(() => menuOpen = true)"
            x-on:keydown.escape.window="menuOpen = false"
            x-on:livewire:navigated.window="menuOpen = false"
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

            {{-- The drawer: the bar's colour, slid in from the left. --}}
            <aside
                x-show="menuOpen"
                x-transition:enter="transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
                x-transition:enter-start="-translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition-transform duration-200 ease-in"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="-translate-x-full"
                style="display: none;"
                class="dash-drawer fixed inset-y-0 left-0 z-[70] flex w-[min(69vw,270px)] flex-col"
                role="dialog"
                aria-modal="true"
                aria-label="Menu"
            >
                {{-- Logo row: as tall as the bar, so the logo sits level with the
                     bar's chips and the close button beside the panel. The logo
                     is drawn white (app.css) on the panel's dark colour.

                     The rule under it separates the brand from the destinations.
                     white/20 rather than the usual white/10: this panel is navy
                     in light and pure black in dark, and at 10 percent a hairline
                     disappears entirely on black, the same way the header chips
                     did. One value covers both themes because both are dark. --}}
                <div class="dash-drawer-head flex shrink-0 items-center border-b border-white/20 px-4">
                    <a href="{{ route('dashboard') }}" wire:navigate.hover @click="menuOpen = false" class="flex items-center rounded-[8px] focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60" aria-label="{{ $siteName }} dashboard">
                        {{-- The full logo (mark + wordmark), the file the storefront
                             header shows from md up. --}}
                        <img src="{{ asset('assets/logo.webp').'?v=4' }}" alt="{{ $siteName }}" class="site-logo h-12 w-auto object-contain">
                    </a>
                </div>

                {{-- The rows, grouped: the main destinations, then Account, then More. --}}
                <nav class="flex flex-1 flex-col gap-0.5 overflow-y-auto px-3 pb-6 pt-3" aria-label="Menu">
                    @php $drawerGroup = null; @endphp
                    @foreach ($drawerItems as $item)
                        @if (($item['group'] ?? null) !== $drawerGroup)
                            @php $drawerGroup = $item['group']; @endphp
                            <p class="dash-drawer-label mb-1 mt-5 px-3 text-[13px] font-semibold">{{ $drawerGroup }}</p>
                        @endif
                        @if ($item['expands'] ?? false)
                            {{-- Shop: the row is a button. Tapping it drops the categories
                                 down beneath it; the chevron turns to say so. The list
                                 starts open on a shop page so the current category shows.
                                 The current category, not the Shop row, is the page. --}}
                            <div x-data="{ shopOpen: @js($item['active']) }" class="flex flex-col gap-0.5">
                                <button
                                    type="button"
                                    @click="shopOpen = ! shopOpen"
                                    :aria-expanded="shopOpen.toString()"
                                    aria-controls="dash-drawer-shop-categories"
                                    class="{{ $drawerRow($item['active']) }} w-full text-left"
                                >
                                    <span class="flex min-w-0 items-center gap-3">
                                        <x-ui.anim-icon :file="$item['icon']" tone="inherit" :class="$drawerIcon" />
                                        <span class="truncate">{{ $item['label'] }}</span>
                                    </span>
                                    <svg :class="shopOpen && 'rotate-180'" class="h-4 w-4 shrink-0 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>
                                <div id="dash-drawer-shop-categories" x-show="shopOpen" x-collapse class="ml-5 flex flex-col gap-0.5 border-l border-white/10 pl-3">
                                    @foreach ($shopSubItems as [$label, $href, $flag, $newKey, $subActive])
                                        @if ($flag === null || \App\Support\FeatureFlag::on($flag))
                                            <a
                                                href="{{ $href }}"
                                                wire:navigate.hover
                                                @click="menuOpen = false"
                                                class="{{ $drawerSubRow($subActive) }}"
                                                @if ($subActive) aria-current="page" @endif
                                            >
                                                <span class="truncate">{{ $label }}</span>
                                                @if (in_array($newKey, $newServices, true))
                                                    <span class="dash-row-badge inline-flex items-center whitespace-nowrap rounded-[5px] bg-white/15 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">New</span>
                                                @endif
                                            </a>
                                        @else
                                            {{-- Feature off: listed but greyed and not a link, as on desktop. --}}
                                            <span class="{{ $drawerSubRow(false) }} cursor-not-allowed opacity-50" aria-disabled="true" title="Temporarily unavailable">
                                                <span class="truncate">{{ $label }}</span>
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                            @continue
                        @endif
                        <a
                            href="{{ $item['href'] }}"
                            @if ($item['nav']) wire:navigate.hover @else target="_blank" rel="noopener" @endif
                            @click="menuOpen = false"
                            class="{{ $drawerRow($item['active']) }}"
                            @if ($item['active']) aria-current="page" @endif
                        >
                            <span class="flex min-w-0 items-center gap-3">
                                <x-ui.anim-icon :file="$item['icon']" tone="inherit" :class="$drawerIcon" />
                                <span class="truncate">{{ $item['label'] }}</span>
                            </span>
                            @if (($item['badge'] ?? 0) > 0)
                                <span class="dash-row-badge inline-flex items-center whitespace-nowrap rounded-[5px] bg-white/15 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">{{ $item['badge'] > 99 ? '99+' : $item['badge'] }}</span>
                            @endif
                        </a>
                    @endforeach
                </nav>
            </aside>

            {{-- Close: outside the panel, on the backdrop beside its top edge,
                 level with the bar's chips and the logo. Tapping the backdrop
                 closes too. --}}
            <button
                type="button"
                x-show="menuOpen"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                @click="menuOpen = false"
                style="display: none; left: calc(min(69vw, 270px) + 0.75rem); top: calc(env(safe-area-inset-top) + 0.75rem);"
                aria-label="Close menu"
                class="dash-drawer-close fixed z-[70] flex h-9 w-9 items-center justify-center rounded-full bg-white/15 text-white backdrop-blur-sm transition-colors active:bg-white/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60"
            >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- PWA pull-to-refresh (standalone mode only). --}}
        {{-- Rate-your-experience prompt. Asks a signed-in customer once a day,
             after the admin-set delay, until they have answered. Gated by
             features.experience_prompt_enabled so it can be switched off
             without a deploy; the flag ships off. --}}
        @feature('experience_prompt')
            <livewire:experience-prompt />
        @endfeature

        <x-pull-to-refresh />

        {{-- Collapsed-rail tooltip: shows a sidebar link's name (its data-tip)
             beside the icon on hover or keyboard focus, only while the rail is
             collapsed. One fixed element on <body>, placed from the link's live
             position, so the rail's overflow clip cannot hide it. Delegated and
             guarded so wire:navigate never double-binds; the element is rebuilt
             if a navigation swapped the body out from under it. --}}
        <script>
            (function () {
                if (window.__dashRailTipInit) { return; }
                window.__dashRailTipInit = true;

                let tip = null;
                const linkFor = (target) => target && target.closest
                    ? target.closest('[data-flux-sidebar] nav :is(a, button)[data-tip]')
                    : null;

                const show = (link) => {
                    if (! document.documentElement.classList.contains('dashboard-sidebar-collapsed')) { return hide(); }
                    if (! tip || ! document.body.contains(tip)) {
                        tip = document.createElement('div');
                        tip.className = 'dash-rail-tip';
                        tip.setAttribute('role', 'tooltip');
                        document.body.appendChild(tip);
                    }
                    const r = link.getBoundingClientRect();
                    tip.textContent = link.getAttribute('data-tip');
                    tip.style.top = (r.top + r.height / 2) + 'px';
                    // 12px clear of the icon leaves room for the bubble's pointer.
                    tip.style.left = (r.right + 12) + 'px';
                    tip.classList.add('is-visible');
                };
                const hide = () => { if (tip) { tip.classList.remove('is-visible'); } };

                document.addEventListener('pointerover', (e) => { const link = linkFor(e.target); link ? show(link) : hide(); });
                document.addEventListener('focusin', (e) => { const link = linkFor(e.target); link ? show(link) : hide(); });
                document.addEventListener('focusout', hide);
                document.addEventListener('scroll', hide, true);
                document.addEventListener('livewire:navigating', hide);
            })();
        </script>

        {{-- Global Web Push Notification Prompt --}}
        <x-push-prompt />

        @fluxScripts
    </body>
</html>
