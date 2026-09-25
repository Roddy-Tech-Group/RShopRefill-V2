@props(['placeholder' => __('giftcards.search_placeholder')])

{{-- Global product search. Reuses the nav's navBrandSearch() factory, which hits
     /api/search/brands across EVERY category (gift cards, eSIMs, mobile top-ups,
     bill payments) and renders a live results dropdown. Drop it on any
     storefront page so the search is the same everywhere. --}}
<div
    x-data="navBrandSearch()"
    @click.outside="open = false"
    {{-- Freeze the page while the panel is open. rshopScrollLock is
         reference-counted, so the three search instances (and any modal
         above them) can hold it at once without fighting. --}}
    x-effect="open ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
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
        <button type="submit" class="shrink-0 text-zinc-900 transition-colors hover:text-blue-600 focus:outline-none dark:text-white" aria-label="{{ __('common.search') }}">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </button>
        <input
            x-ref="search"
            x-model="query"
            @input="onInput()"
            @focus="onFocus()"
            name="q"
            type="search"
            placeholder="{{ $placeholder }}"
            aria-label="{{ $placeholder }}"
            autocomplete="off"
            spellcheck="false"
            class="min-w-0 flex-1 bg-transparent text-sm text-zinc-800 placeholder:text-zinc-500 outline-none dark:text-white"
        >
        <button type="button" x-show="query.length > 0" @click="clear()" class="flex h-6 w-6 shrink-0 items-center justify-center rounded-[12px] bg-zinc-200 transition-colors hover:bg-zinc-300 focus:outline-none dark:bg-white/10 dark:hover:bg-white/20" aria-label="{{ __('common.clear') }}">
            <svg class="h-3.5 w-3.5 text-zinc-600 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
        </button>
    </form>

    {{-- Results dropdown. Solid panel; the body is the shared partial. --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        style="display:none;"
        class="absolute left-0 right-0 top-full z-30 mt-2 overflow-hidden rounded-[20px] border border-zinc-200 bg-white shadow-2xl shadow-zinc-900/15 dark:border-white/10"
    >
        <x-nav.search-panel />
    </div>
</div>
