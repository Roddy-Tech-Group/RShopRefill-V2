@php
    use App\Models\Product;
    use App\Models\Category;
    use App\Models\Subcategory;
    use Illuminate\Support\Facades\DB;

    abort_if(! \App\Support\FeatureFlag::on('gift_cards'), 404);

    $giftCardsCategory = Category::where('slug', 'gift-cards')->first();

    // URL-driven filters. $country is the locked region (resolved by ResolveRegion
    // middleware and shared as $region) — the catalog only ever shows that country.
    $search   = (string) request()->query('q', '');
    $country  = strtoupper((string) ($region ?? 'US'));
    $currency = strtoupper((string) request()->query('currency', ''));
    $sub      = (string) request()->query('subcategory', '');
    $sort     = (string) request()->query('sort', 'popular');

    // Resolve a partial country-name search ("camero", "United Sta") to ISO codes so the
    // user can type a country name in the global nav and get a matching catalog filter.
    $searchCountryCodes = [];
    if ($search !== '') {
        $needle = mb_strtolower(trim($search));
        foreach (config('countries.codes', []) as $cname => $code) {
            if (str_contains(mb_strtolower($cname), $needle)) {
                $searchCountryCodes[] = strtoupper($code);
            }
        }
        if (mb_strlen($needle) >= 1) {
            $searchCountryCodes[] = strtoupper($needle); // also catch raw "us" / "gb" inputs
        }
        $searchCountryCodes = array_values(array_unique(array_filter($searchCountryCodes)));
    }

    /*
     * One card per BRAND, not per (brand × country). Zendit syncs the catalog as one
     * Product row per country (e.g. Apple has 8 country variants), but customer-facing
     * the user just wants to see "Apple" once. They pick country+currency in the locale
     * modal which filters which brands appear, and they pick the denomination later on
     * the detail page.
     *
     * Implementation: filter the products table by the active filters, group by
     * brand_key, take the MIN(id) per group as the representative row to display.
     */
    $baseFilters = Product::query()
        ->where('is_active', true)
        ->whereNotNull('brand_key')
        ->when($giftCardsCategory, fn ($q) => $q->where('category_id', $giftCardsCategory->id))
        ->when($search !== '', fn ($q) => $q->where(function ($qq) use ($search, $searchCountryCodes) {
            $qq->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', '%' . str()->slug($search) . '%')
                ->orWhere('brand_key', 'like', "%{$search}%")
                ->orWhere('country_code', 'like', strtoupper($search) . '%');
            if (! empty($searchCountryCodes)) {
                $qq->orWhereIn('country_code', $searchCountryCodes);
            }
        }))
        ->when($country !== '', fn ($q) => $q->where('country_code', $country))
        ->when($currency !== '', fn ($q) => $q->where('currency_code', $currency))
        // Precise subcategory filter: match products that have at least one
        // VARIANT in this subcategory, not just the product's representative one.
        ->when($sub !== '', fn ($q) => $q->whereHas('variants', fn ($qq) => $qq->whereHas('subcategory', fn ($qqq) => $qqq->where('slug', $sub))));

    // Pluck one representative Product id per brand_key. With ~692 unique brands this is
    // a cheap single SELECT. Prefer the US variant as the representative (matches the
    // detail page, which defaults to US) so the card's price range reflects US pricing;
    // fall back to the lowest id when a brand has no US product.
    $representativeIds = (clone $baseFilters)
        ->select('brand_key', DB::raw("COALESCE(MIN(CASE WHEN country_code = 'US' THEN id END), MIN(id)) as id"))
        ->groupBy('brand_key')
        ->pluck('id');

    $productsQuery = Product::query()
        ->whereIn('id', $representativeIds)
        ->with(['variants:id,product_id,currency,retail_price,cost_price,face_value,min_amount,max_amount,is_variable,is_available']);

    if ($sort === 'name-asc') {
        $productsQuery->orderBy('name');
    } elseif ($sort === 'name-desc') {
        $productsQuery->orderByDesc('name');
    } else {
        // Default (Popularity): the admin's is_popular / is_featured flags
        // lead the page - those are set per product on the admin dashboard
        // and outrank the hand-curated config list, which only breaks ties
        // among unflagged brands. Alphabetical last.
        $productsQuery->orderByDesc('is_popular')->orderByDesc('is_featured');
        $popularKeys = config('popular_brands.keys', []);
        if (! empty($popularKeys)) {
            $placeholders = implode(',', array_fill(0, count($popularKeys), '?'));
            $productsQuery->orderByRaw("CASE WHEN brand_key IN ({$placeholders}) THEN 0 ELSE 1 END", $popularKeys);
        }
        $productsQuery->orderBy('name');
    }

    // The listing shows every matching brand on one page — no pagination.
    $products = $productsQuery->get();

    $subcategories = $giftCardsCategory
        ? Subcategory::where('category_id', $giftCardsCategory->id)->orderBy('name')->get(['name', 'slug'])
        : collect();

    // Green tint for the trust-badge icons (instant delivery / refund policy).
    $greenTint = 'filter: brightness(0) saturate(100%) invert(48%) sepia(79%) saturate(394%) hue-rotate(105deg) brightness(92%) contrast(87%);';

    // Distinct countries + currencies actually available in the synced gift-card catalog.
    $availableCountries = Product::query()
        ->when($giftCardsCategory, fn ($q) => $q->where('category_id', $giftCardsCategory->id))
        ->where('is_active', true)
        ->distinct()
        ->pluck('country_code')
        ->filter()
        ->sort()
        ->values();

    // Country options for the "Shop by country" picker — code + display name.
    $countryNameMap = array_flip(config('countries.codes', []));
    $countryOptions = $availableCountries
        ->map(fn ($c) => ['code' => $c, 'name' => $countryNameMap[$c] ?? $c])
        ->sortBy('name')
        ->values();
    $currentCountryName = $country ? ($countryNameMap[$country] ?? $country) : null;
    // US is the default region — USD, USA products only. Selecting a country
    // narrows to that region; the clear-X resets back to the US default.
    $countryFiltered = $country !== '' && $country !== 'US';

    $availableCurrencies = Product::query()
        ->when($giftCardsCategory, fn ($q) => $q->where('category_id', $giftCardsCategory->id))
        ->where('is_active', true)
        ->distinct()
        ->pluck('currency_code')
        ->filter()
        ->sort()
        ->values();

    // Currency symbols. Falls back to the raw code for anything not in this table.
    $currencySymbols = [
        'USD' => '$',  'EUR' => '€',  'GBP' => '£',  'JPY' => '¥',  'CNY' => '¥',
        'AUD' => 'A$', 'CAD' => 'C$', 'NZD' => 'NZ$', 'CHF' => 'Fr', 'SEK' => 'kr',
        'NOK' => 'kr', 'DKK' => 'kr', 'PLN' => 'zł', 'CZK' => 'Kč', 'HUF' => 'Ft',
        'INR' => '₹',  'KRW' => '₩',  'THB' => '฿',  'IDR' => 'Rp', 'PHP' => '₱',
        'VND' => '₫',  'MYR' => 'RM', 'SGD' => 'S$', 'HKD' => 'HK$','TWD' => 'NT$',
        'BRL' => 'R$', 'MXN' => 'MX$','ARS' => 'AR$','CLP' => 'CL$','COP' => 'CO$',
        'PEN' => 'S/', 'NGN' => '₦',  'GHS' => '₵',  'KES' => 'KSh','UGX' => 'USh',
        'TZS' => 'TSh','ZAR' => 'R',  'EGP' => 'E£', 'MAD' => 'MAD','AED' => 'AED',
        'SAR' => 'SAR','TRY' => '₺',  'ILS' => '₪',  'PKR' => '₨',  'BDT' => '৳',
        'RUB' => '₽',  'UAH' => '₴',  'XAF' => 'XAF ','XOF' => 'CFA',
    ];
    $sym = fn (?string $code) => $code ? ($currencySymbols[strtoupper($code)] ?? $code) : '';

    // Route name swaps between storefront + dashboard chrome so filter, brand,
    // and view-all links keep the user on whichever side they entered from.
    $inDash = request()->is('dashboard/shop*') && auth()->check();
    $shopRoute = fn (string $name, $params = []) => route(($inDash ? 'dashboard.shop.' : 'shop.').$name, $params);

    // Helper to preserve other filters when toggling one
    $filterUrl = function (array $overrides) use ($search, $country, $currency, $sub, $sort, $shopRoute) {
        $params = array_filter([
            'q'           => $search ?: null,
            'country'     => $country ?: null,
            'currency'    => $currency ?: null,
            'subcategory' => $sub ?: null,
            'sort'        => $sort !== 'popular' ? $sort : null,
        ], fn ($v) => $v !== null);
        foreach ($overrides as $k => $v) {
            if ($v === null) {
                unset($params[$k]);
            } else {
                $params[$k] = $v;
            }
        }
        return $shopRoute('gift-cards', $params);
    };

    // Subcategory links for the shared sidebar — "All" first, then each subtype.
    $sidebarSubItems = array_merge(
        [['label' => 'All gift cards', 'url' => $filterUrl(['subcategory' => null]), 'active' => $sub === '']],
        $subcategories->map(fn ($s) => [
            'label' => $s->name,
            'url' => $filterUrl(['subcategory' => $s->slug]),
            'active' => $sub === $s->slug,
        ])->all()
    );

@endphp

<x-shop.layout title="Gift Cards | {{ $siteName }}">

    <section class="min-h-full rounded-[15px] bg-[#eff6ff]">
        <div class="mx-auto w-full max-w-[1550px] px-4 py-8 sm:px-6 lg:px-8">

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-[220px_1fr] lg:gap-8">

                {{-- Shared category sidebar — same component on every storefront. --}}
                <x-shop.category-sidebar active="gift-cards" :sub-items="$sidebarSubItems" />

                {{-- Main column --}}
                <div>
                    {{-- Mobile category picker (dark pill + slide-up sheet). Replaces
                         the hidden desktop sidebar at < sm so customers can hop between
                         categories without leaving the page. --}}
                    <div class="mb-4 sm:hidden">
                        <x-shop.category-picker active="gift-cards" :sub-items="$sidebarSubItems" />
                    </div>

                    {{-- Heading + search row --}}
                    <div class="mb-6 flex flex-col gap-3 sm:grid sm:grid-cols-3 sm:items-center sm:gap-4">
                        <div class="hidden sm:block">
                            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl">Gift Cards</h1>
                            <div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm font-semibold text-zinc-700">
                                <span class="flex items-center gap-1.5">
                                    <img src="{{ asset('assets/' . rawurlencode('instant delivery.webp')) }}" alt="" class="h-4 w-4 object-contain" style="{{ $greenTint }}" loading="lazy">
                                    Instant Delivery
                                </span>
                                <a href="{{ route('shop.refund-policy') }}" wire:navigate class="flex items-center gap-1.5 underline-offset-2 transition-colors hover:text-blue-600 hover:underline">
                                    <img src="{{ asset('assets/' . rawurlencode('Fair Redund Policy.webp')) }}" alt="" class="h-4 w-4 object-contain" style="{{ $greenTint }}" loading="lazy">
                                    Clear Refund Policy
                                </a>
                            </div>
                        </div>

                        {{-- Global product search (all categories) - centered middle column. --}}
                        <x-shop.product-search class="sm:max-w-sm sm:justify-self-center" />

                        {{-- Modern segmented sort selector. URL-driven so the choice survives reloads;
                             each pill is a real <a> that updates ?sort= while preserving other filters. --}}
                        <div class="inline-flex shrink-0 items-center rounded-[12px] bg-zinc-200 p-1 sm:justify-self-end" role="tablist" aria-label="Sort gift cards">
                            @foreach ([
                                ['value' => 'popular',   'label' => 'Popularity'],
                                ['value' => 'name-asc',  'label' => 'A → Z'],
                                ['value' => 'name-desc', 'label' => 'Z → A'],
                            ] as $opt)
                                <a
                                    href="{{ $filterUrl(['sort' => $opt['value'] === 'popular' ? null : $opt['value']]) }}"
                                    wire:navigate
                                    role="tab"
                                    aria-selected="{{ $sort === $opt['value'] ? 'true' : 'false' }}"
                                    class="inline-flex items-center justify-center rounded-[12px] px-3 py-1.5 text-xs font-semibold transition-all {{ $sort === $opt['value'] ? 'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-200' : 'text-zinc-600 hover:bg-white/70 hover:text-zinc-900' }}"
                                >
                                    {{ $opt['label'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>

                    {{-- Mobile/tablet subcategory pill row (sidebar hidden below lg) --}}
                    @if ($subcategories->isNotEmpty())
                        <div class="-mx-1 mb-6 hidden overflow-x-auto px-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden md:block lg:hidden">
                            <div class="flex w-max items-center gap-2">
                                <a
                                    href="{{ $filterUrl(['subcategory' => null]) }}"
                                    wire:navigate
                                    class="inline-flex shrink-0 items-center rounded-[12px] px-4 py-1.5 text-xs font-semibold transition-colors {{ $sub === '' ? 'bg-zinc-900 text-white' : 'bg-white text-zinc-700 ring-1 ring-zinc-200' }}"
                                >
                                    All gift cards
                                </a>
                                @foreach ($subcategories as $s)
                                    <a
                                        href="{{ $filterUrl(['subcategory' => $s->slug]) }}"
                                        wire:navigate
                                        class="inline-flex shrink-0 items-center rounded-[12px] px-4 py-1.5 text-xs font-semibold transition-colors {{ $sub === $s->slug ? 'bg-zinc-900 text-white' : 'bg-white text-zinc-700 ring-1 ring-zinc-200' }}"
                                    >
                                        {{ $s->name }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Product grid. Skeleton overlay removed — it was rendering misaligned with the real
                         grid and flashing on every navigation. wire:navigate transitions are fast enough
                         without a synthetic loading state. --}}
                    <div>
                        @if ($products->isNotEmpty())
                            <ul class="grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                                @foreach ($products as $product)
                                    @php
                                        $variants   = $product->variants;
                                        $available  = $variants->where('is_available', true);
                                        $isOut      = $variants->isNotEmpty() && $available->isEmpty();
                                        // Min-to-max range across ALL denominations (fixed + variable), in the product's currency.
                                        $priceLabel = $product->priceRangeLabel();
                                        $logoSrc    = Product::brandLogoUrl($product->brand_key, $product->logo_url);
                                    @endphp
                                    <li>
                                        <a
                                            href="{{ $shopRoute('brand', ['brandSlug' => Product::brandSlug($product->brand_key), 'country' => $product->country_code]) }}"
                                            wire:navigate
                                            class="card-3d-scene group block focus:outline-none"
                                            aria-label="{{ Product::brandDisplayName($product->brand_key) }}"
                                        >
                                            {{-- Logo tile. bg uses a literal hex so the dark-mode
                                                 remap keeps it white — brand logos are drawn for a
                                                 light tile and vanish on a dark one. --}}
                                            <div
                                                class="relative flex aspect-[16/10] items-center justify-center overflow-hidden rounded-[14px] bg-[#ffffff] shadow-sm ring-1 ring-zinc-200"
                                            >
                                                @if ($logoSrc)
                                                    <img src="{{ $logoSrc }}" alt="{{ Product::brandDisplayName($product->brand_key) }}" class="h-full w-full object-cover" loading="lazy">
                                                @else
                                                    <span class="text-2xl font-black tracking-tight text-[#3f3f46]">
                                                        {{ str(Product::brandDisplayName($product->brand_key))->substr(0, 2)->upper() }}
                                                    </span>
                                                @endif

                                                @if ($isOut)
                                                    <span class="absolute bottom-2 right-2 inline-flex items-center rounded-full border border-white bg-zinc-900/60 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white backdrop-blur-sm">
                                                        Out of stock
                                                    </span>
                                                @elseif ($product->is_popular)
                                                    {{-- Round pill with a soft fuchsia glow halo. --}}
                                                    <span class="absolute left-2 top-2 inline-flex items-center rounded-full border border-fuchsia-500 bg-fuchsia-500/10 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-fuchsia-600 shadow-[0_0_12px_rgba(217,70,239,0.55)] backdrop-blur-sm">Popular</span>
                                                @elseif ($product->is_featured)
                                                    {{-- Round pill with a soft amber glow halo. --}}
                                                    <span class="absolute left-2 top-2 inline-flex items-center rounded-full border border-amber-500 bg-amber-500/10 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-600 shadow-[0_0_12px_rgba(245,158,11,0.6)] backdrop-blur-sm">Featured</span>
                                                @endif

                                            </div>

                                            {{-- Caption — brand name + the real min-to-max denomination range. --}}
                                            <div class="mt-2 px-0.5">
                                                <p class="truncate text-[13px] font-bold leading-tight text-zinc-900">{{ Product::brandDisplayName($product->brand_key) }}</p>
                                                @if ($priceLabel)
                                                    <p class="mt-0.5 truncate text-[14px] text-zinc-600">{{ $priceLabel }}</p>
                                                @endif
                                            </div>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>

                        @else
                            <div class="rounded-[12px] bg-white px-6 py-20 text-center ring-1 ring-zinc-200">
                                <img src="{{ asset('assets/' . rawurlencode('Empty state.webp')) }}" alt="" class="mx-auto block h-44 w-auto object-contain" loading="lazy">
                                <p class="mt-4 text-base font-semibold text-zinc-900">No gift cards match these filters</p>
                                <p class="mt-1 text-sm text-zinc-600">Try clearing the search or pick a different category.</p>
                                @if ($search !== '' || $countryFiltered || $sub !== '')
                                    <a href="{{ $shopRoute('gift-cards') }}" wire:navigate class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-700">
                                        Clear all filters
                                    </a>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>

        </div>
    </section>

</x-shop.layout>
