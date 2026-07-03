{{--
    Floating "Install app" dock for the customer dashboard.

    Cross-platform + hardened:
      - Hidden when already running standalone (installed) or inside an in-app
        browser (Instagram/Facebook/etc.) where install is unreliable.
      - Android / desktop Chromium: replays the captured `beforeinstallprompt`.
      - iOS Safari (no programmatic install): opens a Share -> Add to Home Screen
        instruction sheet instead.
      - Dismissal is remembered for 7 days so it never nags.
      - Anchored with safe-area insets and sits above the mobile bottom nav, so
        it stays steady on notched iPhones and never overlaps the tab bar.

    The prompt itself is captured early in partials/head.blade.php (it fires
    before Alpine boots) and stashed on window.__rshopInstallPrompt.
--}}
<div
    x-data="{
        show: false,
        isIOS: false,
        canPrompt: false,
        iosSheet: false,
        init() {
            const ua = window.navigator.userAgent || '';
            const standalone = window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true;
            const inApp = /FBAN|FBAV|Instagram|Line|Twitter|Snapchat|WebView/i.test(ua);

            this.isIOS = /iphone|ipad|ipod/i.test(ua) && ! window.MSStream;
            this.canPrompt = !! window.__rshopInstallPrompt;

            if (standalone || inApp || this.dismissed()) { return; }
            this.show = this.canPrompt || this.isIOS;

            window.addEventListener('rshop:installable', () => {
                this.canPrompt = true;
                if (! this.dismissed()) { this.show = true; }
            });
            window.addEventListener('rshop:installed', () => { this.show = false; });
        },
        dismissed() {
            try {
                const at = parseInt(localStorage.getItem('pwa.install.dismissedAt') || '0', 10);
                return at > 0 && (Date.now() - at) < 6048e5; // 7 days in ms
            } catch (e) { return false; }
        },
        async install() {
            if (this.isIOS) { this.iosSheet = true; return; }
            const evt = window.__rshopInstallPrompt;
            if (! evt) { return; }
            evt.prompt();
            try { await evt.userChoice; } catch (e) {}
            window.__rshopInstallPrompt = null;
            this.show = false;
        },
        dismiss() {
            try { localStorage.setItem('pwa.install.dismissedAt', String(Date.now())); } catch (e) {}
            this.show = false;
            this.iosSheet = false;
        },
    }"
    x-cloak
>
    <style>
        /* Steady anchor: ride the safe-area inset and clear the mobile tab bar. */
        .pwa-install-dock {
            bottom: calc(env(safe-area-inset-bottom, 0px) + 5.5rem);
        }
        @media (min-width: 1024px) {
            .pwa-install-dock {
                bottom: calc(env(safe-area-inset-bottom, 0px) + 1.5rem);
            }
        }
    </style>

    {{-- The dock card --}}
    <div
        x-show="show"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-4"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-4"
        class="pwa-install-dock fixed inset-x-0 z-[70] flex justify-center px-4 lg:justify-end lg:px-6"
        role="dialog"
        aria-label="Install the app"
    >
        <div class="flex w-full max-w-sm items-center gap-3 rounded-[12px] border border-zinc-200 bg-white p-3 shadow-2xl shadow-zinc-900/15 dark:border-white/10 dark:bg-[#13294d] dark:shadow-black/40">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-white ring-1 ring-zinc-200 dark:ring-white/10">
                <img src="{{ asset('assets/icon-192.png') }}" alt="" class="h-full w-full object-contain no-dark-invert" width="44" height="44">
            </span>

            <div class="min-w-0 flex-1">
                <p class="text-sm font-bold text-zinc-900 dark:text-white">Install {{ $siteName }}</p>
                <p class="truncate text-xs text-zinc-600 dark:text-zinc-300">Add it to your home screen for an app-like experience.</p>
            </div>

            <button
                type="button"
                @click="install()"
                class="shrink-0 rounded-[12px] bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
            >
                Install
            </button>

            <button
                type="button"
                @click="dismiss()"
                aria-label="Dismiss"
                class="shrink-0 rounded-[5px] p-1 text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10 dark:hover:text-white"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </button>
        </div>
    </div>

    {{-- iOS instructions sheet (Safari has no programmatic install) --}}
    <div
        x-show="iosSheet"
        x-transition:enter="transition-opacity ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="iosSheet = false"
        @keydown.escape.window="iosSheet = false"
        class="fixed inset-0 z-[80] bg-zinc-900/50"
    ></div>

    <div
        x-show="iosSheet"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="translate-y-full"
        x-transition:enter-end="translate-y-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="translate-y-0"
        x-transition:leave-end="translate-y-full"
        class="fixed inset-x-0 bottom-0 z-[90] mx-auto w-full max-w-md rounded-t-[20px] bg-white p-5 shadow-2xl dark:bg-[#13294d]"
        style="padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 1.5rem);"
        role="dialog"
        aria-modal="true"
        aria-label="Add to Home Screen"
    >
        <div class="mx-auto mb-4 h-1.5 w-10 rounded-[12px] bg-zinc-200 dark:bg-white/15"></div>

        <div class="flex items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-white ring-1 ring-zinc-200 dark:ring-white/10">
                <img src="{{ asset('assets/icon-192.png') }}" alt="" class="h-full w-full object-contain no-dark-invert" width="44" height="44">
            </span>
            <div>
                <p class="text-base font-bold text-zinc-900 dark:text-white">Install {{ $siteName }}</p>
                <p class="text-xs text-zinc-600 dark:text-zinc-300">Two taps in Safari and it lives on your home screen.</p>
            </div>
        </div>

        <ol class="mt-5 space-y-3 text-sm text-zinc-700 dark:text-zinc-200">
            <li class="flex items-center gap-3">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[12px] bg-blue-600 text-xs font-bold text-white">1</span>
                <span class="flex flex-1 items-center gap-1.5">
                    Tap the
                    <svg class="h-5 w-5 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L8.5 7.5M12 4l3.5 3.5"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12H5a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5a2 2 0 0 0-2-2h-1"/>
                    </svg>
                    Share button
                </span>
            </li>
            <li class="flex items-center gap-3">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[12px] bg-blue-600 text-xs font-bold text-white">2</span>
                <span class="flex-1">Choose <span class="font-semibold text-zinc-900 dark:text-white">Add to Home Screen</span></span>
            </li>
            <li class="flex items-center gap-3">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[12px] bg-blue-600 text-xs font-bold text-white">3</span>
                <span class="flex-1">Tap <span class="font-semibold text-zinc-900 dark:text-white">Add</span> - done.</span>
            </li>
        </ol>

        <button
            type="button"
            @click="dismiss()"
            class="mt-6 w-full rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700"
        >
            Got it
        </button>
    </div>
</div>
