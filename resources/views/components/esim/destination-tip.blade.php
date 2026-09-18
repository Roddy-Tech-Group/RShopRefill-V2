@props([
    // Where "Buy Global eSIM" goes, and what that plan is called. Passed in
    // rather than resolved here so the page can hand over the dashboard mirror
    // of the route when it is rendered inside the dashboard shop.
    'globalUrl' => null,
    'globalName' => 'Global eSIM',
])

{{-- Purchase check, shown when a visitor opens a destination.

     A local eSIM only works on networks in its own country, and a regional one
     only across its own region, so the one thing worth saying before someone
     pays is: buy this only if you are going there. Anyone who is not gets a way
     out in the same breath, the global plan, rather than a warning that leaves
     them stuck.

     It replaced a first-visit "where are you going?" greeting. That fired once
     per session on arrival, which is the wrong moment: it interrupted before
     the visitor had chosen anything and said nothing about what they were about
     to buy.

     State lives in the page's Alpine scope (`pick`), which the destination
     cards write on click. No sessionStorage: this is a purchase check, not a
     greeting, so it has to appear every time, not once. --}}
<div
    x-show="pick.url"
    x-cloak
    @keydown.escape.window="pick = { name: '', url: '', scope: '' }"
    class="fixed inset-0 z-[80] flex items-end justify-center p-3 sm:items-center"
    role="dialog"
    aria-modal="true"
    aria-labelledby="esim-pick-heading"
>
    <div
        x-show="pick.url"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="pick = { name: '', url: '', scope: '' }"
        class="absolute inset-0 bg-zinc-900/50 dark:bg-zinc-950/70"
        aria-hidden="true"
    ></div>

    <div
        x-show="pick.url"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-3 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-3 sm:scale-95"
        class="relative w-full max-w-[420px] p-6 rounded-[12px] bg-[#eff6ff] shadow-2xl ring-1 ring-zinc-200 dark:ring-[#24364f]"
    >
        <button
            type="button"
            @click="pick = { name: '', url: '', scope: '' }"
            aria-label="{{ __('common.close') }}"
            class="absolute right-3 top-3 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#dde3ee] text-zinc-600 transition-colors hover:bg-[#cdd5e4] hover:text-zinc-900 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/20 dark:hover:text-white"
        >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>

        {{-- Two different warnings, because the two plans fail differently. A
             LOCAL eSIM is tied to one country: the risk is buying it for the
             wrong one. A REGIONAL eSIM covers a set of countries: the risk is
             assuming yours is in it. A global plan has neither risk, which is
             why its cards never open this. --}}
        <h2 id="esim-pick-heading" class="pr-8 text-lg font-bold leading-snug text-zinc-900 dark:text-white">
            <span x-show="pick.scope !== 'regional'">
                Only buy this eSIM if you are in <span x-text="pick.name"></span> or travelling there
            </span>
            <span x-show="pick.scope === 'regional'" x-cloak>
                Check <span x-text="pick.name"></span> covers the country you are travelling to
            </span>
        </h2>

        <p class="mt-2.5 text-[14px] leading-relaxed text-zinc-600 dark:text-zinc-300">
            <span x-show="pick.scope !== 'regional'">
                It connects to networks there, so it is no use anywhere else.
            </span>
            <span x-show="pick.scope === 'regional'" x-cloak>
                It works across the countries this plan lists and nowhere else. The full list is on the plan's page.
            </span>
        </p>

        <a
            :href="pick.url"
            wire:navigate
            class="mt-5 flex h-11 w-full items-center justify-center rounded-[12px] bg-[#0c1337] px-4 text-[14px] text-white transition-colors hover:bg-[#1a2560] dark:bg-[#fdfdfd] dark:text-[#0c1337] dark:hover:bg-[#e4e5f3]"
        >
            Continue to <span class="ml-1" x-text="pick.name"></span>
        </a>

        @if ($globalUrl)
            <p class="mt-5 text-[13px] text-zinc-500 dark:text-zinc-400">
                Not going there? One eSIM covers you worldwide.
            </p>

            <a
                href="{{ $globalUrl }}"
                wire:navigate
                class="mt-2 flex h-11 w-full items-center justify-center rounded-[12px] border border-zinc-200 px-4 text-[14px] text-[#0c1337] transition-colors hover:bg-[#f4f6f5] dark:border-white/15 dark:text-white dark:hover:bg-white/10"
            >
                Buy {{ $globalName }}
            </a>
        @endif
    </div>
</div>
