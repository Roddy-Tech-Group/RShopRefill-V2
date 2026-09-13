{{--
    Interface language - the list inside the locale modal's Language pane. This
    is the ONLY mount: the modal is where language is chosen, so there is no
    standalone pill on the nav duplicating it.

    These rows STAGE a choice, they do not apply it. Each writes draftLanguage
    on the modal scope; the modal's Save is what stores it and sets the cookie
    the translate engine reads, so one Save still applies all three preferences
    together rather than the language jumping the moment you touch it.

    The list is the translate engine's, not the app's own locale list: the
    engine (partials/translate-engine) machine-translates the whole page into
    any of these, including catalogue copy no lang file can reach. Names rather
    than ISO codes because the engine owns that mapping and the picker shows
    names anyway.

    Language shares the modal SHEET with country and currency, but not their
    state: picking a language changes no country or currency value. Someone
    buying US gift cards, paying in XAF, reading in French is a normal
    customer, not a confused one.

    A radiogroup rather than buttons: that is what a staged single choice is,
    and it gives arrow-key navigation and a spoken "selected" for free.
--}}
@props(['languages' => []])

<div
    x-data="{ search: '' }"
    {{ $attributes->merge(['class' => 'flex w-full min-h-0 flex-col']) }}
>
    <div class="relative mb-3">
        <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-zinc-500">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
        </span>
        {{-- Enter would otherwise submit the form this pane sits in, saving a
             half-made choice from the search box. --}}
        <input
            x-model="search"
            @keydown.enter.prevent
            type="text"
            placeholder="{{ __('common.search') }}"
            aria-label="{{ __('common.language') }}"
            class="w-full rounded-[12px] bg-[#ffffff] py-3 pl-11 pr-4 text-base text-zinc-900 outline-none ring-1 ring-[#dee3e1] placeholder:text-zinc-500 focus:ring-2 focus:ring-blue-500/40 dark:bg-white/5 dark:text-white dark:ring-white/10"
        >
    </div>

    <div
        class="min-h-0 max-h-[18.5rem] overflow-y-auto rounded-[12px] bg-[#ffffff] p-1 ring-1 ring-[#dee3e1] dark:bg-white/5 dark:ring-white/10"
        role="radiogroup"
        aria-label="{{ __('common.language') }}"
    >
        <template x-for="name in @js($languages).filter((n) => n.toLowerCase().includes(search.toLowerCase()))" :key="name">
            <button
                type="button"
                role="radio"
                @click="draftLanguage = name; pane = 'root'; search = ''"
                :aria-checked="draftLanguage === name ? 'true' : 'false'"
                :class="draftLanguage === name
                    ? 'font-semibold text-zinc-900 dark:text-white'
                    : 'text-zinc-900 hover:bg-[#f4f6f5] dark:text-white dark:hover:bg-white/10'"
                class="flex w-full items-center gap-3 rounded-[10px] px-3 py-3 text-left text-base transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
            >
                <span class="flex-1 truncate" x-text="name"></span>

                <template x-if="draftLanguage === name">
                    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </template>
            </button>
        </template>
    </div>
</div>
