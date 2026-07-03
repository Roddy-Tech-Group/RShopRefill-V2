@props(['item'])

@php
    // Card is reused by storefront + dashboard shop chrome; pick the matching
    // detail route based on which surface we're rendered on.
    $inDash = request()->is('dashboard/shop*') && auth()->check();
    $detailRoute = $inDash ? 'dashboard.shop.esim' : 'shop.esim';
@endphp

{{-- One eSIM location card for the browse grid. Filtered client-side via the parent
     Alpine scope's locTab/locSearch. Shared by the landing and the country store. --}}
<a
    href="{{ route($detailRoute, $item['slug']) }}"
    wire:navigate
    data-name="{{ \Illuminate\Support\Str::lower($item['name']) }}"
    data-scope="{{ $item['scope'] }}"
    data-pop="{{ $item['popular'] ? '1' : '0' }}"
    x-show="(locTab === 'all' || (locTab === 'popular' ? $el.dataset.pop === '1' : $el.dataset.scope === locTab)) && (locSearch === '' || $el.dataset.name.includes(locSearch.toLowerCase()))"
    class="esim-tile group flex items-center justify-between gap-3 rounded-[12px] bg-[#eff6ff] px-4 py-3.5 ring-1 ring-zinc-200 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md hover:ring-zinc-300 dark:ring-zinc-700/60 dark:hover:ring-zinc-600"
>
    <span class="flex min-w-0 items-center gap-3">
        @if ($item['flag'])
            <img src="{{ $item['flag'] }}" alt="" class="h-6 w-9 shrink-0 rounded-[3px] object-cover ring-1 ring-zinc-200" loading="lazy">
        @else
            <span class="flex h-6 w-9 shrink-0 items-center justify-center rounded-[3px] bg-blue-50 ring-1 ring-zinc-200 dark:bg-blue-500/15 dark:ring-zinc-700/60">
                <svg class="h-4 w-4 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5 0 4.5-4.03 4.5-9S14.5 3 12 3 7.5 7.03 7.5 12s2 9 4.5 9zM3.6 9h16.8M3.6 15h16.8"/></svg>
            </span>
        @endif
        <span class="min-w-0">
            <span class="block truncate text-[16px] font-bold text-zinc-900 group-hover:text-blue-700 dark:text-white dark:group-hover:text-blue-300">{{ $item['name'] }}</span>
            @if (! empty($item['data']))
                <span class="block truncate text-[11px] font-medium text-zinc-500 dark:text-zinc-400">from {{ $item['data'] }}</span>
            @endif
        </span>
    </span>
    <span class="shrink-0 text-[16px] font-bold tabular-nums text-zinc-900 dark:text-white">${{ number_format($item['from'], 2) }}</span>
</a>
