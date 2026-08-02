@props([
    'title' => null,
    'description' => null,
    'ogImage' => null,
    'ogType' => null,
    'keywords' => null,
    'jsonLd' => null,
])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')

        {{-- Storefront-only head additions (Urbanist display font for the hero
             headings, Turnstile loader). All SEO (title, description, OG,
             Twitter, JSON-LD) lives in one place in partials/head, overridable
             per page via the props above. Urbanist is the only extra font here;
             Instrument Sans was dropped (nothing references it). --}}
        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        <link href="https://fonts.bunny.net/css?family=urbanist:800" rel="stylesheet" />

        @if(config('services.turnstile.enabled') && (config('services.turnstile.enforce_auth') || config('services.turnstile.enforce_contact') || config('services.turnstile.enforce_checkout')))
            {{-- Cloudflare Turnstile (explicit render mode) - loaded once per
                 page so any form that needs the widget can call into
                 window.turnstile.render(...). Auth modal, contact forms,
                 feedback widget, and checkout all share this single loader. --}}
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>
        @endif
    </head>
    <body class="flex min-h-screen flex-col bg-white dark:bg-[#0c1a36] text-zinc-900 antialiased">

        {{-- Themed branded loader on hard reload / first paint. Shown on the
             storefront too (not just the dashboard) so a website reload follows
             the active theme instead of flashing unbranded. Hides on
             DOMContentLoaded; never shown on wire:navigate. --}}
        <x-ui.app-loader />

        {{-- Translation engine (auto-detect + manual switching from the locale modal) --}}
        @include('partials.translate-engine')

        <div
            x-data="storefrontLocale()"
            x-init="init()"
            @keydown.escape.window="localeModalOpen = false"
            x-effect="localeModalOpen ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
            class="flex flex-1 flex-col"
        >
            {{-- Maintenance banner - amber strip when system.maintenance_mode is on.
                 Sits above the sticky header so it announces itself first. --}}
            @include('partials.maintenance-banner')

            @if(\App\Support\FeatureFlag::off('gift_cards'))
                @include('partials.giftcard-outage-banner')
            @endif

            {{-- Announcement / coupon bar - blue strip rotating admin-set promos. --}}
            @include('partials.announcement-bar')

            {{-- The whole header is sticky as one block (sticky needs a tall
                 containing block, the body provides that here). main-nav's
                 own Alpine then collapses the primary row on scroll, leaving
                 just the top bar + category strip pinned.

                 pt-[env(safe-area-inset-top)] + the matching bg: with
                 viewport-fit=cover (see partials/head) the page paints
                 edge-to-edge, so the header has to reserve the status-bar strip
                 itself or the top bar sits under the clock in the installed
                 PWA. The padding is on the <header>, not the top bar, so
                 `document.querySelector('header.sticky')` - which the eSIM
                 floating plan bars measure to place themselves - grows with it
                 and those bars stay clear of the nav. bg-zinc-50 matches
                 x-nav.top-bar so the reserved strip is the same colour as the
                 bar under it (the dark remap sends both to the card grey). --}}
            <header class="sticky top-0 z-50 w-full bg-zinc-50 pt-[env(safe-area-inset-top)]">
                <x-nav.top-bar />
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

            <x-back-to-top />

            <x-cookie-consent />

            <x-nav.locale-modal />

            {{-- First-visit nudge pointing new visitors at the country pill so they
                 can switch the catalogue to their own region. Once per session. --}}
            <x-nav.country-tip />

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
