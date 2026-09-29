{{--
    Where this product can be used, and for a gift card sold in more than one
    country, a way to move to the region you are actually buying for.

    The picker lists only the countries THIS brand sells this card in, which is
    the point of it: the old "Find your country" link opened the global locale
    modal, from which you could pick a country the brand has no card in and be
    served another region's card without being told. A brand sold in one country
    says so and offers nothing, because there is nothing to choose.

    Choosing sends a `pick-region` event, which the locale modal applies to the
    shared storefrontLocale() scope. Its watcher navigates and the nav pill
    follows, so the region on the card and the region in the header cannot
    disagree. That switch is site-wide, not card-scoped: it is the same region
    the pill and the locale modal set.

    An event rather than assigning the keys here: the storefront wraps the whole
    page in that scope, but the dashboard wraps only the modal, so on the
    dashboard an assignment from this partial landed on no scope that watches it
    and the picker closed without switching anything.
--}}
@php
    $availabilityCountries = collect($brandCountries ?? [])->values();
    $availabilityCanSwitch = ($isGiftCard ?? false) && $availabilityCountries->count() > 1;
    $availabilityCurrencies = (array) config('country_currency.map', []);
    $availabilityNames = array_flip((array) config('countries.codes', []));

    // Countries this CATEGORY actually sells in, read the same way the listing
    // reads them: distinct country codes on active products in the synced
    // catalog. A top-up brand is one network in one country, so the only useful
    // move is to another country that has networks at all - the global locale
    // modal offered every country on earth, most of which have no refill and
    // land the buyer on an empty list.
    $availabilityRefillCountries = collect();

    if (! ($isGiftCard ?? false) && $product->category_id) {
        $availabilityRefillCountries = \Illuminate\Support\Facades\Cache::remember(
            'availability.countries.'.$product->category_id,
            now()->addHours(6),
            fn () => \App\Models\Product::query()
                ->where('category_id', $product->category_id)
                ->where('is_active', true)
                ->distinct()
                ->pluck('country_code')
                ->filter()
                ->values()
                ->all(),
        );

        $availabilityRefillCountries = collect($availabilityRefillCountries)
            ->map(fn ($c) => strtoupper((string) $c))
            ->reject(fn ($c) => $c === strtoupper((string) $product->country_code))
            ->map(fn ($c) => ['code' => $c, 'name' => $availabilityNames[$c] ?? $c])
            ->sortBy('name')
            ->values();
    }

    // Where a chosen country lands: this category's own listing, filtered.
    $availabilityListing = ($isTopup ?? false)
        ? (($inDashboard ?? false) ? 'dashboard.shop.topups' : 'shop.topups')
        : (($inDashboard ?? false) ? 'dashboard.shop.bills' : 'shop.bills');
@endphp

<div class="{{ $availabilityClass ?? 'mt-4 mb-2' }}">
    <p class="flex items-center gap-2 text-sm text-zinc-700">
        @if (\App\Models\Product::flagUrl($product->country_code))
            <img src="{{ \App\Models\Product::flagUrl($product->country_code) }}" alt="" class="h-4 w-6 shrink-0 rounded-[2px] object-cover ring-1 ring-zinc-200" loading="lazy">
        @endif
        <span>{{ $isTopup ? __('products.detail_works_for_numbers', ['country' => $countryName]) : ($isBill ? __('products.detail_billers_in', ['country' => $countryName]) : __('products.detail_redeemable_in', ['country' => $countryName])) }}</span>
    </p>

    @if ($availabilityCanSwitch)
        {{-- The site's own dropdown, as used for quantity and pay currency. --}}
        <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false" class="relative mt-1.5 inline-block">
            <button
                type="button"
                @click="open = ! open"
                :aria-expanded="open.toString()"
                aria-haspopup="listbox"
                :class="open ? 'border-edge dark:border-edge' : 'border-zinc-200 hover:border-zinc-300 dark:border-white/10 dark:hover:border-white/25'"
                class="flex h-9 items-center gap-2 rounded-full border-2 bg-white px-3.5 text-sm text-zinc-900 transition-colors focus:outline-none dark:bg-white/5 dark:text-white"
            >
                {{ __('products.detail_choose_region') }}
                <svg class="h-4 w-4 shrink-0 text-zinc-500 transition-transform duration-150" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div
                x-show="open"
                x-cloak
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                role="listbox"
                class="absolute left-0 top-full z-20 mt-1 max-h-64 w-60 overflow-y-auto rounded-[12px] border border-zinc-200 bg-white p-1 shadow-xl shadow-zinc-900/10 [scrollbar-width:none] dark:border-white/10 dark:bg-[#1a1a1e] [&::-webkit-scrollbar]:hidden"
            >
                @foreach ($availabilityCountries as $availabilityCode)
                    @php
                        $availabilityCode = strtoupper((string) $availabilityCode);
                        $availabilityCurrency = $availabilityCurrencies[$availabilityCode] ?? 'USD';
                        // The flag emoji is derived from the ISO code, the way the
                        // search panel's country rows derive theirs, so a new
                        // country needs no asset adding anywhere.
                        $availabilityEmoji = mb_chr(0x1F1E6 + ord($availabilityCode[0]) - 65, 'UTF-8')
                            .mb_chr(0x1F1E6 + ord($availabilityCode[1]) - 65, 'UTF-8');
                        $availabilityName = $availabilityNames[$availabilityCode] ?? $availabilityCode;
                    @endphp
                    <button
                        type="button"
                        role="option"
                        :aria-selected="@js($availabilityCode === strtoupper((string) $product->country_code))"
                        @click="$dispatch('pick-region', { country: @js($availabilityName), countryFlag: @js($availabilityEmoji), countryCode: @js($availabilityCode), currency: @js($availabilityCurrency), currencySymbol: @js(\App\Models\Product::currencySymbol($availabilityCurrency)) }); open = false"
                        @class([
                            'flex w-full items-center gap-2 rounded-[12px] px-3 py-2 text-left text-sm font-medium transition-colors',
                            'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300' => $availabilityCode === strtoupper((string) $product->country_code),
                            'text-zinc-800 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-white/10' => $availabilityCode !== strtoupper((string) $product->country_code),
                        ])
                    >
                        @if (\App\Models\Product::flagUrl($availabilityCode))
                            <img src="{{ \App\Models\Product::flagUrl($availabilityCode) }}" alt="" class="h-4 w-6 shrink-0 rounded-[2px] object-cover ring-1 ring-zinc-200" loading="lazy">
                        @endif
                        <span class="truncate">{{ $availabilityName }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @elseif (! ($isGiftCard ?? false) && $availabilityRefillCountries->isNotEmpty())
        {{-- Only the countries this category actually carries. The link used to
             open the global locale modal, which listed every country on earth:
             picking one with no networks landed the buyer on an empty list. --}}
        <p class="mt-2 text-sm text-zinc-600">{{ __('products.detail_not_in_country', ['country' => $countryName]) }}</p>

        <div x-data="{ open: false, q: '' }" @click.outside="open = false; q = ''" @keydown.escape="open = false; q = ''" class="relative mt-1.5 inline-block">
            <button
                type="button"
                @click="open = ! open"
                :aria-expanded="open.toString()"
                aria-haspopup="listbox"
                :class="open ? 'border-edge dark:border-edge' : 'border-zinc-200 hover:border-zinc-300 dark:border-white/10 dark:hover:border-white/25'"
                class="flex h-9 items-center gap-2 rounded-full border-2 bg-white px-3.5 text-sm font-semibold text-zinc-900 transition-colors focus:outline-none dark:bg-white/5 dark:text-white"
            >
                {{ __('products.detail_find_your_country') }}
                <svg class="h-4 w-4 shrink-0 text-zinc-500 transition-transform duration-150" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div
                x-show="open"
                x-cloak
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                role="listbox"
                class="absolute left-0 top-full z-20 mt-1 w-64 rounded-[12px] border border-zinc-200 bg-white p-1 shadow-xl shadow-zinc-900/10 dark:border-zinc-700 dark:bg-[#1d3252]"
            >
                {{-- A long list needs a filter. Below three characters it would
                     match most of the list, so it only narrows from there. --}}
                @if ($availabilityRefillCountries->count() > 8)
                    <input
                        type="text"
                        x-model="q"
                        placeholder="{{ __('products.detail_find_your_country') }}"
                        class="mb-1 w-full rounded-full border-2 border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-900 outline-none transition-colors focus:border-edge focus:ring-2 focus:ring-edge/30 dark:border-white/10 dark:bg-white/5 dark:text-white"
                    >
                @endif

                <div class="max-h-64 overflow-y-auto">
                    @foreach ($availabilityRefillCountries as $availabilityOption)
                        <a
                            href="{{ route($availabilityListing, ['country' => $availabilityOption['code']]) }}"
                            wire:navigate
                            x-show="q.trim().length < 3 || @js(strtolower($availabilityOption['name'])).includes(q.trim().toLowerCase())"
                            class="flex w-full items-center gap-2 rounded-full px-3 py-2 text-left text-sm font-medium text-zinc-800 transition-colors hover:bg-[#f4f6f5] dark:text-zinc-200 dark:hover:bg-[#26416b]"
                        >
                            @if (\App\Models\Product::flagUrl($availabilityOption['code']))
                                <img src="{{ \App\Models\Product::flagUrl($availabilityOption['code']) }}" alt="" class="h-3.5 w-5 shrink-0 rounded-[2px] object-cover ring-1 ring-zinc-200" loading="lazy">
                            @endif
                            <span class="truncate">{{ $availabilityOption['name'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
