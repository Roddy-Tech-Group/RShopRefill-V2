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
            </div>
        </section>

    </div>

    {{-- First-visit "where are you going?" eSIM nudge (once per session). --}}
    <x-esim.destination-tip />

</x-shop.layout>
