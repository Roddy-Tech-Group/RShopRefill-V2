{{-- Mobile app "in development" page. Dark-mode safe (bg-blue-50 -> navy). --}}

<x-layouts.app.header :title="'Mobile App | '.$siteName">

    <section class="mx-auto w-full max-w-[880px] px-4 py-16 text-center sm:px-6 sm:py-24">
        <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">In development</span>
        <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">Our mobile app is on the way</h1>
        <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base">
            The {{ $siteName }} app for the App Store and Play Store is coming soon. In the meantime, our Web App is already
            active: use the website for all your transactions, including your wallet, orders and rewards.
        </p>

        {{-- Coming-soon store badges (visual) --}}
        <p class="mt-10 text-xs font-semibold uppercase tracking-wider text-zinc-500">Coming soon to</p>
        <div class="mt-3 flex flex-wrap items-center justify-center gap-3">
            <span class="inline-flex items-center gap-2 rounded-[12px] px-4 py-2 text-white opacity-90" style="background-color: #18181b;">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/></svg>
                <span class="text-left leading-none">
                    <span class="block text-xs">Get it on</span>
                    <span class="block text-sm font-semibold">App Store</span>
                </span>
            </span>
            <span class="inline-flex items-center gap-2 rounded-[12px] px-4 py-2 text-white opacity-90" style="background-color: #18181b;">
                <img src="{{ asset('assets/'.rawurlencode('Playstore 2.webp')) }}" alt="" width="24" height="24" class="block h-6 w-6 shrink-0 object-contain" loading="lazy">
                <span class="text-left leading-none">
                    <span class="block text-xs">Download on</span>
                    <span class="block text-sm font-semibold">Google Play</span>
                </span>
            </span>
        </div>

        {{-- Install the web app (PWA) - works now, no store needed. Replays the
             beforeinstallprompt captured in partials/head.blade.php on Android /
             desktop Chromium; shows Add-to-Home-Screen steps on iOS (or as a
             fallback elsewhere). Hides itself once the app is already installed. --}}
        <div
            x-data="{
                isIOS: false,
                canPrompt: false,
                installed: false,
                stepsOpen: false,
                init() {
                    const ua = window.navigator.userAgent || '';
                    this.isIOS = /iphone|ipad|ipod/i.test(ua) && ! window.MSStream;
                    this.installed = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
                    this.canPrompt = !! window.__rshopInstallPrompt;
                    window.addEventListener('rshop:installable', () => { this.canPrompt = true; });
                    window.addEventListener('rshop:installed', () => { this.installed = true; this.stepsOpen = false; });
                },
                async install() {
                    if (! this.isIOS && this.canPrompt && window.__rshopInstallPrompt) {
                        const evt = window.__rshopInstallPrompt;
                        evt.prompt();
                        try { await evt.userChoice; } catch (e) {}
                        window.__rshopInstallPrompt = null;
                        this.canPrompt = false;
                        return;
                    }
                    this.stepsOpen = ! this.stepsOpen;
                },
            }"
            x-cloak
            class="mx-auto mt-12 max-w-md"
        >
            <template x-if="installed">
                <p class="rounded-[12px] border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">You already have the app installed. Enjoy!</p>
            </template>

            <template x-if="! installed">
                <div class="rounded-[12px] border border-zinc-200 bg-white p-5 text-left shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:bg-[#13294d] dark:shadow-none">
                    <div class="flex items-center gap-3">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-white ring-1 ring-zinc-200 dark:ring-white/10">
                            <img src="{{ asset('assets/icon-192.png') }}" alt="" class="h-full w-full object-contain no-dark-invert" width="48" height="48">
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-zinc-900 dark:text-white">Install the web app now</p>
                            <p class="text-xs text-zinc-600 dark:text-zinc-300">Add {{ $siteName }} to your home screen for an app-like experience - no store needed.</p>
                        </div>
                    </div>

                    <button
                        type="button"
                        @click="install()"
                        class="mt-4 flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0 4-4m-4 4-4-4M4 20h16"/></svg>
                        Install WebApp
                    </button>

                    <p class="mt-3 text-center text-[11px] leading-relaxed text-zinc-500 dark:text-zinc-400">Updates arrive automatically each time you log in. No app updates or re-downloads needed.</p>

                    {{-- Manual steps: shown on iOS (no programmatic install) or when no
                         install prompt was captured by the browser. --}}
                    <div x-show="stepsOpen" x-collapse x-cloak class="mt-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        <p class="text-xs font-semibold text-zinc-900 dark:text-white" x-text="isIOS ? 'On iPhone / iPad (Safari)' : 'From your browser menu'"></p>
                        <template x-if="isIOS">
                            <p class="mt-2 text-xs leading-relaxed text-zinc-600 dark:text-zinc-300">1. Tap the Share button, 2. choose <span class="font-semibold text-zinc-900 dark:text-white">Add to Home Screen</span>, 3. tap <span class="font-semibold text-zinc-900 dark:text-white">Add</span>.</p>
                        </template>
                        <template x-if="! isIOS">
                            <p class="mt-2 text-xs leading-relaxed text-zinc-600 dark:text-zinc-300">Open your browser menu and choose <span class="font-semibold text-zinc-900 dark:text-white">Install app</span> or <span class="font-semibold text-zinc-900 dark:text-white">Add to Home screen</span>.</p>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        {{-- Install guide. Steps 1-3 are the iPhone (Safari) Add to Home Screen
             flow; card 4 is a one-tap install for Android, replaying the
             beforeinstallprompt captured in partials/head.blade.php. --}}
        <div
            x-data="{
                canPrompt: false,
                installed: false,
                init() {
                    this.installed = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
                    this.canPrompt = !! window.__rshopInstallPrompt;
                    window.addEventListener('rshop:installable', () => { this.canPrompt = true; });
                    window.addEventListener('rshop:installed', () => { this.installed = true; });
                },
                async install() {
                    if (! window.__rshopInstallPrompt) { return; }
                    const evt = window.__rshopInstallPrompt;
                    evt.prompt();
                    try { await evt.userChoice; } catch (e) {}
                    window.__rshopInstallPrompt = null;
                    this.canPrompt = false;
                },
            }"
            x-cloak
            class="mx-auto mt-12 max-w-4xl"
        >
            <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">How to install</p>
            <div class="mt-4 grid grid-cols-1 gap-4 text-left sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-[12px] border border-zinc-200 bg-white p-5 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:bg-[#13294d] dark:shadow-none">
                    <span class="flex h-9 w-9 items-center justify-center rounded-[12px] bg-blue-600 text-sm font-bold text-white">1</span>
                    <p class="mt-3 text-sm font-bold text-zinc-900 dark:text-white">Step 1</p>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">Visit {{ $siteName }} in Safari, then tap the ••• (More) button in the top-right corner of the address bar.</p>
                </div>
                <div class="rounded-[12px] border border-zinc-200 bg-white p-5 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:bg-[#13294d] dark:shadow-none">
                    <span class="flex h-9 w-9 items-center justify-center rounded-[12px] bg-blue-600 text-sm font-bold text-white">2</span>
                    <p class="mt-3 text-sm font-bold text-zinc-900 dark:text-white">Step 2</p>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">Tap Share, then scroll down and select Add to Home Screen.</p>
                </div>
                <div class="rounded-[12px] border border-zinc-200 bg-white p-5 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:bg-[#13294d] dark:shadow-none">
                    <span class="flex h-9 w-9 items-center justify-center rounded-[12px] bg-blue-600 text-sm font-bold text-white">3</span>
                    <p class="mt-3 text-sm font-bold text-zinc-900 dark:text-white">Step 3</p>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">Tap Add in the top-right corner. That's it! 🎉 The {{ $siteName }} app is now installed on your Home Screen and ready to use.</p>
                </div>
                {{-- Android: one-tap direct install via the browser prompt. --}}
                <div class="flex flex-col rounded-[12px] border border-zinc-200 bg-white p-5 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:bg-[#13294d] dark:shadow-none">
                    <span class="flex h-9 w-9 items-center justify-center rounded-[12px] bg-blue-600 text-white">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0 4-4m-4 4-4-4M4 20h16"/></svg>
                    </span>
                    <p class="mt-3 text-sm font-bold text-zinc-900 dark:text-white">On Android</p>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">Tap Install to add {{ $siteName }} straight to your device, no store needed.</p>
                    <button
                        type="button"
                        @click="install()"
                        class="mt-auto inline-flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0 4-4m-4 4-4-4M4 20h16"/></svg>
                        Install
                    </button>
                </div>
            </div>
        </div>

        {{-- Web CTAs --}}
        <div class="mt-10 flex flex-col gap-3 sm:flex-row sm:justify-center">
            <a href="{{ route('shop.gift-cards') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-[6px] bg-blue-600 px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                Continue on the web
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            </a>
            <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-[6px] border border-zinc-200 bg-white px-6 py-3 text-sm font-semibold text-zinc-700 transition-colors hover:bg-zinc-50">
                Go to dashboard
            </a>
        </div>
    </section>

</x-layouts.app.header>
