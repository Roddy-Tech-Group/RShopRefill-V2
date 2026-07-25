{{--
    Category cards under the hero: five flat tiles (illustration + label), one
    per catalogue area. Replaces the old pill quick-nav row. A category whose
    feature flag is off stays visible but greyed + non-clickable (same
    convention as the old tiles). Data Bundles deep-links into the top-ups
    listing pre-filtered to networks that carry data/bundle plans.
--}}
@php
    $gcOn    = \App\Support\FeatureFlag::on('gift_cards');
    $esimOn  = \App\Support\FeatureFlag::on('esims');
    $topupOn = \App\Support\FeatureFlag::on('topups');
    $billOn  = \App\Support\FeatureFlag::on('bills');

    $cards = [
        ['label' => 'Gift Cards',     'icon' => 'digital-gift-cards.svg', 'url' => $gcOn ? route('shop.gift-cards') : null],
        ['label' => 'eSIMs',          'icon' => 'esims.svg',              'url' => $esimOn ? route('shop.esims') : null],
        ['label' => 'Mobile Top-up',  'icon' => 'mobile-top-ups.svg',     'url' => $topupOn ? route('shop.topups') : null],
        ['label' => 'Data Bundles',   'icon' => 'data-bundles.svg',       'url' => $topupOn ? route('shop.topups', ['subcategory' => 'bundles']) : null],
        ['label' => 'Bill Payments',  'icon' => 'prepaid-utilities.svg',  'url' => $billOn ? route('shop.bills') : null],
    ];
@endphp
{{-- sm+: -mt lifts the row up over the hero's bottom edge (the parent
     container's top padding eats part of it, the rest overlaps the hero).
     Mobile keeps the normal flow - no lift. --}}
<section aria-label="Browse categories" class="relative z-10 sm:-mt-20">
    <ul data-reveal-group class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-5">
        @foreach ($cards as $card)
            <li data-reveal-item @class(['col-span-2 sm:col-span-1' => $loop->last])>
                <a
                    @if ($card['url']) href="{{ $card['url'] }}" wire:navigate @else aria-disabled="true" title="Temporarily unavailable" @endif
                    @class([
                        'flex h-full flex-col items-center justify-center gap-4 rounded-[35px] bg-white px-4 py-7 ring-1 ring-zinc-200/60 transition-all duration-300 ease-out will-change-transform hover:-translate-y-1 hover:shadow-lg hover:shadow-zinc-900/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 sm:py-9 dark:bg-[#2f3540] dark:ring-zinc-700/60 dark:hover:shadow-black/20',
                        'pointer-events-none cursor-not-allowed opacity-50 grayscale' => ! $card['url'],
                    ])
                >
                    {{-- Full-colour illustrations designed for both themes:
                         no-dark-invert opts out of the global dark-mode SVG
                         invert in app.css --}}
                    <img
                        src="{{ asset('assets/' . rawurlencode($card['icon'])) }}"
                        alt=""
                        class="no-dark-invert h-20 w-auto shrink-0 object-contain sm:h-24"
                        loading="lazy"
                    >
                    <span class="text-center text-base font-semibold text-zinc-900 dark:text-white">{{ $card['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</section>
