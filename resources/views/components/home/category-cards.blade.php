{{--
    Category cards under the hero: five tiles (illustration + label), one per
    catalogue area. Replaces the old pill quick-nav row. A category whose
    feature flag is off stays visible but greyed + non-clickable (same
    convention as the old tiles). Data Bundles deep-links into the top-ups
    listing pre-filtered to networks that carry data/bundle plans.

    Mobile: cards stack full-width with a one-line description under the
    title. sm+: compact icon+label tiles, 3-up then 5-up (no descriptions).
--}}
@php
    $gcOn    = \App\Support\FeatureFlag::on('gift_cards');
    $esimOn  = \App\Support\FeatureFlag::on('esims');
    $topupOn = \App\Support\FeatureFlag::on('topups');
    $billOn  = \App\Support\FeatureFlag::on('bills');

    $cards = [
        ['label' => 'Gift Cards',     'icon' => 'digital-gift-cards.svg', 'desc' => 'Shop global brands with digital gift cards delivered instantly. Perfect for gaming, streaming, shopping, and more.',       'url' => $gcOn ? route('shop.gift-cards') : null],
        ['label' => 'eSIMs',          'icon' => 'esims.svg',              'desc' => 'Instant travel eSIMs for 200+ destinations. No roaming fees, no physical SIM, and real phone numbers on voice plans.',           'url' => $esimOn ? route('shop.esims') : null],
        ['label' => 'Mobile Top-up',  'icon' => 'mobile-top-ups.svg',     'desc' => 'Recharge mobile numbers worldwide with fast, secure airtime top-ups across leading networks.',           'url' => $topupOn ? route('shop.topups') : null],
        ['label' => 'Data Bundles',   'icon' => 'data-bundles.svg',       'desc' => 'Purchase affordable data bundles and stay online wherever you are with instant activation.',                 'url' => $topupOn ? route('shop.topups', ['subcategory' => 'bundles']) : null],
        ['label' => 'Bill Payments',  'icon' => 'prepaid-utilities.svg',  'desc' => 'Pay utility bills, subscriptions, and everyday services quickly from one secure platform.',           'url' => $billOn ? route('shop.bills') : null],
    ];
@endphp
{{-- sm+: -mt lifts the row up over the hero's bottom edge (the parent
     container's top padding eats part of it, the rest overlaps the hero).
     Mobile keeps the normal flow - no lift. --}}
<section aria-label="Browse categories" class="relative z-10 sm:-mt-20">
    <ul data-reveal-group class="grid grid-cols-1 gap-4 sm:grid-cols-3 sm:gap-4 lg:grid-cols-5">
        @foreach ($cards as $card)
            <li data-reveal-item>
                <a
                    @if ($card['url']) href="{{ $card['url'] }}" wire:navigate @else aria-disabled="true" title="Temporarily unavailable" @endif
                    @class([
                        'flex h-full flex-col items-center justify-center gap-4 rounded-[35px] bg-white px-6 py-9 ring-1 ring-zinc-200/60 transition-all duration-300 ease-out will-change-transform hover:-translate-y-1 hover:shadow-lg hover:shadow-zinc-900/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 sm:px-4 sm:py-9 dark:bg-black dark:ring-zinc-700/60 dark:hover:shadow-black/20',
                        'pointer-events-none cursor-not-allowed opacity-50 grayscale' => ! $card['url'],
                    ])
                >
                    {{-- Full-colour illustrations designed for both themes:
                         no-dark-invert opts out of the global dark-mode SVG
                         invert in app.css --}}
                    <img
                        src="{{ asset('assets/' . rawurlencode($card['icon'])) }}"
                        alt=""
                        class="no-dark-invert h-24 w-auto shrink-0 object-contain"
                        loading="lazy"
                    >
                    <span class="text-center">
                        <span class="block text-lg font-bold text-zinc-900 sm:text-base sm:font-semibold dark:text-white">{{ $card['label'] }}</span>
                        {{-- Description: mobile only - sm+ keeps the compact tile --}}
                        <span class="mx-auto mt-1.5 block max-w-[30ch] text-[15px] leading-relaxed text-zinc-600 sm:hidden dark:text-zinc-300">{{ $card['desc'] }}</span>
                    </span>
                </a>
            </li>
        @endforeach
    </ul>
</section>
