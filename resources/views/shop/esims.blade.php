@php
    // Feature A: keep the eSIM catalog visible but greyed + non-purchasable when off
    // (was a 404). The purchase itself is blocked server-side.
    $categoryEnabled = \App\Support\FeatureFlag::on('esims');

    /** @var \Illuminate\Support\Collection<int, array<string, mixed>> $catalog */
    $catalog = $catalog ?? collect();
@endphp

<x-shop.layout :title="'eSIMs | '.$siteName" :og-image="asset('assets/'.rawurlencode('Esim.webp'))">

    <div
        x-data="{
            locTab: @js($activeScope ?? 'popular'),
            locSearch: '',
            // The destination a visitor just opened, which the purchase-check
            // modal reads. Empty url means the modal is closed.
            pick: { name: '', url: '', scope: '' },
            titles: { popular: 'Popular locations', local: 'Local eSIMs', regional: 'Regional eSIMs', global: 'Global eSIMs', unlimited: 'Unlimited data eSIMs', all: 'All eSIMs' },
            crumbs: { popular: 'Popular', local: 'Local eSIMs', regional: 'Regional eSIMs', global: 'Global eSIMs', unlimited: 'Unlimited', all: 'All eSIMs' },
            subtitles: {
                popular: 'Explore our most popular eSIMs. Packages start from the shown price.',
                local: 'Single-country eSIMs. Packages start from the shown price.',
                regional: 'Multi-country regional eSIMs. Packages start from the shown price.',
                global: 'Worldwide coverage eSIMs. Packages start from the shown price.',
                unlimited: 'Destinations that sell an unlimited data plan. Packages start from the shown price.',
                all: 'Every eSIM we offer. Packages start from the shown price.',
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
            },
        }"
        class="mx-auto w-full max-w-[1550px] px-4 py-8 sm:px-6 lg:px-8 lg:py-12"
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
            $esimSlides = [
                [
                    'illo'    => 'illos.esim-travelers',
                    'heading' => \App\Models\SiteSetting::get('esim.promo1_heading', 'Feel the freedom of unlimited data'),
                    'text'    => \App\Models\SiteSetting::get('esim.promo1_text', 'Go ahead and watch that video, listen to that song, download that app. Explore our eSIM data packages for an uninterrupted connection in 190+ countries.'),
                ],
                [
                    'illo'    => 'illos.esim-people',
                    'heading' => \App\Models\SiteSetting::get('esim.promo2_heading', 'Stay connected the moment you land'),
                    'text'    => \App\Models\SiteSetting::get('esim.promo2_text', 'From the airport gate to the boardroom, travellers, freelancers and remote teams get instant data in 190+ countries. No roaming bills, no SIM swaps.'),
                ],
                [
                    'illo'    => 'illos.esim-globe',
                    'heading' => \App\Models\SiteSetting::get('esim.promo3_heading', 'Set up in minutes, before you even pack'),
                    'text'    => \App\Models\SiteSetting::get('esim.promo3_text', 'Buy your data plan, scan the QR code and you are ready to go. No shop queues, no plastic SIM to lose, and your usual number keeps working for calls and messages.'),
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
                            :aria-label="`Go to advert ${i}`"
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
                <h2 class="text-[30px] font-bold tracking-tight text-zinc-900 dark:text-white" x-text="titles[locTab]">Popular locations</h2>
                <p class="mt-1 text-[18px] text-zinc-600 dark:text-zinc-400" x-text="subtitles[locTab]"></p>
            </div>

            <div class="mt-5 flex justify-center">
                <x-esim.location-tabs />
            </div>

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
                        placeholder="Where are you travelling?"
                        aria-label="Search eSIM destinations"
                        autocomplete="off"
                        spellcheck="false"
                        class="w-full rounded-full bg-[#ffffff] py-4 pl-6 pr-[4.5rem] text-[16px] text-zinc-900 outline-none ring-1 ring-zinc-200 transition-shadow placeholder:text-zinc-500 focus:ring-2 focus:ring-[#0c1337]/30 dark:bg-white/5 dark:text-white dark:ring-white/10 dark:placeholder:text-zinc-400"
                    >

                    {{-- Clear sits inside the field, left of the action, and only
                         once there is something to clear. --}}
                    <button
                        type="button"
                        x-show="locSearch !== ''"
                        x-cloak
                        @click="locSearch = ''; $refs.esimSearch.focus()"
                        aria-label="Clear search"
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
                        aria-label="Show matching destinations"
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
                            <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">eSIMs are temporarily unavailable</p>
                            <p class="mt-0.5 text-xs text-amber-800/80 dark:text-amber-200/70">You can browse coverage, but purchases are paused right now. Please check back soon.</p>
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
                        <p class="text-base font-semibold text-zinc-900">No eSIMs available yet</p>
                        <p class="mt-1 text-sm text-zinc-600">We're adding coverage. Check back shortly.</p>
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
                            Browse all {{ number_format($catalog->count()) }} destinations
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
