@props([
    'title',
    // Optional inline icon rendered before the title. Currently only 'globe'
    // (used by the Discover Global eSIM rows).
    'titleIcon' => null,
    'subtitle' => null,
    'viewAllHref' => '#',
    'cols' => 6,
    // When true, render skeleton cards instead of the slot content. Lets pages flip rows into a loading state
    // (e.g. while a backend query runs) without rebuilding the whole row markup.
    'loading' => false,
    // When true, render the children as a horizontal scrollable carousel with a
    // single toggle button (next at start -> back once scrolled). Use for rows
    // that have more brands than fit in a single grid view.
    'carousel' => false,
    // 'link' (default) renders an underlined "See all" text link. 'plus' swaps it
    // for a small rounded "+" pill that matches the dashboard's see-more pattern.
    'viewAllVariant' => 'link',
    // Full-bleed carousel (track spans 100vw) only lines up when the section sits
    // in a viewport-centered container. Inside an off-center column (e.g. the
    // dashboard's left rail) it overflows its neighbours, so pass :bleed="false"
    // to keep the track contained to its own width.
    'bleed' => true,
])

@php
    // Explicit class strings so Tailwind's JIT picks them up.
    $gridCols = match ((int) $cols) {
        5 => 'lg:grid-cols-5',
        7 => 'lg:grid-cols-7',
        default => 'lg:grid-cols-6',
    };
@endphp

{{-- Brand row section. Header (title + view-all) and content share the SAME
     parent width - the carousel does NOT use a full-bleed negative-margin
     trick, so titles, buttons and "See all" all line up with the card edges. --}}
<section
    aria-label="{{ $title }}"
    x-data="{
        navigating: false,
        bleed: {{ $bleed ? 'true' : 'false' }},
        canPrev: false,
        canNext: true,
        // Native horizontal scroll (no JS hand-drag). Touch + trackpad get the
        // browser's own smooth momentum scrolling, and it never fights vertical
        // page scroll. The arrow buttons (desktop only) drive scrollBy().
        //
        // setup() restores two things the native-scroll track needs that plain
        // CSS can't express against a centered max-w container:
        //   1. padLeft - the section's distance from the viewport's left edge.
        //      The full-bleed track spans 100vw, so we pad the list (and the
        //      snap start) by padLeft to line the FIRST card up with the
        //      section heading / content column. On scroll the cards still pass
        //      through that padding out to the absolute viewport left (x=0).
        //   2. --card-w - a fixed per-breakpoint card width consumed by the
        //      carousel-list card-width rule in app.css. Without it the cards
        //      fall back to brand-card sm:w-auto and balloon to their logo size.
        setup() {
            const t    = this.$refs.track;
            const list = this.$refs.list;
            if (! t || ! list) { return; }
            // rect.left can read ~0 mid entrance-animation or when the page
            // carries a few px of horizontal overflow - applying that pinned
            // the first card to the screen edge and made the row's left edge
            // jump between re-measures. Clamp to the content column's static
            // padding (matches the heading) so alignment can never collapse,
            // and re-settle once after animations finish.
            const minPad  = window.innerWidth >= 1024 ? 32 : (window.innerWidth >= 640 ? 24 : 16);
            const apply = () => {
                // Contained (non-bleed) tracks sit inside their own column, so the
                // first card lines up at the track's left edge - no viewport offset.
                const padLeft = this.bleed
                    ? Math.max(minPad, Math.round(this.$el.getBoundingClientRect().left))
                    : 0;
                if (list.style.paddingLeft !== padLeft + 'px') {
                    list.style.paddingLeft    = padLeft + 'px';
                    t.style.scrollPaddingLeft = padLeft + 'px';
                }
            };
            apply();
            setTimeout(apply, 700);
            const cardW = window.innerWidth >= 1024 ? 280 : window.innerWidth >= 640 ? 200 : 160;
            list.style.setProperty('--card-w', cardW + 'px');
            this.$nextTick(() => this.refresh());
        },
        refresh() {
            const t = this.$refs.track;
            if (! t) { return; }
            this.canPrev = t.scrollLeft > 4;
            this.canNext = t.scrollLeft + t.clientWidth < t.scrollWidth - 4;
        },
        nudge(dir) {
            const t = this.$refs.track;
            if (! t) { return; }
            const first = t.querySelector('.carousel-list > *');
            const gap   = window.innerWidth >= 640 ? 20 : 16;
            // Jump a full page of cards (however many fit in the visible track),
            // keeping one card of overlap for context. A fixed 2-card step meant
            // long rows took many arrow clicks to cross; a page-sized nudge reaches
            // the end in a couple.
            const cardW = first ? first.getBoundingClientRect().width + gap : 0;
            const step  = cardW > 0
                ? cardW * Math.max(1, Math.floor(t.clientWidth / cardW) - 1)
                : t.clientWidth * 0.9;
            t.scrollBy({ left: dir * step, behavior: 'smooth' });
        },
    }"
    x-on:livewire:navigated.window="$nextTick(() => setup())"
    x-init="$nextTick(() => setup())"
    class="relative"
>
    <div class="mb-4 flex items-end justify-between gap-4">
        <div class="min-w-0">
            <h2 class="text-lg font-bold text-zinc-900 sm:text-xl dark:text-white">
                @if ($titleIcon === 'globe')
                    <svg class="mr-1 inline-block h-5 w-5 align-[-3px] text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/></svg>
                @endif{{ $title }}
            </h2>
            @if ($subtitle)
                <p class="mt-0.5 text-base text-zinc-600 dark:text-zinc-400">{{ $subtitle }}</p>
            @endif
        </div>

        <div class="flex shrink-0 items-center gap-2">
            @if ($viewAllVariant === 'plus')
                <a href="{{ $viewAllHref }}" wire:navigate aria-label="See more {{ $title }}" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#eff6ff] text-blue-700 border border-zinc-200 transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                </a>
            @elseif ($viewAllVariant !== 'none')
                {{-- Same control style as the storefront reviews header:
                     white rounded-[12px] pill with a zinc ring. --}}
                <a href="{{ $viewAllHref }}" class="flex h-10 shrink-0 items-center rounded-[12px] bg-white px-4 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-200 shadow-sm transition-colors duration-150 hover:bg-zinc-50 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-[#1d3252] dark:text-zinc-200 dark:ring-zinc-700/60 dark:hover:bg-[#26416b]">
                    See all
                </a>
            @endif

            @if ($carousel)
                <button
                    type="button"
                    @click="nudge(-1)"
                    :disabled="! canPrev"
                    aria-label="Previous"
                    class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-white text-zinc-700 ring-1 ring-zinc-200 shadow-sm transition-colors duration-150 hover:bg-zinc-50 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 disabled:opacity-30 disabled:cursor-not-allowed sm:flex dark:bg-[#1d3252] dark:text-zinc-200 dark:ring-zinc-700/60 dark:hover:bg-[#26416b]"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M15 6l-6 6 6 6"/>
                    </svg>
                </button>
                <button
                    type="button"
                    @click="nudge(1)"
                    :disabled="! canNext"
                    aria-label="Next"
                    class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-white text-zinc-700 ring-1 ring-zinc-200 shadow-sm transition-colors duration-150 hover:bg-zinc-50 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 disabled:opacity-30 disabled:cursor-not-allowed sm:flex dark:bg-[#1d3252] dark:text-zinc-200 dark:ring-zinc-700/60 dark:hover:bg-[#26416b]"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9 6l6 6-6 6"/>
                    </svg>
                </button>
            @endif
        </div>
    </div>

    @if ($carousel)
        {{-- Full-bleed carousel: the track spans 100vw via mx-[calc(50%-50vw)],
             breaking out of the max-w container. JS sets padding-left = the
             section's distance from the viewport left, so the first card lines
             up with the content column on load. On scroll, cards pass through
             that padding all the way to x=0 (the absolute viewport left edge).
             Native overflow-x-auto preserves trackpad/touch swipe momentum. --}}
        <div
            x-ref="track"
            @scroll.passive="refresh()"
            @resize.window.debounce.200ms="setup()"
            {{-- Free native horizontal scroll, matching the "How it works" row: no
                 scroll-snap and no container-level `scroll-behavior: smooth`. Both fight
                 the -webkit-overflow-scrolling momentum and make the swipe feel
                 catchy/sluggish instead of gliding freely. The arrow buttons still
                 animate via nudge()'s explicit scrollBy({ behavior: 'smooth' }). --}}
            class="{{ $bleed ? 'mx-[calc(50%-50vw)] w-screen' : 'w-full' }} overflow-x-auto overflow-y-hidden py-4 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden [-webkit-overflow-scrolling:touch]"
        >
            {{-- pl-* / --card-w fallbacks: setup() overrides paddingLeft and sets
                 --card-w inline at runtime; these keep a sane layout pre-JS. --}}
            {{-- Mobile-first --card-w fallback so every row is a stable 160px on
                 mobile even before setup() runs (or if it misfires on a row);
                 setup() then bumps it to 200/280 on larger breakpoints. --}}
            <ul
                x-ref="list"
                data-reveal-group
                style="--card-w: 160px;"
                class="carousel-list flex w-max gap-4 pl-4 pr-4 sm:gap-5 sm:pl-6 sm:pr-6 lg:pl-8 lg:pr-8 [&>*]:shrink-0"
            >
                {{ $slot }}
            </ul>
        </div>
    @else
        {{-- Grid mode (default): horizontal scroll on mobile, responsive grid on tablet+.
             -webkit-overflow-scrolling:touch gives the mobile swipe the same native
             momentum glide as the testimonial carousel (otherwise it scrolls flat). --}}
        <div class="-mx-4 overflow-x-auto px-4 py-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden [-webkit-overflow-scrolling:touch] sm:mx-0 sm:overflow-visible sm:px-0 sm:py-0">
            @if ($loading)
                <ul class="skeleton-stagger-fast flex w-max gap-4 sm:grid sm:w-full sm:grid-cols-3 sm:gap-5 {{ $gridCols }}">
                    @for ($i = 0; $i < (int) $cols; $i++)
                        <li class="rounded-[12px] bg-white p-3 shadow-sm shadow-zinc-900/[0.04] ring-1 ring-zinc-100" style="--i: {{ $i }}">
                            <x-skeleton class="aspect-[16/10] w-full rounded-[15px]" />
                            <x-skeleton class="mt-3 h-4 w-3/4" />
                        </li>
                    @endfor
                </ul>
            @else
                <ul data-reveal-group class="flex w-max gap-4 sm:grid sm:w-full sm:grid-cols-3 sm:gap-5 {{ $gridCols }}">
                    {{ $slot }}
                </ul>
            @endif

        </div>
    @endif
</section>
