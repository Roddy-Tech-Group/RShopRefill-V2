@props([
    'item',
    'disabled' => false,
    // Off outside the browse grid. The card hides itself against the parent
    // scope's locTab/locSearch, which only exist on a page that has the tabs
    // and the search box; anywhere else that expression would either error or,
    // worse, quietly evaluate against a stray default and hide the card.
    'filter' => true,
    // Off where the page has no `pick` state for the purchase-check modal to
    // read. With it off the card is a plain link and keeps wire:navigate.
    'ask' => true,
])

@php
    // Card is reused by storefront + dashboard shop chrome; pick the matching
    // detail route based on which surface we're rendered on.
    $inDash = request()->is('dashboard/shop*') && auth()->check();
    $detailRoute = $inDash ? 'dashboard.shop.esim' : 'shop.esim';

    // The pinned worldwide plan, the only one carrying a real +1 number.
    $featured = str_contains((string) ($item['slug'] ?? ''), 'discover-global');

    // Whether this card asks the coverage question before it lets anyone
    // through. See the note on the anchor for which cards do and why.
    $asksFirst = $ask && ! $disabled && ! $featured && ($item['scope'] ?? '') !== 'global';
@endphp

{{-- One eSIM location card for the browse grid. Filtered client-side via the parent
     Alpine scope's locTab/locSearch. Shared by the landing and the country store.
     When $disabled (eSIMs feature off), the card stays visible but greyed and inert. --}}
{{-- Reference shape: a tall card with a round flag, the destination, small
     meta chips and a full-width action, rather than the single compact row this
     used to be. The chips carry only what the catalogue summary actually holds,
     the cheapest plan's allowance and the destination's scope; the data and day
     RANGES the reference shows would need a scan of every plan's metadata per
     destination, which is exactly what catalogSummary avoids doing. --}}
<a
    @if (! $disabled) href="{{ route($detailRoute, $item['slug']) }}" @endif
    {{-- Ask before buying, but only where the answer can be wrong. A GLOBAL
         plan works wherever the traveller goes, so there is nothing to warn
         about; a local one is tied to one country and a regional one to a set
         of them, and the modal says the right thing for each. The href stays
         real so the card is still a link (middle click, copy address, no JS),
         and the handler only takes over a plain left click.

         No wire:navigate on those cards, deliberately. It cancels the link on
         mousedown and navigates on mouseup, both of which happen BEFORE click,
         so the page would already be leaving by the time this handler ran and
         the modal would never appear. Cards that go straight through keep it. --}}
    @if ($asksFirst)
        @click.prevent="pick = {
            name: @js($item['name']),
            url: $el.getAttribute('href'),
            scope: @js($item['scope'] ?? 'local'),
        }"
    @elseif (! $disabled)
        wire:navigate
    @endif
    data-name="{{ \Illuminate\Support\Str::lower($item['name']) }}"
    {{-- Everything this card can be found by, lowercased and space separated:
         the name, an accent-folded copy of it so "cote" finds Côte d'Ivoire,
         the ISO code so "gb" finds the United Kingdom, and the scope so
         "regional" finds every regional plan. --}}
    data-terms="{{ \Illuminate\Support\Str::lower(implode(' ', array_unique(array_filter([
        $item['name'],
        \Illuminate\Support\Str::ascii($item['name']),
        $item['cc'] ?? '',
        $item['scope'] ?? '',
        ($item['unlimited'] ?? false) ? 'unlimited' : '',
    ])))) }}"
    data-scope="{{ $item['scope'] }}"
    data-pop="{{ $item['popular'] ? '1' : '0' }}"
    data-unlimited="{{ ($item['unlimited'] ?? false) ? '1' : '0' }}"
    {{-- Popular and Unlimited filter on their own flags; the rest match the
         destination's scope. All shows everything. --}}
    {{-- A query searches EVERYTHING, tab or no tab. The two used to be ANDed,
         so typing a destination that was not in the open tab returned nothing
         and the search read as broken. With no query, the tab decides. --}}
    @if ($filter)
        x-show="locSearch !== ''
            ? $el.dataset.terms.includes(locSearch)
            : (locTab === 'all'
                || (locTab === 'popular' ? $el.dataset.pop === '1'
                    : locTab === 'unlimited' ? $el.dataset.unlimited === '1'
                    : $el.dataset.scope === locTab))"
    @endif
    {{ $attributes->class([
        'esim-tile group flex flex-col rounded-[12px] bg-[#ffffff] p-4 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md dark:bg-white/5',
        // The hero plan wears a lit blue edge: it is the only destination that
        // sells a real +1 number with worldwide coverage, and the one card here
        // worth pulling a shopper's eye. Everything else keeps the hairline.
        'esim-tile-featured ring-2 ring-[#2563eb]' => $featured,
        'ring-1 ring-zinc-200 dark:ring-white/10' => ! $featured,
        'pointer-events-none opacity-60 grayscale' => $disabled,
    ]) }}
    @if ($disabled) aria-disabled="true" @endif
>
    {{-- Flag, round, with the scope beside it where the reference puts a badge. --}}
    <span class="flex items-start justify-between gap-3">
        @if ($item['flag'])
            <img src="{{ $item['flag'] }}" alt="" class="h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-zinc-200 dark:ring-white/10" loading="lazy">
        @else
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#f4f6f5] ring-1 ring-zinc-200 dark:bg-white/10 dark:ring-white/10">
                <svg class="h-5 w-5 text-zinc-500 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.4 4-5.6 4-9s-1.5-6.6-4-9c-2.5 2.4-4 5.6-4 9s1.5 6.6 4 9zM3.6 9h16.8M3.6 15h16.8"/></svg>
            </span>
        @endif

        @if ($item['popular'])
            <span class="shrink-0 rounded-full bg-[#0c1337] px-2 py-0.5 text-[10px] uppercase tracking-wide text-white dark:bg-[#fdfdfd] dark:text-[#0c1337]">{{ __('products.detail_popular') }}</span>
        @endif
    </span>

    <span class="mt-3 block truncate text-[17px] text-[#0c1337] dark:text-white">{{ $item['name'] }}</span>

    {{-- What this destination actually holds: the span of data allowances, the
         span of validity, whether any plan is unlimited, and whether the plans
         come with a phone number or are data only. Every value is summarised
         from the destination's real packages in catalogSummary(), so a card can
         never advertise something the plan list does not contain. --}}
    @php
        // Brand navy stated explicitly rather than a text-zinc-* class: the
        // storefront ink remaps in app.css target those and would repaint it.
        $chip = 'rounded-full bg-[#f4f6f5] px-2.5 py-1 text-[12px] text-[#0c1337] dark:bg-white/10 dark:text-zinc-200';
    @endphp
    <span class="mt-2 flex flex-wrap items-center gap-1.5">
        @if (! empty($item['data_range']))
            <span class="{{ $chip }}">{{ $item['data_range'] }}</span>
        @elseif (! empty($item['data']))
            <span class="{{ $chip }}">{{ __('esim.from_data', ['data' => strcasecmp((string) $item['data'], 'Unlimited') === 0 ? __('esim.unlimited') : $item['data']]) }}</span>
        @endif

        @if (! empty($item['unlimited']))
            <span class="rounded-full bg-[#0c1337] px-2.5 py-1 text-[12px] text-white dark:bg-[#fdfdfd] dark:text-[#0c1337]">{{ __('esim.unlimited') }}</span>
        @endif

        @if (! empty($item['days_range']))
            <span class="{{ $chip }} tabular-nums">{{ __('esim.days_range', ['range' => $item['days_range']]) }}</span>
        @endif

        {{-- One chip per thing the plan carries. Where there is no number, the
             single chip says so: "Data only" is the fact a traveller needs,
             where a bare "Data" leaves them wondering about calls. --}}
        @if ($item['with_number'] ?? false)
            <span class="{{ $chip }}">{{ __('esim.chip_data') }}</span>
            <span class="{{ $chip }}">{{ __('esim.chip_phone_number') }}</span>
        @else
            <span class="{{ $chip }}">{{ __('esim.chip_data_only') }}</span>
        @endif
    </span>

    <span class="mt-3 flex items-baseline gap-1.5">
        <span class="text-[12px] text-zinc-500 dark:text-zinc-400">{{ __('esim.from') }}</span>
        <span class="text-[17px] tabular-nums text-[#0c1337] dark:text-white">${{ number_format($item['from'], 2) }}</span>
    </span>

    {{-- The action reads as a button but stays part of the card's own link. --}}
    <span class="mt-4 block w-full rounded-full bg-[#f4f6f5] px-3 py-2 text-center text-[14px] text-[#0c1337] transition-colors group-hover:bg-[#e8ecea] dark:bg-white/10 dark:text-white dark:group-hover:bg-white/15">{{ __('esim.view_plans') }}</span>
</a>
