{{--
    eSIMs, flights and stays row.

    The eSIM half is four branded destination cards rendered by
    <x-esim.regional-card>, fed by App\Support\EsimCardPresenter. It has been
    three things: two cross-fading SVG scenes, then flag tiles, then exported
    PNG cards. The PNGs are gone because their design could not be corrected -
    logo size, label letter-spacing and half-drawn feature icons were baked
    into the pixels - and because one file per destination does not survive a
    catalog that changes whenever suppliers sync.

    Flights and Stays are still photo cards with a dark backdrop-blur strip
    carrying the icon, title and subtitle. They are coming-soon teasers, so the
    strip is doing the explaining. They render as their own row beneath the
    eSIM cards, under their own heading, since the two sets share neither a
    card shape nor a column count.

    Photos for the two teasers live in public/assets/:
      - card-flights.jpg  (plane wing above sky/ocean)
      - card-stays.jpg    (resort, palms, pool)
--}}
@php
    // Flights and Stays are coming-soon teasers behind their own flags.
    $showFlights = \App\Support\FeatureFlag::on('flights', false);
    $showStays = \App\Support\FeatureFlag::on('stays', false);

    // Feature A, same as the /esims page: when the category is off the cards
    // stay visible but greyed and inert rather than vanishing. Without this
    // the home row went on selling a category the rest of the storefront had
    // already switched off.
    $esimsEnabled = \App\Support\FeatureFlag::on('esims');

    // The whole branded family, scrolled rather than cropped to four. The
    // presenter orders regions first, so a visitor meets the widest-reach
    // products before the single-country ones.
    $esimCards = \App\Support\EsimCardPresenter::family();

    $teaserCols = match ((int) $showFlights + (int) $showStays) {
        2 => 'sm:grid-cols-2',
        default => 'sm:grid-cols-1',
    };

    // The eSIM heading is this row's own advert copy, not a label naming
    // whatever is switched on, so Flights and Stays take their own heading
    // above their teaser row. Neither set can leave a dangling title.
    $esimHeading = 'Stay Connected in 190+ Countries with Travel eSIMs';

    $teaserHeading = match (true) {
        $showFlights && $showStays => 'Flights & stays',
        $showFlights => 'Flights',
        $showStays => 'Stays',
        default => null,
    };
@endphp

@if ($esimCards->isNotEmpty() || $teaserHeading)
<section data-reveal aria-label="{{ $esimCards->isNotEmpty() ? $esimHeading : $teaserHeading }}">

    @if ($esimCards->isNotEmpty())
    {{-- Its own carousel rather than <x-home.brand-row>'s. That one sizes
         cards from a fixed per-breakpoint table and pins anything under 640px
         to 160px wide, which is right for a brand logo tile and far too narrow
         for a card carrying a heading, a description and a feature strip.
         Widening it there would have changed every gift-card row on the page. --}}
    <div
        x-data="{
            canPrev: false,
            canNext: true,
            // The track spans the page (.full-bleed), so the FIRST card would
            // start at the viewport edge rather than under the heading. Pad the
            // list by this section's own distance from the left instead - the
            // same trick x-home.brand-row uses - and match scroll-padding so
            // snap does not scroll that padding straight back off.
            align() {
                const t = this.$refs.track;
                const list = this.$refs.list;
                if (! t || ! list) { return; }
                const minPad = window.innerWidth >= 1024 ? 32 : (window.innerWidth >= 640 ? 24 : 16);
                const apply = () => {
                    const padLeft = Math.max(minPad, Math.round(this.$el.getBoundingClientRect().left));
                    list.style.paddingLeft = padLeft + 'px';
                    t.style.scrollPaddingLeft = padLeft + 'px';
                };
                // Phones get the largest card that still leaves two whole ones
                // on screen - the same measured rule brand-row uses, reading
                // the same padding and gap, so this row and the gift rows land
                // on identical widths. Floored at 160 so it can only grow: the
                // formula on a 360px screen computes ~151, which would make
                // today's cards smaller rather than larger.
                const sizeCards = () => {
                    if (window.innerWidth >= 640) { list.style.removeProperty('--esim-card-w'); return; }
                    const cs = getComputedStyle(list);
                    const padX = (parseFloat(cs.paddingLeft) || 0) + (parseFloat(cs.paddingRight) || 0);
                    const gap = parseFloat(cs.columnGap) || 16;
                    list.style.setProperty('--esim-card-w', Math.max(160, Math.floor((t.clientWidth - padX - gap) / 2)) + 'px');
                };
                apply();
                sizeCards();
                // Re-settle once after entrance animations, exactly as brand-row
                // does: a rect read mid-animation can come back near zero.
                setTimeout(() => { apply(); sizeCards(); }, 700);
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
                const card = t.querySelector('[data-esim-slide]');
                // Read the gap rather than hardcoding it - it is 12px below
                // `sm` and 20px above, and a wrong step drifts a few pixels
                // further out of alignment on every click.
                const gap = parseFloat(getComputedStyle(t).columnGap) || 0;
                const step = card ? card.getBoundingClientRect().width + gap : t.clientWidth * 0.8;
                // Page by whole cards, leaving one on screen for context.
                const pages = Math.max(1, Math.floor(t.clientWidth / step) - 1);
                const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                t.scrollBy({ left: dir * step * pages, behavior: reduce ? 'auto' : 'smooth' });
            },
        }"
        {{-- Re-measure on the element's OWN width, not just the window's: the
             dashboard pane resizes when the sidebar folds and fires no window
             resize, which leaves the track at its old width. --}}
        x-init="
            $nextTick(() => { align(); refresh(); });
            if (window.ResizeObserver) {
                let seen = 0, queued = false;
                $el._exploreObserver = new ResizeObserver(() => {
                    const w = Math.round($el.getBoundingClientRect().width);
                    if (! w || w === seen || queued) { return; }
                    seen = w;
                    queued = true;
                    requestAnimationFrame(() => { queued = false; align(); refresh(); });
                });
                $el._exploreObserver.observe($el);
            }
        "
        x-on:livewire:navigating.window="$el._exploreObserver?.disconnect()"
        x-on:livewire:navigated.window="$nextTick(() => { align(); refresh(); })"
    >
        <div class="mb-4 flex items-end justify-between gap-4">
            <div class="min-w-0">
                <h2 class="text-lg font-bold text-zinc-900 sm:text-[30px] sm:tracking-tight dark:text-white">{{ $esimHeading }}</h2>
            </div>

            {{-- The shared cluster, same as the gift-card rows and the reviews
                 row header. The arrows name this row so a screen reader can
                 tell it apart from the gift-card carousels above it. --}}
            <x-ui.row-controls
                :href="route('shop.esims')"
                prev="nudge(-1)"
                next="nudge(1)"
                prev-disabled="! canPrev"
                next-disabled="! canNext"
                prev-label="Previous eSIMs"
                next-label="More eSIMs"
            />
        </div>

        {{-- Native horizontal scroll: touch and trackpad keep their own
             momentum, and the arrows drive scrollBy(). py-2 leaves room for
             the cards' hover lift, which would otherwise clip against
             overflow-x. --}}
        <div
            x-ref="track"
            @scroll.passive="refresh()"
            @resize.window.debounce.150ms="align(); refresh()"
            {{-- .full-bleed spans the page's usable width (see app.css: it
                 centres against body's own --page-w rather than 100vw, so the
                 scrollbar gutter can never make it a few px too wide). The
                 gift-card rows do exactly this; the eSIM row used to stop at
                 the content column, which left a dead gutter down the right of
                 the page while the rows above ran off the edge. --}}
            class="full-bleed snap-x snap-mandatory overflow-x-auto py-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden [-webkit-overflow-scrolling:touch]"
        >
            {{-- pl-* here is only a pre-JS fallback; align() overwrites it with
                 this section's real offset so card one sits under the heading.
                 pr-* is the trailing breathing room at the far end.

                 Three deep on a phone, two from `sm` up: the same column-
                 filling grid the gift-card and top-up rows use (their
                 mobile-rows="3" and rows="2"), so every row on the home page
                 stacks alike. grid-flow-col fills top to bottom before starting
                 the next column, which keeps the cards in reading order as it
                 scrolls. --}}
            <div
                x-ref="list"
                role="list"
                class="grid w-max grid-flow-col grid-rows-[repeat(3,auto)] gap-4 pl-4 pr-4 sm:grid-rows-[repeat(2,auto)] sm:gap-5 sm:pl-6 sm:pr-6 lg:pl-8 lg:pr-8"
            >
            @foreach ($esimCards as $card)
                {{-- Below `sm`, two whole cards have to fit the screen: the track's own
                     16px padding each side plus the 12px gap come out of the viewport
                     first, and what is left is halved. From `sm` up the cards go back
                     to a comfortable fixed width and simply scroll. --}}
                {{-- Exactly the gift-card tile widths (160 / 200 / 280), so the
                     two rows read as one system down the page. The card scales
                     off its OWN width via container queries rather than the
                     viewport, because the same component also renders at ~350px
                     in the /esims grid. 160px on a phone still leaves two whole
                     cards on screen, which was the earlier requirement. --}}
                <div data-esim-slide role="listitem" class="w-[var(--esim-card-w,10rem)] shrink-0 snap-start sm:w-[200px] lg:w-[280px]">
                    <x-esim.regional-card
                        variant="tile"
                        :name="$card['name']"
                        :type="$card['type']"
                        :country-count="$card['countryCount']"
                        :description="$card['description']"
                        :accent-color="$card['accentColor']"
                        :flag-urls="$card['flagUrls']"
                        :flag-display="$card['flagDisplay']"
                        :background-pattern="$card['backgroundPattern']"
                        :hotspot-supported="$card['hotspotSupported']"
                        :network-types="$card['networkTypes']"
                        :availability="$esimsEnabled ? $card['availability'] : 'unavailable'"
                        :destination-url="$card['destinationUrl']"
                        :from-price="$card['fromPrice']"
                    />
                </div>
            @endforeach
            </div>
        </div>
    </div>
    @endif

    @if ($teaserHeading)
    {{-- The teasers carry their own heading now that the eSIM one is eSIM
         copy. Only spaced off the cards above when there ARE cards above. --}}
    <div class="mb-4 min-w-0 {{ $esimCards->isNotEmpty() ? 'mt-12' : '' }}">
        <h2 class="text-lg font-bold text-zinc-900 sm:text-[30px] sm:tracking-tight dark:text-white">{{ $teaserHeading }}</h2>
    </div>
    <div class="grid grid-cols-1 gap-5 {{ $teaserCols }}">

        {{-- Flights (auto-fades between travel.webp and flight2.webp every 4 seconds, pauses on hover) --}}
        @if ($showFlights)
        <article
            x-data="{
                images: ['{{ asset('assets/travel.webp') }}', '{{ asset('assets/flight2.webp') }}'],
                current: 0,
                paused: false,
                init() {
                    setInterval(() => {
                        if (this.paused) return;
                        this.current = (this.current + 1) % this.images.length;
                    }, 4000);
                }
            }"
            @mouseenter="paused = true"
            @mouseleave="paused = false"
            class="group relative aspect-[16/10] overflow-hidden rounded-[12px] ring-1 ring-zinc-200 shadow-sm"
        >
            <template x-for="(src, i) in images" :key="src">
                <img
                    :src="src"
                    alt="Flights"
                    :class="current === i ? 'opacity-100' : 'opacity-0'"
                    class="absolute inset-0 h-full w-full object-cover transition-opacity duration-700 ease-in-out group-hover:scale-105"
                    loading="lazy"
                >
            </template>
            <div class="absolute inset-x-0 bottom-0 h-[35%] bg-black/55 backdrop-blur-[2px]" aria-hidden="true"></div>
            <div class="absolute inset-x-0 bottom-0 p-5 text-white">
                <div class="flex items-center gap-2">
                    <img src="{{ asset('assets/' . rawurlencode('flight 2.svg')) }}" alt="" class="h-5 w-5 shrink-0 brightness-0 invert" loading="lazy">
                    <h3 class="text-xl font-bold leading-tight">Flights</h3>
                </div>
                <p class="mt-1 text-sm leading-snug text-white/90">Travel smarter with secure bookings, fast confirmations, and global access from anywhere in the world.</p>
            </div>
        </article>
        @endif

        {{-- Stays (auto-fades between stay.webp and stay2.webp every 4 seconds, pauses on hover) --}}
        @if ($showStays)
        <article
            x-data="{
                images: ['{{ asset('assets/stay.webp') }}', '{{ asset('assets/stay2.webp') }}'],
                current: 0,
                paused: false,
                init() {
                    setInterval(() => {
                        if (this.paused) return;
                        this.current = (this.current + 1) % this.images.length;
                    }, 4000);
                }
            }"
            @mouseenter="paused = true"
            @mouseleave="paused = false"
            class="group relative aspect-[16/10] overflow-hidden rounded-[12px] ring-1 ring-zinc-200 shadow-sm"
        >
            <template x-for="(src, i) in images" :key="src">
                <img
                    :src="src"
                    alt="Stays"
                    :class="current === i ? 'opacity-100' : 'opacity-0'"
                    class="absolute inset-0 h-full w-full object-cover transition-opacity duration-700 ease-in-out group-hover:scale-105"
                    loading="lazy"
                >
            </template>
            <div class="absolute inset-x-0 bottom-0 h-[35%] bg-black/55 backdrop-blur-[2px]" aria-hidden="true"></div>
            <div class="absolute inset-x-0 bottom-0 p-5 text-white">
                <div class="flex items-center gap-2">
                    <img src="{{ asset('assets/' . rawurlencode('stay 2.svg')) }}" alt="" class="h-5 w-5 shrink-0 brightness-0 invert" loading="lazy">
                    <h3 class="text-xl font-bold leading-tight">Stays</h3>
                </div>
                <p class="mt-1 text-sm leading-snug text-white/90">Turn digital currency into real-world travel, comfort, and unforgettable experiences.</p>
            </div>
        </article>
        @endif

    </div>
    @endif
</section>
@endif
