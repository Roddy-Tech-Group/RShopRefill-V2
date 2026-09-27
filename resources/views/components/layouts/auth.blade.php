<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\Languages::direction() }}">
    <head>
        @include('partials.head')

        {{-- Auth-page-only extras. Shared bits (SEO meta, theme engine, page
             transition, vite, scroll-lock, dark-mode bg) come from partials/head.
             Fonts: DM Sans only (from partials/head). The old Instrument Sans
             link was dropped - nothing on the auth pages referenced it. --}}

        {{-- .auth-form-slide scopes a slide-from-right animation to the form
             panel only. The script below re-applies it on every Livewire
             navigation so login -> register feels like a single surface
             swapping its details, not a full-page reload. --}}
        <style>
            /* The shared head paints the page edge white (black in dark) for
               the first frame and for overscroll. Here the whole page is navy. */
            html, html.dark { background-color: #0c1337; }

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
    {{-- The dashboard's deep navy in both themes: the white card reads as the
         one lit thing on the page, and signing in already looks like the
         account it opens. --}}
    <body class="flex min-h-screen flex-col bg-[#0c1337] text-zinc-900 antialiased dark:text-zinc-100">

        {{-- Translation engine. Sign-in and register are website content too:
             a visitor who reached them in their own language should not be
             handed an English form at the one step that asks them to type. --}}

        {{-- The brand, top left, and the way back to the store. White on the
             navy: brightness-0 flattens the mark to black and invert turns it
             white, the same treatment the logo gets in dark mode. --}}
        <header class="px-4 pt-4 sm:px-6 sm:pt-6 lg:px-10">
            <a href="{{ route('home') }}" wire:navigate class="inline-flex rounded-[12px] focus:outline-none focus-visible:ring-2 focus-visible:ring-white/40" aria-label="{{ $siteName }} home">
                <img src="{{ asset('assets/logo.webp').'?v=4' }}" alt="{{ $siteName }}" class="site-logo h-9 w-auto object-contain brightness-0 invert">
            </a>
        </header>

        {{-- The form card, centered on the navy.

             flex-1 on a flex-column body, not min-h-screen: the header above
             takes its height and the card fills what is left, where a second
             viewport minimum would push it off the bottom. --}}
        <div class="flex flex-1 items-center justify-center p-4 sm:p-6 lg:p-10">
            {{-- Form card. max-w-md, not max-w-xl: at 576px the fields ran
                 wider than anything typed into them and the card read as a page
                 rather than a form. The page tone underneath stays the
                 storefront's. --}}
            <main class="flex w-full max-w-md flex-col rounded-[20px] bg-[#ffffff] px-6 py-6 shadow-2xl shadow-zinc-900/15 sm:px-8 dark:bg-[#0c1a36] dark:ring-1 dark:ring-white/10">

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
