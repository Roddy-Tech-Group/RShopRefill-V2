{{-- First-visit region nudge. A one-per-session modal that points new visitors
     at the country pill in the top bar, so they can switch the catalogue to
     their own country when they don't see what they're looking for. Carries the
     same gift-card promo art as the dashboard "Give the Perfect Gift" banner.

     Self-gating via sessionStorage: it greets the visitor once when they first
     land and never reappears in the same browser session once dismissed. Lives
     in the storefront layout only, because the country pill it references is
     storefront chrome (the dashboard intentionally has no region switcher). It
     sits inside the storefrontLocale() scope, so "Switch country" can open the
     shared locale modal directly. --}}
<div
    x-data="{
        show: false,
        seenKey: 'rshopRegionTip',
        start() {
            try {
                if (sessionStorage.getItem(this.seenKey)) { return; }
            } catch (e) { return; }
            // Let the page settle before greeting the visitor.
            setTimeout(() => { this.show = true; }, 900);
        },
        dismiss() {
            this.show = false;
            try { sessionStorage.setItem(this.seenKey, '1'); } catch (e) {}
        },
    }"
    x-init="start()"
    x-effect="show ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
>
    <div
        x-show="show"
        x-cloak
        style="display:none;"
        class="fixed inset-0 z-[80] flex items-end justify-center px-3 pb-3 sm:items-center sm:p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="region-tip-title"
    >
        {{-- Backdrop --}}
        <div x-show="show" @click="dismiss()" x-transition.opacity class="absolute inset-0 bg-zinc-900/40 dark:bg-black/60"></div>

        {{-- Panel - slides in from the right (right-to-left) on open. --}}
        <div
            x-show="show"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-x-full"
            x-transition:enter-end="opacity-100 translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-x-0"
            x-transition:leave-end="opacity-0 translate-x-full"
            class="relative w-full max-w-md overflow-hidden rounded-[12px] bg-[#eff6ff] p-5 shadow-2xl ring-1 ring-zinc-200 dark:bg-[#0c1a36] dark:ring-white/10"
        >
            {{-- Close --}}
            <button type="button" @click="dismiss()" aria-label="Close" class="absolute right-3 top-3 z-10 flex h-8 w-8 items-center justify-center rounded-[12px] bg-white/70 text-zinc-600 transition-colors hover:bg-white dark:bg-white/10 dark:text-zinc-200 dark:hover:bg-white/15">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            {{-- Gift-card promo banner: same navy banner + product art as the
                 dashboard "Give the Perfect Gift" card. --}}
            <div class="relative overflow-hidden rounded-[12px] bg-blue-950 p-5 text-white">
                <div class="relative z-10 max-w-[58%]">
                    <h2 id="region-tip-title" class="text-lg font-bold tracking-tight">Shopping from your country?</h2>
                    <p class="mt-1 text-sm text-blue-100/80">Switch your region to see the gift cards, eSIMs and top-ups available where you are.</p>
                </div>
                <img
                    src="{{ asset('assets/'.rawurlencode('Pick a product first process.webp')) }}"
                    alt=""
                    class="pointer-events-none absolute -right-2 bottom-0 w-[44%] max-w-[170px] select-none object-contain object-bottom drop-shadow-2xl no-dark-invert"
                    loading="lazy"
                >
            </div>

            {{-- Message --}}
            <p class="mt-4 text-sm leading-relaxed text-zinc-700 dark:text-zinc-200">
                Can't find what you're looking for? Tap the <span class="font-semibold text-zinc-900 dark:text-white">country pill</span> at the top of the page to switch to your country, and the store updates to what's available in your region.
            </p>

            {{-- How-it-works flow: the cross-region buying flow at a glance,
                 one step per line, left-aligned. --}}
            <div class="mt-4 flex flex-col items-start gap-y-1 rounded-[10px] bg-blue-50 px-4 py-3 text-left text-[13px] font-semibold text-blue-900 ring-1 ring-blue-100 dark:bg-blue-500/10 dark:text-blue-100 dark:ring-blue-500/20">
                <span>Buy from any country/region</span>
                <span>Pay in your currency</span>
                <span>Switch your account to the country you bought from</span>
                <span>Redeem it, pay &amp; enjoy</span>
            </div>

            {{-- Actions --}}
            <div class="mt-5 flex items-center gap-3">
                <button type="button" @click="dismiss()" class="inline-flex h-11 flex-1 items-center justify-center rounded-[12px] bg-white px-4 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-200 transition-colors hover:bg-zinc-50 dark:bg-white/10 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/15">Got it</button>
                <button type="button" @click="dismiss(); localeModalOpen = true" class="inline-flex h-11 flex-1 items-center justify-center rounded-[12px] bg-blue-600 px-4 text-sm font-semibold text-white transition-colors hover:bg-blue-700">Switch country</button>
            </div>
        </div>
    </div>
</div>
