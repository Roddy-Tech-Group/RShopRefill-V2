{{--
    Hero highlight cards, right under the hero. Apple-marketing-style tiles:
    centered headline + subtitle + CTAs above big product imagery, whole card
    clickable. Desktop rests with all cards on screen, then the row slides
    leftward with page scroll (see the data-hero-cards block in app.js);
    mobile is a snap carousel with smooth native touch scrolling.

    CMS-managed via admin > Content > Hero Cards (hero_cards table). Cards
    stay white with dark copy in BOTH colour modes, the way Apple keeps photo
    tiles light in dark mode. A card whose image file is missing is skipped
    (and the section disappears entirely when none survive) so a bad path
    can never render a broken tile.
--}}
@php
    $heroCards = \App\Models\HeroCard::enabledCached()->filter->imageExists();
@endphp

@if ($heroCards->isNotEmpty())
{{-- data-hero-cards: hook for the scroll-scrubbed leftward slide in app.js --}}
<section data-reveal data-hero-cards aria-label="Hero highlights" class="mb-8">
    {{-- One full-bleed overflowing row at every size (.full-bleed
         escapes the page container; the pl-[max(...)] values line the first
         card up with the content column, same as the reviews carousel). --}}
    {{-- No scroll-smooth and NO scroll-snap here: on mobile app.js lerps this
         container's scrollLeft in step with the page scroll, and both would
         fight the frame-by-frame position writes (snap re-snapping mid-drive
         was the mobile jank). py-3 gives the card ring/shadow room to
         breathe: an overflow-x container clips vertically too, so without it
         the top and bottom edges of the cards get cut off (same trick as the
         reviews carousel). --}}
    <div data-hero-cards-track class="full-bleed overflow-x-auto py-3 pl-[max(1rem,calc((100vw-1550px)/2+1rem))] pr-4 [scrollbar-width:none] [-webkit-overflow-scrolling:touch] [&::-webkit-scrollbar]:hidden sm:pl-[max(1.5rem,calc((100vw-1550px)/2+1.5rem))] sm:pr-6 lg:pl-[max(2rem,calc((100vw-1550px)/2+2rem))] lg:pr-8">
        <div data-reveal-group data-hero-cards-row class="flex w-max gap-4 sm:w-full sm:gap-5">
            @foreach ($heroCards as $card)
                {{-- sm+: flex-1 fits every card on screen at rest; the scroll
                     animation then slides the whole row leftward. --}}
                <article data-reveal-item wire:key="hero-card-{{ $card->id }}" class="group relative flex aspect-square w-[86vw] max-w-[440px] shrink-0 snap-start flex-col overflow-hidden rounded-[10px] bg-white ring-1 ring-zinc-200 shadow-sm sm:w-auto sm:max-w-none sm:flex-1 dark:ring-white/10">
                    {{-- Copy sits in its own centered block above the image, Apple-tile style --}}
                    <div class="p-5 text-center sm:p-7 sm:pb-5">
                        <h3 class="text-2xl font-bold leading-tight text-zinc-900 sm:text-3xl">{{ $card->title }}</h3>
                        @if ($card->subtitle)
                            <p class="mx-auto mt-1.5 max-w-[28ch] text-sm leading-snug text-zinc-600 sm:text-base">{{ $card->subtitle }}</p>
                        @endif

                        {{-- Centered CTAs. They sit above the card-wide overlay
                             link (z-20 vs z-10) so they win the click. --}}
                        @if ($card->learn_url || $card->buy_url)
                            <div class="mt-4 flex items-center justify-center gap-2">
                                @if ($card->learn_url)
                                    <a
                                        href="{{ $card->learn_url }}"
                                        wire:navigate
                                        class="relative z-20 inline-flex items-center rounded-[12px] bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700"
                                    >
                                        Learn more
                                    </a>
                                @endif
                                @if ($card->buy_url)
                                    <a
                                        href="{{ $card->buy_url }}"
                                        wire:navigate
                                        class="relative z-20 inline-flex items-center rounded-[12px] bg-zinc-900 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-zinc-800"
                                    >
                                        Buy now
                                    </a>
                                @endif
                            </div>
                        @endif
                    </div>

                    {{-- Image fills the rest of the card below the copy. The
                         focal point comes from the CMS (inline style, so any
                         admin value works without a CSS rebuild). --}}
                    <div class="relative min-h-0 flex-1">
                        <img
                            src="{{ $card->imageUrl() }}"
                            alt=""
                            style="object-position: {{ $card->focal_x }}% 50%;"
                            class="absolute inset-0 h-full w-full object-cover transition-transform duration-500 ease-out group-hover:scale-105"
                            loading="lazy"
                        >
                    </div>

                    @if ($card->link_url)
                        <a href="{{ $card->link_url }}" wire:navigate class="absolute inset-0 z-10" aria-label="{{ $card->title }}"></a>
                    @endif
                </article>
            @endforeach
        </div>
    </div>
</section>
@endif
