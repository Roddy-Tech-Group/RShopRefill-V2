@props([
    // Slug of the currently-active category:
    // gift-cards | mobile-airtime | esims | bill-payments | flights | stays
    'active' => '',
    // Subcategory links for the active category, built by the page (same shape
    // as the desktop sidebar): ['label' => string, 'url' => string, 'active' => bool].
    'subItems' => [],
    // The list of OTHER storefronts. The nav's category rail already carries it
    // on every page, so a storefront with subcategories of its own can drop the
    // duplicate - matching x-shop.category-sidebar.
    'showCategories' => true,
    // Filter in place instead of navigating - see x-shop.category-sidebar. The
    // sheet closes on pick either way, so the result is visible immediately.
    'clientFilter' => false,
])

@php
    // Route name swaps between storefront + dashboard chrome so picking a
    // category keeps the user on whichever side they entered from.
    $inDashboard = request()->is('dashboard/shop*') && auth()->check();
    $r = fn (string $name) => $inDashboard ? route('dashboard.shop.'.$name) : route('shop.'.$name);

    // Single source of truth for the mobile category strip. Mirrors the desktop
    // sidebar's list so swapping between the two surfaces is consistent.
    $categories = [
        ['slug' => 'gift-cards',      'label' => __('navigation.gift_cards'), 'icon' => 'gift cards.svg', 'url' => $r('gift-cards')],
        ['slug' => 'gomarket',        'label' => 'GoMarket',        'icon' => 'Shop.svg',       'url' => $r('gomarket')],
        ['slug' => 'esims',           'label' => __('navigation.esims'), 'icon' => 'esim.svg',       'url' => $r('esims')],
        ['slug' => 'mobile-airtime',  'label' => __('giftcards.category_mobile_topups'), 'icon' => 'topup1.svg',     'url' => $r('topups')],
        ['slug' => 'bill-payments',   'label' => __('giftcards.category_bill_payments'), 'icon' => 'Bills 2.svg',    'url' => $r('bills')],
        ['slug' => 'flights',         'label' => __('navigation.flights'), 'icon' => 'flight 2.svg',   'url' => $r('flights')],
        ['slug' => 'stays',           'label' => __('navigation.stays'), 'icon' => 'stay 2.svg',     'url' => $r('stays')],
    ];

    // Coming-soon categories stay hidden until their feature flag is switched
    // on (admin > Settings > features). Flights and Stays join them via
    // FeatureFlag::categoryVisible(): unlike the live categories they are
    // removed rather than greyed, because there is no catalogue behind either.
    // Their routes 404 in the same state, so link and page vanish together.
    $hiddenWhenOff = ['gomarket' => 'gomarket'];
    $categories = array_values(array_filter(
        $categories,
        fn ($c) => (! isset($hiddenWhenOff[$c['slug']]) || \App\Support\FeatureFlag::on($hiddenWhenOff[$c['slug']], false))
            && \App\Support\FeatureFlag::categoryVisible($c['slug']),
    ));

    $current = collect($categories)->firstWhere('slug', $active) ?? ($categories[0] ?? null);
@endphp

{{-- Mobile category picker. Wears the storefront chip style - white fill,
     page-tone ring, full radius - not the invite pill wash: the section
     behind this is that same wash (#f4f6f5), and a wash-on-wash pill reads
     as plain text with no rest or hover state at all. Tap to open a
     slide-up sheet with every category. Hidden at sm+ where the desktop
     category sidebar takes over. --}}
<div
    x-data="{ open: false }"
    @keydown.escape.window="open = false"
    x-effect="open ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
    class="relative sm:hidden"
>
    {{-- Trigger pill --}}
    <button
        type="button"
        @click="open = true"
        aria-haspopup="dialog"
        :aria-expanded="open.toString()"
        class="flex w-full items-center justify-between gap-2.5 rounded-full bg-[#ffffff] px-4 py-2.5 text-left text-[15px] font-semibold text-zinc-900 shadow-sm ring-1 ring-[#dee3e1] transition-colors duration-150 hover:bg-[#f4f6f5] hover:ring-[#c9d0cd] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white/10 dark:text-zinc-200 dark:ring-white/10 dark:hover:bg-white/15"
    >
        <span class="flex min-w-0 items-center gap-2">
            <img src="{{ asset('assets/' . rawurlencode($current['icon'])) }}" alt="" class="h-5 w-5 shrink-0 object-contain dark:brightness-0 dark:invert" loading="lazy">
            <span class="truncate">{{ $current['label'] }}</span>
        </span>
        <svg class="h-4 w-4 shrink-0 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
        </svg>
    </button>

    {{-- Backdrop --}}
    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition-opacity ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="open = false"
        class="fixed inset-0 z-[70] bg-zinc-900/50 dark:bg-zinc-950/70"
        aria-hidden="true"
    ></div>

    {{-- Slide-up sheet --}}
    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
        x-transition:enter-start="translate-y-full"
        x-transition:enter-end="translate-y-0"
        x-transition:leave="transition-transform duration-200 ease-in"
        x-transition:leave-start="translate-y-0"
        x-transition:leave-end="translate-y-full"
        {{-- Surface matches the bottom-bar menu sheet exactly (same glass, radius,
             inset and shadow) so every slide-up in the app reads as one component.
             `modal-norise` is an opt-OUT: [role="dialog"]:not(.modal-norise) in the
             head partial applies a global modalRise animation, which stacked on top
             of the x-transition below and made this sheet animate differently to the
             menu. Opting out leaves the single x-transition in charge. --}}
        class="modal-norise fixed inset-x-3 bottom-3 z-[71] isolate max-h-[85dvh] overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden pb-4 rounded-[12px] bg-[#eff6ff] shadow-2xl ring-1 ring-zinc-200 dark:ring-[#24364f]"
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('products.pick_a_category') }}"
    >
        {{-- Handle + close, pinned.

             The sheet itself scrolls, so this row is sticky rather than just
             absolute: a close button that scrolls away with a 30-row
             subcategory list is a close button you cannot reach when you need
             it. The drag handle reads as decoration to plenty of people, and
             the backdrop was the only other way out. --}}
        <div class="sticky top-0 z-10 flex items-center justify-center pt-3 pb-1">
            <span class="h-1.5 w-10 rounded-full bg-zinc-300 dark:bg-white/25"></span>

        <button
            type="button"
            @click="open = false"
            aria-label="{{ __('common.close') }}"
            class="absolute right-1 top-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#dde3ee] text-zinc-600 transition-colors hover:bg-[#cdd5e4] hover:text-zinc-900 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/20 dark:hover:text-white"
        >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
        </div>

        @if ($showCategories)
        <div class="px-5 pt-3">
            <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-zinc-500 dark:text-zinc-400">{{ __('products.browse_categories_label') }}</p>
        </div>

        <ul class="mt-3 px-3">
            @foreach ($categories as $cat)
                @php
                    $isActive = $cat['slug'] === $active;
                    $catEnabled = \App\Support\FeatureFlag::categoryEnabled($cat['slug']);
                @endphp
                <li>
                    @if ($catEnabled)
                        <a
                            href="{{ $cat['url'] }}"
                            wire:navigate
                            @class([
                                'flex items-center gap-3 rounded-[15px] px-3 py-3 text-sm',
                                'font-bold text-[#0c1a36] dark:text-white' => $isActive,
                                'font-medium text-[#0c1a36] dark:text-zinc-200' => ! $isActive,
                            ])
                            @if ($isActive) aria-current="page" @endif
                        >
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] bg-zinc-100 dark:bg-white/10">
                                <img src="{{ asset('assets/' . rawurlencode($cat['icon'])) }}" alt="" class="h-5 w-5 object-contain dark:brightness-0 dark:invert" loading="lazy">
                            </span>
                            <span class="flex-1">{{ $cat['label'] }}</span>
                            @if ($isActive)
                                <svg class="h-4 w-4 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                </svg>
                            @endif
                        </a>
                    @else
                        {{-- Feature off: visible but greyed and non-clickable. --}}
                        <span class="flex cursor-not-allowed items-center gap-3 rounded-[15px] px-3 py-3 text-sm font-medium text-zinc-400 opacity-70 dark:text-zinc-500" aria-disabled="true" title="{{ __('products.temporarily_unavailable') }}">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] bg-zinc-100 grayscale dark:bg-white/10">
                                <img src="{{ asset('assets/' . rawurlencode($cat['icon'])) }}" alt="" class="h-5 w-5 object-contain opacity-50 dark:brightness-0 dark:invert" loading="lazy">
                            </span>
                            <span class="flex-1">{{ $cat['label'] }}</span>
                            <span class="text-[9px] font-bold uppercase tracking-wide text-zinc-300 dark:text-zinc-600">{{ __('products.flag_off') }}</span>
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
        @endif

        {{-- Subcategories of the active category (when the page supplies them).
             Lets the sheet switch both the category and its subcategory. Heads
             the sheet when the category list above is off, so it carries no top
             margin then. --}}
        @if (! empty($subItems))
            <div class="{{ $showCategories ? 'mt-4' : 'mt-3' }} px-5">
                <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-zinc-500 dark:text-zinc-400">{{ __('products.subcategories') }}</p>
            </div>
            <ul class="mt-2 px-3">
                @foreach ($subItems as $item)
                    @php $slug = $item['slug'] ?? ''; @endphp
                    <li>
                        <a
                            href="{{ $item['url'] }}"
                            @if (! $clientFilter) wire:navigate @endif
                            @if ($clientFilter)
                                @click.prevent="open = false; $dispatch('category-filtered', { slug: @js($slug) })"
                                :class="activeCategory === @js($slug)
                                    ? 'font-bold text-[#0c1a36] dark:text-white'
                                    : 'font-medium text-[#0c1a36] dark:text-zinc-200'"
                                class="flex items-center gap-3 rounded-full px-3 py-2.5 text-sm"
                            @else
                                @class([
                                    'flex items-center gap-3 rounded-[15px] px-3 py-2.5 text-sm',
                                    'font-bold text-[#0c1a36] dark:text-white' => $item['active'] ?? false,
                                    'font-medium text-[#0c1a36] dark:text-zinc-200' => ! ($item['active'] ?? false),
                                ])
                            @endif
                        >
                            <span class="flex-1">{{ $item['label'] }}</span>

                            @if ($clientFilter)
                                <template x-if="activeCategory === @js($slug)">
                                    <svg class="h-4 w-4 shrink-0 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                    </svg>
                                </template>
                            @elseif ($item['active'] ?? false)
                                <svg class="h-4 w-4 shrink-0 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                </svg>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
