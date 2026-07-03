<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')

        {{-- Auth-page-only extras. Shared bits (SEO meta, theme engine, page
             transition, vite, scroll-lock, dark-mode bg) come from partials/head.
             Fonts: Satoshi only (from partials/head). The old Instrument Sans
             link was dropped - nothing on the auth pages referenced it. --}}

        {{-- .auth-form-slide scopes a slide-from-right animation to the form
             panel only. The script below re-applies it on every Livewire
             navigation so login -> register feels like a single surface
             swapping its details, not a full-page reload. --}}
        <style>
            @keyframes authFormSlide {
                from { transform: translateX(40px); opacity: 0; }
                to   { transform: translateX(0);    opacity: 1; }
            }
            .auth-form-slide {
                animation: authFormSlide 380ms cubic-bezier(0.22, 1, 0.36, 1);
                will-change: transform, opacity;
            }
        </style>
        <script>
            document.addEventListener('livewire:navigated', () => {
                document.querySelectorAll('.auth-form-slide').forEach((el) => {
                    el.classList.remove('auth-form-slide');
                    void el.offsetWidth;
                    el.classList.add('auth-form-slide');
                });
            });
        </script>
        @if(config('services.turnstile.enabled') && config('services.turnstile.enforce_auth'))
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endif
    </head>
    <body class="min-h-screen bg-zinc-100 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">

        <div class="flex min-h-screen items-start p-4 sm:items-stretch sm:p-6 lg:p-[60px]">
            <div class="my-auto grid w-full overflow-hidden rounded-[12px] shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-900/5 sm:my-0 lg:grid-cols-2 lg:shadow-2xl lg:shadow-zinc-900/15 dark:ring-zinc-800">

            {{-- Left panel: marketing / phone mockup --}}
            <aside class="relative hidden flex-col overflow-hidden bg-blue-950 p-10 text-white lg:flex">

                {{-- Brand --}}
                <a href="{{ route('home') }}" wire:navigate class="relative z-10 flex flex-col rounded-[12px] group focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300/40">
                    <span class="flex h-12 items-center">
                        <img
                            src="{{ asset('assets/Rshoprefillslogo.webp') }}"
                            alt="{{ $siteName }}"
                            fetchpriority="high"
                            class="h-full w-auto object-contain brightness-0 invert transition-opacity duration-200 group-hover:opacity-90"
                        />
                    </span>
                    <span class="mt-1 pl-1 text-[11px] font-medium leading-none text-blue-200/80">Digital Marketplace</span>
                </a>

                {{-- Middle: copy + phone --}}
                <div class="relative z-10 mt-12 flex flex-1 items-center gap-8">
                    <div class="flex-1">
                        <h1 class="text-3xl font-bold leading-tight tracking-tight xl:text-4xl">
                            Your digital world,<br />
                            <span class="text-blue-300">all in one place.</span>
                        </h1>
                        <p class="mt-4 max-w-md text-base leading-relaxed text-blue-100/85">
                            Buy gift cards, eSIMs, top-ups, flights and more. Instantly and securely, at the best rates.
                        </p>

                        {{-- Feature bullets --}}
                        <ul class="mt-8 space-y-4">
                            @foreach([
                                ['Secure & Trusted', 'Your data and transactions are 100% secure', 'secure payments.svg'],
                                ['Instant Delivery', 'Get your digital products in seconds',       'fast.webp'],
                                ['Global Access',    'Access products and services worldwide',    'global svg.svg'],
                                ['24/7 Support',     'We\'re here to help anytime, anywhere',     'support.svg'],
                            ] as [$title, $desc, $icon])
                                <li class="flex items-center gap-3">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-white/10 ring-1 ring-white/15">
                                        <img
                                            src="{{ asset('assets/' . rawurlencode($icon)) }}"
                                            alt=""
                                            class="h-5 w-5 shrink-0 brightness-0 invert"
                                            loading="lazy"
                                        >
                                    </span>
                                    <div class="leading-tight">
                                        <p class="text-base font-semibold text-white">{{ $title }}</p>
                                        <p class="text-[12px] text-blue-100/70">{{ $desc }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>

                    </div>

                    {{-- Phone mockup. Two assets stacked: the original light
                         graphic and the new dark variant. Alpine watches the
                         resolved theme (via the same window.themeIsDark hook
                         the toggle uses) and crossfades + slides between them
                         on every change. The reduced-motion check just snaps. --}}
                    <div
                        x-data="{
                            dark: (window.themeIsDark ? window.themeIsDark() : document.documentElement.classList.contains('dark')),
                        }"
                        x-on:theme-changed.window="dark = $event.detail.dark"
                        class="relative z-10 max-h-[560px] w-[280px] shrink-0 select-none"
                    >
                        {{-- Light variant. Visible when the resolved theme is
                             light; slides UP off-screen + fades when switching. --}}
                        <img
                            x-show="! dark"
                            x-transition:enter="transition ease-out duration-450"
                            x-transition:enter-start="opacity-0 translate-y-12"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-300"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 -translate-y-12"
                            src="{{ asset('assets/graphic_rshoprefill.webp') }}"
                            alt=""
                            fetchpriority="high"
                            class="absolute inset-0 mx-auto h-auto max-h-[560px] w-full object-contain drop-shadow-2xl"
                        />
                        {{-- Dark variant. Mirror animation so the swap reads
                             as "the next slide pushing the old one off". --}}
                        <img
                            x-show="dark"
                            x-cloak
                            x-transition:enter="transition ease-out duration-450"
                            x-transition:enter-start="opacity-0 translate-y-12"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-300"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 -translate-y-12"
                            src="{{ asset('assets/' . rawurlencode('auth page mockup two.webp')) }}"
                            alt=""
                            fetchpriority="high"
                            class="absolute inset-0 mx-auto h-auto max-h-[560px] w-full object-contain drop-shadow-2xl"
                        />
                        {{-- Spacer so the parent flex column reserves space for
                             the absolutely-positioned imgs above. Same height
                             as max-h on the imgs themselves. --}}
                        <div class="invisible h-[560px]" aria-hidden="true"></div>
                    </div>
                </div>

                {{-- Footer. Year auto-updates; legal + help links route to the
                     real storefront pages instead of dead anchors. --}}
                <div class="relative z-10 mt-6 flex flex-wrap items-center justify-between gap-4 text-[12px] text-blue-200/70">
                    <p>&copy; {{ date('Y') }} {{ $siteName }}. All rights reserved.</p>
                    <div class="flex flex-wrap gap-6">
                        <a href="{{ route('shop.privacy') }}" wire:navigate class="transition-colors hover:text-white">Privacy Policy</a>
                        <a href="{{ route('shop.terms') }}" wire:navigate class="transition-colors hover:text-white">Terms of Service</a>
                        <a href="{{ route('shop.help') }}" wire:navigate class="transition-colors hover:text-white">Help Center</a>
                    </div>
                </div>
            </aside>

            {{-- Right panel: auth form. Has its own slide-in animation so
                 login ↔ register hand off feels like a single surface. --}}
            <main class="relative flex flex-col bg-white px-6 py-[50px] sm:px-10 sm:py-10 lg:px-16 dark:bg-zinc-900">

                {{-- Top-right utility row: Back to website + theme toggle.
                     Pinned to the top so the form copy starts cleanly under it
                     on every viewport. Sits above mobile brand on small screens
                     and the form contents on desktop. --}}
                <div class="absolute right-4 top-4 z-10 flex items-center gap-2 sm:right-6 sm:top-6 lg:right-10 lg:top-10">
                    <a
                        href="{{ route('home') }}"
                        wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-[12px] border border-zinc-200 bg-white px-3 py-1.5 text-[12px] font-semibold text-zinc-700 transition-colors hover:bg-zinc-50 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                        </svg>
                        Back to website
                    </a>
                </div>

                {{-- Mobile brand (inside the card) --}}
                <a href="{{ route('home') }}" wire:navigate class="flex shrink-0 flex-col items-center rounded-[12px] group focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 lg:hidden">
                    <span class="flex h-10 items-center">
                        <img
                            src="{{ asset('assets/Rshoprefillslogo.webp') }}"
                            alt="{{ $siteName }}"
                            class="h-full w-auto object-contain transition-opacity duration-200 group-hover:opacity-90"
                        />
                    </span>
                    <span class="mt-0.5 text-[10px] font-medium leading-none text-zinc-600 dark:text-zinc-400">Digital Marketplace</span>
                </a>

                {{-- Form details panel - this is what slides on login ↔ register --}}
                <div class="auth-form-slide flex flex-1 flex-col">
                    {{ $slot }}
                </div>

                {{-- Bottom-right theme toggle. The segmented variant displays
                     the three PNG icons (light / dark / auto) in a compact pill,
                     matching every other theme switcher in the system. --}}
                <div class="pointer-events-none absolute bottom-4 right-4 z-10 sm:bottom-6 sm:right-6 lg:bottom-10 lg:right-10">
                    <div class="pointer-events-auto">
                        <x-theme-toggle variant="segmented" />
                    </div>
                </div>
            </main>

            </div>
        </div>

        @fluxScripts
    </body>
</html>
