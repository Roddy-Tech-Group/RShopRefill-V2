@php
    use App\Models\Product;
    use App\Models\Category;
    use App\Models\Subcategory;
    use Illuminate\Support\Facades\DB;

    abort_if(! \App\Support\FeatureFlag::on('topups'), 404);

    // Mobile top-up listing — mirrors the gift-card listing exactly. One card per
    // operator BRAND (Products in the `mobile-airtime` category, grouped by
    // brand_key); the detail page reuses the shared `shop.product` view.
    $topupCategory = Category::where('slug', 'mobile-airtime')->first();

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
     * One card per BRAND, not per (brand × country) — same approach as the gift-card
     * listing. Filter the products table by the active filters, group by brand_key,
     * take the MIN(id) per group as the representative row to display.
     */
    // Hard-scope to the mobile-airtime category. If that category row does not
    // exist yet, `?->id ?? 0` matches nothing — so the page shows its empty
    // state instead of silently falling through to the whole gift-card catalog.
    $baseFilters = Product::query()
        ->where('is_active', true)
        ->whereNotNull('brand_key')
        ->where('category_id', $topupCategory?->id ?? 0)
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
        // Offer-type filter (virtual - reads each variant's Zendit subType from JSON):
        //   'bundles' -> only networks that carry data/bundle plans
        //   'credit' / '' -> every network (all carry credit)
        ->when($sub === 'bundles', fn ($q) => $q->whereHas('variants', fn ($qq) => $qq
            ->where('is_available', true)
            ->where(fn ($w) => $w
                ->whereRaw("JSON_EXTRACT(metadata, '\$.subTypes[0]') LIKE '%Data%'")
                ->orWhereRaw("JSON_EXTRACT(metadata, '\$.subTypes[0]') LIKE '%Bundle%'"))));

    // One representative Product id per brand_key. Prefer the US variant (matches the
    // detail page default); fall back to the lowest id when a brand has no US product.
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
        // Default (Popularity): the is_popular / is_featured flags, then alphabetical.
        $productsQuery->orderByDesc('is_popular')->orderByDesc('is_featured')->orderBy('name');
    }

    // The listing shows every matching operator on one page — no pagination.
    $products = $productsQuery->get();

    // Per-operator Credit / Data / Bundle support for the tile badges. One light
    // DISTINCT query reading just the offer subtype out of the JSON, rather than
    // loading every variant's full metadata into memory across the whole listing.
    $topupBadgesByProduct = \Illuminate\Support\Facades\DB::table('product_variants')
        ->whereIn('product_id', $products->pluck('id'))
        ->where('is_available', true)
        ->selectRaw("product_id, JSON_EXTRACT(metadata, '\$.subTypes[0]') AS subtype")
        ->distinct()
        ->get()
        ->groupBy('product_id')
        ->map(fn ($rows) => $rows
            ->map(fn ($r) => strtolower(trim((string) $r->subtype, '"')))
            ->map(fn ($st) => str_contains($st, 'data') ? 'Data' : (str_contains($st, 'bundle') ? 'Bundle' : 'Credit'))
            ->unique()
            ->sortBy(fn ($t) => ['Credit' => 0, 'Data' => 1, 'Bundle' => 2][$t] ?? 9)
            ->values());

    // No type sub-filter on the listing: the per-network Credit/Data/Bundle badges
    // already show what each operator offers, and the detail page has the switcher.
    // So the listing is one flat "All networks" list.
    $subcategories = collect();

    // Green tint for the trust-badge icons (instant delivery / refund policy).
    $greenTint = 'filter: brightness(0) saturate(100%) invert(48%) sepia(79%) saturate(394%) hue-rotate(105deg) brightness(92%) contrast(87%);';

    // Distinct countries actually available in the synced mobile-airtime catalog.
    $availableCountries = Product::query()
        ->where('category_id', $topupCategory?->id ?? 0)
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

    // Helper to preserve other filters when toggling one.
    // Route name swaps between storefront + dashboard chrome so filter, brand,
    // and view-all links keep the user on whichever side they entered from.
    $inDash = request()->is('dashboard/shop*') && auth()->check();
    $shopRoute = fn (string $name, $params = []) => route(($inDash ? 'dashboard.shop.' : 'shop.').$name, $params);

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
        return $shopRoute('topups', $params);
    };

    // Offer-type browse modes. "All networks" is untouched (every network). "Credit only"
    // is every network scoped to credit. "Credit, Data & Calls" is only networks with
    // data/bundle plans, and carries that scope to the detail page's opening tab.
    $sidebarSubItems = [
        ['label' => 'All networks', 'url' => $filterUrl(['subcategory' => null]), 'active' => $sub === ''],
        ['label' => 'Credit only', 'url' => $filterUrl(['subcategory' => 'credit']), 'active' => $sub === 'credit'],
        ['label' => 'Credit, Data & Calls', 'url' => $filterUrl(['subcategory' => 'bundles']), 'active' => $sub === 'bundles'],
    ];
@endphp

<x-shop.layout :title="'Mobile Top-up | '.$siteName">

    <section class="min-h-full rounded-[15px] bg-[#eff6ff]">
        <div class="mx-auto w-full max-w-[1550px] px-4 py-8 sm:px-6 lg:px-8">

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-[220px_1fr] lg:gap-8">

                {{-- Shared category sidebar — same component on every storefront. --}}
                <x-shop.category-sidebar active="mobile-airtime" :sub-items="$sidebarSubItems" />

                {{-- Main column --}}
                <div>
                    {{-- Mobile category picker (dark pill + slide-up sheet). --}}
                    <div class="mb-4 sm:hidden">
                        <x-shop.category-picker active="mobile-airtime" :sub-items="$sidebarSubItems" />
                    </div>

                    {{-- Heading + search row --}}
                    <div class="mb-6 flex flex-col gap-3 sm:grid sm:grid-cols-3 sm:items-center sm:gap-4">
                        <div class="hidden sm:block">
                            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl">Mobile Top-up</h1>
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

                        {{-- Modern segmented sort selector. URL-driven so the choice survives reloads. --}}
                        <div class="inline-flex shrink-0 items-center rounded-[12px] bg-zinc-200 p-1 sm:justify-self-end" role="tablist" aria-label="Sort networks">
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

                    {{-- Mobile/tablet filter pill row (sidebar hidden below lg) --}}
                    <div class="-mx-1 mb-6 hidden overflow-x-auto px-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden md:block lg:hidden">
                        <div class="flex w-max items-center gap-2">
                            @foreach ($sidebarSubItems as $item)
                                <a
                                    href="{{ $item['url'] }}"
                                    wire:navigate
                                    class="inline-flex shrink-0 items-center rounded-[12px] px-4 py-1.5 text-xs font-semibold transition-colors {{ $item['active'] ? 'bg-zinc-900 text-white' : 'bg-white text-zinc-700 ring-1 ring-zinc-200' }}"
                                >
                                    {{ $item['label'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>

                    {{-- Operator grid. Same card treatment as the gift-card listing. --}}
                    <div>
                        @if ($products->isNotEmpty())
                            <ul class="grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                                @foreach ($products as $product)
                                    @php
                                        $variants   = $product->variants;
                                        $available  = $variants->where('is_available', true);
                                        $isOut      = $variants->isNotEmpty() && $available->isEmpty();
                                        $priceLabel = $product->priceRangeLabel();
                                        $logoSrc    = Product::brandLogoUrl($product->brand_key, $product->logo_url);
                                        $opName     = Product::brandDisplayName($product->brand_key);
                                        $tileColor  = Product::tileColor($product->brand_key);
                                        $badges     = $topupBadgesByProduct[$product->id] ?? collect();
                                    @endphp
                                    <li>
                                        <a
                                            href="{{ $shopRoute('topup', array_filter(['brandSlug' => Product::brandSlug($product->brand_key), 'country' => $product->country_code, 'plan' => $sub === 'bundles' ? 'bundles' : ($sub === 'credit' ? 'credit' : null)], fn ($v) => $v !== null)) }}"
                                            wire:navigate
                                            class="card-3d-scene group block focus:outline-none"
                                            aria-label="{{ Product::brandDisplayName($product->brand_key) }}"
                                        >
                                            {{-- White tile, logo fills the card edge-to-edge. Caption sits OUTSIDE on the page background. --}}
                                            <div
                                                class="card-3d relative flex aspect-[16/10] items-center justify-center overflow-hidden rounded-[15px] shadow-sm ring-1 ring-zinc-200 group-hover:shadow-lg group-hover:ring-zinc-300 {{ $logoSrc ? 'bg-[#ffffff]' : '' }}"
                                                @if (! $logoSrc) style="background-color: {{ $tileColor }}" @endif
                                                x-data="cardTilt()"
                                                @mousemove="tilt($event)"
                                                @mouseleave="reset()"
                                            >
                                                @if ($logoSrc)
                                                    <img src="{{ $logoSrc }}" alt="{{ $opName }}" class="h-full w-full object-cover" loading="lazy">
                                                @else
                                                    <span class="px-3 text-center text-lg font-extrabold leading-tight text-white">{{ $opName }}</span>
                                                @endif

                                                @if ($isOut)
                                                    <span class="absolute bottom-2 right-2 inline-flex items-center rounded-full border border-white bg-zinc-900/60 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white backdrop-blur-sm">
                                                        Out of stock
                                                    </span>
                                                @elseif ($product->is_popular)
                                                    <span class="absolute left-2 top-2 inline-flex items-center rounded-full border border-fuchsia-500 bg-fuchsia-500/10 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-fuchsia-600 backdrop-blur-sm">Popular</span>
                                                @elseif ($product->is_featured)
                                                    <span class="absolute left-2 top-2 inline-flex items-center rounded-full border border-amber-500 bg-amber-500/10 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-600 backdrop-blur-sm">Featured</span>
                                                @endif

                                                <span class="card-3d-glare pointer-events-none absolute inset-0" aria-hidden="true"></span>
                                            </div>

                                            {{-- Caption — operator name (only when a real logo is on the
                                                 tile; the styled tile already shows the name) + amount range. --}}
                                            <div class="mt-2 px-0.5">
                                                @if ($logoSrc)
                                                    <p class="truncate text-[13px] font-bold leading-tight text-zinc-900 group-hover:text-blue-700">{{ $opName }}</p>
                                                @endif
                                                @if ($priceLabel)
                                                    <p class="mt-0.5 truncate text-[14px] text-zinc-600">{{ $priceLabel }}</p>
                                                @endif
                                                @if ($badges->isNotEmpty())
                                                    {{-- What this network offers: Credit / Data / Bundle. --}}
                                                    <div class="mt-1.5 flex flex-wrap gap-1">
                                                        @foreach ($badges as $badge)
                                                            <span @class([
                                                                'inline-flex items-center rounded-[6px] px-1.5 py-0.5 text-[10px] font-semibold',
                                                                'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300' => $badge === 'Credit',
                                                                'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300' => $badge === 'Data',
                                                                'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300' => $badge === 'Bundle',
                                                            ])>{{ $badge }}</span>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>

                        @else
                            <div class="rounded-[12px] bg-white px-6 py-20 text-center ring-1 ring-zinc-200">
                                <img src="{{ asset('assets/' . rawurlencode('Empty state.webp')) }}" alt="" class="mx-auto block h-44 w-auto object-contain" loading="lazy">
                                @if ($search !== '' || $countryFiltered || $sub !== '')
                                    <p class="mt-4 text-base font-semibold text-zinc-900">No networks match these filters</p>
                                    <p class="mt-1 text-sm text-zinc-600">Try clearing the search or pick a different country.</p>
                                    <a href="{{ $shopRoute('topups') }}" wire:navigate class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-700">
                                        Clear all filters
                                    </a>
                                @else
                                    <p class="mt-4 text-base font-semibold text-zinc-900">Mobile top-up coming soon</p>
                                    <p class="mt-1 text-sm text-zinc-600">We're adding mobile networks for your region. Check back shortly.</p>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>

        </div>
    </section>

</x-shop.layout>
