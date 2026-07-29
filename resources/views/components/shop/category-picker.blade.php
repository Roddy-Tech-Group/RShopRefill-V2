@props([
    // Slug of the currently-active category:
    // gift-cards | mobile-airtime | esims | bill-payments | flights | stays
    'active' => '',
    // Subcategory links for the active category, built by the page (same shape
    // as the desktop sidebar): ['label' => string, 'url' => string, 'active' => bool].
    'subItems' => [],
])

@php
    // Route name swaps between storefront + dashboard chrome so picking a
    // category keeps the user on whichever side they entered from.
    $inDashboard = request()->is('dashboard/shop*') && auth()->check();
    $r = fn (string $name) => $inDashboard ? route('dashboard.shop.'.$name) : route('shop.'.$name);

    // Single source of truth for the mobile category strip. Mirrors the desktop
    // sidebar's list so swapping between the two surfaces is consistent.
    $categories = [
        ['slug' => 'gift-cards',      'label' => 'Buy Gift Cards',  'icon' => 'gift cards.svg', 'url' => $r('gift-cards')],
        ['slug' => 'sell-gift-cards', 'label' => 'Sell Gift Cards', 'icon' => 'gift cards.svg', 'url' => $r('sell-gift-cards')],
        ['slug' => 'gomarket',        'label' => 'GoMarket',        'icon' => 'Shop.svg',       'url' => $r('gomarket')],
        ['slug' => 'esims',           'label' => 'eSIMs',           'icon' => 'esim.svg',       'url' => $r('esims')],
        ['slug' => 'mobile-airtime',  'label' => 'Mobile top-ups',  'icon' => 'topup1.svg',     'url' => $r('topups')],
        ['slug' => 'bill-payments',   'label' => 'Bill payments',   'icon' => 'Bills 2.svg',    'url' => $r('bills')],
        ['slug' => 'flights',         'label' => 'Flights',         'icon' => 'flight 2.svg',   'url' => $r('flights')],
        ['slug' => 'stays',           'label' => 'Stays',           'icon' => 'stay 2.svg',     'url' => $r('stays')],
    ];

    // Coming-soon categories stay hidden until their feature flag is switched
    // on (admin > Settings > features). Their pages remain reachable by URL.
    $hiddenWhenOff = ['sell-gift-cards' => 'sell_gift_cards', 'gomarket' => 'gomarket'];
    $categories = array_values(array_filter(
        $categories,
        fn ($c) => ! isset($hiddenWhenOff[$c['slug']]) || \App\Support\FeatureFlag::on($hiddenWhenOff[$c['slug']], false),
    ));

    $current = collect($categories)->firstWhere('slug', $active) ?? $categories[0];
@endphp

{{-- Mobile category picker. Dark navy pill that mirrors the country picker
     style; tap to open a slide-up sheet with every category. Hidden at sm+
     where the desktop category sidebar takes over. --}}
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
        class="flex w-full items-center justify-between gap-2 rounded-[12px] bg-[#0c1a36] px-4 py-3 text-left text-sm font-semibold text-white ring-1 ring-white/15 transition-colors hover:bg-[#1d3252]"
    >
        <span class="flex min-w-0 items-center gap-2.5">
            <img src="{{ asset('assets/' . rawurlencode($current['icon'])) }}" alt="" class="h-5 w-5 shrink-0 object-contain brightness-0 invert" loading="lazy">
            <span class="truncate">{{ $current['label'] }}</span>
        </span>
        <svg class="h-4 w-4 shrink-0 text-white/80" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
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
        class="fixed inset-0 z-[70] bg-zinc-900/45"
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
        class="modal-norise fixed inset-x-3 bottom-3 z-[71] max-h-[85dvh] overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden rounded-3xl bg-white/70 ring-1 ring-white/40 backdrop-blur-2xl backdrop-saturate-150 pb-4 shadow-2xl shadow-zinc-900/25 dark:bg-[#0c1a36]/70 dark:ring-white/10"
        role="dialog"
        aria-modal="true"
        aria-label="Pick a category"
    >
        <div class="flex justify-center pt-3">
            <span class="h-1.5 w-10 rounded-full bg-zinc-300 dark:bg-white/25"></span>
        </div>

        <div class="px-5 pt-3">
            <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-zinc-500 dark:text-zinc-400">Browse categories</p>
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
                                'flex items-center gap-3 rounded-[15px] px-3 py-3 text-sm transition-colors',
                                'bg-blue-50 font-bold text-blue-700 ring-1 ring-blue-200 dark:bg-white/10 dark:text-blue-300 dark:ring-white/15' => $isActive,
                                'font-medium text-zinc-800 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-white/10' => ! $isActive,
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
                        <span class="flex cursor-not-allowed items-center gap-3 rounded-[15px] px-3 py-3 text-sm font-medium text-zinc-400 opacity-70 dark:text-zinc-500" aria-disabled="true" title="Temporarily unavailable">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] bg-zinc-100 grayscale dark:bg-white/10">
                                <img src="{{ asset('assets/' . rawurlencode($cat['icon'])) }}" alt="" class="h-5 w-5 object-contain opacity-50 dark:brightness-0 dark:invert" loading="lazy">
                            </span>
                            <span class="flex-1">{{ $cat['label'] }}</span>
                            <span class="text-[9px] font-bold uppercase tracking-wide text-zinc-300 dark:text-zinc-600">Off</span>
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>

        {{-- Subcategories of the active category (when the page supplies them).
             Lets the sheet switch both the category and its subcategory. --}}
        @if (! empty($subItems))
            <div class="mt-4 px-5">
                <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-zinc-500 dark:text-zinc-400">Subcategories</p>
            </div>
            <ul class="mt-2 px-3">
                @foreach ($subItems as $item)
                    <li>
                        <a
                            href="{{ $item['url'] }}"
                            wire:navigate
                            @class([
                                'flex items-center gap-3 rounded-[15px] px-3 py-2.5 text-sm transition-colors',
                                'bg-blue-50 font-bold text-blue-700 ring-1 ring-blue-200 dark:bg-white/10 dark:text-blue-300 dark:ring-white/15' => $item['active'] ?? false,
                                'font-medium text-zinc-800 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-white/10' => ! ($item['active'] ?? false),
                            ])
                        >
                            <span class="flex-1">{{ $item['label'] }}</span>
                            @if ($item['active'] ?? false)
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
