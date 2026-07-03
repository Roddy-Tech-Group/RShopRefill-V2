@props([
    // Slug of the category whose storefront is currently open:
    // gift-cards | mobile-airtime | esims | bill-payments | flights | stays
    'active' => '',
    // Subcategory links for the active category, built by the page so filter
    // state is preserved. Each: ['label' => string, 'url' => string, 'active' => bool].
    'subItems' => [],
])

@php
    // When the sidebar renders inside the dashboard shop chrome, swap every
    // category link to the matching dashboard route so the user stays inside
    // their dashboard. Storefront chrome keeps the public URLs.
    $inDashboard = request()->is('dashboard/shop*') && auth()->check();
    $r = fn (string $name) => $inDashboard ? route('dashboard.shop.'.$name) : route('shop.'.$name);

    // The single source of truth for the storefront category list. Categories
    // without a live page render as disabled "Soon" entries until one ships.
    $categories = [
        ['slug' => 'gift-cards',     'label' => 'Gift Cards',           'url' => $r('gift-cards')],
        ['slug' => 'mobile-airtime', 'label' => 'Mobile top up & data', 'url' => $r('topups')],
        ['slug' => 'esims',          'label' => 'eSIMs',                'url' => $r('esims')],
        ['slug' => 'bill-payments',  'label' => 'Bill payments',        'url' => $r('bills')],
        ['slug' => 'flights',        'label' => 'Flights',              'url' => $r('flights')],
        ['slug' => 'stays',          'label' => 'Stays',                'url' => $r('stays')],
    ];
@endphp

{{-- Shared category sidebar — used by every category storefront so the catalog
     navigation is identical everywhere. Sticky as one unit; the inner panel
     scrolls if the subcategory list outgrows the viewport. --}}
<aside class="hidden self-start lg:sticky lg:top-[156px] lg:block">
    <div class="max-h-[calc(100vh-180px)] overflow-y-auto rounded-[14px] bg-[#eff6ff] p-3 border border-zinc-200 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden dark:bg-[#0c1a36] dark:border-[#24364f]">

        {{-- Categories — switches the storefront entirely --}}
        <p class="px-3 pb-1 pt-1 text-[10px] font-bold uppercase tracking-[0.12em] text-zinc-500 dark:text-zinc-400">Categories</p>
        <nav class="space-y-0.5 text-sm" aria-label="Product categories">
            @foreach ($categories as $cat)
                @if ($cat['url'])
                    <a
                        href="{{ $cat['url'] }}"
                        wire:navigate
                        @class([
                            'flex items-center justify-between rounded-[12px] px-3 py-1.5 transition-colors',
                            'bg-blue-100 font-bold text-blue-700 dark:bg-white/10 dark:text-white' => $active === $cat['slug'],
                            'text-zinc-700 hover:bg-blue-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/10 dark:hover:text-white' => $active !== $cat['slug'],
                        ])
                        @if ($active === $cat['slug']) aria-current="page" @endif
                    >
                        <span>{{ $cat['label'] }}</span>
                    </a>
                @else
                    <span class="flex cursor-not-allowed items-center justify-between rounded-[12px] px-3 py-1.5 text-zinc-400 dark:text-zinc-500">
                        <span>{{ $cat['label'] }}</span>
                        <span class="text-[9px] font-bold uppercase tracking-wide text-zinc-300 dark:text-zinc-600">Soon</span>
                    </span>
                @endif
            @endforeach
        </nav>

        {{-- Subcategories — of the active category only --}}
        @if (! empty($subItems))
            <p class="mt-4 px-3 pb-1 pt-1 text-[10px] font-bold uppercase tracking-[0.12em] text-zinc-500 dark:text-zinc-400">Subcategories</p>
            <nav class="space-y-0.5 text-sm" aria-label="Subcategories">
                @foreach ($subItems as $item)
                    <a
                        href="{{ $item['url'] }}"
                        wire:navigate
                        @class([
                            'block rounded-[12px] px-3 py-1.5 transition-colors',
                            'bg-blue-100 font-bold text-blue-700 dark:bg-blue-500/15 dark:text-blue-300' => $item['active'] ?? false,
                            'text-zinc-700 hover:bg-blue-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/10 dark:hover:text-white' => ! ($item['active'] ?? false),
                        ])
                    >
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
        @endif

    </div>
</aside>
