@php
    use App\Domain\Cart\Services\CartPricingService;
    use App\Models\Category;
    use App\Models\Product;
    use App\Models\ProductVariant;
    use Illuminate\Support\Facades\DB;

    // Pricing engine — resolves the marked-up USD sales price using the
    // product → subcategory → category → global rule hierarchy in
    // pricing_rules. Same engine the cart uses at checkout, so what an
    // admin sees in this grid matches what the customer would pay.
    $pricing = app(CartPricingService::class);

    // Face values are stored in each card's own currency (a £1000 Amazon GB
    // card stores 1000/GBP) while cost_price is USD — these rates convert the
    // value before any USD comparison in the grid.
    $ratesPerUsd = \App\Models\CurrencyRate::query()
        ->where('is_active', true)
        ->pluck('rate_per_usd', 'code')
        ->map(fn ($r) => (float) $r);

    $search = request()->query('q', '');
    $categorySlug = request()->query('category', 'all');                   // Product Type filter
    $subtypeFilter = (string) request()->query('subtype', '');             // eSIM sub-type: 'data' | 'voice'
    $country = strtoupper((string) request()->query('country', 'all'));    // Country filter (ISO-2)
    $brandKey = (string) request()->query('brand', '');                    // Brand filter (brand_key)
    $providerName = (string) request()->query('provider', '');             // Provider filter (zendit | airalo)
    $regionFilter = (string) request()->query('region', '');               // Region filter (continent name)
    $perPage = 25;

    // Pills: real categories from DB + an "All" sentinel that maps to no filter.
    $categoryPills = collect([['name' => 'All', 'slug' => 'all']])
        ->merge(Category::orderBy('sort_order')->get(['name', 'slug'])->toArray());

    // Country options for the filter dropdown — every country that has products,
    // with its product count, ordered by volume. config/countries.php maps ISO → name.
    $countryNames = array_flip(config('countries.codes', []));
    $countryOptions = Product::query()
        ->select('country_code', DB::raw('COUNT(*) as product_count'))
        ->whereNotNull('country_code')
        ->groupBy('country_code')
        ->orderByDesc('product_count')
        ->get();

    $anyFilter = $search !== '' || $categorySlug !== 'all' || $country !== 'ALL';

    $countryFilterUrl = fn (?string $code) => route('admin.products', array_filter([
        'q' => $search ?: null,
        'category' => $categorySlug !== 'all' ? $categorySlug : null,
        'country' => $code,
    ]));

    $selectedCountryName = $country !== 'ALL' ? ($countryNames[$country] ?? $country) : null;

    // Search resolves against the product name + country code + country name.
    $matchedCountryCodes = [];
    if ($search !== '') {
        $needle = mb_strtolower(trim($search));
        foreach (config('countries.codes', []) as $name => $code) {
            if ($needle !== '' && str_contains(mb_strtolower($name), $needle)) {
                $matchedCountryCodes[] = strtoupper($code);
            }
        }
        if (mb_strlen($needle) >= 1) {
            $matchedCountryCodes[] = strtoupper($needle);
        }
        $matchedCountryCodes = array_values(array_unique(array_filter($matchedCountryCodes)));
    }

    // Region map — derived from country_code via config/continents.php. Used
    // as the FALLBACK when a variant has no metadata.regions value.
    $countryToContinent = (array) config('continents.codes', []);
    $regionToCountries = [];
    foreach ($countryToContinent as $code => $continent) {
        $regionToCountries[$continent][] = strtoupper($code);
    }

    // Real regions actually populated in metadata.regions (Zendit's canonical
    // buckets: Africa, Western Europe, North America, Caribbean, Middle East
    // and North Africa, etc.). Pull DISTINCT live values so the filter only
    // ever offers regions that have at least one product.
    $metaRegionRows = DB::table('product_variants')
        ->selectRaw("DISTINCT JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.regions[0]')) as region")
        ->whereRaw("JSON_EXTRACT(metadata, '$.regions[0]') IS NOT NULL")
        ->pluck('region')
        ->filter()
        ->values()
        ->all();

    // Final filter list = real metadata regions ∪ continent fallbacks ∪ Global.
    // "Global" matches eSIMs whose coverage spans many countries — captured
    // via metadata.coverage / metadata.countries length in the query below.
    $availableRegions = collect($metaRegionRows)
        ->merge(array_keys($regionToCountries))
        ->unique()
        ->sort()
        ->values()
        ->prepend('Global')
        ->all();

    // Per-variant rows. Each variant is its own card — eSIM "Faster" vs "Standard"
    // become independent rows so the admin sees pricing per SKU. Joins to the
    // owning product + category + subcategory for context. Real filters live
    // alongside search: brand, product type (category), country, region.
    $variants = ProductVariant::query()
        ->select('product_variants.*')
        ->with(['product:id,name,brand_key,country_code,category_id,subcategory_id,logo_url,is_active', 'product.category:id,name,slug', 'subcategory:id,name'])
        ->join('products', 'products.id', '=', 'product_variants.product_id')
        ->when($search !== '', function ($q) use ($search, $matchedCountryCodes) {
            $q->where(function ($qq) use ($search, $matchedCountryCodes) {
                $qq->where('products.name', 'like', "%{$search}%")
                    ->orWhere('products.country_code', 'like', strtoupper($search) . '%')
                    ->orWhere('product_variants.sku', 'like', "%{$search}%");
                if (! empty($matchedCountryCodes)) {
                    $qq->orWhereIn('products.country_code', $matchedCountryCodes);
                }
            });
        })
        ->when($categorySlug !== 'all', fn ($q) => $q->whereHas('product.category', fn ($qq) => $qq->where('slug', $categorySlug)))
        ->when($subtypeFilter === 'data', fn ($q) => $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(product_variants.metadata, '$.plan_type')) = 'data_only'"))
        ->when($subtypeFilter === 'voice', fn ($q) => $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(product_variants.metadata, '$.plan_type')) IN ('voice_sms_data', 'voice_only')"))
        ->when($country !== 'ALL', fn ($q) => $q->where('products.country_code', $country))
        ->when($brandKey !== '', fn ($q) => $q->where('products.brand_key', $brandKey))
        ->when($providerName !== '', fn ($q) => $q->where('products.provider_name', $providerName))
        ->when($regionFilter !== '', function ($q) use ($regionFilter, $regionToCountries) {
            // Smart region filter — matches three sources:
            //   1. metadata.regions[0] = X  (Zendit's canonical bucket)
            //   2. country_code in continent X (fallback for variants without
            //      metadata.regions, like eSIMs)
            //   3. "Global" = coverage spans >5 countries (multi-country eSIMs
            //      that effectively cover a region or the world)
            $q->where(function ($qq) use ($regionFilter, $regionToCountries) {
                if ($regionFilter === 'Global') {
                    // WW is the explicit Worldwide marker we set on Global
                    // products at sync time (Airalo's "Global eSIM" etc.).
                    // The JSON_LENGTH checks catch multi-country variants whose
                    // metadata enumerates coverage, but Airalo's single 'WW'
                    // bucket needs the direct country_code match too.
                    $qq->where('products.country_code', 'WW')
                       ->orWhereRaw("JSON_LENGTH(JSON_EXTRACT(product_variants.metadata, '$.coverage')) > 5")
                       ->orWhereRaw("JSON_LENGTH(JSON_EXTRACT(product_variants.metadata, '$.countries')) > 5")
                       ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(product_variants.metadata, '$.regions[0]')) IN ('Global', 'World', 'Worldwide')");

                    return;
                }

                $qq->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(product_variants.metadata, '$.regions[0]')) = ?", [$regionFilter]);

                if (isset($regionToCountries[$regionFilter])) {
                    $qq->orWhere(function ($qqq) use ($regionFilter, $regionToCountries) {
                        $qqq->whereRaw("JSON_EXTRACT(product_variants.metadata, '$.regions[0]') IS NULL")
                            ->whereIn('products.country_code', $regionToCountries[$regionFilter]);
                    });
                }
            });
        })
        ->orderByDesc('product_variants.id')
        ->paginate($perPage)
        ->withQueryString();

    // Brand options for the filter dropdown — distinct brand_keys with their
    // first product name so we can show display labels.
    $brandOptions = Product::query()
        ->select('brand_key', DB::raw('MIN(name) as label'))
        ->whereNotNull('brand_key')
        ->groupBy('brand_key')
        ->orderBy('brand_key')
        ->get();

    // Provider options — for categories like eSIMs where products carry NO
    // brand_key, the supplier itself (Airalo / Zendit) is the de-facto brand.
    // Surface them in the Brand dropdown as a separate "By Provider" section.
    $providerOptions = Product::query()
        ->select('provider_name', DB::raw('COUNT(*) as cnt'))
        ->whereNotNull('provider_name')
        ->where('provider_name', '!=', '')
        ->groupBy('provider_name')
        ->orderBy('provider_name')
        ->get();

    // Helper to build a URL that toggles a single filter while preserving the others.
    $filterUrl = function (array $overrides) use ($search, $categorySlug, $subtypeFilter, $country, $brandKey, $providerName, $regionFilter) {
        return route('admin.products', array_filter([
            'q' => $search ?: null,
            'category' => $categorySlug !== 'all' ? $categorySlug : null,
            'subtype' => $subtypeFilter ?: null,
            'country' => $country !== 'ALL' ? $country : null,
            'brand' => $brandKey ?: null,
            'provider' => $providerName ?: null,
            'region' => $regionFilter ?: null,
            ...$overrides,
        ], fn ($v) => $v !== null && $v !== ''));
    };

    $stats = [
        'total'    => Product::count(),
        'active'   => Product::where('is_active', true)->count(),
        'featured' => Product::where('is_featured', true)->count(),
        'popular'  => Product::where('is_popular', true)->count(),
    ];

    // $countryToContinent already defined above with the region filter map.
@endphp

<x-layouts.admin>
    <x-slot:heading>All Products</x-slot:heading>
    <x-slot:subheading>{{ number_format($stats['total']) }} products synced. Manage status, featured and popular flags across the catalog.</x-slot:subheading>

    <div class="flex flex-1 flex-col gap-6">

        {{-- KPI strip — quiet, scannable. Tints sit on the dot indicator, not the whole pill. --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ([
                ['label' => 'Total',    'value' => $stats['total'],    'dot' => 'bg-blue-500'],
                ['label' => 'Active',   'value' => $stats['active'],   'dot' => 'bg-emerald-500'],
                ['label' => 'Featured', 'value' => $stats['featured'], 'dot' => 'bg-amber-500'],
                ['label' => 'Popular',  'value' => $stats['popular'],  'dot' => 'bg-fuchsia-500'],
            ] as $stat)
                <div class="rounded-[12px] bg-white p-4 shadow-sm shadow-zinc-900/[0.04] ring-1 ring-zinc-100">
                    <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-600">
                        <span class="inline-block h-1.5 w-1.5 rounded-full {{ $stat['dot'] }}"></span>
                        {{ $stat['label'] }}
                    </p>
                    <p class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">{{ number_format($stat['value']) }}</p>
                </div>
            @endforeach
        </div>

        {{-- Search + country filter + Add row --}}
        <form method="GET" action="{{ route('admin.products') }}" class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
            {{-- Live-search input. Typing fires a debounced fetch against
                 admin.products.search-suggest and renders matches in a glass
                 dropdown beneath the input. Pressing Enter still submits the
                 form for a full filtered page (escape hatch). --}}
            <div
                x-data="adminProductSearch()"
                @keydown.escape="results = []"
                @click.outside="results = []"
                class="relative flex-1"
            >
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input
                    type="search"
                    name="q"
                    x-model.debounce.250ms="query"
                    @input="suggest()"
                    value="{{ $search }}"
                    placeholder="Search products by name, SKU or country (e.g. Cameroon, US, ESIM-AD)"
                    class="w-full rounded-[12px] border border-zinc-200 bg-white py-2.5 pl-10 pr-3 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-white"
                />
                @if ($categorySlug !== 'all')
                    <input type="hidden" name="category" value="{{ $categorySlug }}">
                @endif

                {{-- Glass dropdown. Renders only when there's >= 1 match. Each
                     row is a click target that navigates to the filtered page
                     (uses the same `q=` URL param). --}}
                <div
                    x-show="results.length > 0 || loading"
                    x-cloak
                    x-transition.opacity
                    class="absolute left-0 right-0 top-full z-40 mt-2 overflow-hidden rounded-[12px] bg-white/90 ring-1 ring-zinc-900/10 shadow-2xl shadow-zinc-900/20 backdrop-blur-xl dark:bg-[#0c1a36]/95 dark:ring-white/10 dark:shadow-black/40"
                >
                    {{-- Loading state --}}
                    <div x-show="loading" class="flex items-center gap-2 px-4 py-3 text-[12px] text-zinc-500 dark:text-zinc-400">
                        <svg class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/>
                            <path d="M22 12a10 10 0 01-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                        Searching…
                    </div>

                    {{-- Results --}}
                    <ul x-show="! loading && results.length > 0" class="max-h-80 overflow-y-auto p-1.5">
                        <template x-for="row in results" :key="row.id">
                            <li>
                                <a
                                    :href="'{{ route('admin.products') }}?q=' + encodeURIComponent(row.name || row.sku)"
                                    wire:navigate
                                    class="flex items-center gap-3 rounded-[12px] px-3 py-2 transition-colors hover:bg-blue-50 dark:hover:bg-blue-500/10"
                                >
                                    <template x-if="row.logo">
                                        <img :src="row.logo" alt="" class="h-9 w-9 shrink-0 rounded-[12px] object-contain bg-white ring-1 ring-zinc-100 dark:ring-white/10" loading="lazy">
                                    </template>
                                    <template x-if="! row.logo">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] bg-blue-50 text-[11px] font-bold uppercase text-blue-700 ring-1 ring-blue-100 dark:bg-blue-500/15 dark:text-blue-200 dark:ring-blue-400/20" x-text="(row.brand || row.name || row.sku || '').replace(/[^A-Za-z0-9]/g,'').slice(0,2).toUpperCase() || '—'"></span>
                                    </template>
                                    <span class="min-w-0 flex-1 leading-tight">
                                        <span class="block truncate text-[13px] font-semibold text-zinc-900 dark:text-white" x-text="row.brand || row.name || row.sku"></span>
                                        <span class="block truncate text-[11px] text-zinc-500 dark:text-zinc-400">
                                            <span x-show="row.category" x-text="row.category"></span>
                                            <span x-show="row.country"> · <span x-text="row.country"></span></span>
                                            <span x-show="row.sku"> · <span class="font-mono" x-text="row.sku"></span></span>
                                        </span>
                                    </span>
                                    <span class="shrink-0 text-right text-[11px] tabular-nums">
                                        <span class="block text-zinc-500 dark:text-zinc-400">Cost</span>
                                        <span class="block font-semibold text-zinc-900 dark:text-white" x-text="'USD ' + Number(row.cost).toFixed(2)"></span>
                                    </span>
                                </a>
                            </li>
                        </template>
                    </ul>

                    {{-- Footer: open full filtered page with current query --}}
                    <div x-show="! loading && results.length > 0" class="border-t border-zinc-200/60 px-4 py-2 text-[11px] text-zinc-500 dark:border-white/10 dark:text-zinc-400">
                        Press <kbd class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] text-zinc-700 dark:bg-white/10 dark:text-zinc-200">Enter</kbd> to see all matches
                    </div>
                </div>
            </div>

            <script>
                // Live-search controller — debounced fetch against the admin
                // search-suggest endpoint, races-safe via an incrementing
                // requestId so an in-flight slow request can't overwrite a
                // newer one.
                window.adminProductSearch = function () {
                    return {
                        query: @js($search),
                        results: [],
                        loading: false,
                        _seq: 0,

                        async suggest() {
                            const q = (this.query || '').trim();
                            if (q.length < 2) { this.results = []; this.loading = false; return; }

                            const mySeq = ++this._seq;
                            this.loading = true;
                            try {
                                const res = await fetch('{{ route('admin.products.search-suggest') }}?q=' + encodeURIComponent(q), {
                                    headers: { 'Accept': 'application/json' },
                                });
                                if (mySeq !== this._seq) { return; } // stale response, drop
                                this.results = await res.json();
                            } catch (e) {
                                this.results = [];
                            } finally {
                                if (mySeq === this._seq) { this.loading = false; }
                            }
                        },
                    };
                };
            </script>

            {{-- Country filter — modern searchable dropdown. Each option is a real link
                 that preserves the search + category filters. --}}
            <div
                x-data="{ open: false, search: '' }"
                @click.outside="open = false"
                @keydown.escape="open = false"
                class="relative sm:w-60"
            >
                <button
                    type="button"
                    @click="open = ! open; if (open) $nextTick(() => $refs.countrySearch.focus())"
                    :class="open ? 'border-blue-500 ring-2 ring-blue-500/15' : 'border-zinc-200 hover:border-zinc-400'"
                    class="flex w-full items-center gap-2 rounded-[12px] border bg-white py-2.5 pl-3 pr-3 text-sm text-zinc-900 outline-none transition-colors"
                >
                    <span class="flex-1 truncate text-left font-medium">
                        @if ($selectedCountryName)
                            @if (Product::flagUrl($country))
                                <img src="{{ Product::flagUrl($country) }}" alt="" class="mr-1.5 inline-block h-3.5 w-5 rounded-[2px] object-cover align-[-3px] ring-1 ring-zinc-200">
                            @endif
                            {{ $selectedCountryName }}
                        @else
                            All countries
                        @endif
                    </span>
                    <svg class="h-4 w-4 shrink-0 text-zinc-500 transition-transform duration-150" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                <div
                    x-show="open"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    style="display:none;"
                    class="absolute left-0 right-0 top-full z-30 mt-2 overflow-hidden rounded-[12px] border border-zinc-200 bg-white shadow-xl shadow-zinc-900/10"
                >
                    <div class="border-b border-zinc-100 p-2">
                        <input
                            x-ref="countrySearch"
                            x-model="search"
                            type="text"
                            placeholder="Search countries"
                            class="w-full rounded-[12px] border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-800 outline-none transition-colors focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-500/15"
                        >
                    </div>
                    <div class="max-h-72 overflow-y-auto p-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                        <a
                            href="{{ $countryFilterUrl(null) }}"
                            class="flex items-center justify-between rounded-[12px] px-3 py-2 text-sm font-medium transition-colors {{ $country === 'ALL' ? 'bg-blue-50 text-blue-700' : 'text-zinc-800 hover:bg-zinc-100' }}"
                        >
                            <span>All countries</span>
                            <span class="text-xs text-zinc-500">{{ number_format($stats['total']) }}</span>
                        </a>
                        @foreach ($countryOptions as $opt)
                            @php
                                $code = strtoupper($opt->country_code);
                                $name = $countryNames[$code] ?? $code;
                            @endphp
                            <a
                                href="{{ $countryFilterUrl($code) }}"
                                x-show="'{{ Str::lower($name . ' ' . $code) }}'.includes(search.toLowerCase())"
                                class="flex items-center justify-between gap-2 rounded-[12px] px-3 py-2 text-sm font-medium transition-colors {{ $country === $code ? 'bg-blue-50 text-blue-700' : 'text-zinc-800 hover:bg-zinc-100' }}"
                            >
                                <span class="flex min-w-0 items-center gap-2">
                                    @if (Product::flagUrl($code))
                                        <img src="{{ Product::flagUrl($code) }}" alt="" class="h-3.5 w-5 shrink-0 rounded-[2px] object-cover ring-1 ring-zinc-200" loading="lazy">
                                    @endif
                                    <span class="truncate">{{ $name }}</span>
                                </span>
                                <span class="shrink-0 text-xs text-zinc-500">{{ $opt->product_count }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>

            @if ($anyFilter)
                <a href="{{ route('admin.products') }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 rounded-[12px] border border-zinc-200 bg-white px-4 py-2.5 text-sm font-semibold text-zinc-700 transition-colors hover:bg-zinc-50">
                    <img src="{{ asset('assets/' . rawurlencode('x button.webp')) }}" alt="" class="h-4 w-4 object-contain" loading="lazy">
                    Clear filters
                </a>
            @endif

            {{-- One-tap full Zendit catalog sync (queued background job). --}}
            <livewire:admin.sync-products-button />
        </form>

        {{-- Category filter pills — glass treatment with an icon per category.
             Server-routed via query string. Hidden on mobile to remove the
             horizontal-slide bar (filtering on mobile can be added later). --}}
        @php
            // SVG asset per category slug, sitting in public/assets/. Each
            // chip renders the matching file as an <img>; falls back to a
            // neutral menu icon for any slug we don't have art for yet.
            $categoryIcons = [
                'all' => 'Hamburger menu.svg',
                'gift-cards' => 'gift cards.svg',
                'esims' => 'esim.svg',
                'mobile-airtime' => 'mobile.svg',
                'bill-payments' => 'bill payment.svg',
            ];
        @endphp
        <div class="-mx-1 hidden overflow-x-auto px-1 py-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden md:block">
            <div class="flex w-max items-center gap-2">
                @foreach ($categoryPills as $pill)
                    @php
                        $isActive = $categorySlug === $pill['slug'];
                        $href = route('admin.products', array_filter([
                            'category' => $pill['slug'] === 'all' ? null : $pill['slug'],
                            'q' => $search ?: null,
                        ]));
                        $iconFile = $categoryIcons[$pill['slug']] ?? $categoryIcons['all'];
                    @endphp
                    <a
                        href="{{ $href }}"
                        wire:navigate
                        @class([
                            'group relative inline-flex items-center gap-2 rounded-[12px] px-4 py-2 text-sm font-semibold ring-1 backdrop-blur-md transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40',
                            // Active: solid blue glass — pops as the picked filter. Icon is whitened to read on the blue bg.
                            'bg-blue-600/90 text-white ring-blue-500/50 shadow-lg shadow-blue-600/20' => $isActive,
                            // Inactive: frosted glass — translucent panel with subtle hairline ring
                            'bg-white/60 text-zinc-800 ring-zinc-200/70 hover:bg-white/80 dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10 dark:hover:bg-white/10' => ! $isActive,
                        ])
                    >
                        <img
                            src="{{ asset('assets/' . rawurlencode($iconFile)) }}"
                            alt=""
                            @class([
                                'h-4 w-4 shrink-0 object-contain',
                                // Whiten the icon when active so it reads on the blue bg
                                'brightness-0 invert' => $isActive,
                            ])
                            loading="lazy"
                        >
                        {{ $pill['name'] }}
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Product table.
             Skeleton rows are layered under the real table and shown during wire:navigate
             page transitions (livewire:navigating fires on link click, ends on navigated).
             This relies on the global `wire:navigate` flow that's already in the project. --}}
        {{-- Per-variant pills laid out as a table-grid. A shared grid-template
             on the header + every row keeps every column dead-aligned so the
             eye can scan a column top-to-bottom without zig-zag. The "Your
             Price · Margin · FX" trio gets a soft blue bg extension on the
             right to flag it as the pricing-tool zone (matches the reference). --}}
        <style>
            /* Single source of truth for column widths. Header + rows both use
               .variant-row so any tweak here updates both. Same 9 columns at
               every viewport; the parent .variant-scroll wraps it in a
               horizontal scroller so mobile gets the full table by swiping
               sideways instead of hiding 6 columns. */
            .variant-row {
                display: grid;
                grid-template-columns:
                    minmax(160px, 1.4fr)    /* Brand (name only, no logo) */
                    minmax(120px, 1fr)      /* Product Type */
                    minmax(100px, 0.9fr)    /* Country */
                    minmax(110px, 1fr)      /* Region */
                    minmax(110px, 1fr)      /* Cost Price */
                    minmax(110px, 1fr)      /* Value */
                    minmax(90px,  0.8fr)    /* Discount % */
                    minmax(120px, 1fr)      /* Sales Price (highlighted) */
                    minmax(160px, 1.4fr);   /* Benefits */
                gap: 1.25rem;
                align-items: center;
                /* Keep the row wide on small screens; the parent scroller
                   lets the user pan horizontally to reach hidden columns. */
                min-width: 1180px;
            }
            /* Sticky header row inside the scroller. Stays pinned to the top
               of the scroll viewport while body rows pan vertically + the
               whole table pans horizontally on mobile. */
            .variant-header {
                position: sticky;
                top: 0;
                z-index: 20;
            }
            /* Inset divider between body rows. The line stops 1.5rem short of
               each edge so it doesn't run into the card's rounded corners. */
            .variant-body:not(:last-of-type)::after {
                content: '';
                position: absolute;
                left: 1.5rem;
                right: 1.5rem;
                bottom: 0;
                height: 1px;
                background-color: rgb(244 244 245);
                pointer-events: none;
            }
            html.dark .variant-body:not(:last-of-type)::after {
                background-color: rgb(255 255 255 / 0.08);
            }
            /* Hide the divider line when hovering — otherwise it paints over
               the bottom edge of the hover ring (ring + divider both sit at
               y = bottom of the row, divider wins because ::after is the
               last-painted element). */
            .variant-body:hover::after {
                display: none;
            }
            /* Match the header pill's 10px corner radius on hover so the
               ring reads as the same shape language. */
            .variant-body:hover {
                border-radius: 10px;
            }
        </style>

        <div
            x-data="{ navigating: false }"
            x-on:livewire:navigate.window="navigating = true"
            x-on:livewire:navigated.window="navigating = false"
            class="relative overflow-hidden rounded-[12px] border-[1.5px] border-white bg-white shadow-sm shadow-zinc-900/[0.04] dark:bg-[#1d3252] dark:border-white"
        >
          <div class="overflow-x-auto [scrollbar-width:thin] [&::-webkit-scrollbar]:h-1.5 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-zinc-300 dark:[&::-webkit-scrollbar-thumb]:bg-zinc-600">

            {{-- Header row — its own pill card. Each filterable column owns
                 its own Alpine scope with a dropdown anchored directly beneath
                 the column header so the panel appears exactly where clicked. --}}
            @php
                $filterIcon = asset('assets/'.rawurlencode('filter to be used as black and white for light mode and dark mode leave origianl color if only asked.webp'));
                $activeItem = 'bg-blue-50 text-blue-700 dark:bg-blue-600/15 dark:text-blue-300';
                $inactiveItem = 'text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-white/5';
                $itemBase = 'block truncate rounded-[12px] px-3 py-2 text-[12px] font-medium transition-colors';
                $panelBase = 'absolute left-0 top-full z-40 mt-2 w-72 overflow-hidden rounded-[12px] bg-white shadow-xl shadow-zinc-900/15 ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-zinc-700/60';
            @endphp
            <div class="variant-row variant-header grid mx-3 my-3 rounded-[12px] bg-blue-50 px-6 py-3 text-[10px] font-bold uppercase tracking-wider text-blue-700 ring-2 ring-blue-500 dark:bg-blue-600/15 dark:text-blue-300 dark:ring-blue-400">

                {{-- BRAND --}}
                <span class="col-brand relative" x-data="{ open: false, search: '' }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <span class="inline-flex items-center gap-1.5">
                        Brand
                        <button type="button" @click="open = ! open" aria-label="Filter brand" class="flex h-5 w-5 items-center justify-center rounded-[6px] text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10 dark:hover:text-white">
                            <img src="{{ $filterIcon }}" alt="" class="h-3 w-3 object-contain opacity-70 brightness-0 dark:invert">
                        </button>
                    </span>
                    <div x-show="open" x-cloak x-transition.opacity class="{{ $panelBase }}">
                        <div class="border-b border-zinc-100 p-2 dark:border-zinc-700/60">
                            <input x-model="search" type="text" placeholder="Search brands…" class="w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-[12px] outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-white">
                        </div>
                        <div class="max-h-80 overflow-y-auto p-1">
                            <a href="{{ $filterUrl(['brand' => null, 'provider' => null]) }}" wire:navigate class="{{ $itemBase }} {{ $brandKey === '' && $providerName === '' ? $activeItem : $inactiveItem }}">All brands</a>

                            {{-- Provider section — relevant for categories like eSIMs
                                 that have no brand_key (Airalo / Zendit are the de-facto brands). --}}
                            @if ($providerOptions->isNotEmpty())
                                <p class="mt-2 px-3 pb-1 text-[9px] font-bold uppercase tracking-wider text-zinc-400">By Provider</p>
                                @foreach ($providerOptions as $opt)
                                    <a href="{{ $filterUrl(['provider' => $opt->provider_name, 'brand' => null]) }}" wire:navigate
                                       x-show="search === '' || '{{ Str::lower($opt->provider_name) }}'.includes(search.toLowerCase())"
                                       class="{{ $itemBase }} {{ $providerName === $opt->provider_name ? $activeItem : $inactiveItem }}">
                                        {{ ucfirst($opt->provider_name) }}
                                        <span class="ml-1 text-[10px] text-zinc-400">{{ $opt->cnt }}</span>
                                    </a>
                                @endforeach
                            @endif

                            {{-- Real brands (gift cards, mobile airtime, etc.) --}}
                            @if ($brandOptions->isNotEmpty())
                                <p class="mt-2 px-3 pb-1 text-[9px] font-bold uppercase tracking-wider text-zinc-400">By Brand</p>
                                @foreach ($brandOptions as $opt)
                                    <a href="{{ $filterUrl(['brand' => $opt->brand_key, 'provider' => null]) }}" wire:navigate
                                       x-show="search === '' || '{{ Str::lower($opt->brand_key.' '.$opt->label) }}'.includes(search.toLowerCase())"
                                       class="{{ $itemBase }} {{ $brandKey === $opt->brand_key ? $activeItem : $inactiveItem }}">
                                        {{ \App\Models\Product::brandDisplayName($opt->brand_key) }}
                                    </a>
                                @endforeach
                            @endif
                        </div>
                    </div>
                </span>

                {{-- PRODUCT TYPE --}}
                <span class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <span class="inline-flex items-center gap-1.5">
                        Product Type
                        <button type="button" @click="open = ! open" aria-label="Filter product type" class="flex h-5 w-5 items-center justify-center rounded-[6px] text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10 dark:hover:text-white">
                            <img src="{{ $filterIcon }}" alt="" class="h-3 w-3 object-contain opacity-70 brightness-0 dark:invert">
                        </button>
                    </span>
                    <div x-show="open" x-cloak x-transition.opacity class="{{ $panelBase }}">
                        <div class="max-h-80 overflow-y-auto p-1">
                            <a href="{{ $filterUrl(['category' => null, 'subtype' => null]) }}" wire:navigate class="{{ $itemBase }} {{ $categorySlug === 'all' && $subtypeFilter === '' ? $activeItem : $inactiveItem }}">All types</a>
                            @foreach (\App\Models\Category::orderBy('sort_order')->get() as $cat)
                                @if ($cat->slug === 'esims')
                                    {{-- eSIM splits into Data / Voice via metadata.plan_type --}}
                                    <a href="{{ $filterUrl(['category' => 'esims', 'subtype' => null]) }}" wire:navigate class="{{ $itemBase }} {{ $categorySlug === 'esims' && $subtypeFilter === '' ? $activeItem : $inactiveItem }}">All eSIMs</a>
                                    <a href="{{ $filterUrl(['category' => 'esims', 'subtype' => 'data']) }}" wire:navigate class="{{ $itemBase }} pl-6 {{ $categorySlug === 'esims' && $subtypeFilter === 'data' ? $activeItem : $inactiveItem }}">Data eSIMs</a>
                                    <a href="{{ $filterUrl(['category' => 'esims', 'subtype' => 'voice']) }}" wire:navigate class="{{ $itemBase }} pl-6 {{ $categorySlug === 'esims' && $subtypeFilter === 'voice' ? $activeItem : $inactiveItem }}">Voice eSIMs</a>
                                @else
                                    <a href="{{ $filterUrl(['category' => $cat->slug, 'subtype' => null]) }}" wire:navigate class="{{ $itemBase }} {{ $categorySlug === $cat->slug ? $activeItem : $inactiveItem }}">{{ $cat->name }}</a>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </span>

                {{-- COUNTRY --}}
                <span class="relative" x-data="{ open: false, search: '' }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <span class="inline-flex items-center gap-1.5">
                        Country
                        <button type="button" @click="open = ! open" aria-label="Filter country" class="flex h-5 w-5 items-center justify-center rounded-[6px] text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10 dark:hover:text-white">
                            <img src="{{ $filterIcon }}" alt="" class="h-3 w-3 object-contain opacity-70 brightness-0 dark:invert">
                        </button>
                    </span>
                    <div x-show="open" x-cloak x-transition.opacity class="{{ $panelBase }}">
                        <div class="border-b border-zinc-100 p-2 dark:border-zinc-700/60">
                            <input x-model="search" type="text" placeholder="Search countries…" class="w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-[12px] outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-white">
                        </div>
                        <div class="max-h-72 overflow-y-auto p-1">
                            <a href="{{ $filterUrl(['country' => null]) }}" wire:navigate class="{{ $itemBase }} {{ $country === 'ALL' ? $activeItem : $inactiveItem }}">All countries</a>
                            @foreach ($countryOptions as $opt)
                                @php $code = strtoupper($opt->country_code); $name = $countryNames[$code] ?? $code; @endphp
                                <a href="{{ $filterUrl(['country' => $code]) }}" wire:navigate
                                   x-show="search === '' || '{{ Str::lower($name.' '.$code) }}'.includes(search.toLowerCase())"
                                   class="{{ $itemBase }} {{ $country === $code ? $activeItem : $inactiveItem }}">
                                    {{ $name }} <span class="text-[10px] text-zinc-400">{{ $code }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </span>

                {{-- REGION --}}
                <span class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <span class="inline-flex items-center gap-1.5">
                        Region
                        <button type="button" @click="open = ! open" aria-label="Filter region" class="flex h-5 w-5 items-center justify-center rounded-[6px] text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10 dark:hover:text-white">
                            <img src="{{ $filterIcon }}" alt="" class="h-3 w-3 object-contain opacity-70 brightness-0 dark:invert">
                        </button>
                    </span>
                    <div x-show="open" x-cloak x-transition.opacity class="{{ $panelBase }}">
                        <div class="max-h-72 overflow-y-auto p-1">
                            <a href="{{ $filterUrl(['region' => null]) }}" wire:navigate class="{{ $itemBase }} {{ $regionFilter === '' ? $activeItem : $inactiveItem }}">All regions</a>
                            @foreach ($availableRegions as $reg)
                                <a href="{{ $filterUrl(['region' => $reg]) }}" wire:navigate class="{{ $itemBase }} {{ $regionFilter === $reg ? $activeItem : $inactiveItem }}">{{ $reg }}</a>
                            @endforeach
                        </div>
                    </div>
                </span>

                <span class="col-cost">Cost Price</span>
                <span>Value</span>
                <span>Discount</span>
                <span class="col-price text-blue-700/70 dark:text-blue-300/70">Sales Price</span>
                <span>Benefits</span>
            </div>
            {{-- Skeleton overlay shown while navigating between paginator pages or filtered URLs. --}}
            <div x-show="navigating" x-cloak class="absolute inset-0 z-10 flex flex-col gap-2 bg-[#eff6ff] dark:bg-transparent" aria-hidden="true">
                @for ($i = 0; $i < 8; $i++)
                    <div class="flex items-center gap-3 rounded-[12px] bg-white p-4 ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:ring-zinc-700/60" style="--i: {{ $i }}">
                        <x-skeleton class="h-10 w-10" rounded="rounded-[12px]" />
                        <div class="flex flex-1 flex-col gap-2">
                            <x-skeleton class="h-4 w-40" />
                            <x-skeleton class="h-3 w-24" />
                        </div>
                        <x-skeleton class="h-4 w-20" />
                        <x-skeleton class="h-4 w-16" />
                        <x-skeleton class="h-6 w-16 rounded-[12px]" />
                    </div>
                @endfor
            </div>

            @forelse ($variants as $variant)
                @php
                    $product = $variant->product;
                    $meta = (array) ($variant->metadata ?? []);
                    $costMeta = (array) ($meta['cost'] ?? []);
                    $priceMeta = (array) ($meta['price'] ?? []);
                    $categorySlugOfRow = $product?->category?->slug;

                    // ── Country + coverage. Real metadata keys differ by source:
                    //   eSIMs (Airalo):   metadata.coverage = ["AD"] or ["AD","FR",...]
                    //   eSIMs (Zendit):   metadata.countries = [...]
                    //   GC / Bills / TopUp: products.country_code (single)
                    $coverage = array_values(array_filter(array_merge(
                        (array) data_get($meta, 'coverage', []),
                        (array) data_get($meta, 'countries', []),
                    )));
                    $coverageCount = count($coverage);
                    $primaryCountry = strtoupper((string) ($product?->country_code ?? ($coverage[0] ?? '')));

                    $countryLabel = ($categorySlugOfRow === 'esims' && $coverageCount > 1)
                        ? 'Regional ('.$coverageCount.')'
                        : ($primaryCountry !== '' ? $primaryCountry : '—');

                    // ── Region. Real keys:
                    //   metadata.regions = ["North America"]  (GC / Bills / TopUp)
                    //   eSIMs have no regions field — derive from the primary
                    //   country's continent (config/continents.php).
                    $metaRegions = array_values(array_filter((array) data_get($meta, 'regions', [])));
                    $region = $metaRegions[0] ?? ($primaryCountry !== '' ? ($countryToContinent[$primaryCountry] ?? '—') : '—');

                    // ── Sub-type. subcategory FK wins; else metadata.subTypes[0]
                    // (GC) or metadata.plan_type (eSIM).
                    $subType = $variant->subcategory?->name
                        ?? data_get($meta, 'subTypes.0')
                        ?? data_get($meta, 'plan_type')
                        ?? '—';

                    // ── Benefits — what the customer actually receives. Per-category
                    // mapping using the real keys discovered in DB:
                    //   eSIMs:         data_limit + validity_days  → e.g. "Unlimited · 10 days"
                    //   Mobile Airtime: dataGB / voiceMinutes / smsNumber / durationDays
                    //   GC / Bills:    shortNotes, else face value summary
                    if ($categorySlugOfRow === 'esims') {
                        // eSIM metadata comes in TWO shapes depending on the supplier:
                        //   1. Zendit data eSIMs → metadata.raw_payload.{dataGB,
                        //      voiceMinutes, smsNumber, durationDays, *Unlimited}
                        //   2. Airalo (voice+sms+data) → top-level
                        //      {data_limit: "10 GB", voice_limit: "75", sms_limit: "30",
                        //       validity_days: 365}
                        // Plus a legacy shape with data_limit:"Unknown" / validity:0
                        // → fall through to SKU parsing.
                        $raw = (array) data_get($meta, 'raw_payload', []);
                        $sku = (string) ($variant->sku ?? '');
                        $parts = [];

                        // DATA — try raw_payload (Zendit), then top-level (Airalo),
                        // then SKU regex as last resort.
                        if (data_get($raw, 'dataUnlimited')) {
                            $parts[] = 'Unlimited data';
                        } elseif (is_numeric(data_get($raw, 'dataGB'))) {
                            $parts[] = ((float) data_get($raw, 'dataGB')).' GB';
                        } elseif (($dl = data_get($meta, 'data_limit')) && is_string($dl) && strtolower($dl) !== 'unknown') {
                            $parts[] = $dl; // already includes "GB" unit
                        } elseif (preg_match('/(\d+)\s*GB/i', $sku, $m)) {
                            $parts[] = $m[1].' GB';
                        } elseif (preg_match('/UNLIMITED|UL[EPU]?(?=[-_])/i', $sku)) {
                            $parts[] = 'Unlimited data';
                        }

                        // VOICE — raw_payload first, then top-level voice_limit (minutes).
                        if (data_get($raw, 'voiceUnlimited')) {
                            $parts[] = 'Unlimited voice';
                        } elseif (is_numeric(data_get($raw, 'voiceMinutes')) && (int) data_get($raw, 'voiceMinutes') > 0) {
                            $parts[] = ((int) data_get($raw, 'voiceMinutes')).' min';
                        } elseif (($vl = data_get($meta, 'voice_limit')) && is_numeric($vl) && (int) $vl > 0) {
                            $parts[] = ((int) $vl).' min';
                        }

                        // SMS — raw_payload first, then top-level sms_limit.
                        if (data_get($raw, 'smsUnlimited')) {
                            $parts[] = 'Unlimited SMS';
                        } elseif (is_numeric(data_get($raw, 'smsNumber')) && (int) data_get($raw, 'smsNumber') > 0) {
                            $parts[] = ((int) data_get($raw, 'smsNumber')).' SMS';
                        } elseif (($sl = data_get($meta, 'sms_limit')) && is_numeric($sl) && (int) $sl > 0) {
                            $parts[] = ((int) $sl).' SMS';
                        }

                        // VALIDITY — raw_payload, then top-level validity_days, then SKU.
                        if (is_numeric(data_get($raw, 'durationDays')) && (int) data_get($raw, 'durationDays') > 0) {
                            $parts[] = ((int) data_get($raw, 'durationDays')).' days';
                        } elseif (($vd = data_get($meta, 'validity_days')) && is_numeric($vd) && (int) $vd > 0) {
                            $parts[] = ((int) $vd).' days';
                        } elseif (preg_match('/(\d+)D[-_]/i', $sku, $m)) {
                            $parts[] = $m[1].' days';
                        }

                        $sentBenefits = $parts ? implode(' · ', $parts) : (data_get($raw, 'shortNotes') ?: data_get($meta, 'shortNotes'));
                    } elseif ($categorySlugOfRow === 'mobile-airtime') {
                        $parts = [];
                        if (data_get($meta, 'dataUnlimited')) {
                            $parts[] = 'Unlimited data';
                        } elseif ($dataGb = data_get($meta, 'dataGB')) {
                            $parts[] = $dataGb.' GB';
                        }
                        if (data_get($meta, 'voiceUnlimited')) {
                            $parts[] = 'Unlimited voice';
                        } elseif ($voice = data_get($meta, 'voiceMinutes')) {
                            $parts[] = $voice.' min';
                        }
                        if (data_get($meta, 'smsUnlimited')) {
                            $parts[] = 'Unlimited SMS';
                        } elseif ($sms = data_get($meta, 'smsNumber')) {
                            $parts[] = $sms.' SMS';
                        }
                        if ($days = data_get($meta, 'durationDays')) {
                            $parts[] = $days.' days';
                        }
                        $sentBenefits = $parts ? implode(' · ', $parts) : data_get($meta, 'shortNotes');
                    } else {
                        $sentBenefits = data_get($meta, 'shortNotes')
                            ?? ($variant->face_value ? number_format((float) $variant->face_value, 2).' '.$variant->currency.' value' : null);
                    }

                    $costUsd = (float) $variant->cost_price;

                    // Value (supplier-suggested retail). Stored on the variant
                    // at sync time as `retail_price` — falls back to face_value
                    // when retail is missing. It lives in the CARD's currency,
                    // so convert to USD before comparing against the USD cost;
                    // mixing units made strong-currency cards read as negative
                    // discounts and weak-currency ones as ~100%. Currencies
                    // without an active rate fall back to the USD cost — the
                    // same authority the cart pricing uses.
                    $valueNative = (float) ($variant->retail_price ?: $variant->face_value ?: $costUsd);
                    $valueCurrency = strtoupper((string) ($variant->currency ?: 'USD'));
                    if ($valueCurrency === '' || $valueCurrency === 'USD') {
                        $valueUsd = $valueNative;
                    } else {
                        $valueRate = (float) ($ratesPerUsd[$valueCurrency] ?? 0);
                        $valueUsd = $valueRate > 0 ? round($valueNative / $valueRate, 4) : $costUsd;
                    }

                    // SALES PRICE — mirrors CartPricingService exactly: USD
                    // face values are the markup base; non-USD denominations
                    // base on the supplier's USD cost. What this column shows
                    // is what checkout actually charges.
                    $retailBase = ($valueCurrency === 'USD' || $valueCurrency === '') && $valueNative > 0 ? $valueNative : $costUsd;
                    // resolveVariantRetailPrice honours the per-variant
                    // manual_retail_price_usd override set in the drawer; the
                    // product-level resolveRetailPrice would ignore it, so the
                    // column kept showing the rule price after an admin override.
                    $retailUsd = $pricing->resolveVariantRetailPrice($variant, $retailBase);

                    // Discount % — the supplier's wholesale discount: the gap
                    // between the face Value (what the customer receives) and
                    // the Cost (what the supplier charges us), as a % of Value.
                    // e.g. Value $12.50, Cost $3.30 → 73.6% off face. A negative
                    // figure is now honest data: the supplier charges a premium
                    // above face for that card (common cross-currency).
                    $discountPct = $valueUsd > 0
                        ? round((($valueUsd - $costUsd) / $valueUsd) * 100, 2)
                        : null;
                    $marginPct = $discountPct ?? 0.0;
                    $fee = data_get($costMeta, 'fee');
                    $feePct = data_get($costMeta, 'feePct');
                    $costFx = data_get($costMeta, 'fx');
                    $priceFx = data_get($priceMeta, 'fx');

                    $brandLabel = $product?->brand_key ? \App\Models\Product::brandDisplayName($product->brand_key) : ($product?->name ?? '—');
                    $logoSrc = $product ? \App\Models\Product::brandLogoUrl($product->brand_key, $product->logo_url) : null;
                @endphp
                {{-- Click anywhere on the row to open the slide-out detail panel.
                     Drawer payload is everything the panel needs — keyed plainly
                     so the Alpine template stays declarative. --}}
                @php
                    $countryNameForDrawer = $country !== '' ? ($countryNames[$country] ?? $country) : '—';
                    $valueLabel = $variant->is_variable
                        ? number_format((float) $variant->min_amount, 2).'–'.number_format((float) $variant->max_amount, 2).' '.$variant->currency
                        : number_format((float) $variant->face_value, 2).' '.$variant->currency;
                    $roamingCountries = collect((array) data_get($meta, 'roamingCountries', []))
                        ->map(fn ($c) => is_array($c) ? $c : ['code' => $c, 'speeds' => null])
                        ->values();
                    $drawerPayload = [
                        'id' => $variant->sku ?: ('VAR-'.$variant->id),
                        'variantId' => $variant->id,
                        'productId' => $product?->id,
                        'productType' => $product?->category?->name ?? '—',
                        'subType' => $subType,
                        'notes' => data_get($meta, 'notes') ?? data_get($meta, 'shortNotes'),
                        'isVariable' => (bool) $variant->is_variable,
                        'region' => $region,
                        'countryName' => $countryNameForDrawer,
                        'countryCode' => $country,
                        'dataAmount' => data_get($meta, 'dataAmount'),
                        'duration' => data_get($meta, 'duration'),
                        'dataSpeed' => data_get($meta, 'dataSpeed'),
                        'isAvailable' => (bool) $variant->is_available,
                        'isActive' => (bool) ($product?->is_active ?? false),
                        'isFeatured' => (bool) ($product?->is_featured ?? false),
                        'isPopular' => (bool) ($product?->is_popular ?? false),
                        'valueLabel' => $valueLabel,
                        'increment' => data_get($meta, 'increment'),
                        'costLabel' => number_format($costUsd, 2).' USD',
                        // Sales price (computed): the rate-derived USD price.
                        'retail' => number_format($retailUsd, 2),
                        'retailLabel' => number_format($retailUsd, 2).' USD',
                        // Admin override, if set. NULL means "using rules".
                        'manualPriceUsd' => $variant->manual_retail_price_usd !== null
                            ? (float) $variant->manual_retail_price_usd
                            : null,
                        // Per-product PricingRule, if one exists. NULL = falling
                        // back to subcategory / category / global rule chain.
                        'productMarkup' => (function () use ($product) {
                            $rule = $product ? \App\Models\PricingRule::where('product_id', $product->id)->where('is_active', true)->first() : null;
                            return $rule ? ['type' => $rule->markup_type, 'value' => (float) $rule->markup_value] : null;
                        })(),
                        'roamingCountries' => $roamingCountries,
                    ];
                @endphp
                <article
                    @click="$dispatch('variant-show', @js($drawerPayload))"
                    class="variant-row variant-body group relative mx-3 cursor-pointer bg-white px-6 py-3 transition-all hover:bg-blue-50 dark:bg-[#1d3252] dark:hover:bg-blue-600/10 dark:hover:ring-blue-400"
                >
                    {{-- Brand (text only, no logo) --}}
                    <span class="col-brand min-w-0 truncate text-[13px] font-semibold text-zinc-900 dark:text-white">{{ $brandLabel }}</span>

                    {{-- Product Type --}}
                    <span class="min-w-0 truncate text-[13px] text-zinc-700 dark:text-zinc-200">{{ $product?->category?->name ?? '—' }}</span>

                    {{-- Country — single ISO code, OR "Regional (N)" for multi-country eSIMs. --}}
                    <span class="min-w-0 truncate text-[13px] text-zinc-700 dark:text-zinc-200">{{ $countryLabel }}</span>

                    {{-- Region --}}
                    <span class="min-w-0 truncate text-[13px] text-zinc-700 dark:text-zinc-200">{{ $region }}</span>

                    {{-- Cost Price — what we pay the supplier (USD) --}}
                    <span class="col-cost min-w-0 truncate text-[13px] font-semibold tabular-nums text-zinc-900 dark:text-white">@moneyCode($costUsd, 'USD')</span>

                    {{-- Value — supplier-suggested retail (what the customer receives). --}}
                    <span class="min-w-0 truncate text-[13px] font-medium tabular-nums text-zinc-700 dark:text-zinc-200">
                        @if ($variant->is_variable)
                            {{ number_format((float) $variant->min_amount, 2) }}–{{ number_format((float) $variant->max_amount, 2) }} {{ $variant->currency }}
                        @elseif ($valueUsd > 0)
                            @moneyCode($valueUsd, 'USD')
                        @else
                            —
                        @endif
                    </span>

                    {{-- Discount % — (Sales − Cost) / Sales, computed live --}}
                    <span class="min-w-0 truncate text-[13px] tabular-nums text-zinc-700 dark:text-zinc-200">
                        {{ $discountPct !== null ? number_format($discountPct, 2).'%' : '—' }}
                    </span>

                    {{-- Sales Price — markup applied to Value (highlighted).
                         w-fit + inline-flex so the pill hugs its content and
                         doesn't fill the full grid cell. --}}
                    <span class="col-price">
                        <span class="inline-flex w-fit items-center whitespace-nowrap rounded-[12px] bg-blue-50/70 px-3 py-1.5 text-[13px] font-bold tabular-nums text-blue-900 ring-1 ring-blue-100 dark:bg-blue-600/10 dark:text-blue-200 dark:ring-blue-500/20">@moneyCode($retailUsd, 'USD')</span>
                    </span>

                    {{-- Benefits — clamp to 2 lines so long lists ("5GB · 30 days · 200 SMS")
                         wrap naturally instead of being cut off after one line. --}}
                    <span class="col-benefits min-w-0 text-[13px] leading-snug text-zinc-700 line-clamp-2 dark:text-zinc-200">{{ $sentBenefits ?? '—' }}</span>
                </article>
            @empty
                <div class="px-5 py-20 text-center">
                    <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-[12px] bg-blue-50 text-blue-600 dark:bg-blue-600/15 dark:text-blue-300">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </span>
                    <p class="mt-4 text-base font-semibold text-zinc-900 dark:text-white">No products match these filters</p>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Try clearing the search or picking a different category.</p>
                    @if ($search !== '' || $categorySlug !== 'all')
                        <a href="{{ route('admin.products') }}" wire:navigate class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-300">
                            Clear all filters
                            <img src="{{ asset('assets/' . rawurlencode('x button.webp')) }}" alt="" class="h-3.5 w-3.5 object-contain" loading="lazy">
                        </a>
                    @endif
                </div>
            @endforelse
          </div>

            @if ($variants->hasPages())
                <div class="border-t border-zinc-100 px-5 py-3 dark:border-zinc-700/60">
                    {{ $variants->onEachSide(1)->links('vendor.pagination.circles') }}
                </div>
            @endif
        </div>

        {{-- Slide-out variant detail drawer. One instance reused for every row:
             clicking a row dispatches `variant-show` with the variant payload, the
             drawer picks it up and slides in from the right. Read-only for now —
             pricing inputs render but don't persist (admin editing comes later). --}}
        <div
            x-data="variantDrawer()"
            @variant-show.window="open($event.detail)"
            @keydown.escape.window="close()"
            x-cloak
            class="fixed inset-0 z-50"
            x-show="isOpen"
        >
            {{-- Backdrop --}}
            <div
                x-show="isOpen"
                @click="close()"
                x-transition.opacity
                class="absolute inset-0 bg-zinc-900/40 dark:bg-zinc-950/60"
            ></div>

            {{-- Panel --}}
            <aside
                x-show="isOpen"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="translate-x-full"
                class="absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-2xl dark:bg-[#1d3252]"
                role="dialog"
                aria-modal="true"
                aria-label="Product variant details"
            >
                {{-- Header --}}
                <header class="flex items-center justify-between border-b border-zinc-100 px-5 py-4 dark:border-zinc-700/60">
                    <h2 class="text-base font-bold text-zinc-900 dark:text-white">Product Details</h2>
                    <button type="button" @click="close()" aria-label="Close" class="flex h-8 w-8 items-center justify-center rounded-[12px] text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </header>

                {{-- Scrollable body --}}
                <div class="flex-1 overflow-y-auto px-5 py-4">

                    {{-- ID + Status --}}
                    <div class="space-y-3">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">ID</p>
                            <div class="mt-1 flex items-center gap-2">
                                <p class="font-mono text-sm text-zinc-900 dark:text-white" x-text="data.id"></p>
                                <button type="button" @click="navigator.clipboard.writeText(data.id)" aria-label="Copy ID" class="text-zinc-400 hover:text-zinc-700 dark:hover:text-white">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3"/></svg>
                                </button>
                            </div>
                        </div>
                        {{-- Live on website: flips products.is_active. Off pulls the
                             WHOLE product (every variant) from storefront listings and
                             its detail page - this is the "turn a product off" switch. --}}
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Live on website</p>
                            <div class="mt-1 inline-flex items-center gap-0.5 rounded-[12px] bg-zinc-100 p-0.5 dark:bg-[#26416b]">
                                <button type="button" @click="setActive(true)" :disabled="savingActive" class="rounded-[8px] px-3 py-1 text-[11px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60" :class="data.isActive ? 'bg-emerald-500 text-white' : 'text-zinc-600 dark:text-zinc-300'">On</button>
                                <button type="button" @click="setActive(false)" :disabled="savingActive" class="rounded-[8px] px-3 py-1 text-[11px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60" :class="! data.isActive ? 'bg-zinc-700 text-white' : 'text-zinc-600 dark:text-zinc-300'">Off</button>
                            </div>
                            <p class="mt-1 text-[10px] leading-snug text-zinc-500 dark:text-zinc-400">Off removes the whole product from the website.</p>
                        </div>

                        {{-- Availability: flips THIS variant's is_available, so only this
                             one denomination/amount disappears from the product page. --}}
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Availability (this variant)</p>
                            <div class="mt-1 inline-flex items-center gap-0.5 rounded-[12px] bg-zinc-100 p-0.5 dark:bg-[#26416b]">
                                <button type="button" @click="setAvailable(true)" :disabled="savingAvailable" class="rounded-[8px] px-3 py-1 text-[11px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60" :class="data.isAvailable ? 'bg-emerald-500 text-white' : 'text-zinc-600 dark:text-zinc-300'">Enabled</button>
                                <button type="button" @click="setAvailable(false)" :disabled="savingAvailable" class="rounded-[8px] px-3 py-1 text-[11px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60" :class="! data.isAvailable ? 'bg-zinc-700 text-white' : 'text-zinc-600 dark:text-zinc-300'">Disabled</button>
                            </div>
                        </div>
                    </div>

                    {{-- Two-column field grid --}}
                    <dl class="mt-5 grid grid-cols-2 gap-x-4 gap-y-4 text-[12px]">
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Product Type</dt>
                            <dd class="mt-1 font-medium text-zinc-900 dark:text-white" x-text="data.productType"></dd>
                        </div>
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Sub Type</dt>
                            <dd class="mt-1">
                                <span class="inline-flex items-center rounded-[12px] bg-zinc-100 px-2 py-0.5 text-[11px] font-medium text-zinc-700 dark:bg-white/5 dark:text-zinc-200" x-text="data.subType"></span>
                            </dd>
                        </div>
                        <div class="col-span-2" x-show="data.notes">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Extended Notes</dt>
                            <dd class="mt-1 text-zinc-700 dark:text-zinc-300" x-text="data.notes"></dd>
                        </div>
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Offer Price Type</dt>
                            <dd class="mt-1">
                                <span class="inline-flex items-center gap-1.5 rounded-[12px] bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700 dark:bg-blue-600/15 dark:text-blue-300">
                                    <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span>
                                    <span x-text="data.isVariable ? 'Variable' : 'Fixed'"></span>
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Region</dt>
                            <dd class="mt-1 font-medium text-zinc-900 dark:text-white" x-text="data.region"></dd>
                        </div>
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Country</dt>
                            <dd class="mt-1 font-medium text-zinc-900 dark:text-white" x-text="data.countryName"></dd>
                        </div>
                        <div x-show="data.dataAmount">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Data Amount</dt>
                            <dd class="mt-1 font-medium text-zinc-900 dark:text-white" x-text="data.dataAmount || '—'"></dd>
                        </div>
                        <div x-show="data.duration">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Duration</dt>
                            <dd class="mt-1 font-medium text-zinc-900 dark:text-white" x-text="data.duration || '—'"></dd>
                        </div>
                        <div x-show="data.dataSpeed">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Data Speed</dt>
                            <dd class="mt-1 font-medium text-zinc-900 dark:text-white" x-text="data.dataSpeed || '—'"></dd>
                        </div>
                    </dl>

                    {{-- Roaming countries — only renders if the variant exposes a list. --}}
                    <div class="mt-6 border-t border-zinc-100 pt-4 dark:border-zinc-700/60" x-show="(data.roamingCountries || []).length > 0">
                        <div class="flex items-center justify-between">
                            <h3 class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Roaming Countries</h3>
                            <div class="inline-flex items-center gap-0.5 rounded-[12px] bg-zinc-100 p-0.5 dark:bg-[#26416b]">
                                <button type="button" @click="roamingView = 'map'" :class="roamingView === 'map' ? 'bg-blue-600 text-white' : 'text-zinc-600 dark:text-zinc-300'" class="rounded-[8px] px-3 py-1 text-[11px] font-semibold transition-colors">Map View</button>
                                <button type="button" @click="roamingView = 'list'" :class="roamingView === 'list' ? 'bg-blue-600 text-white' : 'text-zinc-600 dark:text-zinc-300'" class="rounded-[8px] px-3 py-1 text-[11px] font-semibold transition-colors">List View</button>
                            </div>
                        </div>

                        {{-- Map view: lazy-loaded jsvectormap (same factory the dashboard uses).
                             Each roaming country gets a coloured fill so the admin can scan
                             coverage at a glance. --}}
                        <div x-show="roamingView === 'map'" x-cloak class="mt-3" x-ref="mapContainer">
                            <div
                                wire:ignore
                                x-data="variantRoamingMap()"
                                x-init="render($el, data.roamingCountries)"
                                x-effect="render($el, data.roamingCountries)"
                                class="h-64 w-full rounded-[12px] bg-zinc-50 dark:bg-[#0c1a36]"
                            ></div>
                        </div>

                        {{-- List view: rows with flag + country name + supported speeds. --}}
                        <ul x-show="roamingView === 'list'" x-cloak class="mt-3 divide-y divide-zinc-100 dark:divide-zinc-700/60">
                            <template x-for="country in (data.roamingCountries || [])" :key="country.code">
                                <li class="flex items-center justify-between gap-3 py-2 text-[12px]">
                                    <span class="flex items-center gap-2">
                                        <img :src="'https://flagcdn.com/w40/' + (country.code || '').toLowerCase() + '.png'" alt="" class="h-3 w-[18px] shrink-0 rounded-[2px] object-cover ring-1 ring-zinc-200 dark:ring-white/15">
                                        <span class="text-zinc-800 dark:text-zinc-200" x-text="country.name || country.code"></span>
                                    </span>
                                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400" x-text="country.speeds || 'Speed not available'"></span>
                                </li>
                            </template>
                        </ul>
                    </div>

                    {{-- Cost and Value --}}
                    <div class="mt-6 border-t border-zinc-100 pt-4 dark:border-zinc-700/60">
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Cost and Value</h3>
                        <dl class="mt-3 grid grid-cols-3 gap-x-4 gap-y-3 text-[12px]">
                            <div>
                                <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Value</dt>
                                <dd class="mt-1 font-bold tabular-nums text-zinc-900 dark:text-white" x-text="data.valueLabel"></dd>
                            </div>
                            <div>
                                <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Increment</dt>
                                <dd class="mt-1 text-zinc-700 dark:text-zinc-300" x-text="data.increment || 'N/A'"></dd>
                            </div>
                            <div>
                                <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Cost</dt>
                                <dd class="mt-1 font-bold tabular-nums text-zinc-900 dark:text-white" x-text="data.costLabel"></dd>
                            </div>
                            <div>
                                <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Fee</dt>
                                <dd class="mt-1 text-zinc-700 dark:text-zinc-300" x-text="data.fee || '—'"></dd>
                            </div>
                            <div>
                                <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Fee %</dt>
                                <dd class="mt-1 text-zinc-700 dark:text-zinc-300" x-text="data.feePct || '—'"></dd>
                            </div>
                        </dl>
                    </div>

                    {{-- Sales Price override. Blank input + Save = use the rates
                         chain. Typed value + Save = pin this SKU to that USD
                         price. Reset clears the override. --}}
                    <div class="mt-6 border-t border-zinc-100 pt-4 dark:border-zinc-700/60">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Sales Price</h3>
                            <span
                                class="rounded-[12px] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider"
                                :class="data.manualPriceUsd !== null ? 'bg-blue-50 text-blue-700 dark:bg-blue-600/15 dark:text-blue-300' : 'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300'"
                                x-text="data.manualPriceUsd !== null ? 'Override active' : 'Using rates'"
                            ></span>
                        </div>
                        <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">Leave blank to use the rates settings.</p>
                        <div class="mt-3 flex items-stretch gap-2">
                            <div class="flex flex-1 overflow-hidden rounded-[12px] border border-zinc-200 dark:border-zinc-700/60">
                                <span class="bg-zinc-50 px-3 py-2 text-[11px] font-medium text-zinc-600 dark:bg-white/5 dark:text-zinc-300">USD</span>
                                <input
                                    type="number" step="0.01" min="0"
                                    x-model="priceInput"
                                    :placeholder="data.retail"
                                    class="flex-1 border-0 bg-white px-3 py-2 text-[12px] tabular-nums text-zinc-900 outline-none dark:bg-[#26416b] dark:text-white"
                                >
                            </div>
                            <button type="button"
                                @click="savePrice()" :disabled="savingPrice"
                                class="rounded-[12px] bg-blue-600 px-3 text-[11px] font-semibold text-white transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
                                <span x-text="savingPrice ? 'Saving…' : 'Save'"></span>
                            </button>
                            <button type="button"
                                @click="resetPrice()" :disabled="savingPrice || data.manualPriceUsd === null"
                                class="rounded-[12px] border border-zinc-200 bg-white px-3 text-[11px] font-semibold text-zinc-700 transition-colors hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-zinc-200 dark:hover:bg-[#34507a]">
                                Reset
                            </button>
                        </div>
                        <p class="mt-2 text-[10px] text-zinc-500">Rates-derived suggestion: <span class="font-semibold" x-text="data.retailLabel"></span></p>
                    </div>

                    {{-- Markup rule (per-product). Wins over subcategory /
                         category / global rules. Save creates the rule, Reset
                         deletes it and falls back to the chain. Independent
                         from the Sales Price override above: the override is
                         an absolute price (no scaling); markup scales with
                         supplier cost. --}}
                    <div class="mt-6 border-t border-zinc-100 pt-4 dark:border-zinc-700/60">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Markup rule</h3>
                            <span x-show="data.productMarkup" class="rounded-[5px] bg-emerald-100 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">Per-product</span>
                            <span x-show="!data.productMarkup" class="rounded-[5px] bg-zinc-200 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider text-zinc-700 dark:bg-zinc-700/50 dark:text-zinc-300">Using chain</span>
                        </div>
                        <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">Markup wins over subcategory / category / global rules for this product only.</p>
                        <div class="mt-3 flex flex-wrap items-stretch gap-2">
                            {{-- Modern dropdown - Alpine version matching the
                                 <x-admin.select> visual language. Uses the
                                 parent drawer's `markupType` directly via
                                 the inherited Alpine scope. --}}
                            <div
                                x-data="{ open: false, options: { percentage: 'Percent (%)', fixed: 'Flat ($)' } }"
                                @click.outside="open = false"
                                @keydown.escape.window="open = false"
                                class="relative w-32 shrink-0"
                            >
                                <button
                                    type="button"
                                    @click="open = !open"
                                    :disabled="savingMarkup"
                                    :aria-expanded="open.toString()"
                                    class="flex h-9 w-full items-center justify-between gap-2 rounded-[12px] border bg-white px-3 text-[12px] font-semibold text-zinc-900 outline-none transition-colors disabled:cursor-not-allowed disabled:opacity-60 dark:bg-[#0c1a36] dark:text-white"
                                    :class="open ? 'border-blue-500 ring-2 ring-blue-500/15' : 'border-zinc-200 hover:border-zinc-400 dark:border-zinc-700/60'"
                                >
                                    <span x-text="options[markupType]">Percent (%)</span>
                                    <svg class="h-3.5 w-3.5 shrink-0 text-zinc-500 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>
                                <div
                                    x-show="open"
                                    x-cloak
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    class="absolute left-0 right-0 top-full z-30 mt-1 overflow-hidden rounded-[12px] border border-zinc-200 bg-white p-1 shadow-xl shadow-zinc-900/10 dark:border-zinc-700/60 dark:bg-[#1d3252]"
                                    role="listbox"
                                >
                                    <template x-for="(label, value) in options" :key="value">
                                        <button
                                            type="button"
                                            role="option"
                                            :aria-selected="markupType === value"
                                            @click="markupType = value; open = false"
                                            class="flex w-full items-center justify-between gap-2 rounded-[12px] px-3 py-2 text-left text-[12px] font-medium transition-colors"
                                            :class="markupType === value ? 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300' : 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-white/5'"
                                        >
                                            <span x-text="label"></span>
                                            <svg x-show="markupType === value" class="h-3.5 w-3.5 shrink-0 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                            </svg>
                                        </button>
                                    </template>
                                </div>
                            </div>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                x-model="markupValue"
                                :disabled="savingMarkup"
                                :placeholder="markupType === 'percentage' ? '5' : '0.50'"
                                class="h-9 flex-1 rounded-[12px] border border-zinc-200 bg-white px-3 text-[12px] text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white"
                            >
                            <button
                                type="button"
                                @click="saveMarkup()"
                                :disabled="savingMarkup || !markupValue"
                                class="h-9 rounded-[12px] bg-blue-600 px-3 text-[11px] font-semibold text-white transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <span x-text="savingMarkup ? 'Saving...' : 'Save'"></span>
                            </button>
                            <button
                                type="button"
                                @click="resetMarkup()"
                                :disabled="savingMarkup || !data.productMarkup"
                                class="h-9 rounded-[12px] border border-zinc-200 bg-white px-3 text-[11px] font-semibold text-zinc-700 transition-colors hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-zinc-200 dark:hover:bg-[#34507a]"
                            >
                                Reset
                            </button>
                        </div>
                        <p x-show="data.productMarkup" class="mt-2 text-[10px] text-zinc-500">
                            Currently <span class="font-semibold" x-text="data.productMarkup ? (data.productMarkup.type === 'percentage' ? data.productMarkup.value + '%' : '$' + data.productMarkup.value + ' flat') : ''"></span>
                        </p>
                    </div>

                    {{-- Featured + Popular toggles. Both are product-level flags
                         (not per-variant): toggling them on any one of a
                         product's variants flips the badge sitewide. --}}
                    <div class="mt-6 border-t border-zinc-100 pt-4 dark:border-zinc-700/60">
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Badges</h3>
                        <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">Applies to the parent product across the storefront.</p>
                        <div class="mt-3 space-y-2">
                            {{-- Featured toggle with glowing-gradient preview.
                                 Right slot is a checkbox to ENABLE the badge,
                                 OR (once set) a red X button to remove it -
                                 same "click here to switch off" affordance the
                                 admin asked for, no extra round-trip needed. --}}
                            <div class="flex items-center justify-between gap-3 rounded-[12px] border border-zinc-200 px-3 py-2.5 dark:border-zinc-700/60" :class="data.isFeatured && 'ring-1 ring-inset ring-amber-300/60 bg-amber-50/40 dark:bg-amber-500/[0.07] dark:ring-amber-400/30'">
                                <span class="flex items-center gap-2.5">
                                    <span class="inline-flex items-center gap-1 rounded-[12px] bg-gradient-to-r from-amber-400 via-pink-500 to-purple-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-white shadow-sm shadow-pink-500/40">
                                        <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l2.39 7.36H22l-6.18 4.49L18.18 21 12 16.51 5.82 21l2.36-7.15L2 9.36h7.61z"/></svg>
                                        Featured
                                    </span>
                                    <span class="text-[12px] text-zinc-700 dark:text-zinc-200">Glowing gradient badge</span>
                                </span>
                                {{-- One persistent control. ON: a red X that stays put so the
                                     drawer shows at a glance the product is featured - click it
                                     to remove. OFF: a + to add it. toggleFeatured() flips the
                                     server flag and syncs data.isFeatured from the response. --}}
                                <button
                                    type="button"
                                    @click="toggleFeatured()"
                                    :disabled="togglingFeatured"
                                    :aria-label="data.isFeatured ? 'Remove Featured badge' : 'Mark as Featured'"
                                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-[6px] transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                                    :class="data.isFeatured
                                        ? 'bg-red-50 text-red-600 hover:bg-red-100 hover:text-red-700 dark:bg-red-500/15 dark:text-red-300 dark:hover:bg-red-500/25'
                                        : 'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 hover:text-zinc-700 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/15'"
                                >
                                    <svg x-show="data.isFeatured" x-cloak class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                    <svg x-show="!data.isFeatured" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                                    </svg>
                                </button>
                            </div>

                            {{-- Popular toggle. Same pattern. --}}
                            <div class="flex items-center justify-between gap-3 rounded-[12px] border border-zinc-200 px-3 py-2.5 dark:border-zinc-700/60" :class="data.isPopular && 'ring-1 ring-inset ring-blue-300/60 bg-blue-50/40 dark:bg-blue-500/[0.07] dark:ring-blue-400/30'">
                                <span class="flex items-center gap-2.5">
                                    <span class="inline-flex items-center gap-1 rounded-[12px] bg-blue-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-white">
                                        <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.5a9.5 9.5 0 100 19 9.5 9.5 0 000-19zm1 14h-2v-2h2v2zm0-4h-2V6h2v6.5z"/></svg>
                                        Popular
                                    </span>
                                    <span class="text-[12px] text-zinc-700 dark:text-zinc-200">Customer-favourite tag</span>
                                </span>
                                {{-- Same persistent control as Featured: X stays while ON
                                     (visible "this is popular" marker + remove), + to add. --}}
                                <button
                                    type="button"
                                    @click="togglePopular()"
                                    :disabled="togglingPopular"
                                    :aria-label="data.isPopular ? 'Remove Popular badge' : 'Mark as Popular'"
                                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-[6px] transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                                    :class="data.isPopular
                                        ? 'bg-red-50 text-red-600 hover:bg-red-100 hover:text-red-700 dark:bg-red-500/15 dark:text-red-300 dark:hover:bg-red-500/25'
                                        : 'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 hover:text-zinc-700 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/15'"
                                >
                                    <svg x-show="data.isPopular" x-cloak class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                    <svg x-show="!data.isPopular" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- Coupon codes. Per-variant: one code → one SKU. Auto-expires
                         when valid_until passes; admin can also delete or pause. --}}
                    <div class="mt-6 border-t border-zinc-100 pt-4 dark:border-zinc-700/60">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Coupon Codes</h3>
                            <span class="text-[11px] text-zinc-500" x-text="(coupons.length || 0) + ' active'"></span>
                        </div>

                        {{-- Create form --}}
                        <div class="mt-3 space-y-2 rounded-[12px] border border-zinc-200 p-3 dark:border-zinc-700/60">
                            <div class="grid grid-cols-2 gap-2">
                                <input type="text" x-model="couponForm.code" placeholder="CODE (e.g. SAVE10)" class="col-span-2 rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-[12px] uppercase text-zinc-900 outline-none focus:border-blue-500 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-white">
                                {{-- Modern dropdown (Alpine) - mirrors the markup-type
                                     picker so the drawer reads consistently. --}}
                                <div
                                    x-data="{ open: false, options: { percent: 'Percent off', fixed: 'USD off' } }"
                                    @click.outside="open = false"
                                    @keydown.escape.window="open = false"
                                    class="relative"
                                >
                                    <button
                                        type="button"
                                        @click="open = !open"
                                        :aria-expanded="open.toString()"
                                        class="flex w-full items-center justify-between gap-2 rounded-[12px] border bg-white px-3 py-2 text-[12px] font-medium text-zinc-900 outline-none transition-colors dark:bg-[#26416b] dark:text-white"
                                        :class="open ? 'border-blue-500 ring-2 ring-blue-500/15' : 'border-zinc-200 hover:border-zinc-400 dark:border-zinc-700/60'"
                                    >
                                        <span x-text="options[couponForm.discount_type]">Percent off</span>
                                        <svg class="h-3.5 w-3.5 shrink-0 text-zinc-500 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                        </svg>
                                    </button>
                                    <div
                                        x-show="open"
                                        x-cloak
                                        x-transition:enter="transition ease-out duration-150"
                                        x-transition:enter-start="opacity-0 -translate-y-1"
                                        x-transition:enter-end="opacity-100 translate-y-0"
                                        class="absolute left-0 right-0 top-full z-30 mt-1 overflow-hidden rounded-[12px] border border-zinc-200 bg-white p-1 shadow-xl shadow-zinc-900/10 dark:border-zinc-700/60 dark:bg-[#1d3252]"
                                        role="listbox"
                                    >
                                        <template x-for="(label, value) in options" :key="value">
                                            <button
                                                type="button"
                                                role="option"
                                                :aria-selected="couponForm.discount_type === value"
                                                @click="couponForm.discount_type = value; open = false"
                                                class="flex w-full items-center justify-between gap-2 rounded-[12px] px-3 py-2 text-left text-[12px] font-medium transition-colors"
                                                :class="couponForm.discount_type === value ? 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300' : 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-white/5'"
                                            >
                                                <span x-text="label"></span>
                                                <svg x-show="couponForm.discount_type === value" class="h-3.5 w-3.5 shrink-0 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                                </svg>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                                <input type="number" step="0.01" min="0.01" x-model="couponForm.discount_value" :placeholder="couponForm.discount_type === 'percent' ? '10' : '5.00'" class="rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-[12px] tabular-nums text-zinc-900 outline-none focus:border-blue-500 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-white">
                                <input type="number" min="1" x-model="couponForm.max_uses" placeholder="Max uses (blank = ∞)" class="rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-[12px] tabular-nums text-zinc-900 outline-none focus:border-blue-500 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-white">
                                {{-- Flatpickr expiry picker. Date-only, future dates only (the API
                                     requires valid_until > now), stored as end-of-day. flatpickr is
                                     exposed on window in app.js; the dark calendar theme lives in
                                     app.css. Writes the ISO value to couponForm.valid_until and
                                     clears itself when the form resets. --}}
                                <input
                                    type="text"
                                    x-ref="couponExpiry"
                                    placeholder="Expiry (optional)"
                                    readonly
                                    x-init="
                                        const pad = (n) => String(n).padStart(2, '0');
                                        const fp = window.initExpiryFlatpickr($refs.couponExpiry, (d) => {
                                            couponForm.valid_until = d ? d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T23:59' : '';
                                        });
                                        $watch('couponForm.valid_until', (v) => { if (!v && fp.selectedDates.length) { fp.clear(); } });
                                    "
                                    class="cursor-pointer rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-[12px] text-zinc-900 outline-none focus:border-blue-500 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-white dark:placeholder:text-zinc-500">
                            </div>
                            <p x-show="couponError" x-text="couponError" class="text-[11px] text-red-600"></p>
                            <button type="button" @click="createCoupon()" :disabled="creatingCoupon" class="w-full rounded-[12px] bg-blue-600 px-3 py-2 text-[11px] font-semibold text-white transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
                                <span x-text="creatingCoupon ? 'Creating…' : '+ Create coupon'"></span>
                            </button>
                        </div>

                        {{-- Existing coupons list --}}
                        <ul class="mt-3 space-y-1.5" x-show="coupons.length > 0">
                            <template x-for="c in coupons" :key="c.id">
                                <li class="flex items-center justify-between gap-2 rounded-[12px] border border-zinc-200 px-3 py-2 dark:border-zinc-700/60">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-mono text-[12px] font-bold text-zinc-900 dark:text-white" x-text="c.code"></span>
                                        <span class="block text-[10px] text-zinc-500 dark:text-zinc-400">
                                            <span x-text="c.discount_type === 'percent' ? c.discount_value + '% off' : '$' + Number(c.discount_value).toFixed(2) + ' off'"></span>
                                            <span x-show="c.max_uses"> · <span x-text="c.used_count + '/' + c.max_uses"></span></span>
                                            <span x-show="c.valid_until"> · expires <span x-text="new Date(c.valid_until).toLocaleDateString()"></span></span>
                                        </span>
                                    </span>
                                    <span
                                        class="rounded-[12px] px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider"
                                        :class="c.is_redeemable ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : (c.is_expired ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' : 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300')"
                                        x-text="c.is_redeemable ? 'Active' : (c.is_expired ? 'Expired' : (c.is_used_up ? 'Used up' : 'Paused'))"
                                    ></span>
                                    <button type="button" @click="deleteCoupon(c.id)" aria-label="Delete coupon" class="rounded-[12px] p-1 text-zinc-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                                    </button>
                                </li>
                            </template>
                        </ul>
                        <p x-show="coupons.length === 0" class="mt-3 text-center text-[11px] text-zinc-500">No coupons yet for this SKU.</p>
                    </div>
                </div>

                {{-- Footer: just Close. Save/Reset live inline with their fields. --}}
                <footer class="border-t border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700/60 dark:bg-[#162a4a]">
                    <button type="button" @click="close()" class="w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-[11px] font-semibold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-zinc-200 dark:hover:bg-[#34507a]">Close</button>
                </footer>
            </aside>
        </div>

        <script>
            // Tiny Alpine factory for the drawer state. `data` holds whatever
            // payload the row dispatches via `variant-show` — every row sends
            // the same shape so the drawer template stays declarative.
            window.variantDrawer = function () {
                return {
                    isOpen: false,
                    data: {},
                    roamingView: 'map',

                    // Pricing override state
                    priceInput: '',
                    savingPrice: false,

                    // Badge toggles
                    togglingFeatured: false,
                    togglingPopular: false,

                    // Variant availability toggle
                    savingAvailable: false,

                    // Product on/off (is_active) toggle
                    savingActive: false,

                    // Per-product markup rule form
                    markupType: 'percentage',
                    markupValue: '',
                    savingMarkup: false,

                    // Coupons
                    coupons: [],
                    couponForm: {
                        code: '', discount_type: 'percent', discount_value: '',
                        max_uses: '', valid_until: '',
                    },
                    creatingCoupon: false,
                    couponError: '',

                    open(payload) {
                        this.data = payload || {};
                        this.roamingView = 'map';
                        this.priceInput = this.data.manualPriceUsd !== null && this.data.manualPriceUsd !== undefined
                            ? String(this.data.manualPriceUsd)
                            : '';
                        this.coupons = [];
                        this.couponError = '';
                        this.couponForm = { code: '', discount_type: 'percent', discount_value: '', max_uses: '', valid_until: '' };
                        // Pre-fill the markup form with the existing per-product rule (if any).
                        if (this.data.productMarkup) {
                            this.markupType = this.data.productMarkup.type || 'percentage';
                            this.markupValue = String(this.data.productMarkup.value ?? '');
                        } else {
                            this.markupType = 'percentage';
                            this.markupValue = '';
                        }
                        this.isOpen = true;
                        // Lock background scroll the sidebar-safe way: DON'T use the
                        // shared rshopScrollLock here. It sets body{position:fixed},
                        // which jumps the Flux *sticky* sidebar, and its ref-counter
                        // desyncs when you click another row while the drawer is open
                        // (re-lock without a matching unlock) - leaving the page stuck
                        // and unable to scroll. Instead just hide the document's
                        // overflow and reserve the scrollbar width on <body> so the
                        // layout never shifts. This is idempotent (safe to re-run).
                        if (document.documentElement.style.overflow !== 'hidden') {
                            const sbw = window.innerWidth - document.documentElement.clientWidth;
                            document.documentElement.style.overflow = 'hidden';
                            document.body.style.paddingRight = sbw > 0 ? sbw + 'px' : '';
                        }
                        this.loadCoupons();
                    },
                    close() {
                        this.isOpen = false;
                        document.documentElement.style.overflow = '';
                        document.body.style.paddingRight = '';
                    },

                    _csrf() {
                        return document.querySelector('meta[name="csrf-token"]')?.content
                            || document.querySelector('input[name="_token"]')?.value
                            || '';
                    },

                    async _send(method, url, body) {
                        const opts = {
                            method,
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this._csrf(),
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'same-origin',
                        };
                        if (body !== undefined) {
                            opts.headers['Content-Type'] = 'application/json';
                            opts.body = JSON.stringify(body);
                        }
                        const res = await fetch(url, opts);
                        const json = await res.json().catch(() => ({}));
                        if (!res.ok) {
                            const err = new Error(json.message || 'Request failed');
                            err.payload = json;
                            err.status = res.status;
                            throw err;
                        }
                        return json;
                    },

                    async savePrice() {
                        if (!this.data.variantId) { return; }
                        const value = parseFloat(this.priceInput);
                        if (!value || value <= 0) {
                            // Empty input + Save = clear the override (same as Reset).
                            return this.resetPrice();
                        }
                        this.savingPrice = true;
                        try {
                            const json = await this._send('PATCH',
                                `/admin/api/catalog/variants/${this.data.variantId}/price`,
                                { manual_retail_price_usd: value });
                            this.data.manualPriceUsd = json.manual_retail_price_usd;
                            // The Sales Price column is server-rendered, so reload
                            // to show the new override (the data is already saved).
                            window.location.reload();
                        } catch (e) {
                            alert(e.message);
                        } finally {
                            this.savingPrice = false;
                        }
                    },

                    async resetPrice() {
                        if (!this.data.variantId) { return; }
                        this.savingPrice = true;
                        try {
                            await this._send('DELETE',
                                `/admin/api/catalog/variants/${this.data.variantId}/price`);
                            this.data.manualPriceUsd = null;
                            this.priceInput = '';
                            window.location.reload();
                        } catch (e) {
                            alert(e.message);
                        } finally {
                            this.savingPrice = false;
                        }
                    },

                    async saveMarkup() {
                        if (!this.data.productId || this.savingMarkup) { return; }
                        const value = parseFloat(this.markupValue);
                        if (!isFinite(value) || value < 0) { return; }
                        this.savingMarkup = true;
                        try {
                            const json = await this._send('PATCH',
                                `/admin/api/catalog/products/${this.data.productId}/markup`,
                                { markup_type: this.markupType, markup_value: value });
                            this.data.productMarkup = { type: json.markup_type, value: json.markup_value };
                            window.location.reload();
                        } catch (e) {
                            alert(e.message);
                        } finally {
                            this.savingMarkup = false;
                        }
                    },

                    async resetMarkup() {
                        if (!this.data.productId || this.savingMarkup) { return; }
                        if (!confirm('Remove the per-product markup and fall back to category / global rules?')) { return; }
                        this.savingMarkup = true;
                        try {
                            await this._send('DELETE',
                                `/admin/api/catalog/products/${this.data.productId}/markup`);
                            this.data.productMarkup = null;
                            this.markupType = 'percentage';
                            this.markupValue = '';
                            window.location.reload();
                        } catch (e) {
                            alert(e.message);
                        } finally {
                            this.savingMarkup = false;
                        }
                    },

                    async setAvailable(value) {
                        if (!this.data.variantId || this.savingAvailable || this.data.isAvailable === value) { return; }
                        const previous = this.data.isAvailable;
                        this.data.isAvailable = value;
                        this.savingAvailable = true;
                        try {
                            const json = await this._send('PATCH',
                                `/admin/api/catalog/variants/${this.data.variantId}/availability`,
                                { is_available: value });
                            this.data.isAvailable = json.is_available;
                        } catch (e) {
                            this.data.isAvailable = previous;
                            alert(e.message);
                        } finally {
                            this.savingAvailable = false;
                        }
                    },

                    async setActive(value) {
                        if (!this.data.productId || this.savingActive || this.data.isActive === value) { return; }
                        const previous = this.data.isActive;
                        this.data.isActive = value;
                        this.savingActive = true;
                        try {
                            // toggle-active flips is_active; we only get here when the
                            // current state differs from `value`, so the flip lands on it.
                            const json = await this._send('PATCH',
                                `/admin/api/catalog/products/${this.data.productId}/toggle-active`);
                            this.data.isActive = json.is_active;
                        } catch (e) {
                            this.data.isActive = previous;
                            alert(e.message);
                        } finally {
                            this.savingActive = false;
                        }
                    },

                    async toggleFeatured() {
                        if (!this.data.productId) { return; }
                        this.togglingFeatured = true;
                        try {
                            const json = await this._send('PATCH',
                                `/admin/api/catalog/products/${this.data.productId}/toggle-featured`);
                            this.data.isFeatured = json.is_featured;
                        } catch (e) {
                            // data.isFeatured is only ever set from the server response,
                            // so a failed flip (e.g. the 10-badge cap) leaves it correct -
                            // just surface why.
                            alert(e.message);
                        } finally {
                            this.togglingFeatured = false;
                        }
                    },

                    async togglePopular() {
                        if (!this.data.productId) { return; }
                        this.togglingPopular = true;
                        try {
                            const json = await this._send('PATCH',
                                `/admin/api/catalog/products/${this.data.productId}/toggle-popular`);
                            this.data.isPopular = json.is_popular;
                        } catch (e) {
                            // Same as Featured: state mirrors the server, so no revert -
                            // a rejected flip (cap reached) just alerts.
                            alert(e.message);
                        } finally {
                            this.togglingPopular = false;
                        }
                    },

                    async loadCoupons() {
                        if (!this.data.variantId) { return; }
                        try {
                            const json = await this._send('GET',
                                `/admin/api/catalog/variants/${this.data.variantId}/coupons`);
                            this.coupons = json.coupons || [];
                        } catch (e) { /* silent — drawer still works */ }
                    },

                    async createCoupon() {
                        if (!this.data.variantId) { return; }
                        this.couponError = '';
                        if (!this.couponForm.code || !this.couponForm.discount_value) {
                            this.couponError = 'Code and discount value are required.';
                            return;
                        }
                        this.creatingCoupon = true;
                        try {
                            const body = {
                                code: this.couponForm.code,
                                discount_type: this.couponForm.discount_type,
                                discount_value: parseFloat(this.couponForm.discount_value),
                            };
                            if (this.couponForm.max_uses) { body.max_uses = parseInt(this.couponForm.max_uses, 10); }
                            if (this.couponForm.valid_until) { body.valid_until = this.couponForm.valid_until; }
                            const json = await this._send('POST',
                                `/admin/api/catalog/variants/${this.data.variantId}/coupons`, body);
                            this.coupons.unshift(json.coupon);
                            this.couponForm = { code: '', discount_type: 'percent', discount_value: '', max_uses: '', valid_until: '' };
                        } catch (e) {
                            this.couponError = e.payload?.errors
                                ? Object.values(e.payload.errors).flat().join(' ')
                                : e.message;
                        } finally {
                            this.creatingCoupon = false;
                        }
                    },

                    async deleteCoupon(id) {
                        if (!confirm('Delete this coupon?')) { return; }
                        try {
                            await this._send('DELETE', `/admin/api/catalog/coupons/${id}`);
                            this.coupons = this.coupons.filter((c) => c.id !== id);
                        } catch (e) { alert(e.message); }
                    },
                };
            };

            // Roaming-coverage map. Lazy-imports jsvectormap on first render,
            // colours every country in the variant's coverage list, and rebuilds
            // when the drawer opens with a different variant.
            window.variantRoamingMap = function () {
                return {
                    map: null,
                    async render(el, countries) {
                        if (!el) { return; }
                        const list = Array.isArray(countries) ? countries : [];
                        if (list.length === 0) {
                            if (this.map && typeof this.map.destroy === 'function') { this.map.destroy(); this.map = null; }
                            el.innerHTML = '';
                            return;
                        }
                        // Lazy-import jsvectormap (same pattern the dashboard uses).
                        const { default: JsVectorMap } = await import('jsvectormap');
                        await import('jsvectormap/dist/jsvectormap.css');
                        window.jsVectorMap = JsVectorMap;
                        await import('jsvectormap/dist/maps/world-merc.js');

                        // Tear down any previous instance — the drawer reuses
                        // this container across variant clicks.
                        if (this.map && typeof this.map.destroy === 'function') { this.map.destroy(); this.map = null; }
                        el.innerHTML = '';

                        const dark = document.documentElement.classList.contains('dark');
                        const values = {};
                        list.forEach((c) => {
                            const code = (c.code || '').toUpperCase();
                            if (code.length === 2) { values[code] = 1; }
                        });

                        this.map = new JsVectorMap({
                            selector: el,
                            map: 'world_merc',
                            backgroundColor: 'transparent',
                            zoomOnScroll: false,
                            zoomButtons: false,
                            regionStyle: {
                                initial: { fill: dark ? '#1d3252' : '#e5e7eb', fillOpacity: 1, stroke: dark ? '#34507a' : '#cbd5e1', strokeWidth: 0.5 },
                                hover: { fillOpacity: 0.85 },
                            },
                            series: {
                                regions: [{
                                    scale: ['#3b82f6', '#1d4ed8'],
                                    values,
                                    normalizeFunction: 'polynomial',
                                    attribute: 'fill',
                                }],
                            },
                        });
                    },
                };
            };
        </script>

    </div>
</x-layouts.admin>
