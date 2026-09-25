{{--
    Storefront hero. Light theme. Centered headline + CTAs + announcement
    banner, sitting over a static blue dotted backdrop.

    When the admin uploads media to the built-in hero (admin > Content >
    Hero Sections), $mediaPath/$mediaType replace the dotted backdrop with a
    full-bleed image or video under a light scrim; everything else stays the
    designed original.
--}}
@props(['mediaPath' => null, 'mediaType' => null])

<section class="relative w-full overflow-hidden">

    {{-- Static dotted world map backdrop. Pure CSS, no JS, so it renders with
         the page and never blocks interaction. The outer layer fades the map
         out at the top and bottom only, so it runs the full width of the page;
         the inner layer paints the dots. --}}
    @php
        // Versioned by mtime so a replaced map is not served from a stale cache.
        $worldMapUrl = asset('assets/dotted-world-map.svg').'?v='.filemtime(public_path('assets/dotted-world-map.svg'));
    @endphp
    <style>
        .roddy-dots-bg {
            position: absolute;
            inset: 0;
            pointer-events: none;
            -webkit-mask-image: linear-gradient(to bottom, transparent 0%, #000 12%, #000 88%, transparent 100%);
            mask-image: linear-gradient(to bottom, transparent 0%, #000 12%, #000 88%, transparent 100%);
        }
        /* The SVG's own dots are a dark navy, so it is used as a mask rather
           than an image: only its shape is kept, and the colour comes from
           background-color. That keeps the soft blue-200 in light mode and
           the faint grey in dark, both at full strength.

           The file's 1600-wide viewBox carries blank margin around the dots
           (they run from x 127 to 1517.5), so the layer overhangs the hero by
           that margin, scaled: 127/1390.5 of the width on the left and
           82.5/1390.5 on the right. The map is then pushed 4% to the left and
           grown 4% so its right edge still meets the page: both margins scale
           by 1.04 and the left one takes the extra 4%. On wide screens `cover`
           fits the map to the layer's width, so the rightmost dots land on the
           page edge and the leftmost sit 4% past it. Narrow screens stay
           height-bound and crop to the middle of the map, as before. */
        .roddy-world-map {
            position: absolute;
            top: 0;
            bottom: 0;
            left: -13.50%;
            right: -6.17%;
            background-color: rgb(191 219 254);
            -webkit-mask: url('{{ $worldMapUrl }}') center / cover no-repeat;
            mask: url('{{ $worldMapUrl }}') center / cover no-repeat;
        }
        .dark .roddy-world-map {
            background-color: rgba(161, 161, 170, 0.30);
        }
        /* The readability scrim (radial gradient wash) was removed per request -
           the hero now shows the dotted backdrop with no gradient over it. */
    </style>

    @if ($mediaPath)
        @if ($mediaType === 'video')
            <video src="{{ asset('assets/'.$mediaPath) }}" class="absolute inset-0 h-full w-full object-cover" autoplay muted loop playsinline aria-hidden="true"></video>
        @else
            <img src="{{ asset('assets/'.$mediaPath) }}" alt="" class="absolute inset-0 h-full w-full object-cover">
        @endif
        {{-- Light scrim so the dark headline stays readable over any footage --}}
        <div class="absolute inset-0 bg-white/60" aria-hidden="true"></div>
    @else
        <div class="roddy-dots-bg" aria-hidden="true">
            <div class="roddy-world-map"></div>
        </div>
    @endif

    {{-- Content over the dots (constrained to 1200px, dots stay full-width) --}}
    <div class="relative z-10 mx-auto max-w-[1200px] px-3 pt-10 pb-14 text-center sm:px-10 sm:pt-20 sm:pb-24 lg:px-14 lg:pt-24 lg:pb-32">

        @php
            // Shared heading classes used by both the visible h1 and the glowing overlay copy
            $headingClass = 'mx-auto my-0 max-w-[1300px] p-0 text-center text-[34px] leading-[1.15] tracking-tight text-zinc-900 sm:text-[42px] md:text-[54px] lg:text-[64px] lg:leading-[1.1]';
        @endphp

        <div
            data-anim="hero-headline"
            x-data="{ x: -9999, y: -9999,
                track(e) {
                    const r = this.$el.getBoundingClientRect();
                    this.x = e.clientX - r.left;
                    this.y = e.clientY - r.top;
                }
            }"
            @mousemove="track($event)"
            class="relative"
        >
            {{-- Base (visible) heading --}}
            <h1 class="{{ $headingClass }}">
                {{ __('home.hero_heading_line_one') }}<br class="hidden lg:inline">
                {{ __('home.hero_heading_line_two') }}
            </h1>

            {{-- Glow overlay: bright green copy masked to a small circle that follows
                 the cursor. When the cursor leaves the heading, x/y stay at the last
                 position so the green letters at that spot remain visible. --}}
            <div
                aria-hidden="true"
                class="pointer-events-none absolute inset-0"
                :style="`mask-image: radial-gradient(circle 170px at ${x}px ${y}px, #000 0%, #000 80%, transparent 100%); -webkit-mask-image: radial-gradient(circle 170px at ${x}px ${y}px, #000 0%, #000 80%, transparent 100%);`"
            >
                <div class="{{ $headingClass }} hero-glow-accent">
                    {{ __('home.hero_heading_line_one') }}<br class="hidden lg:inline">
                    {{ __('home.hero_heading_line_two') }}
                </div>
            </div>
        </div>

        <p data-anim="hero-subtitle" class="card-text-face mx-auto mt-5 max-w-2xl text-zinc-900">
            {{ __('home.hero_subtitle') }}
        </p>

        {{-- CTAs --}}
        <div data-anim="hero-ctas" class="mt-8 flex flex-wrap items-center justify-center gap-4">

            {{-- Shop Gift Cards (primary) --}}
            <a href="{{ route('shop.gift-cards') }}" wire:navigate class="group inline-flex h-12 items-center gap-2 rounded-full bg-blue-600 px-6 text-base font-semibold text-white shadow-lg shadow-blue-600/20 transition-transform hover:-translate-y-0.5 hover:bg-blue-700">
                {{ __('home.hero_cta_gift_cards') }}
                <svg viewBox="0 0 24 24" class="h-5 w-5 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 8a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v8a3 3 0 0 1 -3 3H6a3 3 0 0 1 -3 -3z"/>
                    <path d="m7 16 3 -3 3 3"/>
                    <path d="M8 13c-0.789 0 -2 -0.672 -2 -1.5S6.711 10 7.5 10c1.128 -0.02 2.077 1.17 2.5 3 0.423 -1.83 1.372 -3.02 2.5 -3 0.789 0 1.5 0.672 1.5 1.5S12.789 13 12 13H8z"/>
                </svg>
            </a>

            {{-- Explore eSIMs - pure transparent glass that adapts to theme:
                 dark text + the storefront's #dee3e1 ring on the light hero,
                 white text + white ring on the dark one. Backdrop blur stays the
                 same; only the visible contrast pieces flip. --}}
            <a href="{{ route('shop.esims') }}" wire:navigate class="group inline-flex h-12 items-center gap-2 rounded-full bg-transparent backdrop-blur-md px-6 text-base font-semibold text-zinc-900 ring-1 ring-[#dee3e1] transition-all hover:-translate-y-0.5 hover:bg-zinc-900/5 hover:ring-[#c9d0cd] dark:text-white dark:ring-white/30 dark:hover:bg-white/10 dark:hover:ring-white/50">
                {{ __('home.hero_cta_esims') }}
                <svg viewBox="0 0 24 24" class="h-5 w-5 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M6 3h8.5L19 7.5V20a1 1 0 0 1 -1 1H6a1 1 0 0 1 -1 -1V4a1 1 0 0 1 1 -1z"/>
                    <path d="M9 11h3v6"/>
                    <path d="M15 17v0.01"/>
                    <path d="M15 14v0.01"/>
                    <path d="M15 11v0.01"/>
                    <path d="M9 14v0.01"/>
                    <path d="M9 17v0.01"/>
                </svg>
            </a>

        </div>

        @php
            // Roddy Custom Hero animation with GSAP: unified expandable chip bar.
            // Each chip starts as a small circle showing only its PNG. The active
            // chip expands to reveal its title, subtitle, and arrow. The cycle
            // advances right to left, one chip every 6 seconds. Pauses on hover.
            // Live catalog stats. Cached for 10 minutes so the homepage doesn't
            // hit the DB on every render. Floor to a "marketing round" number
            // (nearest thousand for products, nearest ten for countries) so the
            // hero copy stays clean even as the catalog grows.
            $catalogStats = \Illuminate\Support\Facades\Cache::remember(
                'hero.catalog_stats',
                now()->addMinutes(10),
                fn () => [
                    'variants'  => (int) \App\Models\ProductVariant::where('is_available', true)->count(),
                    'countries' => (int) \App\Models\Product::where('is_active', true)
                        ->whereNotNull('country_code')
                        ->distinct('country_code')
                        ->count('country_code'),
                ],
            );
            $productsRounded = number_format(max(1000, intdiv($catalogStats['variants'], 1000) * 1000));
            $countriesRounded = max(10, intdiv($catalogStats['countries'], 10) * 10);

            // Each chip's `href` resolves to the storefront surface that best
            // matches the teaser, so clicking "200+ countries" lands on /esims.
            // `illo` swaps the webp for an inline animated SVG (no image request);
            // chips without one keep their webp.
            $heroChips = [
                [
                    'illo'     => 'gift',
                    'title'    => __('home.chip_products_title', ['count' => $productsRounded]),
                    'subtitle' => __('home.chip_products_subtitle'),
                    'href'     => route('shop.gift-cards'),
                ],
                [
                    'illo'     => 'globe',
                    'title'    => __('home.chip_countries_title', ['count' => $countriesRounded]),
                    'subtitle' => __('home.chip_countries_subtitle', ['count' => $countriesRounded]),
                    'href'     => route('shop.esims'),
                ],
                [
                    'illo'     => 'shield',
                    'title'    => __('home.chip_reviews_title'),
                    // Describes what /reviews actually shows. The page renders
                    // only the reviews our own form collected, so promising
                    // Trustpilot and Google feedback there would be a claim the
                    // destination cannot keep.
                    'subtitle' => __('home.chip_reviews_subtitle'),
                    'href'     => route('shop.reviews'),
                ],
                [
                    'illo'     => 'mobile',
                    'title'    => __('home.chip_devices_title'),
                    'subtitle' => __('home.chip_devices_subtitle'),
                    'href'     => route('shop.mobile-app'),
                ],
                [
                    'illo'     => 'search',
                    'title'    => __('home.chip_search_title'),
                    'subtitle' => __('home.chip_search_subtitle'),
                    'href'     => route('shop.topups'),
                ],
            ];
        @endphp

        {{-- Mobile chip slide animations - active chip slides in from the left
             (when ping-pong direction is leftward) or from the right (rightward).
             Disabled on lg+ so the desktop expand/collapse animation is the only
             motion at that breakpoint. --}}
        <style>
            @keyframes rshop-chip-slide-from-left {
                from { transform: translateX(-40px); opacity: 0; }
                to   { transform: translateX(0);     opacity: 1; }
            }
            @keyframes rshop-chip-slide-from-right {
                from { transform: translateX(40px); opacity: 0; }
                to   { transform: translateX(0);    opacity: 1; }
            }
            @media (max-width: 1023.98px) {
                .rshop-chip-from-left  { animation: rshop-chip-slide-from-left  600ms cubic-bezier(0.22, 1, 0.36, 1) both; }
                .rshop-chip-from-right { animation: rshop-chip-slide-from-right 600ms cubic-bezier(0.22, 1, 0.36, 1) both; }
            }
            @media (prefers-reduced-motion: reduce) {
                .rshop-chip-from-left, .rshop-chip-from-right { animation: none; }
            }
        </style>

        {{-- Floating expandable chip bar (single row, one chip expanded at a time) --}}
        <div data-anim="hero-banner" class="mx-auto mt-14 w-full max-w-5xl min-h-[84px] sm:min-h-[100px]">
            <div
                x-data="{
                    total: {{ count($heroChips) }},
                    // Index of the chip currently expanded. Starts on the rightmost.
                    current: {{ count($heroChips) - 1 }},
                    // Ping-pong direction: -1 = next chip is one to the LEFT,
                    // +1 = next chip is one to the RIGHT. Exposed in state so
                    // the mobile slide-in animation can pick the right side.
                    direction: -1,
                    paused: false,
                    wait(ms) {
                        return new Promise(r => {
                            const tick = () => {
                                if (this.paused) return setTimeout(tick, 100);
                                r();
                            };
                            setTimeout(tick, ms);
                        });
                    },
                    async cycle() {
                        while (true) {
                            await this.wait(10000);
                            const next = this.current + this.direction;
                            if (next < 0 || next >= this.total) {
                                // Hit an edge: reverse direction and step the other way.
                                this.direction = -this.direction;
                                this.current = this.current + this.direction;
                            } else {
                                this.current = next;
                            }
                        }
                    }
                }"
                x-init="cycle()"
                x-cloak
                @mouseenter="paused = true"
                @mouseleave="paused = false"
                class="flex flex-nowrap items-center justify-center gap-5"
                aria-live="polite"
            >
                @foreach ($heroChips as $i => $chip)
                    <a
                        href="{{ $chip['href'] }}"
                        wire:navigate
                        x-data="{ hovered: false }"
                        @mouseenter="hovered = true"
                        @mouseleave="hovered = false"
                        :class="[
                            current === {{ $i }} ? 'pl-1.5 pr-4 sm:pl-2 sm:pr-5 lg:pr-8' : 'aspect-square justify-center',
                            (current === {{ $i }} && hovered) ? '-translate-y-0.5' : '',
                            current === {{ $i }} ? 'max-lg:w-full' : 'max-lg:hidden',
                            current === {{ $i }} && direction < 0 ? 'rshop-chip-from-left' : '',
                            current === {{ $i }} && direction > 0 ? 'rshop-chip-from-right' : ''
                        ]"
                        class="group inline-flex h-[84px] shrink-0 items-center rounded-full bg-transparent backdrop-blur-md ring-1 ring-[#dee3e1] transition-colors hover:bg-zinc-900/5 hover:ring-[#c9d0cd] dark:ring-white/30 dark:hover:bg-white/10 dark:hover:ring-white/50 lg:transition-all lg:duration-500 lg:ease-out sm:h-[100px]"
                        aria-label="{{ $chip['title'] }}"
                    >
                        {{-- Icon (always visible) — inline animated SVG when available
                             (no image request), else the webp. The SVG fills the chip. --}}
                        <span class="flex h-[72px] w-[72px] shrink-0 items-center justify-center sm:h-[92px] sm:w-[92px]">
                            @if (! empty($chip['illo']))
                                <x-illo :name="$chip['illo']" class="h-full w-full" />
                            @else
                                <img src="{{ asset('assets/' . rawurlencode($chip['image'])) }}" alt="" class="h-full w-full object-contain" loading="lazy">
                            @endif
                        </span>

                        {{-- Title + subtitle (revealed when this chip is active).
                             Desktop gets a smooth reveal/collapse transition; on
                             mobile the swap is instant so chip changes don't
                             animate awkwardly. --}}
                        <span
                            :class="current === {{ $i }} ? 'flex-1 ml-3 opacity-100 lg:flex-none lg:max-w-[360px]' : 'max-w-0 ml-0 opacity-0'"
                            class="block min-w-0 overflow-hidden whitespace-nowrap text-left lg:transition-all lg:duration-500 lg:ease-out"
                        >
                            <span class="block truncate text-[15px] font-semibold leading-tight text-zinc-900 transition-colors duration-200 group-hover:text-blue-600 sm:text-base">{{ $chip['title'] }}</span>
                            <span class="block truncate text-[13px] leading-tight text-zinc-600">{{ $chip['subtitle'] }}</span>
                        </span>

                        {{-- Reserved area when the chip is active, so the "Show more" can fade in
                             on hover WITHOUT changing the chip's width (which would shift/jitter the
                             whole row). Flat label, single arrow after the text, hover-only. --}}
                        <span
                            :class="current === {{ $i }} ? 'w-[150px] ml-4 opacity-100' : 'w-0 ml-0 opacity-0'"
                            class="relative hidden h-14 shrink-0 items-center justify-end overflow-hidden transition-all duration-500 ease-out lg:flex"
                        >
                            <span
                                :class="(current === {{ $i }} && hovered) ? 'opacity-100 translate-x-0' : 'opacity-0 translate-x-2 pointer-events-none'"
                                class="inline-flex items-center pr-2 text-[15px] font-semibold text-zinc-900 transition-all duration-300 ease-out dark:text-white"
                            >
                                <span class="whitespace-nowrap">{{ __('common.show_more') }}</span>
                                <svg class="ml-1.5 h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M9 6l6 6-6 6"/>
                                </svg>
                            </span>
                        </span>
                    </a>
                @endforeach
            </div>
        </div>

    </div>
</section>
