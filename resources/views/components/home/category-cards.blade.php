{{--
    Category cards under the hero: four tiles (illustration + label +
    supporting line), one per catalogue area. Replaces the old pill quick-nav
    row. A category whose feature flag is off stays visible but greyed +
    non-clickable (same convention as the old tiles).

    Airtime and data bundles are one thing to the customer, so they share a
    single "Mobile Refill" tile that opens the full top-ups listing. The
    separate Data Bundles tile and its ?subcategory=bundles deep link went
    with the merge.

    Layout: full-width stack on mobile, 2-up from sm, 4-up from lg. Four
    tiles divide evenly at both, so neither breakpoint leaves an orphan on
    its last row.

    Each tile carries its OWN call to action at its foot, naming that tile's
    destination rather than repeating one line across all four. It fades in on
    hover from sm up, and stays visible below that, where there is no hover.
--}}
@php
    $gcOn    = \App\Support\FeatureFlag::on('gift_cards');
    $esimOn  = \App\Support\FeatureFlag::on('esims');
    $topupOn = \App\Support\FeatureFlag::on('topups');
    $billOn  = \App\Support\FeatureFlag::on('bills');

    // Each card names its own action. They all said "Check it out", which is
    // the same sentence four times and tells nobody what happens next; the
    // destination differs on every tile, so the button should too.
    $cards = [
        ['label' => __('navigation.gift_cards'),     'icon' => 'digital-gift-cards.svg', 'desc' => __('products.gift_cards_desc'),    'cta' => __('products.cta_gift_cards'), 'url' => $gcOn ? route('shop.gift-cards') : null],
        ['label' => __('navigation.esims'),          'icon' => 'esims.svg',              'desc' => __('products.esims_desc'),         'cta' => __('products.cta_esims'),      'url' => $esimOn ? route('shop.esims') : null],
        ['label' => __('navigation.mobile_refill'),  'icon' => 'mobile-top-ups.svg',     'desc' => __('products.mobile_refill_desc'), 'cta' => __('products.cta_topups'),     'url' => $topupOn ? route('shop.topups') : null],
        ['label' => __('navigation.bill_payments'),  'icon' => 'prepaid-utilities.svg',  'desc' => __('products.bill_payments_desc'), 'cta' => __('products.cta_bills'),      'url' => $billOn ? route('shop.bills') : null],
    ];
@endphp
{{-- sm+: -mt lifts the row up over the hero's bottom edge (the parent
     container's top padding eats part of it, the rest overlaps the hero).
     Mobile keeps the normal flow - no lift. --}}
<section aria-label="Browse categories" class="relative isolate z-10 sm:-mt-20">
    {{-- The blue behind the row - see .cat-cards-wash in app.css. --}}
    <span aria-hidden="true" class="cat-cards-wash"></span>

    {{-- Phone: a snapping carousel. Four full-width cards stacked meant four
         screens of scrolling before the page even started, and the section
         below it was invisible. Each card takes most of the width so the
         next one peeks in and the row reads as swipeable without a hint.
         From sm it is the grid again, unchanged. --}}
    <ul
        data-reveal-group
        class="-mx-4 flex snap-x snap-mandatory gap-4 overflow-x-auto scroll-px-4 px-4 pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden
               sm:mx-0 sm:grid sm:grid-cols-2 sm:gap-4 sm:overflow-visible sm:px-0 sm:pb-0
               lg:grid-cols-4 lg:auto-rows-[minmax(280px,auto)]"
    >
        @foreach ($cards as $card)
            <li data-reveal-item class="w-[80%] shrink-0 snap-start sm:w-auto sm:shrink">
                <a
                    @if ($card['url']) href="{{ $card['url'] }}" wire:navigate @else aria-disabled="true" title="{{ __('products.temporarily_unavailable') }}" @endif
                    @class([
                        'group relative isolate flex h-full flex-col overflow-hidden rounded-[20px] bg-[#ffffff] px-5 pb-6 pt-5 transition-all duration-300 ease-out will-change-transform hover:-translate-y-1 hover:shadow-lg hover:shadow-zinc-900/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-[var(--ash)] dark:hover:shadow-black/40',
                        'pointer-events-none cursor-not-allowed opacity-50 grayscale' => ! $card['url'],
                    ])
                >
                    {{-- The bloom rising from the card's lower edge. Its own
                         layer, under the copy - see .cat-card-glow in app.css. --}}
                    <span aria-hidden="true" class="cat-card-glow"></span>

                    {{-- Full-colour illustrations designed for both themes:
                         no-dark-invert opts out of the global dark-mode SVG
                         invert in app.css.

                         h-16 (64px), up from h-12. w-auto, so each mark keeps
                         its own aspect ratio - these are not all square, and
                         pinning a width would squash the wider ones. --}}
                    <img
                        src="{{ asset('assets/' . rawurlencode($card['icon'])) }}"
                        alt=""
                        class="no-dark-invert h-16 w-auto shrink-0 self-start object-contain transition-transform duration-300 ease-out group-hover:scale-105"
                        loading="lazy"
                    >

                    <span class="mt-4 block text-[19px] font-bold leading-tight text-zinc-900 dark:text-white">{{ $card['label'] }}</span>

                    {{-- mt-auto pins this to the card's lower edge, which is what
                         gives the reference its rhythm: mark and name at the top,
                         a gap, then the supporting line at the bottom.

                         No clamp. Even at four across the text column is only
                         ~270px and these descriptions still run several lines,
                         so a two-line clamp would hide most of every one of
                         them. The cards are grid items with h-full, so they all
                         take the height of the tallest in the row and still
                         finish level without one. --}}
                    <span class="card-text mt-auto block pt-6">{{ $card['desc'] }}</span>

                    {{-- Hover CTA. A span, not a nested <a>: the whole card is
                         already the link, and an anchor inside an anchor is
                         invalid. aria-hidden because the card's own label and
                         supporting line already name the destination - this only
                         adds visual affordance.

                         It stays in flow at full height and only fades, so the
                         card does not resize under the cursor. Below sm it is
                         simply always visible: touch has no hover, and an
                         element that can never reveal itself is just dead space
                         at the foot of every card. --}}
                    <span
                        aria-hidden="true"
                        class="mt-5 block w-full rounded-[12px] bg-gradient-to-r from-[#0a1740] via-[#153a9e] to-[#2563eb] px-4 py-2.5 text-center text-sm font-semibold text-white shadow-sm shadow-blue-900/20 transition-[opacity,transform] duration-300 ease-out sm:translate-y-1 sm:opacity-0 sm:group-hover:translate-y-0 sm:group-hover:opacity-100"
                    >{{ $card['cta'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</section>
