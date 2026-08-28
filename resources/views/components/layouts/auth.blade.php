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
            /* No will-change. It reads as a free hint but it is not: it
               promotes the form to its own compositor layer permanently, and
               text on a composited layer loses subpixel antialiasing - which
               is why every field looked blurry after the slide. A 380ms
               transform needs no help. */
            .auth-form-slide {
                animation: authFormSlide 380ms cubic-bezier(0.22, 1, 0.36, 1);
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
    <body class="flex min-h-screen flex-col bg-[#dee3e1] text-zinc-900 antialiased dark:bg-[#060607] dark:text-zinc-100">

        {{-- Translation engine. Sign-in and register are website content too:
             a visitor who reached them in their own language should not be
             handed an English form at the one step that asks them to type. --}}
        @include("partials.translate-engine")

        {{-- The only way back to the shop from an auth page. Standalone screens
             strip the nav on purpose - one job per page - but a bookmark that
             lands here should not be a dead end.

             All widths: the brand card that used to carry the logo and a home
             link beside the form on desktop is gone, so this row is the only
             brand mark and the only exit at every size. --}}
        <header>
            <div class="flex items-center justify-between gap-3 px-4 pt-5 sm:px-6">
                <a
                    href="{{ route('home') }}"
                    wire:navigate
                    class="flex h-8 items-center rounded-[12px] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                    aria-label="{{ $siteName }} home"
                >
                    <img
                        src="{{ asset('assets/Rshoprefillslogo.webp') }}"
                        alt="{{ $siteName }}"
                        class="h-full w-auto object-contain dark:brightness-0 dark:invert"
                    />
                </a>

                {{-- A labelled way back, because the logo alone is a way back
                     only if you already know it is one. --}}
                <a
                    href="{{ route('home') }}"
                    wire:navigate
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-[#ffffff] px-3.5 py-2 text-[13px] font-semibold text-zinc-700 shadow-sm ring-1 ring-[#dee3e1] transition-colors hover:text-zinc-900 hover:ring-[#c9d0cd] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white/10 dark:text-zinc-200 dark:ring-white/10 dark:hover:text-white"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                    </svg>
                    {{ __('common.back_to_website') }}
                </a>
            </div>
        </header>

        {{-- The form card alone, centered on the page tone. A brand identity
             card used to sit offset to its left; it was dropped so the form is
             the only surface on the page.

             flex-1, not min-h-screen: the header above is a sibling, and a
             full-viewport minimum here would push the card off the bottom by
             exactly the header's height. --}}
        <div class="flex flex-1 items-center justify-center p-4 sm:p-6 lg:p-10">
            {{-- Form card --}}
            <main class="flex w-full max-w-xl flex-col rounded-[20px] bg-[#ffffff] px-6 py-6 shadow-2xl shadow-zinc-900/15 sm:px-10 dark:bg-[#0c1a36] dark:ring-1 dark:ring-white/10">

                {{-- Sign In / Sign Up. Links, not a client-side toggle: the
                     two are separate Livewire components on separate routes,
                     and merging them would put both forms in one page on a
                     security-sensitive flow for the sake of a transition. --}}
                @php
                    $authTab = 'flex items-center gap-2 border-b-2 pb-3 text-base transition-colors focus:outline-none';
                    $authTabOn = 'border-zinc-900 font-bold text-zinc-900 dark:border-white dark:text-white';
                    $authTabOff = 'border-transparent font-medium text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white';
                @endphp
                <nav class="-mb-px mb-5 flex items-center gap-8 border-b border-[#dee3e1] dark:border-white/10" aria-label="Authentication">
                    <a href="{{ route('login') }}" wire:navigate @class([$authTab, $authTabOn => request()->routeIs('login'), $authTabOff => ! request()->routeIs('login')])>
                        {{ __('auth.sign_in') }}
                    </a>
                    <a href="{{ route('register') }}" wire:navigate @class([$authTab, $authTabOn => request()->routeIs('register'), $authTabOff => ! request()->routeIs('register')])>
                        {{ __('auth.sign_up') }}
                    </a>
                </nav>

                {{-- Form details panel - this is what slides on login <-> register --}}
                <div class="auth-form-slide flex flex-1 flex-col">
                    {{ $slot }}
                </div>

                {{-- Theme control, under the form rather than pinned to a
                     corner: the card is no longer full-height, so a fixed
                     corner would float it off the card entirely.

                     The labelled variant, not the icon strip: this one
                     stands alone, with nothing beside it to say what three
                     marks would mean. --}}
                <div class="mt-6 flex justify-center">
                    <x-theme-toggle variant="labelled" />
                </div>
            </main>
        </div>

        @fluxScripts
    </body>
</html>
