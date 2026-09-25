{{--
    Quick-access category tiles. Pill-shaped, content-sized, single-active state.
    Default: Buy Gift Cards (index 0) is black/active. Hover any tile to activate it;
    leaving the row reverts to the first tile.
    Hidden on mobile (<sm) to remove the horizontal-slide bar; the Quick Actions card
    on the dashboard + the main nav cover discovery on small screens.
--}}
@php
    // Feature A: a category whose feature is off stays visible but greyed + non-clickable.
    $gcOn    = \App\Support\FeatureFlag::on('gift_cards');
    $esimOn  = \App\Support\FeatureFlag::on('esims');
    $topupOn = \App\Support\FeatureFlag::on('topups');
    $billOn  = \App\Support\FeatureFlag::on('bills');
    $tileOff = 'pointer-events-none cursor-not-allowed opacity-50 grayscale';
@endphp
<section aria-label="Browse categories" class="hidden sm:block">
    <div class="-mx-4 overflow-x-auto px-4 py-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden sm:mx-0 sm:overflow-visible sm:px-0 sm:py-0">
        <ul
            x-data="{ active: -1 }"
            @mouseleave="active = -1"
            data-reveal-group
            class="flex w-max gap-3 sm:w-full sm:flex-wrap sm:justify-center sm:gap-3 lg:gap-4"
        >

            {{-- Buy Gift Cards (black default, gray-900 on hover) --}}
            <li data-reveal-item class="shrink-0">
                <a
                    @if ($gcOn) href="{{ route('shop.gift-cards') }}" wire:navigate @else aria-disabled="true" title="Temporarily unavailable" @endif
                    @class(['inline-flex items-center gap-3 rounded-[25px] bg-black px-6 py-2 ring-1 ring-black transition-all duration-300 ease-out will-change-transform hover:-translate-y-0.5 hover:bg-gray-900 hover:ring-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-blue-600 dark:ring-blue-600 dark:hover:bg-blue-700 dark:hover:ring-blue-700', $tileOff => ! $gcOn])
                >
                    <img src="{{ asset('assets/' . rawurlencode('gift cards.svg')) }}" alt="" class="h-6 w-6 shrink-0 object-contain brightness-0 invert" loading="lazy">
                    <span class="text-base font-semibold text-white">Buy Gift Cards</span>
                </a>
            </li>

            {{-- GoMarket (coming soon) - hidden until its feature flag is switched on --}}
            @if (\App\Support\FeatureFlag::on('gomarket', false))
            <li data-reveal-item class="shrink-0">
                <a
                    href="{{ route('shop.gomarket') }}" wire:navigate
                    @mouseenter="active = 2"
                    :class="active === 2 ? 'bg-zinc-900 ring-zinc-900 dark:bg-blue-600 dark:ring-blue-600' : 'bg-transparent ring-zinc-200 hover:ring-zinc-300 dark:ring-zinc-700/60'"
                    class="inline-flex items-center gap-3 rounded-full px-6 py-2 ring-1 transition-all duration-300 ease-out will-change-transform hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                >
                    <img src="{{ asset('assets/' . rawurlencode('Shop.svg')) }}" alt="" :class="active === 2 ? 'brightness-0 invert' : ''" class="h-6 w-6 shrink-0 object-contain transition-all duration-300 ease-out dark:brightness-0 dark:invert" loading="lazy">
                    <span :class="active === 2 ? 'text-white' : 'text-zinc-800 dark:text-white'" class="text-base font-semibold transition-colors duration-300 ease-out">GoMarket</span>
                </a>
            </li>
            @endif

            {{-- eSIMs --}}
            <li data-reveal-item class="shrink-0">
                <a
                    @if ($esimOn) href="{{ route('shop.esims') }}" wire:navigate @else aria-disabled="true" title="Temporarily unavailable" @endif
                    @mouseenter="active = 3"
                    :class="active === 3 ? 'bg-zinc-900 ring-zinc-900 dark:bg-blue-600 dark:ring-blue-600' : 'bg-transparent ring-zinc-200 hover:ring-zinc-300 dark:ring-zinc-700/60'"
                    @class(['inline-flex items-center gap-3 rounded-[25px] px-6 py-2 ring-1 transition-all duration-300 ease-out will-change-transform hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40', $tileOff => ! $esimOn])
                >
                    <img src="{{ asset('assets/' . rawurlencode('esim.svg')) }}" alt="" :class="active === 3 ? 'brightness-0 invert' : ''" class="h-6 w-6 shrink-0 object-contain transition-all duration-300 ease-out dark:brightness-0 dark:invert" loading="lazy">
                    <span :class="active === 3 ? 'text-white' : 'text-zinc-800 dark:text-white'" class="text-base font-semibold transition-colors duration-300 ease-out">eSIMs</span>
                </a>
            </li>

            {{-- Mobile Top-ups --}}
            <li data-reveal-item class="shrink-0">
                <a
                    @if ($topupOn) href="{{ route('shop.topups') }}" wire:navigate @else aria-disabled="true" title="Temporarily unavailable" @endif
                    @mouseenter="active = 4"
                    :class="active === 4 ? 'bg-zinc-900 ring-zinc-900 dark:bg-blue-600 dark:ring-blue-600' : 'bg-transparent ring-zinc-200 hover:ring-zinc-300 dark:ring-zinc-700/60'"
                    @class(['inline-flex items-center gap-3 rounded-[25px] px-6 py-2 ring-1 transition-all duration-300 ease-out will-change-transform hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40', $tileOff => ! $topupOn])
                >
                    <img src="{{ asset('assets/' . rawurlencode('mobile.svg')) }}" alt="" :class="active === 4 ? 'brightness-0 invert' : ''" class="h-6 w-6 shrink-0 object-contain transition-all duration-300 ease-out dark:brightness-0 dark:invert" loading="lazy">
                    <span :class="active === 4 ? 'text-white' : 'text-zinc-800 dark:text-white'" class="text-base font-semibold transition-colors duration-300 ease-out">Mobile Top-ups</span>
                </a>
            </li>

            {{-- Bill Payments --}}
            <li data-reveal-item class="shrink-0">
                <a
                    @if ($billOn) href="{{ route('shop.bills') }}" wire:navigate @else aria-disabled="true" title="Temporarily unavailable" @endif
                    @mouseenter="active = 5"
                    :class="active === 5 ? 'bg-zinc-900 ring-zinc-900 dark:bg-blue-600 dark:ring-blue-600' : 'bg-transparent ring-zinc-200 hover:ring-zinc-300 dark:ring-zinc-700/60'"
                    @class(['inline-flex items-center gap-3 rounded-[25px] px-6 py-2 ring-1 transition-all duration-300 ease-out will-change-transform hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40', $tileOff => ! $billOn])
                >
                    <img :src="active === 5 ? '{{ asset('assets/' . rawurlencode('bill payment.svg')) }}' : '{{ asset('assets/' . rawurlencode('Bills 2.svg')) }}'" alt="" :class="active === 5 ? 'brightness-0 invert' : ''" class="h-6 w-6 shrink-0 object-contain transition-all duration-300 ease-out dark:brightness-0 dark:invert" loading="lazy">
                    <span :class="active === 5 ? 'text-white' : 'text-zinc-800 dark:text-white'" class="text-base font-semibold transition-colors duration-300 ease-out">Bill Payments</span>
                </a>
            </li>

        </ul>
    </div>
</section>
