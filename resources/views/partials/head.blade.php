<meta charset="utf-8" />
{{-- viewport-fit=cover is what makes env(safe-area-inset-*) resolve to real
     numbers. Without it iOS reports every inset as 0, so the safe-area padding
     this app already carries on its fixed chrome (dashboard top bar, floating
     locale/cart pills, mobile drawer, bottom nav) silently collapsed to its
     small fallback - which is fine in a browser, where browser UI occupies that
     space, but put the chrome under the status bar once the site was installed
     to the home screen and ran standalone. Every top- and bottom-anchored
     surface pads for the inset; see the storefront header below and
     layouts/dashboard. --}}
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
<meta name="csrf-token" content="{{ csrf_token() }}" />
<meta name="vapid-public-key" content="{{ config('webpush.vapid.public_key') }}">

{{-- Analytics / marketing tags (GA, GTM, Meta pixel) - driven by the admin
     SEO settings, loaded on customer pages only. --}}
<x-tracking-tags />


@php
    /*
     * Resolve the user's effective theme (dark | light) on the server so
     * the pre-paint frame uses the right colour scheme and bg, eliminating
     * the dark-on-light flash on hard refresh:
     *
     *   - Account holders: their saved DB preference is authoritative.
     *   - Guests + 'system': fall back to a cookie that mirrors the JS
     *     decision from the last visit. First-ever visit defaults to LIGHT
     *     (better trade-off than reading OS pref into a dark blank state).
     *
     * The theme-engine script below still re-asserts at runtime, but by
     * then HTML + CSS already painted the correct colour.
     */
    $themeIsAdminAreaHead = request()->is('admin*');
    $themeAccountHead = $themeIsAdminAreaHead
        ? auth('admin')->user()
        : auth('web')->user();
    $themeServerChoiceHead = $themeAccountHead?->theme;
    $themeCookieHead = $themeIsAdminAreaHead
        ? request()->cookie('theme_admin_dark')
        : request()->cookie('theme_web_dark');
    $themeIsDarkInitial = match (true) {
        $themeServerChoiceHead === 'dark' => true,
        $themeServerChoiceHead === 'light' => false,
        // 'system' or null falls through to the cookie hint.
        default => $themeCookieHead === '1',
    };

    // Soft dark is retired: there is a single dark palette, so the first paint
    // no longer needs a second cookie to pick a ramp. .pure-dark now rides
    // along with .dark wherever dark resolves.
@endphp

{{-- Tell the browser the resolved scheme so the OS-default blank-page bg
     during a full refresh matches the page that's about to paint. Setting
     only the resolved value (not "light dark") stops the browser from
     using OS preference for the pre-CSS frame. --}}
<meta name="color-scheme" content="{{ $themeIsDarkInitial ? 'dark' : 'light' }}">

{{-- Apply .dark to <html> server-side AND prime Flux UI's own theme
     storage key in the SAME synchronous block. Flux ships its own
     appearance directive that reads `flux.appearance` from localStorage
     and falls back to OS pref - if it loads before our value is written,
     it can flip the page based on OS pref alone (the dark-then-light
     blink the user kept hitting on light-mode accounts whose OS is in
     dark mode). Writing the synced value here means even the very first
     paint after Flux's script sees the correct preference. --}}
<script>
    (function () {
        @if ($themeIsDarkInitial)
            document.documentElement.classList.add('dark');
        @endif
        @if ($themeIsDarkInitial)
            {{-- One dark palette now, so .pure-dark rides along with .dark
                 rather than being a separate stored preference. --}}
            document.documentElement.classList.add('pure-dark');
        @endif
        try {
            localStorage.setItem(
                'flux.appearance',
                @json($themeServerChoiceHead ?? ($themeIsDarkInitial ? 'dark' : 'light'))
            );
        } catch (_) {}
    })();
</script>

{{-- Pin Flux to manual mode so its appearance script reads our value
     from localStorage instead of treating an unset key as "follow OS".
     Combined with the localStorage write above, this guarantees Flux
     and our theme engine always agree. --}}
<script>window.flux = window.flux || {}; window.flux.appearance = @json($themeServerChoiceHead ?? ($themeIsDarkInitial ? 'dark' : 'light'));</script>

{{-- THEME ENGINE MUST RUN BEFORE the Vite directive. The script inside
     reconciles the .dark class on <html> against the account's saved
     theme + localStorage + OS preference. The server-side hints above
     mean it usually has nothing to change, so no flash. --}}
@include('partials.theme-engine')

{{-- Match the blank-page bg to whatever the body bg is, so the moment the
     browser shows the new request before our full CSS parses doesn't flash
     a different colour. .dark is on <html> from the script above so the
     correct rule wins from the first paint. --}}
<style>
    html { background-color: #ffffff; }
    /* Paint the pre-CSS frame at the grainy-black ground so the first byte
       already matches the palette and dark never flashes a lighter grey. */
    html.dark { background-color: #060607; }
    /* Kill the horizontal scrollbar from full-bleed sections (.full-bleed in
       app.css) at the viewport root. `clip` keeps vertical scroll and, unlike
       doing this on an inner wrapper, never becomes a containing block/clip
       context for position:fixed modals + bars.

       This is belt-and-braces now: .full-bleed corrects for the scrollbar
       itself, so a bleeding row no longer overhangs the right edge in the first
       place. Kept because other pages still bleed with a raw calc(50%-50vw). */
    html { overflow-x: clip; }
</style>

{{-- ─────────────────────────────── SEO ───────────────────────────────────
     Single source of truth for the whole site. Pages override per-page by
     passing $title / $description / $ogImage / $ogType / $keywords through the
     layout (see x-shop.layout + x-layouts.app.header), e.g. product pages set
     the brand name + logo so a shared link previews as that product. --}}
@php
    // Public-facing brand name. Shared globally by SiteIdentityComposer from the
    // admin "Site -> name" setting; the ?? keeps a literal fallback for the rare
    // view that renders outside the composer (defensive, should not happen).
    $siteName = $siteName ?? \App\Models\SiteSetting::get('site.name', 'RshopRefill');

    // Admin-controlled SEO defaults (System Settings -> SEO). Each falls back to
    // the built-in default when the admin field is blank, so the panel is the
    // live source of truth without ever rendering an empty tag. Per-page props
    // ($title / $description / ...) still win over both.
    $defaultDescription = \App\Models\SiteSetting::get('seo.default_description')
        ?: 'Buy gift cards, eSIMs, mobile top-ups and bill payments worldwide - instantly. '.$siteName.' removes the friction of regional restrictions, slow delivery and limited payment options, with instant digital delivery, great prices, crypto and mobile-money checkout, and 24/7 support.';
    $defaultKeywords = \App\Models\SiteSetting::get('seo.default_keywords')
        ?: $siteName.', gift cards, buy gift cards online, eSIM, mobile top up, airtime recharge, bill payments, crypto gift cards, Amazon gift card, Apple gift card, Google Play, Steam, Netflix, PlayStation, Xbox, Cameroon, Africa fintech, Divine Ofeh, Johnpaul';
    $defaultTitle = \App\Models\SiteSetting::get('seo.default_title')
        ?: $siteName.' - Gift Cards, eSIMs, Top-ups & Bill Payments';

    $seoTitle       = ! empty($title) ? $title : $defaultTitle;
    $seoDescription = ! empty($description) ? $description : $defaultDescription;
    $seoKeywords    = ! empty($keywords) ? $keywords : $defaultKeywords;
    $seoType        = ! empty($ogType) ? $ogType : 'website';
    // OG image stays on the known-good asset; the seeded seo.og_image_url points
    // at a .jpg that does not exist, so reading it would break link previews.
    // A per-page $ogImage still wins (product pages set their brand art).
    $seoImage       = ! empty($ogImage) ? $ogImage : asset('assets/og-image.png');

    // Default robots directive, admin-overridable (set "noindex, nofollow" to
    // delist the whole site in one switch). A per-page $robots prop wins.
    $defaultRobots = \App\Models\SiteSetting::get('seo.robots_default')
        ?: 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
    $googleSiteVerification = \App\Models\SiteSetting::get('seo.google_verification');

    // Canonical URL, anchored to the configured production host rather than the
    // request host. www vs non-www, and any http-behind-proxy scheme, all
    // collapse to ONE absolute https URL per page - the single signal Google
    // needs so it never splits ranking across duplicate hosts. Query strings
    // are dropped on purpose: locale filters (?country=&currency=) are the same
    // page for ranking. A per-page $canonical prop wins when a page needs to
    // point elsewhere (e.g. a filtered view canonicalising to its base page).
    $canonicalRoot = rtrim(config('app.url') ?: url('/'), '/');
    $canonicalPath = trim(request()->getPathInfo(), '/');
    $seoUrl = ! empty($canonical)
        ? $canonical
        : $canonicalRoot.($canonicalPath === '' ? '' : '/'.$canonicalPath);

    // Brand-entity signals so Google resolves every spelling of the name to ONE
    // entity (this site) and ranks us for brand searches. alternateName covers
    // the singular/plural + .com variants people actually type (the site brand is
    // "RshopRefill" but the domain is rshoprefill.com); sameAs points at the
    // official profiles, read from the same settings the footer uses so there's a
    // single place to edit them.
    $brandAliases = ['RshopRefill', 'rshoprefills', 'rshoprefills.com', 'rshoprefill', 'rshoprefill.com'];

    $brandProfiles = array_values(array_filter([
        \App\Models\SiteSetting::get('social.facebook', 'https://facebook.com/rshoprefills'),
        \App\Models\SiteSetting::get('social.x', 'https://x.com/roddytech237'),
        \App\Models\SiteSetting::get('social.instagram', 'https://instagram.com/rshoprefills'),
        \App\Models\SiteSetting::get('social.tiktok', 'https://tiktok.com/@rshoprefills'),
        config('services.trustpilot.profile_url'),
    ]));

    // Organization schema: the brand, the founders, and the mission - written so
    // search engines and AI crawlers can pick up who built RshopRefill and why.
    $orgJsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => $siteName,
        'alternateName' => $brandAliases,
        'url' => url('/'),
        'sameAs' => $brandProfiles,
        'logo' => asset('assets/icon-512.png'),
        'image' => asset('assets/og-image.png'),
        'description' => $siteName.' is the first product of Roddy Technologies. It makes gift cards, eSIMs, mobile top-ups and bill payments instant and affordable. Founded and built in Cameroon by CEO Divine Ofeh and CTO Johnpaul, the team keeps shipping updates and solving problems for the community while building an Africa-to-international digital ecosystem - from Cameroon to the world.',
        'slogan' => 'From Cameroon to the world.',
        'foundingLocation' => [
            '@type' => 'Place',
            'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'CM'],
        ],
        'areaServed' => 'Worldwide',
        'parentOrganization' => [
            '@type' => 'Organization',
            'name' => 'Roddy Technologies',
        ],
        'founder' => [
            ['@type' => 'Person', 'name' => 'Divine Ofeh', 'jobTitle' => 'Chief Executive Officer (CEO)'],
            ['@type' => 'Person', 'name' => 'Johnpaul', 'jobTitle' => 'Chief Technology Officer (CTO)'],
        ],
    ];
    $siteJsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => $siteName,
        'alternateName' => $brandAliases,
        'url' => url('/'),
    ];
@endphp

<title>{{ $seoTitle }}</title>
<meta name="description" content="{{ $seoDescription }}">
<meta name="keywords" content="{{ $seoKeywords }}">
<meta name="author" content="Divine Ofeh (CEO) and Johnpaul (CTO) - {{ $siteName }}">
{{-- index,follow is the default; the max-* directives explicitly let Google
     show large image previews and full text snippets, which lifts click-through
     on results. Admin can override the default (System Settings -> SEO); a
     per-page $robots prop wins over both. --}}
<meta name="robots" content="{{ $robots ?? $defaultRobots }}">
<link rel="canonical" href="{{ $seoUrl }}">
@if ($googleSiteVerification)
    <meta name="google-site-verification" content="{{ $googleSiteVerification }}">
@endif

{{-- Open Graph: link previews on Facebook, WhatsApp, LinkedIn, iMessage, etc. --}}
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:type" content="{{ $seoType }}">
<meta property="og:title" content="{{ $seoTitle }}">
<meta property="og:description" content="{{ $seoDescription }}">
<meta property="og:url" content="{{ $seoUrl }}">
<meta property="og:image" content="{{ $seoImage }}">
<meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}">

{{-- Twitter / X card --}}
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seoTitle }}">
<meta name="twitter:description" content="{{ $seoDescription }}">
<meta name="twitter:image" content="{{ $seoImage }}">

{{-- Structured data (founders + mission for bots/AI crawlers) --}}
<script type="application/ld+json">{!! json_encode($orgJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
<script type="application/ld+json">{!! json_encode($siteJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

{{-- Per-page structured data (e.g. Product + BreadcrumbList on brand pages).
     Pages pass $jsonLd through x-shop.layout; it may be a single schema array
     or a list of them. Guarded with empty() so the layouts that never set it
     (admin, auth, dashboard) emit nothing. --}}
@if (! empty($jsonLd))
    @foreach ((array_is_list($jsonLd) ? $jsonLd : [$jsonLd]) as $pageSchema)
        <script type="application/ld+json">{!! json_encode($pageSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endforeach
@endif

<link rel="icon" type="image/x-icon" href="{{ asset('assets/favicon.ico') }}">

{{-- ───────────────────────── PWA / installable app ─────────────────────────
     iOS does not read most manifest fields, so the Apple-specific meta + a
     square, OPAQUE apple-touch-icon (transparency would be black-filled on the
     home screen) are required for a clean "Add to Home Screen" result. --}}
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="theme-color" content="#2563eb">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('assets/apple-touch-icon-180.png') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ $siteName }}">
<meta name="application-name" content="{{ $siteName }}">
<script>
    // Capture the install prompt as early as possible (it fires before Alpine
    // boots) and stash it so the floating Install button can replay it on tap.
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        window.__rshopInstallPrompt = e;
        window.dispatchEvent(new CustomEvent('rshop:installable'));
    });
    window.addEventListener('appinstalled', function () {
        window.__rshopInstallPrompt = null;
        window.dispatchEvent(new CustomEvent('rshop:installed'));
    });

    // Register the service worker once the page has loaded so it never competes
    // with first paint. Failures are swallowed - the site works without it.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js').catch(function () {});
        });
    }
</script>

{{-- DM Sans is the only web font we load (the primary --font-sans). It is
     pulled by an @import at the top of app.css, which the browser cannot start
     until the stylesheet parses. Pre-opening both Google Fonts origins here
     lets the fetch begin in parallel: googleapis serves the CSS, gstatic serves
     the font files, and the second needs crossorigin because fonts are always
     fetched anonymously. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

{{-- Flag CDN used by the locale modal, top-bar country chip, and admin
     country pickers. Pre-resolving the DNS shaves ~50-100ms off the first
     flag render without forcing any image load up front. --}}
<link rel="dns-prefetch" href="https://flagcdn.com">
<link rel="preconnect" href="https://flagcdn.com" crossorigin>

{{-- Brand-logo CDN: ~1,300 gift-card / top-up / bill brands serve their logo
     from this single host. Pre-opening the TCP+TLS connection means the first
     row of product logos starts downloading the instant the grid paints instead
     of stalling on a cold handshake. No `crossorigin` - the logos load through
     plain <img> (no-cors), so a credentialed preconnect would open a second
     connection the image requests never reuse. --}}
<link rel="dns-prefetch" href="https://bmk.idtdigitalpayments.com">
<link rel="preconnect" href="https://bmk.idtdigitalpayments.com">

{{-- Async third-party origins (chat, analytics, captcha). These load async/defer
     and sit OFF the critical render path, so dns-prefetch (resolve DNS early) is
     the right hint - not preconnect, which would spend a full TCP+TLS connection
     the fonts/flags need first. Each is gated on its own config so we never hint
     an origin this install doesn't actually use. --}}
@if (config('services.chatway.widget_id'))
    <link rel="dns-prefetch" href="https://cdn.chatway.app">
@endif
@if (config('services.turnstile.enabled'))
    <link rel="dns-prefetch" href="https://challenges.cloudflare.com">
@endif
@if (! request()->is('admin*') && (\App\Models\SiteSetting::get('seo.google_analytics_id') ?: config('services.google.analytics_id') ?: \App\Models\SiteSetting::get('seo.google_tag_manager_id')))
    <link rel="dns-prefetch" href="https://www.googletagmanager.com">
@endif

{{-- The language the server rendered this page in, as its display name.

     app.js paints the nav's language pill from this before hydration and
     mirrors it into localStorage. It has to be set BEFORE the bundle loads,
     and it has to come from the server: the pill used to read localStorage
     first, which was right while a browser translate widget owned the choice
     and wrong the moment Laravel took it back, because a stale entry would
     label the page with a language it is not in. --}}
<script>window.rshopLanguage = @json(\App\Support\Languages::nameFor(app()->getLocale()) ?? \App\Support\Languages::defaultName());</script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

@include('partials.scroll-lock')

{{-- Global keyboard shortcuts (Ctrl+J chat, Ctrl+F ticket, Ctrl+M theme,
     Ctrl+P profile). Site-wide, customer side only. --}}
@include('partials.shortcuts')

{{-- Page transitions. There is no default one: an arriving page is simply
     there. A layout opts in by setting data-page-transition on <body>, which
     is what admin does below - its dense tables looked like they were dropping
     into place on every navigation, and a short fade reads better there than a
     slide.

     The default used to be a 500ms upward slide on every <main> insertion, on
     every layout. Nothing waited for it, but half a second of movement on
     arrival is not distinguishable from half a second of loading, and it ran
     on every wire:navigate swap the site made. --}}
<style>
    /* Micro-Fade Shift: scoped to layouts that opt in via
       <body data-page-transition="micro-fade-shift">. Replaces the slide
       with a very short opacity DIP + tiny upward nudge. Admin uses this
       so the dense data tables don't appear to drop into place on every
       navigation - feels more like a desktop app surface refresh. The
       opacity dips to 0.4 (not 0) so the body bg never gets fully exposed
       through a transparent main. */
    @keyframes microFadeShift {
        from { opacity: 0.4; transform: translateY(6px); }
        to   { opacity: 1;   transform: translateY(0); }
    }
    body[data-page-transition="micro-fade-shift"] main {
        animation: microFadeShift 240ms cubic-bezier(0.22, 1, 0.36, 1) backwards;
    }

    /* Modals/dialogs rise instead of flashing open. */
    @keyframes modalRise { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }
    [role="dialog"]:not(.modal-norise) { animation: modalRise 240ms cubic-bezier(0.22, 1, 0.36, 1) backwards; }

    @media (prefers-reduced-motion: reduce) {
        main { animation: none; }
        [role="dialog"] { animation: none; }
    }
</style>
