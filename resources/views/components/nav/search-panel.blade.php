{{--
    The body of every storefront search panel: loading state, recent searches,
    popular searches, grouped results, and the "show all" footer.

    One partial, three mounts (desktop nav, mobile search sheet, and the shop's
    x-shop.product-search). The markup was already near-identical in all three,
    and this version adds sections, a country switcher and a history list on top
    of that - triplicating it would guarantee they drift apart.

    It renders inside an x-data="navBrandSearch()" scope and reads only that
    scope, so the parent owns the panel chrome (position, width, background) and
    this owns the contents.
--}}
@props(['listClass' => 'max-h-[60vh] overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden'])

@php
    // The section headings and row types, in the page's language. navBrandSearch()
    // lives in app.js and keys results by the API's English type names, so the
    // copy is handed to it from here, the one partial all three mounts share.
    $searchLabels = [
        'groups' => [
            'Gift card' => __('navigation.gift_cards'),
            'Mobile top-up' => __('navigation.mobile_refill'),
            'Bill payment' => __('navigation.search_group_bill_payments'),
            'eSIM' => __('navigation.esims'),
            'Page' => __('navigation.search_group_suggestions'),
            'Country' => __('common.change_country'),
        ],
        'types' => [
            'Gift card' => __('navigation.search_type_gift_card'),
            'Mobile top-up' => __('navigation.search_type_topup'),
            'Bill payment' => __('navigation.search_type_bill'),
            'eSIM' => __('navigation.search_type_esim'),
            'Page' => __('navigation.search_type_page'),
        ],
    ];
@endphp

{{-- Loading. Also where the labels reach the search scope: this element is
     the partial's first, so x-init runs before any result row renders. --}}
<div x-init="labels = @js($searchLabels)" x-show="loading && results.length === 0" class="px-5 py-6 text-center text-sm text-zinc-600 dark:text-zinc-300">
    <svg class="mx-auto h-5 w-5 animate-spin text-zinc-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
    <p class="mt-2">{{ __('common.searching') }}</p>
</div>

<div class="{{ $listClass }}">

    {{-- Recent searches. Local to this browser, so only ever shown on the idle
         panel - never mixed into live results. --}}
    <div x-show="showingPopular && recent.length > 0" x-cloak>
        <div class="flex items-center justify-between px-5 pt-4 pb-1">
            <span class="text-[13px] font-medium text-zinc-500 dark:text-zinc-400">{{ __('common.recent_searches') }}</span>
            <button type="button" @click="clearRecent()" class="text-[13px] font-medium text-zinc-500 transition-colors hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">{{ __('common.clear') }}</button>
        </div>

        <template x-for="term in recent" :key="'recent-' + term">
            <button
                type="button"
                @click="useRecent(term)"
                class="mx-1.5 flex w-[calc(100%-0.75rem)] items-center gap-3 rounded-full px-3.5 py-2 text-left transition-colors hover:bg-[#f4f6f5] dark:hover:bg-white/5"
            >
                <svg class="h-4 w-4 shrink-0 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span class="truncate text-sm text-zinc-800 dark:text-zinc-200" x-text="term"></span>
            </button>
        </template>
    </div>

    {{-- Popular searches. Heading only on the idle panel, never above typed results. --}}
    <div x-show="showingPopular && results.length > 0" class="px-5 pt-4 pb-1 text-[13px] font-medium text-zinc-500 dark:text-zinc-400">{{ __('common.popular_searches') }}</div>

    {{-- Results, in labelled sections. `groups` drops any section with no rows,
         so a heading can never render above an empty list. --}}
    <template x-for="g in groups" :key="g.type">
        <div>
            {{-- The idle panel is one flat Popular list, so its rows carry no
                 section heading of their own. --}}
            <div x-show="! showingPopular" class="px-5 pt-4 pb-1 text-[13px] font-medium text-zinc-500 dark:text-zinc-400" x-text="g.label"></div>

            <template x-for="r in g.rows" :key="r.type + ':' + (r.url || r.country)">
                <div>
                    {{-- Country rows switch the storefront region in place, so
                         they are buttons. Everything else is a link. --}}
                    <template x-if="r.type === 'Country'">
                        <button
                            type="button"
                            @click="pickCountry(r)"
                            class="mx-1.5 flex w-[calc(100%-0.75rem)] items-center gap-3 rounded-full px-3.5 py-2.5 text-left transition-colors hover:bg-[#f4f6f5] dark:hover:bg-white/5"
                        >
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-[8px] bg-white p-0.5 dark:bg-white/5">
                                <img :src="r.logo" :alt="r.name" class="h-full w-full object-contain">
                            </span>
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span class="truncate text-sm font-semibold text-zinc-900 dark:text-white" x-text="r.name"></span>
                                <span class="text-[11px] text-zinc-500 dark:text-zinc-400">{!! __('common.shop_in', ['currency' => '<span x-text="r.currency"></span>']) !!}</span>
                            </span>
                        </button>
                    </template>

                    <template x-if="r.type !== 'Country'">
                        <a
                            :href="r.url"
                            wire:navigate
                            @click="open = false"
                            class="mx-1.5 flex items-center gap-3 rounded-full px-3.5 py-2.5 transition-colors hover:bg-[#f4f6f5] dark:hover:bg-white/5"
                        >
                            <template x-if="r.logo">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-[8px] bg-white p-0.5 dark:bg-white/5">
                                    <img :src="r.logo" :alt="r.name" class="h-full w-full object-contain">
                                </span>
                            </template>
                            <template x-if="! r.logo">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[8px] bg-white text-[13px] uppercase text-zinc-700 dark:bg-white/5 dark:text-zinc-200" x-text="r.name.substring(0, 2).toUpperCase()"></span>
                            </template>
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span class="truncate text-sm font-semibold text-zinc-900 dark:text-white" x-text="r.name"></span>
                                <span class="text-[11px] text-zinc-500 dark:text-zinc-400" x-text="typeLabel(r.type)"></span>
                            </span>
                        </a>
                    </template>
                </div>
            </template>
        </div>
    </template>

    {{-- No results --}}
    <div x-show="! loading && results.length === 0 && query.length >= 2" class="px-5 py-6 text-center text-sm text-zinc-600 dark:text-zinc-300">
        {!! __('navigation.search_no_results', ['query' => '<span class="font-semibold text-zinc-900 dark:text-white" x-text="query"></span>']) !!}
    </div>
</div>

{{-- {{ __('common.show_all_results') }}. Hidden on the idle panel: it links to ?q=, which would
     submit an empty search.

     A filled pill inset from the panel edge rather than a full-bleed text link
     across a rule: it is the panel's one action, so it should read as a button.
     The wrapper carries the inset so the pill never touches the panel's own
     rounded corner. --}}
<div x-show="results.length > 0 && ! showingPopular" class="p-3">
    <a
        :href="'{{ route('shop.gift-cards') }}?q=' + encodeURIComponent(query)"
        wire:navigate
        @click="open = false"
        class="block rounded-full bg-[#f4f6f5] px-5 py-3 text-center text-sm font-bold text-zinc-900 transition-colors hover:bg-[#e8ecea] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white/10 dark:text-white dark:hover:bg-white/15"
    >
        {{ __('common.show_all_results') }}
    </a>
</div>
