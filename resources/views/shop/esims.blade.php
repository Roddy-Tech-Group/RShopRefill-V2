@php
    abort_if(! \App\Support\FeatureFlag::on('esims'), 404);

    /** @var \Illuminate\Support\Collection<int, array<string, mixed>> $catalog */
    $catalog = $catalog ?? collect();
@endphp

<x-shop.layout :title="'eSIMs | '.$siteName" :og-image="asset('assets/'.rawurlencode('Esim.webp'))">

    <div
        x-data="{
            locTab: @js($activeScope ?? 'popular'),
            locSearch: '',
            titles: { popular: 'Popular locations', local: 'Local eSIMs', regional: 'Regional eSIMs', global: 'Global eSIMs', all: 'All eSIMs' },
            crumbs: { popular: 'Popular', local: 'Local eSIMs', regional: 'Regional eSIMs', global: 'Global eSIMs', all: 'All eSIMs' },
            subtitles: {
                popular: 'Explore our most popular eSIMs. Packages start from the shown price.',
                local: 'Single-country eSIMs. Packages start from the shown price.',
                regional: 'Multi-country regional eSIMs. Packages start from the shown price.',
                global: 'Worldwide coverage eSIMs. Packages start from the shown price.',
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
        class="mx-auto w-full max-w-[1140px] px-4 py-8 sm:px-6 lg:py-12"
    >

        {{-- Mobile category picker (dark pill + slide-up sheet). --}}
        <div class="mb-4 sm:hidden">
            <x-shop.category-picker active="esims" />
        </div>

        {{-- ── Hero ───────────────────────────────────────────────────────────
             Three eSIM adverts. Illustrations are fixed inline SVGs (two travel
             scenes + a World Cup scene); the heading + copy are admin-editable on
             the System Settings page (group "esim"), defaults below mirror the
             seeded rows. Mobile = swipeable carousel; desktop = 3 cards. The
             section itself inherits the page background (no photo / bubbles). --}}
        @php
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
                    'illo'    => 'illos.esim-worldcup',
                    'heading' => \App\Models\SiteSetting::get('esim.promo3_heading', 'Heading to the World Cup? Travel data sorted'),
                    'text'    => \App\Models\SiteSetting::get('esim.promo3_text', 'Follow every match across the host cities without hunting for WiFi. Activate a travel eSIM before you fly and stay online from kickoff to the final whistle.'),
                ],
            ];
        @endphp
        <section class="relative">
            {{-- Desktop: the three adverts as side-by-side cards. --}}
            <div class="hidden gap-5 lg:grid lg:grid-cols-3">
                @foreach ($esimSlides as $slide)
                    <article class="flex flex-col overflow-hidden rounded-[20px] bg-[#0c1a36] p-4 border border-zinc-300 dark:border-zinc-700">
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
        <section id="esim-locations" class="mt-10 scroll-mt-24">
            <x-esim.location-tabs />

            {{-- Global product search (all categories). --}}
            <x-shop.product-search class="mt-5" />

            <div class="mt-6">
                <h2 class="text-[30px] font-bold tracking-tight text-zinc-900" x-text="titles[locTab]">Popular locations</h2>
                <p class="mt-1 text-[18px] text-zinc-600" x-text="subtitles[locTab]"></p>

                @if ($catalog->isNotEmpty())
                    <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($catalog as $item)
                            <x-esim.location-card :item="$item" />
                        @endforeach
                    </div>
                @else
                    <div class="mt-6 rounded-[12px] bg-white px-6 py-16 text-center ring-1 ring-zinc-200">
                        <p class="text-base font-semibold text-zinc-900">No eSIMs available yet</p>
                        <p class="mt-1 text-sm text-zinc-600">We're adding coverage. Check back shortly.</p>
                    </div>
                @endif
            </div>
        </section>

    </div>

    {{-- First-visit "where are you going?" eSIM nudge (once per session). --}}
    <x-esim.destination-tip />

</x-shop.layout>
