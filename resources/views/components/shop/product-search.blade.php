@props(['placeholder' => 'Search all products - brands, eSIMs, countries'])

{{-- Global product search. Reuses the nav's navBrandSearch() factory, which hits
     /api/search/brands across EVERY category (gift cards, eSIMs, mobile top-ups,
     bill payments) and renders a live results dropdown. Drop it on any
     storefront page so the search is the same everywhere. --}}
<div
    x-data="navBrandSearch()"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    {{ $attributes->merge(['class' => 'relative w-full']) }}
>
    <form
        role="search"
        method="GET"
        action="{{ route('shop.gift-cards') }}"
        @click="$refs.search.focus()"
        :class="open ? 'border-blue-500 ring-2 ring-blue-500/15' : 'border-zinc-300 hover:border-zinc-400 dark:border-zinc-700'"
        class="group flex cursor-text items-center gap-3 rounded-[15px] border bg-[#eff6ff] px-4 py-2.5 transition-all duration-200"
    >
        <button type="submit" class="shrink-0 text-zinc-900 transition-colors hover:text-blue-600 focus:outline-none dark:text-white" aria-label="Search">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </button>
        <input
            x-ref="search"
            x-model="query"
            @input="onInput()"
            @focus="if (query.length >= 2) open = true"
            name="q"
            type="search"
            placeholder="{{ $placeholder }}"
            aria-label="{{ $placeholder }}"
            autocomplete="off"
            spellcheck="false"
            class="min-w-0 flex-1 bg-transparent text-sm text-zinc-800 placeholder:text-zinc-500 outline-none dark:text-white"
        >
        <button type="button" x-show="query.length > 0" @click="clear()" class="flex h-6 w-6 shrink-0 items-center justify-center rounded-[12px] bg-zinc-200 transition-colors hover:bg-zinc-300 focus:outline-none dark:bg-white/10 dark:hover:bg-white/20" aria-label="Clear">
            <svg class="h-3.5 w-3.5 text-zinc-600 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
        </button>
    </form>

    {{-- Results dropdown - page-bg frosted glass. --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        style="display:none;"
        class="glass-panel absolute left-0 right-0 top-full z-30 mt-2 overflow-hidden rounded-[12px] shadow-2xl shadow-zinc-900/15"
    >
        <div x-show="loading && results.length === 0" class="px-5 py-6 text-center text-sm text-zinc-600 dark:text-zinc-300">
            <svg class="mx-auto h-5 w-5 animate-spin text-zinc-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
            <p class="mt-2">Searching...</p>
        </div>

        <ul x-show="results.length > 0" class="max-h-[60vh] divide-y divide-zinc-100/80 overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden dark:divide-white/10">
            <template x-for="r in results" :key="r.url">
                <li>
                    <a :href="r.url" wire:navigate @click="open = false" class="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-zinc-100 dark:hover:bg-white/5">
                        <template x-if="r.logo">
                            <span class="flex aspect-[16/10] w-20 shrink-0 items-center justify-center overflow-hidden rounded-[5px] bg-white shadow-sm ring-1 ring-zinc-200">
                                <img :src="r.logo" :alt="r.name" class="h-full w-full object-cover">
                            </span>
                        </template>
                        <template x-if="!r.logo">
                            <span class="flex aspect-[16/10] w-20 shrink-0 items-center justify-center rounded-[5px] bg-white text-sm font-black uppercase text-zinc-700 shadow-sm ring-1 ring-zinc-200" x-text="r.name.substring(0, 2).toUpperCase()"></span>
                        </template>
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="truncate text-sm font-semibold text-zinc-900 dark:text-white" x-text="r.name"></span>
                            <span class="text-[11px] text-zinc-500 dark:text-zinc-400" x-text="r.type"></span>
                        </span>
                    </a>
                </li>
            </template>
        </ul>

        <div x-show="!loading && results.length === 0 && query.length >= 2" class="px-5 py-6 text-center text-sm text-zinc-600 dark:text-zinc-300">
            No products match "<span class="font-semibold text-zinc-900 dark:text-white" x-text="query"></span>"
        </div>

        <a
            x-show="results.length > 0"
            :href="'{{ route('shop.gift-cards') }}?q=' + encodeURIComponent(query)"
            wire:navigate
            @click="open = false"
            class="block border-t border-zinc-100 px-5 py-3 text-center text-sm font-semibold text-blue-600 transition-colors hover:bg-zinc-100/70 hover:text-blue-700 dark:border-white/10"
        >
            Show all results
        </a>
    </div>
</div>
