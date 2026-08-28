@props([
    // Slug of the category whose storefront is currently open:
    // gift-cards | mobile-airtime | esims | bill-payments | flights | stays
    'active' => '',
    // Subcategory links for the active category, built by the page so filter
    // state is preserved. Each: ['label' => string, 'url' => string, 'active' => bool].
    'subItems' => [],
    // The list of OTHER storefronts. The nav's category rail already carries
    // it on every page, so a storefront with subcategories of its own can drop
    // the duplicate and give that space to the filters people came for.
    'showCategories' => true,
    // When the page already holds every category, a subcategory click filters
    // in place instead of navigating. Off by default: a storefront that renders
    // one category at a time has nothing to filter and must keep the round trip.
    'clientFilter' => false,
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
        ['slug' => 'gift-cards',      'label' => 'Gift Cards',       'url' => $r('gift-cards')],
        ['slug' => 'gomarket',        'label' => 'GoMarket',             'url' => $r('gomarket')],
        ['slug' => 'mobile-airtime',  'label' => 'Mobile top up & data', 'url' => $r('topups')],
        ['slug' => 'esims',           'label' => 'eSIMs',                'url' => $r('esims')],
        ['slug' => 'bill-payments',   'label' => 'Bill payments',        'url' => $r('bills')],
        ['slug' => 'flights',         'label' => 'Flights',              'url' => $r('flights')],
        ['slug' => 'stays',           'label' => 'Stays',                'url' => $r('stays')],
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
@endphp

{{-- Shared category sidebar — used by every category storefront so the catalog
     navigation is identical everywhere. Sticky as one unit; the inner panel
     scrolls if the subcategory list outgrows the viewport.

     Hover and selected are written as arbitrary hexes on purpose. app.css
     flattens bg-blue-100, hover:bg-blue-100 and this panel's bg-[#eff6ff] all
     to var(--ash) in dark mode, and those rules are unlayered - so they beat
     this component's own dark: variants and painted hover, selection and panel
     the same colour. #ffffff and #dbeafe are not in that remap, which lets
     dark:hover:bg-white/10 and dark:bg-blue-500/25 through. Renaming either to
     a Tailwind blue would silently kill both states again. --}}
<aside class="hidden self-start lg:sticky lg:top-[156px] lg:block">
    <div class="max-h-[calc(100vh-180px)] overflow-y-auto rounded-[14px] bg-[#eff6ff] p-3 border border-zinc-200 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden dark:bg-[#0c1a36] dark:border-[#24364f]">

        {{-- Categories — switches the storefront entirely. --}}
        @if ($showCategories)
        <p class="px-3 pb-2 pt-1 text-[13px] font-bold uppercase tracking-[0.08em] text-[#0c1a36] dark:text-blue-300">{{ __('navigation.categories') }}</p>
        <nav class="space-y-0.5 text-[16px]" aria-label="{{ __('navigation.categories') }}">
            @foreach ($categories as $cat)
                @php $catEnabled = \App\Support\FeatureFlag::categoryEnabled($cat['slug']); @endphp
                @if ($cat['url'] && $catEnabled)
                    <a
                        href="{{ $cat['url'] }}"
                        wire:navigate
                        @class([
                            'flex items-center justify-between rounded-[12px] px-3 py-2 transition-colors',
                            'cat-selected bg-[#ffffff] font-bold text-[#0c1a36] shadow-sm dark:text-white' => $active === $cat['slug'],
                            'cat-hover font-bold text-zinc-800 hover:bg-[#ffffff] hover:text-zinc-900 dark:text-zinc-300' => $active !== $cat['slug'],
                        ])
                        @if ($active === $cat['slug']) aria-current="page" @endif
                    >
                        <span>{{ $cat['label'] }}</span>
                    </a>
                @elseif ($cat['url'] && ! $catEnabled)
                    {{-- Feature off: stays visible but greyed and non-clickable. --}}
                    <span class="flex cursor-not-allowed items-center justify-between rounded-[12px] px-3 py-2 font-bold text-zinc-400 opacity-70 dark:text-zinc-500" aria-disabled="true" title="{{ __('products.temporarily_unavailable') }}">
                        <span>{{ $cat['label'] }}</span>
                        <span class="text-[9px] font-bold uppercase tracking-wide text-zinc-300 dark:text-zinc-600">{{ __('products.flag_off') }}</span>
                    </span>
                @else
                    <span class="flex cursor-not-allowed items-center justify-between rounded-[12px] px-3 py-2 font-bold text-zinc-400 dark:text-zinc-500">
                        <span>{{ $cat['label'] }}</span>
                        <span class="text-[9px] font-bold uppercase tracking-wide text-zinc-300 dark:text-zinc-600">{{ __('products.coming_soon') }}</span>
                    </span>
                @endif
            @endforeach
        </nav>
        @endif

        {{-- Subcategories — of the active category only. Heads the panel when
             the category list above is off, so it carries no top margin then. --}}
        @if (! empty($subItems))
            <p class="{{ $showCategories ? 'mt-4 ' : '' }}px-3 pb-2 pt-1 text-[13px] font-bold uppercase tracking-[0.08em] text-[#0c1a36] dark:text-blue-300">{{ __('products.subcategories') }}</p>
            <nav class="space-y-0.5 text-[16px]" aria-label="{{ __('products.subcategories') }}">
                @foreach ($subItems as $item)
                    @php $slug = $item['slug'] ?? ''; @endphp
                    <a
                        href="{{ $item['url'] }}"
                        @if (! $clientFilter) wire:navigate @endif
                        @if ($clientFilter)
                            {{-- .prevent only on a plain click: a middle-click or
                                 cmd-click must still open the real URL. --}}
                            @click.prevent="$dispatch('category-filtered', { slug: @js($slug) })"
                        @endif
                        @if ($clientFilter)
                            :class="activeCategory === @js($slug)
                                ? 'cat-selected bg-[#ffffff] font-bold text-[#0c1a36] shadow-sm dark:text-blue-300'
                                : 'cat-hover font-bold text-zinc-800 hover:bg-[#ffffff] hover:text-zinc-900 dark:text-zinc-300'"
                            :aria-current="activeCategory === @js($slug) ? 'true' : null"
                            class="block rounded-[12px] px-3 py-2 transition-colors"
                        @else
                            @class([
                                'block rounded-[12px] px-3 py-2 transition-colors',
                                'cat-selected bg-[#ffffff] font-bold text-[#0c1a36] shadow-sm dark:text-blue-300' => $item['active'] ?? false,
                                'cat-hover font-bold text-zinc-800 hover:bg-[#ffffff] hover:text-zinc-900 dark:text-zinc-300' => ! ($item['active'] ?? false),
                            ])
                        @endif
                    >
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
        @endif

    </div>
</aside>
