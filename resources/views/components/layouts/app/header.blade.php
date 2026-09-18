@props([
    'title' => null,
    'description' => null,
    'ogImage' => null,
    'ogType' => null,
    'keywords' => null,
    'jsonLd' => null,
])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\Languages::direction() }}">
    <head>
        @include('partials.head')

        {{-- Storefront-only head additions (Turnstile loader). All SEO (title,
             description, OG, Twitter, JSON-LD) lives in one place in
             partials/head, overridable per page via the props above.

             No second font here any more. The hero headings used to load
             Urbanist from a third origin purely for display type; DM Sans is
             the whole site's face now, so that request and that origin are
             gone. --}}

        @if(config('services.turnstile.enabled') && (config('services.turnstile.enforce_auth') || config('services.turnstile.enforce_contact') || config('services.turnstile.enforce_checkout')))
            {{-- Cloudflare Turnstile (explicit render mode) - loaded once per
                 page so any form that needs the widget can call into
                 window.turnstile.render(...). Auth modal, contact forms,
                 feedback widget, and checkout all share this single loader. --}}
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>
        @endif
    </head>
    {{-- `storefront` is the scope hook for the shared outline colour in
         app.css. The dashboard and admin have their own <body> and keep the
         plain zinc scale. --}}
    <body class="storefront flex min-h-screen flex-col bg-white dark:bg-[#0c1a36] text-zinc-900 antialiased">

        {{-- Themed branded loader on hard reload / first paint. Shown on the
             storefront too (not just the dashboard) so a website reload follows
             the active theme instead of flashing unbranded. Hides on
             DOMContentLoaded; never shown on wire:navigate. --}}
        <x-ui.app-loader />

        {{-- Translation engine (auto-detect + manual switching from the locale modal) --}}

        <div
            x-data="storefrontLocale()"
            x-init="init()"
            @keydown.escape.window="localeModalOpen = false"
            x-effect="localeModalOpen ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
            class="flex flex-1 flex-col"
        >
            {{-- Maintenance banner - amber strip when system.maintenance_mode is on.
                 Sits above the header so it announces itself first. --}}
            @include('partials.maintenance-banner')

            @if(\App\Support\FeatureFlag::off('gift_cards'))
                @include('partials.giftcard-outage-banner')
            @endif

            {{-- Announcement / coupon bar - blue strip rotating admin-set promos. --}}
            @include('partials.announcement-bar')

            {{-- The header PINS to the top. Only its first row rides along:
                 main-nav collapses the search row and the category strip on
                 scroll below lg, so what stays with the viewport is the logo,
                 the hamburger and the account and cart controls, and what
                 scrolls away is everything a shopper only needs on arrival.

                 There is no separate top utility bar any more: Help, region and
                 currency, account, cart and the theme picker share the logo's
                 line inside main-nav, and Invite a Friend shares the category
                 line. See x-nav.utility-cluster.

                 pt-[env(safe-area-inset-top)] + the matching bg: with
                 viewport-fit=cover (see partials/head) the page paints
                 edge-to-edge, so the header has to reserve the status-bar strip
                 itself or the nav sits under the clock in the installed PWA.
                 The eSIM and discover floating bars used to measure
                 `header.sticky` to place themselves below the pinned nav; with
                 nothing pinned they take a plain offset instead. --}}
            {{-- rounded-b only, no overflow-hidden: the background is clipped to the
                 rounded border box anyway, and hiding overflow here would cut off the
                 search results panel and the mega menu, which both hang below. --}}
            <header class="nav-surface sticky top-0 z-50 w-full rounded-b-[10px] pt-[env(safe-area-inset-top)]">
                <x-nav.main-nav />
            </header>

            {{-- Horizontal-clip for the carousel full-bleed (mx-[calc(50%-50vw)])
                 lives on <html> (see partials/head) instead of here. Putting it on
                 <main> made <main> clip its fixed-position descendants (the eSIM buy
                 bar + modals rendered trapped inside the content box instead of the
                 viewport). <html> is the viewport root, so it never traps fixed. --}}
            <main data-page-content class="flex-1 bg-[#eff6ff] dark:bg-[#0c1a36]">
                {{ $slot }}
            </main>

            <x-footer />

            <x-cookie-consent />

            <x-nav.locale-modal />

            {{-- Global confirm modal — intercepts any form/button with `data-confirm`. --}}
            <x-confirm-modal />

            {{-- Global auth modal — login slides in from the right, register from
                 the left. Any `<button @click="$dispatch('open-auth-modal', { mode: '…' })">`
                 across the storefront pops this open instead of redirecting. --}}
            @guest
                <livewire:auth.auth-modal />
            @endguest

            {{-- Storefront feedback tab. Gated by the features.feedback_widget_enabled
                 admin toggle so it can be hidden without redeploying. --}}
            @feature('feedback_widget')
                <livewire:feedback-widget />
            @endfeature
        </div>

        <x-chatway-widget />

        {{-- PWA pull-to-refresh (standalone mode only). --}}
        <x-pull-to-refresh />

        {{-- Google Customer Reviews seller-rating badge (storefront-wide). --}}
        @include('partials.google-reviews-badge')

        @fluxScripts
    </body>
</html>
