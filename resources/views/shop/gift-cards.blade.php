@php
    use App\Models\Product;
    use App\Models\Category;
    use App\Models\Subcategory;
    use Illuminate\Support\Facades\DB;

    // Feature A: when the category feature is off we keep the catalog VISIBLE but
    // greyed and non-purchasable (it used to 404). The buy itself is blocked server-side.
    $categoryEnabled = \App\Support\FeatureFlag::on('gift_cards');

    $giftCardsCategory = Category::where('slug', 'gift-cards')->first();

    // URL-driven filters. $country is the locked region (resolved by ResolveRegion
    // middleware and shared as $region) — the catalog only ever shows that country.
    $search   = request()->queryString('q', '');
    $country  = strtoupper((string) ($region ?? 'US'));
    $currency = strtoupper(request()->queryString('currency', ''));
    $sub      = request()->queryString('subcategory', '');
    $sort     = request()->queryString('sort', 'popular');

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
        ->when($currency !== '', fn ($q) => $q->where('currency_code', $currency));

    // Everything above WITHOUT the subcategory filter, kept so the sidebar can
    // ask which categories have stock in this country. Taking that from the
    // grid's own results would not work: once a subcategory is picked, the
    // grid holds only that one, and the menu would collapse to a single entry.
    $filtersIgnoringSubcategory = clone $baseFilters;

    // NOT filtered by subcategory any more. The listing renders every category
    // as its own section and the client switches between them, so the products
    // for all of them have to be here. ?subcategory= still decides which
    // section is showing on arrival - it is now a view concern, not a query.
    //
    // This costs nothing: the "All gift cards" view already loaded every brand,
    // so a deep-linked category is the same payload it always was.

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

    if ($sort === 'recent') {
        // Newest first. The menu's "Recently Added" links here; without this
        // branch the parameter would fall through to popularity and the link
        // would quietly do nothing.
        $productsQuery->orderByDesc('created_at')->orderByDesc('id');
    } elseif ($sort === 'name-asc') {
        $productsQuery->orderBy('name');
    } elseif ($sort === 'name-desc') {
        $productsQuery->orderByDesc('name');
    } else {
        // Default (Popularity): Gaming & Entertainment brands lead the whole
        // page, then the admin's is_popular / is_featured flags - those are
        // set per product on the admin dashboard and outrank the hand-curated
        // config list, which only breaks ties among unflagged brands.
        // Alphabetical last. Explicit A-Z sorts above skip the gaming boost.
        $gamingSubId = $giftCardsCategory
            ? Subcategory::where('category_id', $giftCardsCategory->id)->where('slug', 'gaming-entertainment')->value('id')
            : null;
        if ($gamingSubId) {
            $productsQuery->orderByRaw('CASE WHEN subcategory_id = ? THEN 0 ELSE 1 END', [$gamingSubId]);
        }
        // Curated gaming leaders open the page in their exact config order
        // (Free Fire, PUBG, EA Play, Mobile Legends, Discord Nitro, ...).
        $gamingLeaders = config('popular_brands.gaming_leaders', []);
        if (! empty($gamingLeaders)) {
            $leaderCases = implode(' ', array_map(fn ($i) => "WHEN ? THEN {$i}", array_keys($gamingLeaders)));
            $productsQuery->orderByRaw("CASE brand_key {$leaderCases} ELSE 99 END", $gamingLeaders);
        }
        $productsQuery->orderByDesc('is_popular')->orderByDesc('is_featured');
        $popularKeys = config('popular_brands.keys', []);
        if (! empty($popularKeys)) {
            $placeholders = implode(',', array_fill(0, count($popularKeys), '?'));
            $productsQuery->orderByRaw("CASE WHEN brand_key IN ({$placeholders}) THEN 0 ELSE 1 END", $popularKeys);
        }
        $productsQuery->orderBy('name');
    }

    // Final tie-break on the primary key. Every sort above can tie (two brands
    // sharing a name, or the whole unflagged tail sharing one popularity rank),
    // and each page is its own query, so without it a tied brand can show up on
    // two consecutive pages while another never appears at all.
    $productsQuery->orderBy('id');

    // Unpaginated query: one Product per brand_key, so every matching brand is
    // available to the category sections and the total shown below the flat grid.
    //
    // It stays a fixed number of queries rather than N+1: the variants come back
    // in one eager-load and the ratings in one rollup, both keyed by the full
    // result set. Tile images past the first ten load lazily, so the browser
    // fetches them as the visitor scrolls rather than all at once.
    //
    // This is still one of the heaviest anonymous requests in the app. The flat
    // "All gift cards" grid below limits its duplicate tile markup by default;
    // ?brands=all opts into rendering its complete copy of the catalogue.
    $products = $productsQuery->get();

    // Categories that actually have something to sell under the current
    // filters. A category with no stock in the chosen country is not listed at
    // all - it was a menu entry whose only destination was the empty state.
    $stockedSubcategoryIds = DB::table('product_variants')
        ->whereIn('product_id', (clone $filtersIgnoringSubcategory)->select('id'))
        ->whereNotNull('subcategory_id')
        ->distinct()
        ->pluck('subcategory_id');

    $subcategories = $giftCardsCategory
        ? Subcategory::where('category_id', $giftCardsCategory->id)
            ->whereIn('id', $stockedSubcategoryIds)
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
        : collect();

    // Which categories each brand belongs to, for the section-per-category
    // layout below. One query for the page, keyed by brand.
    //
    // Read off the VARIANTS' subcategory, not the product's own column: that
    // is what the subcategory FILTER matches on, so a brand lands in exactly
    // the sections whose filter would return it. The product-level column
    // holds only its representative subcategory, which would drop a brand from
    // every other category it actually sells in.
    //
    // A brand spanning several categories appears in each of them - browsing
    // by category should find it wherever a shopper looks, and a tile is
    // cheap to repeat.
    $brandSubcategories = collect();
    if ($products->isNotEmpty()) {
        $brandSubcategories = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('products.id', $products->pluck('id'))
            ->whereNotNull('product_variants.subcategory_id')
            ->distinct()
            ->get(['products.brand_key', 'product_variants.subcategory_id'])
            ->groupBy('brand_key')
            ->map(fn ($rows) => $rows->pluck('subcategory_id')->unique()->all());
    }

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

    // Nothing at all for this country, as opposed to a search or category that
    // happened to match nothing. $stockedSubcategoryIds already answers this:
    // it is built from the country / currency / search filters WITHOUT the
    // subcategory clause, so an empty set means the country is bare rather
    // than the visitor having narrowed too far.
    $countryHasNoCatalogue = $country !== ''
        && $search === ''
        && $stockedSubcategoryIds->isEmpty();

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

    // ALWAYS grouped, even when a category is deep-linked. Every section is on
    // the page, so switching between them is a class toggle rather than a
    // request - and a deep link just starts with one of them showing.
    $groupByCategory = $subcategories->isNotEmpty() && $products->isNotEmpty();

    // The curated row that opens the page. Ordered as configured, not
    // alphabetically - it is a shop window rather than an index - and built by
    // key lookup so a brand the catalogue lacks is skipped, not left as a gap.
    $featuredKeys = config('popular_brands.featured', []);

    // Keyed case-insensitively: the catalogue stores PlayStation as
    // "Playstation", and an exact match dropped it from the row without a
    // trace. The database compares case-insensitively, so every check said the
    // brand was present - only PHP's array lookup disagreed.
    $byBrand = $products->keyBy(fn ($product) => strtolower($product->brand_key));
    $featured = collect($featuredKeys)
        ->map(fn ($key) => $byBrand[strtolower($key)] ?? null)
        ->filter()
        ->values();

    $sections = collect();
    if ($groupByCategory) {
        // Supplier subcategories no longer render as sections - the curated set
        // above IS the category list. Anything they held that no curated
        // category claims still reaches the page through the "More gift cards"
        // sweep below, so nothing is lost.
        $sections = collect()
            ->map(fn ($subcategory) => [
                'name' => $subcategory->name,
                'slug' => $subcategory->slug,
                'products' => $products->filter(
                    fn ($product) => in_array(
                        $subcategory->id,
                        $brandSubcategories[$product->brand_key] ?? [],
                        false
                    )
                ),
            ])
            ->filter(fn ($section) => $section['products']->isNotEmpty())
            ->values();

        // Curated categories next: shopper-facing groupings the supplier
        // taxonomy has no equivalent for. They sit between the featured row and
        // the synced subcategories, and a brand appearing here still appears
        // under whatever the supplier filed it as further down.
        $curatedSections = \App\Support\GiftCardCategories::curated($products);

        foreach ($curatedSections->reverse() as $curated) {
            $sections->prepend([
                'name' => $curated['name'],
                'slug' => $curated['slug'],
                'products' => $curated['products'],
            ]);
        }

        // Featured leads, before the alphabetical categories.
        if ($featured->isNotEmpty()) {
            $sections->prepend([
                'name' => __('products.popular_brands_heading'),
                'slug' => 'popular',
                'products' => $featured,
            ]);
        }

        /*
         * "More Gift Cards" - everything sellable here that no category claims.
         *
         * 723 of the catalogue's 1,327 brand-country records carry no curated
         * category, and only 7 of 58 countries are fully covered. Without this
         * section those brands are reachable by search or a direct link and
         * nothing else, which is not browsing.
         *
         * Derived, never stored: it is defined as the remainder, so a brand
         * leaves the moment a category claims it and no cleanup is needed.
         *
         * Keyed on brand AND country. The same brand is a different product in
         * a different country - different denominations, different redemption
         * rules - so brand_key alone would collapse two real cards into one.
         * $products is already filtered to the selected country, but the key
         * stays explicit so it cannot silently become wrong if that changes.
         */
        $keyOf = fn ($product) => strtolower($product->brand_key).'|'.strtoupper((string) $product->country_code);

        // The featured row is excluded: it is a shop window over brands that
        // each still belong to a real category, so counting it here would hide
        // a genuinely unfiled brand behind its appearance in that row.
        $claimed = $sections
            ->reject(fn ($section) => $section['slug'] === 'popular')
            ->pluck('products')
            ->flatten()
            ->map($keyOf)
            ->unique()
            ->flip();

        $unclaimed = $products
            ->reject(fn ($product) => $claimed->has($keyOf($product)))
            // Same brand+country twice cannot render twice, whatever upstream does.
            ->unique($keyOf)
            // Ranked where we have a signal - the admin's popular and featured
            // flags, then the curated leader list - and alphabetical after
            // that, so an unranked tail is still predictable rather than
            // arbitrary database order.
            ->sortBy([
                fn ($product) => $product->is_popular ? 0 : 1,
                fn ($product) => $product->is_featured ? 0 : 1,
                fn ($product) => array_search($product->brand_key, config('popular_brands.keys', []), true) === false ? 1 : 0,
                fn ($product) => Product::brandDisplayName($product->brand_key),
            ])
            ->values();

        if ($unclaimed->isNotEmpty()) {
            $sections->push([
                'name' => __('products.more_gift_cards'),
                // A real slug, so the section is filterable and linkable like
                // any other rather than being a dead heading.
                'slug' => 'more',
                'products' => $unclaimed,
            ]);
        }
    }

    // How many brands the catch-all holds, for the menu entry below - the
    // sections are built after this point.
    $moreCount = $sections->firstWhere('slug', 'more')['products'] ?? collect();
    $moreCount = $moreCount instanceof \Illuminate\Support\Collection ? $moreCount->count() : 0;

    // Subcategory links for the shared sidebar — "All" first, then each subtype.
    $sidebarSubItems = array_merge(
        [['label' => __('products.all_gift_cards'), 'slug' => '', 'url' => $filterUrl(['subcategory' => null]), 'active' => $sub === '']],
        // Featured sits directly under "All", ahead of the alphabetical list.
        // Short label here - the long heading belongs on the section, not in a
        // 268px menu column.
        $featured->isNotEmpty()
            ? [['label' => __('products.popular_brands'), 'slug' => 'popular', 'url' => $filterUrl(['subcategory' => 'popular']), 'active' => $sub === 'popular']]
            : [],
        // Curated categories, in configured order, before the synced ones.
        \App\Support\GiftCardCategories::curated($products)->map(fn ($c) => [
            'label' => $c['name'],
            'slug' => $c['slug'],
            'url' => $filterUrl(['subcategory' => $c['slug']]),
            'active' => $sub === $c['slug'],
        ])->all(),
        // "More Gift Cards" closes the menu when it has anything in it, so the
        // section is reachable from the sidebar and the sheet, not only by
        // scrolling to the bottom of the page.
        $moreCount > 0
            ? [['label' => __('products.more_gift_cards'), 'slug' => 'more', 'url' => $filterUrl(['subcategory' => 'more']), 'active' => $sub === 'more']]
            : [],
        collect()->map(fn ($s) => [
            'label' => $s->name,
            'slug' => $s->slug,
            'url' => $filterUrl(['subcategory' => $s->slug]),
            'active' => $sub === $s->slug,
        ])->all()
    );

@endphp

<x-shop.layout :title="__('giftcards.page_title', ['site' => $siteName])">

    {{-- No radius: this is the page slab, edge to edge, not a card. Rounded
         corners on it left the page ground showing through above and below. --}}
    <section class="min-h-full bg-[#eff6ff]">
        {{-- Inside the dashboard the frame pads the page itself (16px a side on a
             phone, what the overview gets), so the wrapper adds none of its own:
             with both, the listing ran 16px narrower than the overview each side. --}}
        <div class="mx-auto w-full max-w-[1550px] {{ $inDash ? '' : 'px-4 py-8 sm:px-6 lg:px-8' }}">

            {{-- The category filter scope wraps BOTH menus and the sections: the
                 sidebar and the mobile sheet are siblings of the main column, so
                 a scope inside that column would leave their bindings undefined. --}}
            <div
                class="grid grid-cols-1 gap-6 lg:grid-cols-[268px_1fr] lg:gap-8 scroll-mt-32"
                x-data="{
                    activeCategory: @js($sub),

                    /*
                     * Rewrite the URL rather than navigate. The view stays
                     * shareable and reloadable, and Back still works, without
                     * fetching markup the page already holds.
                     */
                    select(slug) {
                        this.activeCategory = slug;

                        try {
                            const url = new URL(window.location.href);
                            if (slug) {
                                url.searchParams.set('subcategory', slug);
                            } else {
                                url.searchParams.delete('subcategory');
                            }
                            window.history.pushState({ subcategory: slug }, '', url.toString());
                        } catch (e) {}

                        // A category further down the page would otherwise open
                        // scrolled past its own heading.
                        this.$el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    },
                }"
                @category-filtered.window="select($event.detail.slug)"
                {{-- Back / Forward move between categories too, since each one
                     pushed a history entry. --}}
                @popstate.window="activeCategory = new URL(window.location.href).searchParams.get('subcategory') || ''"
            >

                {{-- Shared category sidebar — same component on every storefront. --}}
                <x-shop.category-sidebar active="gift-cards" :sub-items="$sidebarSubItems" :show-categories="false" :client-filter="$groupByCategory" />

                {{-- Main column.

                     min-w-0 is load-bearing: a grid item defaults to
                     min-width:auto and will not shrink below its content, so a
                     horizontally scrolling child widens this column instead of
                     scrolling inside it. --}}
                <div class="min-w-0">
                    {{-- Mobile category picker (dark pill + slide-up sheet). Replaces
                         the hidden desktop sidebar at < sm so customers can hop between
                         categories without leaving the page. --}}
                    <div class="mb-4 sm:hidden">
                        <x-shop.category-picker active="gift-cards" :sub-items="$sidebarSubItems" :show-categories="false" :client-filter="$groupByCategory" />
                    </div>

                    {{-- No page h1: the first section carries that heading now,
                         and the same sentence twice on one screen reads as a
                         mistake. The sidebar names the page for screen readers. --}}

                    {{-- Mobile/tablet subcategory pill row (sidebar hidden below lg) --}}
                    @if ($subcategories->isNotEmpty())
                        <div class="-mx-1 mb-6 hidden overflow-x-auto px-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden md:block lg:hidden">
                            <div class="flex w-max items-center gap-2">
                                {{-- Third menu onto the same filter, so it switches in
                                     place like the sidebar and the sheet. href stays real
                                     for middle-click and for JS off. --}}
                                @php
                                    $pillBase = 'inline-flex shrink-0 items-center rounded-[12px] px-4 py-1.5 text-xs font-semibold transition-colors';
                                    $pillOn = 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900';
                                    $pillOff = 'bg-white text-zinc-700 ring-1 ring-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10';
                                @endphp

                                <a
                                    href="{{ $filterUrl(['subcategory' => null]) }}"
                                    @if ($groupByCategory)
                                        @click.prevent="$dispatch('category-filtered', { slug: '' })"
                                        :class="activeCategory === '' ? @js($pillOn) : @js($pillOff)"
                                        class="{{ $pillBase }}"
                                    @else
                                        wire:navigate
                                        class="{{ $pillBase }} {{ $sub === '' ? $pillOn : $pillOff }}"
                                    @endif
                                >
                                    {{ __('products.all_gift_cards') }}
                                </a>
                                @foreach ($subcategories as $s)
                                    <a
                                        href="{{ $filterUrl(['subcategory' => $s->slug]) }}"
                                        @if ($groupByCategory)
                                            @click.prevent="$dispatch('category-filtered', { slug: @js($s->slug) })"
                                            :class="activeCategory === @js($s->slug) ? @js($pillOn) : @js($pillOff)"
                                            class="{{ $pillBase }}"
                                        @else
                                            wire:navigate
                                            class="{{ $pillBase }} {{ $sub === $s->slug ? $pillOn : $pillOff }}"
                                        @endif
                                    >
                                        {{ $s->name }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @unless ($categoryEnabled)
                        <div class="mb-5 flex items-start gap-3 rounded-[12px] border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-500/20 dark:bg-amber-500/10">
                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                            </svg>
                            <div>
                                <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">{{ __('products.category_unavailable') }}</p>
                                <p class="mt-0.5 text-xs text-amber-800/80 dark:text-amber-200/70">{{ __('giftcards.purchases_paused_hint') }}</p>
                            </div>
                        </div>
                    @endunless

                    {{-- Product grid. Skeleton overlay removed — it was rendering misaligned with the real
                         grid and flashing on every navigation. wire:navigate transitions are fast enough
                         without a synthetic loading state. --}}
                    @php
                        // One rollup for the whole page rather than a query per
                        // card - see Review::brandRatings().
                        $brandRatings = \App\Models\Review::brandRatings($products->pluck('brand_key'));
                    @endphp

                    <div>
                        @if ($products->isNotEmpty())
                            @php
                                // Everything a tile needs, resolved once per product.
                                // Sections repeat a brand that sells in more than one
                                // category, so this must not be recomputed per section.
                                $tileFor = function ($product) use ($brandRatings, $categoryEnabled, $shopRoute) {
                                    $available = $product->variants->where('is_available', true);
                                    // Out of stock = no available variant OR the admin's product-level flag.
                                    $outOfStock = ($product->variants->isNotEmpty() && $available->isEmpty())
                                        || $product->is_out_of_stock;

                                    return [
                                        // Not buyable = category off OR out of stock. The tile
                                        // then renders greyed and inert.
                                        'notBuyable' => ! $categoryEnabled || $outOfStock,
                                        'outOfStock' => $outOfStock,
                                        'categoryEnabled' => $categoryEnabled,
                                        // Min-to-max across ALL denominations, in the product's currency.
                                        'priceLabel' => $product->priceRangeLabel(),
                                        'logoSrc' => Product::brandLogoUrl($product->brand_key, $product->logo_url),
                                        // Absent until a brand has been reviewed - the caption
                                        // then shows no rating rather than a zero.
                                        'rating' => $brandRatings[$product->brand_key] ?? null,
                                        'href' => $shopRoute('brand', [
                                            'brandSlug' => Product::brandSlug($product->brand_key),
                                            'country' => $product->country_code,
                                        ]),
                                    ];
                                };

                                // Counts tiles across the WHOLE page, not per section:
                                // it decides which images load eagerly, and each section
                                // restarting would eagerly load ten logos per heading.
                                $tileIndex = 0;
                            @endphp

                            @if ($groupByCategory)

                                {{-- A section per category, so "All gift cards" is still
                                     browsable: 285 unlabelled tiles in one grid is a wall,
                                     and the whole point of showing them all at once is that
                                     you can find something in it.

                                     A brand selling across several categories appears in
                                     each - someone browsing Gaming should find it there
                                     whether or not it also sells under Entertainment. --}}
                                @foreach ($sections as $section)
                                    <x-shop.category-section
                                        :title="$section['name']"
                                        :id="'cat-'.$loop->index"
                                        :slug="$section['slug']"
                                    >
                                        {{-- No chunking: the section lays the tiles out
                                             three rows deep and pages sideways, so the
                                             row count holds at every breakpoint. --}}
                                        @foreach ($section['products'] as $product)
                                            @php $tile = $tileFor($product); @endphp
                                            <x-shop.brand-tile
                                                :product="$product"
                                                :index="$tileIndex++"
                                                :href="$tile['href']"
                                                :rating="$tile['rating']"
                                                :price-label="$tile['priceLabel']"
                                                :logo-src="$tile['logoSrc']"
                                                :out-of-stock="$tile['outOfStock']"
                                                :category-enabled="$tile['categoryEnabled']"
                                                :not-buyable="$tile['notBuyable']"
                                            />
                                        @endforeach
                                    </x-shop.category-section>
                                @endforeach

                                {{-- Everything, flat. See the note on the
                                     component-free grid below: this is the
                                     "just show me all of them" view, so it
                                     carries no category headings at all. --}}
                                @php
                                    // How many of the flat grid's tiles are RENDERED, as
                                    // opposed to revealed.
                                    //
                                    // This grid used to render the whole catalogue, every
                                    // tile wrapped in `<template x-if="index < limit">` with
                                    // limit starting at 30. The reveal was instant, but the
                                    // other ~650 tiles were still built by Blade, sent down
                                    // the wire and parsed by the browser on every single
                                    // visit, for content almost nobody scrolls to. Measured
                                    // on this page: 722 tiles at ~1,614 bytes each, and the
                                    // page came to 1.73 MB with a 4.7s render.
                                    //
                                    // Four reveals' worth are rendered now. Anyone who wants
                                    // the whole list follows the link at the end, which comes
                                    // back with ?brands=all and renders the lot, so nothing
                                    // is unreachable and the default visit pays for a
                                    // fraction of it.
                                    $flatShowAll = request()->query('brands') === 'all';
                                    $flatProducts = $flatShowAll ? $products : $products->take(120);
                                @endphp
                                <section
                                    class="mt-20"
                                    aria-labelledby="all-gift-cards"
                                    x-show="activeCategory === ''"
                                    x-data="{
                                        /* `rendered` is the ceiling the reveal can reach,
                                           `total` is the whole catalogue. They differ unless
                                           the page was asked for every brand, and the copy
                                           below reports both honestly. */
                                        limit: 30,
                                        rendered: {{ $flatProducts->count() }},
                                        total: {{ $products->count() }},

                                        more() {
                                            this.limit = Math.min(this.rendered, this.limit + 30);
                                        },
                                    }"
                                >
                                    {{-- Heavier than a category heading: this is a
                                         section of the page, not another entry in
                                         the list above it. --}}
                                    <h2 id="all-gift-cards" class="mb-6 text-[32px] font-extrabold leading-[38px] tracking-tight text-zinc-900 dark:text-white">
                                        {{ __('products.all_gift_cards') }}
                                    </h2>

                                    <ul class="grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-3 lg:grid-cols-4">
                                        @foreach ($flatProducts as $product)
                                            @php $tile = $tileFor($product); @endphp
                                            <template x-if="{{ $loop->index }} < limit">
                                                <x-shop.brand-tile
                                                    :product="$product"
                                                    :index="99"
                                                    :href="$tile['href']"
                                                    :rating="$tile['rating']"
                                                    :price-label="$tile['priceLabel']"
                                                    :logo-src="$tile['logoSrc']"
                                                    :out-of-stock="$tile['outOfStock']"
                                                    :category-enabled="$tile['categoryEnabled']"
                                                    :not-buyable="$tile['notBuyable']"
                                                />
                                            </template>
                                        @endforeach
                                    </ul>

                                    {{-- How many of the catalogue are on screen,
                                         then the control that adds to it. The
                                         count reads as a progress report on the
                                         button below it rather than a claim that
                                         everything is already here. --}}
                                    @php
                                        [$beforeShown, $rest] = explode(':shown', __('products.showing_out_of'), 2);
                                        [$between, $after] = explode(':total', $rest, 2);
                                    @endphp

                                    <div class="mt-10 flex flex-col items-center gap-4">
                                        <p class="text-[14px] font-bold text-zinc-900 dark:text-white">
                                            {{ $beforeShown }}<span x-text="Math.min(limit, total)"></span>{{ $between }}<span x-text="total"></span>{{ $after }}
                                        </p>

                                        <div class="h-1.5 w-56 overflow-hidden rounded-full bg-[#dee3e1] dark:bg-white/10" role="presentation">
                                            <span
                                                class="block h-full rounded-full bg-[#0c1a36] transition-[width] duration-300 ease-out dark:bg-white"
                                                :style="'width: ' + Math.round((Math.min(limit, total) / total) * 100) + '%'"
                                            ></span>
                                        </div>

                                        {{-- The request-a-product form, not the contact page:
                                             it files the ask as a product request, which is
                                             what a shopper who reached the end of the list has. --}}
                                        <p class="text-[14px] text-zinc-700 dark:text-zinc-300">
                                            {{ __('products.didnt_find') }}
                                            <a
                                                href="{{ route('shop.request-product') }}"
                                                wire:navigate
                                                class="font-semibold text-zinc-900 underline decoration-1 underline-offset-4 transition-colors hover:text-blue-600 dark:text-white dark:hover:text-blue-400"
                                            >{{ __('products.request_a_product') }}</a>
                                        </p>

                                        {{-- Reveal while tiles are still rendered and waiting. --}}
                                        <div class="mt-2" x-show="limit < rendered">
                                            <button
                                                type="button"
                                                @click="more()"
                                                class="inline-flex items-center rounded-full bg-zinc-900 px-6 py-3 text-[15px] font-bold text-white transition-colors hover:bg-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                                            >
                                                {{ __('products.see_more') }}
                                            </button>
                                        </div>

                                        {{-- The rendered set is exhausted but the catalogue
                                             is not. A link rather than another reveal,
                                             because the remaining tiles were never sent:
                                             this comes back with every brand rendered.
                                             wire:navigate keeps it an in-place swap.
                                             Hidden entirely once ?brands=all has been
                                             followed, since there is nothing left to ask
                                             for. --}}
                                        @unless ($flatShowAll)
                                            <div class="mt-2" x-show="limit >= rendered && rendered < total" x-cloak>
                                                <a
                                                    href="{{ request()->fullUrlWithQuery(['brands' => 'all']) }}#all-gift-cards"
                                                    wire:navigate
                                                    class="inline-flex items-center rounded-full bg-zinc-900 px-6 py-3 text-[15px] font-bold text-white transition-colors hover:bg-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                                                >
                                                    {{ __('products.see_more') }}
                                                </a>
                                            </div>
                                        @endunless
                                    </div>
                                </section>

                            @else
                                {{-- No categories to group by at all - a single grid is
                                     all there is to show. --}}
                                <ul class="grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-3 lg:grid-cols-4">
                                    @foreach ($products as $product)
                                        @php $tile = $tileFor($product); @endphp
                                        <x-shop.brand-tile
                                            :product="$product"
                                            :index="$tileIndex++"
                                            :href="$tile['href']"
                                            :rating="$tile['rating']"
                                            :price-label="$tile['priceLabel']"
                                            :logo-src="$tile['logoSrc']"
                                            :out-of-stock="$tile['outOfStock']"
                                            :category-enabled="$tile['categoryEnabled']"
                                            :not-buyable="$tile['notBuyable']"
                                        />
                                    @endforeach
                                </ul>
                            @endif

                        @else
                            <div class="rounded-[12px] bg-white px-6 py-20 text-center ring-1 ring-zinc-200">
                                <img src="{{ asset('assets/empty-state-blue.webp') }}" alt="" class="mx-auto block h-44 w-auto object-contain" loading="lazy">
                                @if ($countryHasNoCatalogue)
                                    {{-- The country is bare, so nothing the visitor did
                                         caused this. Naming it is the whole message, and
                                         "clear all filters" would be misleading - there is
                                         nothing to clear. The way out is the locale
                                         switcher, which is where country lives. --}}
                                    <p class="mt-4 text-base font-semibold text-zinc-900 dark:text-white">
                                        {{ __('products.no_gift_cards_in_country', ['country' => $currentCountryName ?? $country]) }}
                                    </p>
                                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                        {{ __('products.no_gift_cards_in_country_hint') }}
                                    </p>

                                    @include('shop._empty-state-back', ['switcher' => false])

                                    <div class="mt-3 flex items-center justify-center">
                                        {{-- Opens the same locale sheet the nav pill does.
                                             `.stop` for the reason the footer chip needs it:
                                             without it the click bubbles, the backdrop
                                             appears under the cursor, and the same click
                                             closes the sheet again. --}}
                                        <button
                                            type="button"
                                            x-data
                                            @click.stop="$dispatch('open-locale-modal'); localeModalOpen = true"
                                            class="inline-flex items-center gap-2 rounded-full bg-zinc-900 px-5 py-2.5 text-sm font-bold text-white transition-colors hover:bg-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                                        >
                                            {{ __('products.switch_country') }}
                                        </button>
                                    </div>
                                @else
                                    <p class="mt-4 text-base font-semibold text-zinc-900 dark:text-white">{{ __('products.no_products') }}</p>
                                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ __('products.no_products_hint') }}</p>
                                    @if ($search !== '' || $countryFiltered || $sub !== '')
                                        <a href="{{ $shopRoute('gift-cards') }}" wire:navigate class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400">
                                            {{ __('products.clear_all_filters') }}
                                        </a>
                                    @endif
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>

        </div>
    </section>

    {{-- FAQ. The storefront only: this is the page a first-time visitor lands on
         from search, and the questions it answers (region locking, how fast a
         code arrives, where to find it afterwards) are exactly what stops them
         buying. A signed-in customer in the dashboard has already crossed that
         bridge, and the page there is a shopping surface, not a landing page.

         Questions come from the faqs table through <x-home.faq>, the same
         component and the same admin editor as the homepage, scoped to the two
         topics that answer a gift card shopper. Nothing is written here, so the
         copy can never drift from /faq. --}}
    @if (! $inDash)
        {{-- This page's FAQ needs a visible strip of page ground before the
             footer. Other FAQ callers keep their own existing boundary. --}}
        <div class="bg-[#eff6ff] pb-3 sm:pb-4 lg:pb-5">
            {{-- top-margin="" and the row above dropping its mb-16: together
                 those two were a measured 160px of empty page between the last
                 "See more" and the first question. --}}
            <x-home.faq
                :topics="['Gift Cards']"
                :limit="12"
                heading-id="gift-cards-faq-heading"
                :grow-from="1100"
                top-margin=""
            />
        </div>
    @endif

</x-shop.layout>
