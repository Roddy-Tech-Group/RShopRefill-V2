{{-- First-visit eSIM nudge. A one-per-session modal that greets visitors landing
     on the eSIM page with a "where are you going?" travel hook, using the same
     World Cup illustration as the eSIM hero adverts. Self-gating via
     sessionStorage so it greets the visitor once and never reappears in the same
     browser session once dismissed. --}}
<div
    x-data="{
        show: false,
        seenKey: 'rshopEsimTip',
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
        aria-labelledby="esim-tip-title"
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

            {{-- World Cup advert banner: same illustration as the eSIM hero. --}}
            <div class="overflow-hidden rounded-[14px] bg-[#0c1a36] p-4 text-white">
                <x-illos.esim-worldcup class="h-auto w-full rounded-[12px]" aria-hidden="true" />
                <h2 id="esim-tip-title" class="mt-4 text-lg font-bold tracking-tight">Where are you going?</h2>
                <p class="mt-1 text-sm text-zinc-300">Heading to the World Cup, or travelling soon?</p>
            </div>

            {{-- Message --}}
            <p class="mt-4 text-sm leading-relaxed text-zinc-700 dark:text-zinc-200">
                Get an eSIM and stay connected with your family and loved ones, anywhere you go - instant data in 190+ countries, no SIM swap.
            </p>

            {{-- Action --}}
            <div class="mt-5">
                <button type="button" @click="dismiss()" class="inline-flex h-11 w-full items-center justify-center rounded-[12px] bg-blue-600 px-4 text-sm font-semibold text-white transition-colors hover:bg-blue-700">Find my eSIM</button>
            </div>
        </div>
    </div>
</div>
