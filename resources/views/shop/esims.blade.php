@php
    // Feature A: keep the eSIM catalog visible but greyed + non-purchasable when off
    // (was a 404). The purchase itself is blocked server-side.
    $categoryEnabled = \App\Support\FeatureFlag::on('esims');

    /** @var \Illuminate\Support\Collection<int, array<string, mixed>> $catalog */
    $catalog = $catalog ?? collect();
    // Inside the dashboard the frame pads the page itself (16px a side on a
    // phone, what the overview gets). The page wrapper adds none of its own
    // there, so the listing runs as wide as the overview instead of 16px
    // narrower each side.
    $inDash = request()->is('dashboard/shop*') && auth()->check();
@endphp

<x-shop.layout :title="__('navigation.esims').' | '.$siteName" :og-image="asset('assets/'.rawurlencode('Esim.webp'))">

    <div
        x-data="{
            locTab: @js($activeScope ?? 'popular'),
            locSearch: '',
            // Whether the destination field has the caret. It drives the
            // field's border by swapping the class rather than with a focus:
            // variant, because the storefront pins .border-zinc-200 to the
            // shared edge colour at a specificity no state variant can beat.
            // Swapping the class takes border-zinc-200 off the element, so
            // that rule has nothing to match. This lives on the page scope,
            // not the input, because $refs.esimSearch is read from here.
            searchFocused: false,
            // The destination a visitor just opened, which the purchase-check
            // modal reads. Empty url means the modal is closed.
            pick: { name: '', url: '', scope: '' },
            titles: { popular: @js(__('esim.title_popular')), local: @js(__('esim.title_local')), regional: @js(__('esim.title_regional')), global: @js(__('esim.title_global')), unlimited: @js(__('esim.title_unlimited')), all: @js(__('esim.title_all')) },
            crumbs: { popular: @js(__('esim.tab_popular')), local: @js(__('esim.title_local')), regional: @js(__('esim.title_regional')), global: @js(__('esim.title_global')), unlimited: @js(__('esim.tab_unlimited')), all: @js(__('esim.title_all')) },
            subtitles: {
                popular: @js(__('esim.subtitle_popular')),
                local: @js(__('esim.subtitle_local')),
                regional: @js(__('esim.subtitle_regional')),
                global: @js(__('esim.subtitle_global')),
                unlimited: @js(__('esim.subtitle_unlimited')),
                all: @js(__('esim.subtitle_all')),
            },
            init() {
                // Deep link / back-forward: keep the active category and the URL in sync.
                const fromUrl = new URLSearchParams(window.location.search).get('scope');
                if (fromUrl && this.titles[fromUrl]) { this.locTab = fromUrl; }
                this.$watch('locTab', (v) => {
                    const url = new URL(window.location.href);
                    if (v === 'popular') { url.searchParams.delete('scope'); } else { url.searchParams.set('scope', v); }
                    window.history.replaceState({}, '', url);
                });
                window.addEventListener('popstate', () => {
                    const s = new URLSearchParams(window.location.search).get('scope') || 'popular';
                    if (this.titles[s]) { this.locTab = s; }
                });

                // Freeze the page behind the purchase-check modal. Watched as a
                // boolean, not as pick.url itself: the url is a string, and
                // opening the check for a second destination without closing
                // the first changes it while it stays truthy. On the shared
                // reference counter that reads as a second lock, and the page
                // would never unfreeze.
                this.$watch('!! pick.url', (isOpen) => {
                    if (isOpen) {
                        window.rshopScrollLock?.lock();
                    } else {
                        window.rshopScrollLock?.unlock();
                    }
                });
            },
        }"
        class="mx-auto w-full max-w-[1550px] {{ $inDash ? '' : 'px-4 py-8 sm:px-6 lg:px-8 lg:py-12' }}"
    >

        {{-- ── Hero ───────────────────────────────────────────────────────────
             Three eSIM adverts. Illustrations are fixed inline SVGs; every
             heading and line of copy is admin-editable on the System Settings
             page (group "esim"), so a seasonal push never needs a deploy. The
             third card used to run a World Cup stadium scene, which expired
             with the tournament - it is a globe now, and its copy carries no
             date. Mobile = swipeable carousel; desktop = 3 cards. The section
             itself inherits the page background (no photo / bubbles). --}}
        @php
            // Fallbacks are last-resort only: the seeded rows carry the live
            // wording, so an empty database still renders a usable hero rather
            // than three blank cards.
            //
            // The stock wording is the English lang line. While a setting still
            // holds exactly that, the visitor's own translation is shown; once
            // an admin rewrites it, their copy is shown as written.
            $promoCopy = function (string $settingKey, string $langKey): string {
                $stock = trans($langKey, [], 'en');
                $stored = (string) \App\Models\SiteSetting::get($settingKey, $stock);

                return $stored === $stock ? __($langKey) : $stored;
            };
            $esimSlides = [
                [
                    'illo'    => 'illos.esim-travelers',
                    'heading' => $promoCopy('esim.promo1_heading', 'esim.hero_1_heading'),
                    'text'    => $promoCopy('esim.promo1_text', 'esim.hero_1_text'),
                ],
                [
                    'illo'    => 'illos.esim-people',
                    'heading' => $promoCopy('esim.promo2_heading', 'esim.hero_2_heading'),
                    'text'    => $promoCopy('esim.promo2_text', 'esim.hero_2_text'),
                ],
                [
                    'illo'    => 'illos.esim-globe',
                    'heading' => $promoCopy('esim.promo3_heading', 'esim.hero_3_heading'),
                    'text'    => $promoCopy('esim.promo3_text', 'esim.hero_3_text'),
                ],
            ];
        @endphp
        <section class="relative">
            {{-- Desktop: the three adverts share ONE panel, as three columns
                 divided by a hairline, rather than three cards with three sets
                 of edges. They are one argument for buying an eSIM, so they read
                 better as one object. Each column keeps its own heading levels
                 (the first is the page's h1) and its own illustration. --}}
            {{-- Narrower than the page: the panel is three short adverts, and at
                 the full 1550px column each one runs to a line length nobody
                 reads. Centred, so the grid below still lines up with the nav. --}}
            <div class="mx-auto hidden w-full max-w-[1100px] lg:block">
                <div class="overflow-hidden rounded-[20px] bg-[#0c1a36] p-6 shadow-md shadow-zinc-900/[0.06] dark:shadow-none">
                    <div class="grid grid-cols-3 gap-7 divide-x divide-white/10">
                        @foreach ($esimSlides as $slide)
                            <article class="flex flex-col {{ $loop->first ? '' : 'pl-7' }}">
                                <x-dynamic-component :component="$slide['illo']" class="h-auto w-full rounded-[14px]" aria-hidden="true" />
                                @if ($loop->first)
                                    <h1 class="mt-4 text-xl font-bold tracking-tight text-white xl:text-2xl">{{ $slide['heading'] }}</h1>
                                @else
                                    <h2 class="mt-4 text-xl font-bold tracking-tight text-white xl:text-2xl">{{ $slide['heading'] }}</h2>
                                @endif
                                <p class="mt-2 text-sm leading-relaxed text-zinc-300">{{ $slide['text'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Mobile: the same three adverts as a swipeable, auto-advancing carousel. --}}
            <div
                x-data="{
                    slides: {{ count($esimSlides) }},
                    current: 0,
                    timer: null,
                    sx: 0,
                    init() { this.play(); },
                    play() { this.stop(); this.timer = setInterval(() => this.next(), 8000); },
                    stop() { if (this.timer) clearInterval(this.timer); },
                    next() { this.current = (this.current + 1) % this.slides; },
                    go(i) { this.current = i; this.play(); },
                    swipe(e) {
                        const dx = e.changedTouches[0].clientX - this.sx;
                        if (Math.abs(dx) > 40) { this.current = (this.current + (dx < 0 ? 1 : this.slides - 1)) % this.slides; }
                        this.play();
                    },
                    destroy() { this.stop(); },
                }"
                class="relative lg:hidden"
            >
                <div
                    class="overflow-hidden rounded-[20px]"
                    @touchstart.passive="sx = $event.changedTouches[0].clientX; stop()"
                    @touchend.passive="swipe($event)"
                >
                    <div class="flex transition-transform duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]" :style="`transform: translateX(-${current * 100}%)`">
                        @foreach ($esimSlides as $slide)
                            <article class="w-full shrink-0">
                                <div class="rounded-[20px] bg-[#0c1a36] p-5 border border-zinc-300 dark:border-zinc-700">
                                    <x-dynamic-component :component="$slide['illo']" class="mx-auto h-auto w-full max-w-sm rounded-[14px]" aria-hidden="true" />
                                    <div class="mx-auto mt-4 max-w-md text-center">
                                        <h2 class="text-2xl font-bold tracking-tight text-white">{{ $slide['heading'] }}</h2>
                                        <p class="mt-2 text-sm leading-relaxed text-zinc-300">{{ $slide['text'] }}</p>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>

                {{-- Dots --}}
                <div class="mt-5 flex items-center justify-center gap-2">
                    <template x-for="i in slides" :key="i">
                        <button
                            type="button"
                            @click="go(i - 1)"
                            :aria-label="@js(__('esim.go_to_advert')).replace(':number', i)"
                            :class="current === (i - 1) ? 'w-6 bg-blue-600' : 'w-2.5 bg-zinc-300 dark:bg-zinc-600'"
                            class="h-2.5 rounded-full transition-all duration-300"
                        ></button>
                    </template>
                </div>
            </div>
        </section>

        {{-- ── Browse by location ───────────────────────────────────────────── --}}
        {{-- Same width as the promo panel above, so the page reads as one
             column rather than a narrow advert followed by a wider grid. --}}
        <section id="esim-locations" class="mx-auto mt-10 w-full max-w-[1100px] scroll-mt-24">
            {{-- Heading first, then the tabs under it, both centred: the tabs
                 choose what the heading is naming, so they read as one control
                 rather than a toolbar sitting above an unrelated title. --}}
            <div class="text-center">
                <h2 class="text-[30px] font-bold tracking-tight text-zinc-900 dark:text-white" x-text="titles[locTab]">{{ __('esim.title_popular') }}</h2>
                <p class="mt-1 text-[18px] text-zinc-600 dark:text-zinc-400" x-text="subtitles[locTab]"></p>
            </div>

            {{-- The tabs ride under the nav while the grid they filter scrolls
                 past, so the control that chooses what you are looking at is
                 never scrolled away from the thing it chose.

                 The offset is the nav's REAL height, which x-nav.main-nav
                 publishes as --nav-h and republishes as the category strip
                 folds, so the pill follows the bar instead of hanging under a
                 hardcoded number. Same idiom as the cart panel. The 64px
                 fallback covers the frame before Alpine first measures.

                 z-30 keeps it over the destination cards and under the header
                 (z-50), which it never reaches anyway. Sticky works here
                 because nothing between this and <body> scrolls or clips:
                 <main> deliberately carries no overflow (see the note in
                 layouts/app/header.blade.php) and the page's own
                 overflow-hidden boxes are inside the promo section above.

                 The band carries the page's own tone - the same class pair
                 <main> wears, so it follows every remap main does in both
                 themes - which is what the cards scroll behind. Without it the
                 wrapper is transparent either side of the pill and the grid
                 slides through the gap in full view. It costs nothing at rest,
                 being the colour already behind it, and only reads as a
                 surface once the row is stuck.

                 Flush under the bar, not 8px below it, and the 8px is padding
                 instead: as a gap it was a strip of open page between the nav
                 and the pill with cards travelling up it.

                 Desktop only. A phone viewport is short enough that a pinned
                 control is a standing tax on the list it filters, and the bar
                 above it already takes its share. Below md the row scrolls
                 away with the page. Only the positioning is scoped - the band
                 is the page's own tone, so where nothing is stuck there is
                 nothing to see. --}}
            {{-- A <section>, not a <div>, and that is load-bearing in dark mode.
                 The band has to be the page's own tone so the grid passes
                 behind a surface rather than through the gap either side of the
                 pill. In dark mode the tone is not a colour this element can
                 name: dark:bg-[#0c1a36] means "page black" only on page-level
                 elements (body, main, section, footer, [data-page-content]) and
                 card grey on everything else, which is what a div got, leaving
                 a lifted grey slab across a black page. As a section it lands
                 on the same rule main does, grain and all. --}}
            {{-- The band paints nothing, stuck or not: the pill is its own
                 surface and the page behind it is the hero artwork, so both the
                 permanent page-tone fill and the frosted fill it briefly had
                 while stuck read as a slab across the picture. --}}
            {{-- Where the band parks depends on what actually scrolls. On the
                 storefront that is the window and it parks under the header
                 (--nav-h). Inside the dashboard frame the page scrolls in its
                 own panel, below a header that is not part of that panel, so
                 it parks at the panel's top edge instead (.esim-tab-band in
                 app.css) - offset by the header's height there, it floated a
                 header's height down the panel, over the cards. --}}
            <section
                x-data="{
                    park() {
                        let scroller = $el.parentElement;
                        while (scroller && ! /(auto|scroll)/.test(getComputedStyle(scroller).overflowY)) { scroller = scroller.parentElement; }
                        // A scrolling panel keeps its top padding in front of a
                        // sticky child, so top:0 parked the band a padding's
                        // height below the panel's edge, with cards showing
                        // through the gap. Cancel the padding so the band meets
                        // the edge itself. On the window there is nothing to do:
                        // --nav-h already puts it under the header.
                        if (scroller) { $el.style.top = -(parseFloat(getComputedStyle(scroller).paddingTop) || 0) + 'px'; }
                    },
                }"
                x-init="park()"
                x-on:resize.window.passive="park()"
                class="esim-tab-band mt-5 flex justify-center bg-transparent py-2 md:sticky md:top-[var(--nav-h,64px)] md:z-30"
            >
                <x-esim.location-tabs />
            </section>

            {{-- eSIM-only search. The global product search used to sit here,
                 which sent anyone typing "France" off into gift cards and
                 top-ups as well. This one filters THIS grid: it writes locSearch,
                 which every destination card already reads in its x-show, so the
                 list narrows as you type with no request and no page change.

                 Same pill as the rest of the page, and it clears itself rather
                 than leaving the shopper to select and delete. --}}
            <div class="mt-5 flex justify-center">
                <div class="relative w-full max-w-[560px]">
                    <input
                        type="search"
                        x-ref="esimSearch"
                        x-model="locSearch"
                        @input="locSearch = locSearch.toLowerCase()"
                        placeholder="{{ __('esim.search_placeholder') }}"
                        aria-label="{{ __('esim.search_label') }}"
                        autocomplete="off"
                        spellcheck="false"
                        {{-- The interactive edge, as on the locale modal's search
                             fields: a 2px border in the blue that means "this
                             control is yours to use" (--color-edge in app.css).
                             It replaces a hairline ring that read as a divider,
                             and it is a border rather than a ring so the pill
                             keeps one outline instead of stacking a second,
                             thicker one on focus. --}}
                        @focus="searchFocused = true"
                        @blur="searchFocused = false"
                        {{-- Grey until you click into it, then the interactive
                             edge. Swapped as a whole class rather than through
                             a focus: variant: the storefront pins
                             .border-zinc-200 to the shared edge colour from
                             html:not(.dark) body.storefront, which outranks any
                             focus: utility, so the field stayed grey however it
                             was focused. Taking the class off the element
                             leaves that rule nothing to match. --}}
                        :class="searchFocused
                            ? 'border-edge dark:border-edge'
                            : 'border-zinc-200 dark:border-white/10'"
                        class="w-full rounded-full border-2 bg-[#ffffff] py-4 pl-6 pr-[4.5rem] text-[16px] text-zinc-900 outline-none transition-colors placeholder:text-zinc-500 focus:ring-2 focus:ring-edge/30 dark:bg-white/5 dark:text-white dark:placeholder:text-zinc-400"
                    >

                    {{-- Clear sits inside the field, left of the action, and only
                         once there is something to clear. --}}
                    <button
                        type="button"
                        x-show="locSearch !== ''"
                        x-cloak
                        @click="locSearch = ''; $refs.esimSearch.focus()"
                        aria-label="{{ __('esim.clear_search') }}"
                        class="absolute right-[3.75rem] top-1/2 grid h-7 w-7 -translate-y-1/2 place-items-center rounded-full text-zinc-500 transition-colors hover:bg-[#f4f6f5] hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-white"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>

                    {{-- The action: a filled circle inside the pill's right edge,
                         as in the reference. The grid filters live as you type, so
                         this has nothing to submit; it takes you to the results,
                         which is the one thing left to do on a long page. --}}
                    <button
                        type="button"
                        @click="$refs.esimSearch.focus(); document.getElementById('esim-locations')?.scrollIntoView({ behavior: 'smooth', block: 'start' })"
                        aria-label="{{ __('esim.show_matching') }}"
                        class="absolute right-2 top-1/2 grid h-11 w-11 -translate-y-1/2 place-items-center rounded-full bg-[#0c1337] text-white transition-colors hover:bg-[#1a2560] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#0c1337]/40 dark:bg-[#fdfdfd] dark:text-[#0c1337] dark:hover:bg-[#e4e5f3]"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35m1.85-4.65a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="mt-6">

                @unless ($categoryEnabled)
                    <div class="mt-5 flex items-start gap-3 rounded-[12px] border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-500/20 dark:bg-amber-500/10">
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                        </svg>
                        <div>
                            <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">{{ __('esim.unavailable_title') }}</p>
                            <p class="mt-0.5 text-xs text-amber-800/80 dark:text-amber-200/70">{{ __('esim.unavailable_browse') }}</p>
                        </div>
                    </div>
                @endunless

                @if ($catalog->isNotEmpty())
                    <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($catalog as $item)
                            <x-esim.location-card :item="$item" :disabled="! $categoryEnabled" />
                        @endforeach
                    </div>
                @else
                    <div class="mt-6 rounded-[12px] bg-white px-6 py-16 text-center ring-1 ring-zinc-200">
                        <p class="text-base font-semibold text-zinc-900">{{ __('esim.empty_title') }}</p>
                        <p class="mt-1 text-sm text-zinc-600">{{ __('esim.empty_body') }}</p>
                    </div>
                @endif

                {{-- Opens every destination by switching to the All tab, rather
                     than linking away. The count is the catalogue's own, so it
                     can never advertise coverage we do not carry. --}}
                @if ($catalog->count() > 1)
                    <div class="mt-8 flex justify-center">
                        <button
                            type="button"
                            @click="locTab = 'all'; locSearch = ''"
                            x-show="locTab !== 'all'"
                            class="inline-flex items-center rounded-full bg-[#0c1337] px-6 py-3 text-[14px] font-semibold text-white transition-colors hover:bg-[#1a2560] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#0c1337]/40 dark:bg-[#fdfdfd] dark:text-[#0c1337] dark:hover:bg-[#e4e5f3]"
                        >
                            {{ __('esim.browse_all', ['count' => number_format($catalog->count())]) }}
                        </button>
                    </div>
                @endif
            </div>
        </section>

        {{-- Same FAQ as the destination pages, from the faqs table through the
             shared component. Storefront only: the dashboard shop is for people
             who have already bought, and its own help lives in the sidebar. --}}
        @unless (request()->is('dashboard/shop*') && auth()->check())
            <x-home.faq
                :topics="['eSIMs']"
                :limit="17"
                heading-id="esims-faq-heading"
                :grow-from="1100"
            />
        @endunless

    {{-- Purchase check, raised by a destination card rather than on arrival.
         It offers the worldwide plan as the way out, so that plan's own URL is
         resolved here, on whichever surface the page is rendered. --}}
    @php
        $globalPick = $catalog->first(fn ($item) => str_contains($item['slug'], 'discover-global'));
    @endphp
    <x-esim.destination-tip
        :global-url="$globalPick ? route(request()->is('dashboard/shop*') && auth()->check() ? 'dashboard.shop.esim' : 'shop.esim', $globalPick['slug']) : null"
        :global-name="$globalPick['name'] ?? 'Global eSIM'"
    />

    </div>



</x-shop.layout>
