@php
    use App\Models\Category;
    use App\Models\Product;
    use Illuminate\Support\Facades\DB;

    $giftCardsCategory = Category::where('slug', 'gift-cards')->first();

    // Region lock — the homepage only showcases brands sold in the customer's
    // resolved region (ResolveRegion middleware shares $region).
    $region = strtoupper((string) ($region ?? 'US'));

    /*
     * Returns a collection of representative Products — one per brand_key — for a
     * home-page row. `$flag` optionally scopes to is_popular / is_featured.
     */
    $brandPicks = function (?string $flag = null, int $limit = 6) use ($giftCardsCategory, $region) {
        $base = Product::query()
            ->where('is_active', true)
            ->whereNotNull('brand_key')
            ->where('country_code', $region)
            ->when($giftCardsCategory, fn ($q) => $q->where('category_id', $giftCardsCategory->id));
        if ($flag) {
            // Curation is brand-level. A product row carries is_featured /
            // is_popular on a single country, but the badge should follow the
            // brand: flagging Amazon's GB row must still feature Amazon on the
            // US homepage. So we gather every flagged brand_key (any country)
            // and keep the region's own row above for correct price + link.
            $flaggedKeys = Product::query()
                ->where($flag, true)
                ->whereNotNull('brand_key')
                ->when($giftCardsCategory, fn ($q) => $q->where('category_id', $giftCardsCategory->id))
                ->distinct()
                ->pluck('brand_key');
            $base->whereIn('brand_key', $flaggedKeys);
        }
        $ids = (clone $base)
            ->select('brand_key', DB::raw("COALESCE(MIN(CASE WHEN country_code = 'US' THEN id END), MIN(id)) as id"))
            ->groupBy('brand_key')
            ->limit($limit)
            ->pluck('id');

        return Product::whereIn('id', $ids)
            ->with('variants:id,product_id,retail_price,is_variable,is_available')
            ->orderBy('name')
            ->get();
    };

    // Same as brandPicks but scoped to a specific subcategory slug. Used by
    // the per-category rows (Gaming, Digital Apps, Clothing, Food, etc.).
    $brandPicksBySubcategory = function (string $subcategorySlug, int $limit = 5) use ($giftCardsCategory, $region) {
        $base = Product::query()
            ->where('is_active', true)
            ->whereNotNull('brand_key')
            ->where('country_code', $region)
            ->when($giftCardsCategory, fn ($q) => $q->where('category_id', $giftCardsCategory->id))
            ->whereHas('subcategory', fn ($q) => $q->where('slug', $subcategorySlug));

        $ids = (clone $base)
            ->select('brand_key', DB::raw("COALESCE(MIN(CASE WHEN country_code = 'US' THEN id END), MIN(id)) as id"))
            ->groupBy('brand_key')
            ->limit($limit)
            ->pluck('id');

        return Product::whereIn('id', $ids)
            ->with('variants:id,product_id,retail_price,is_variable,is_available')
            ->orderBy('name')
            ->get();
    };

    // "Popular" is the curated, ordered list in config/popular_brands.php, resolved
    // per region: every brand shows its USA row to everyone, and a brand that also has
    // a row in the visitor's own country (e.g. the Cameroon top-ups in CM) uses that
    // local row and floats to the top. See Product::popularForRegion.
    $popularBrands = Product::popularForRegion($region);

    // Featured row renders at the very top of the storefront, and ONLY from
    // real admin flags - no fallback filler, so an empty flag set simply
    // hides the row instead of duplicating the browse list.
    $featuredBrands = $brandPicks('is_featured', 10);
    $browseBrands   = $brandPicks(null, 5);

    // Last-resort fallback so the Popular row never renders empty.
    if ($popularBrands->isEmpty()) { $popularBrands = $browseBrands; }

    /*
     * Mobile top-ups. One card per operator BRAND in the mobile-airtime
     * category, region-locked like the gift-card picks above and using the
     * same representative-row trick, so a brand selling in several countries
     * still shows once.
     */
    $topupCategory = Category::where('slug', 'mobile-airtime')->first();

    $topupIds = $topupCategory
        ? Product::query()
            ->where('is_active', true)
            ->whereNotNull('brand_key')
            ->where('country_code', $region)
            ->where('category_id', $topupCategory->id)
            ->select('brand_key', DB::raw("COALESCE(MIN(CASE WHEN country_code = 'US' THEN id END), MIN(id)) as id"))
            ->groupBy('brand_key')
            ->limit(20)
            ->pluck('id')
        : collect();

    // Variants carry the SAME column set + subtype_hint alias the /topups
    // listing loads, because priceRangeLabel(fixedFacesFirst: true) reads all
    // of them. Without it this row would quote the raw variable bounds while
    // the listing quotes the credit denominations, and the two pages would
    // contradict each other on the same brand.
    $topupBrands = $topupIds->isEmpty() ? collect() : Product::whereIn('id', $topupIds)
        ->with(['variants' => fn ($q) => $q
            ->select('id', 'product_id', 'currency', 'retail_price', 'cost_price', 'face_value', 'min_amount', 'max_amount', 'is_variable', 'is_available')
            ->selectRaw("json_extract(metadata, '$.subTypes[0]') as subtype_hint")])
        ->orderBy('name')
        ->get();
@endphp

<x-layouts.app.header>

{{-- Hero is full-width so the dots backdrop spans the entire page. The
     slider is CMS-driven (admin > Content > Hero Sections); with only the
     built-in section enabled it renders exactly the designed static hero.

     The hero is pinned (sticky) while the sections below scroll up and pass
     over it. The outer relative wrapper bounds the pin, so the hero unpins
     and scrolls away with the page before the footer arrives. --}}
{{-- Home follows the storefront light-mode tone pair: the page is #f4f6f5 and
     its cards are #ffffff (see LIGHT THEME SURFACES in app.css) - surface and
     fill, with no third value for a border. The #dee3e1 ring they used to
     wear is gone: the bloom bleeding in from a card's edge is what tells it
     apart from the page now, and a hairline drawn over that bloom read as a
     second outline competing with it. The bloom stays blue on purpose, which is
     the treatment the hero chips use too.

     Both are authored as literal hex, not bg-white, which is this codebase's
     idiom for opting OUT of the dark-mode flip - dark is pinned explicitly by
     the dark: variant beside it. The wrapper needs the fill too, not just the
     inner section: the hero sits above that and would otherwise show <main>'s
     own tone as a band across the top.

     Both are <section>, never <div>. app.css grants the dark PAGE tone only to
     the elements it names (body, main, section, footer, the min-h wrappers);
     on a div the same dark:bg-[#0c1a36] falls through to the CARD tone and the
     hero band comes back a shade lighter than everything under it. --}}
<section class="relative bg-[#f4f6f5] dark:bg-[#0c1a36]">
    {{-- The hero scrolls with the page (the old sticky pin + nav-height
         tracking was removed). --}}
    <x-home.hero-slider />

    {{-- Content below the hero: opaque page background, stacked above the
         hero so the lifted category cards can overlap its bottom edge. --}}
    <section class="relative z-10 bg-[#f4f6f5] dark:bg-[#0c1a36]">

<div class="mx-auto w-full max-w-[1550px] px-4 py-6 sm:px-6 sm:py-8 lg:px-8 lg:py-10">

    {{-- Category cards - one tile per catalogue area, first thing under the hero --}}
    <x-home.category-cards />

    {{-- The panel that makes the page's claim and then backs it. The payment
         pyramid and the four trust tiles both used to live in here; what it
         carries now is the heading, the live counts, the gift-card category row
         and the assurance strip.

         Fill AND bloom are the category card's, verbatim: #ffffff on the light
         page, the shared --ash tone in dark, and the same wash bleeding in from
         the edge rather than a flat fill. Neither carries a ring any more - the
         bloom is the edge. The panel used to be a navy island in both themes -
         see .trust-panel in app.css for why that also took a set of scoped ink
         remaps with it.

         `isolate` is load-bearing: .cat-card-glow sits at z-index -1, so
         without a stacking context here it escapes behind the section's own
         background and vanishes.

         No tex-grain either. That layer paints dark noise at 0.5 opacity, which
         is built for a near-black ground and reads as grey static on a white
         card. The cards it now matches carry no texture. --}}
    <div class="trust-panel isolate mt-8 overflow-hidden rounded-[20px] bg-[#ffffff] px-5 py-10 sm:px-10 sm:py-14 dark:bg-[var(--ash)]">

        {{-- The bloom rising from the panel's edge - see .cat-card-glow in
             app.css. Same layer the four cards above use. --}}
        <span aria-hidden="true" class="cat-card-glow"></span>

        {{-- Both lines come from lang files. They were hardcoded English, which
             on a site that ships English and French meant the largest heading
             on the home page stayed English for half its audience. --}}
        <h2 class="mx-auto max-w-[900px] text-center text-[30px] leading-[1.1] tracking-tight text-zinc-900 sm:text-[40px] lg:text-[52px] dark:text-white">
            {{ __('home.panel_title') }}
        </h2>
        {{-- Live counts. Read from the database and rounded DOWN, with a floor
             below which each figure simply does not render - a true number that
             is too small to impress is worse than none. --}}
        <x-home.customer-counts />

        {{-- Blue rule under the counts. The panel makes two separate moves -
             here is what we are, then here is what you get and how you pay -
             and without a break between them the promises read as a fourth
             statistic. Hairline rather than a band: it has the full width of
             the panel to carry, and at any more than a pixel a saturated blue
             across that span competes with the figures above it. --}}
        <hr class="mt-10 h-px border-0 bg-blue-600 dark:bg-blue-400" aria-hidden="true">

        {{-- The gift-card category row used to sit here, between the counts and
             the strip. It was removed deliberately: this box was given over to
             the live counts, and the categories were what it replaced. Do not
             put it back.

             It reads like an accident in the diff, which is the reason for this
             note. The row went out in the same edit as the trust strip, and the
             component is still in the repo and still maintained, so anyone
             reading only the code sees a live component that nothing mounts and
             concludes something got dropped by mistake. It did not. The panel
             is the counts and the strip.

             The four category TILES above the panel are a different thing and
             are untouched. --}}

        {{-- Foot of the panel: what you get, then how you pay. Last on purpose -
             the heading makes the claim, the live counts back it, and this
             closes with the reassurance and the rails behind it. --}}
        <x-home.assurance-strip />

    </div>

    {{-- Brand rows - all wired to the synced catalog (one card per brand_key).
         Each subcategory row's `href` deep-links to the filtered gift-cards
         page so "View all" goes straight to that category's full brand list. --}}
    @php
        // Subcategory rows are titled by the subcategory's own name, so they
        // pick up its translation. One query for the four rows; the literal is
        // the fallback for a database that lacks the row.
        $subcategoryNames = \App\Models\Subcategory::query()
            ->whereIn('slug', ['gaming-entertainment', 'digital-apps', 'clothing-accessories', 'food-beverage'])
            ->withTranslations()
            ->get()
            ->mapWithKeys(fn ($subcategory) => [$subcategory->slug => $subcategory->name]);
        $subcategoryTitle = fn (string $slug, string $fallback): string => $subcategoryNames[$slug] ?? $fallback;

        // 5 columns on desktop (lg) for larger, less cramped cards. Each row's
        // brand list is capped at 5 above so the desktop row stays clean.
        $renderRow = [
            ['title' => __('home.row_featured_gift_cards'),        'cols' => 5, 'carousel' => true, 'brands' => $featuredBrands, 'href' => route('shop.gift-cards')],
            ['title' => __('home.row_popular_gift_cards'),         'cols' => 5, 'carousel' => true, 'brands' => $popularBrands],
            ['title' => $subcategoryTitle('gaming-entertainment', 'Gaming & Entertainment'), 'cols' => 5, 'carousel' => true, 'brands' => $brandPicksBySubcategory('gaming-entertainment', 20), 'href' => route('shop.gift-cards', ['subcategory' => 'gaming-entertainment'])],
            ['title' => $subcategoryTitle('digital-apps', 'Digital Apps'), 'cols' => 5, 'carousel' => true, 'brands' => $brandPicksBySubcategory('digital-apps', 20),         'href' => route('shop.gift-cards', ['subcategory' => 'digital-apps'])],
            ['title' => $subcategoryTitle('clothing-accessories', 'Clothing & Accessories'), 'cols' => 5, 'carousel' => true, 'brands' => $brandPicksBySubcategory('clothing-accessories', 20), 'href' => route('shop.gift-cards', ['subcategory' => 'clothing-accessories'])],
            ['title' => $subcategoryTitle('food-beverage', 'Food & Beverage'), 'cols' => 5, 'carousel' => true, 'brands' => $brandPicksBySubcategory('food-beverage', 20),        'href' => route('shop.gift-cards', ['subcategory' => 'food-beverage'])],
        ];
    @endphp

    @php
        // One rollup for every row on the page rather than a query per card -
        // see Review::brandRatings(). Keys are collected across all rows first
        // because the same brand can appear in more than one of them.
        $brandRatings = \App\Models\Review::brandRatings(
            collect($renderRow)->pluck('brands')->flatten()->pluck('brand_key')
                ->concat($topupBrands->pluck('brand_key'))
                ->all()
        );
    @endphp

    @foreach ($renderRow as $row)
        @if ($row['brands']->isNotEmpty())
            <div class="mt-12">
                <x-home.brand-row
                    :title="$row['title']"
                    {{-- Category / subcategory rows carry the home page's large
                         section heading, the same 30px the eSIM browse page
                         uses. The component's smaller default stands everywhere
                         else it is reused (dashboard, eSIM pages, reviews). --}}
                    title-size="text-lg sm:text-[30px] sm:tracking-tight"
                    :view-all-href="$row['href'] ?? route('shop.gift-cards')"
                    :cols="$row['cols']"
                    :carousel="$row['carousel'] ?? false"
                    {{-- Two deep on a desktop, three on a phone: a screenful
                         shows several times the brands without several times
                         the scroll, and the depth follows the card size at each
                         breakpoint. A row can opt back to a single line with
                         'rows' => 1 in the list above. --}}
                    :rows="$row['rows'] ?? 2"
                    :mobile-rows="$row['mobileRows'] ?? 3"
                >
                    @foreach ($row['brands'] as $p)
                        @php
                            $logo       = Product::brandLogoUrl($p->brand_key, $p->logo_url);
                            $label      = Product::brandDisplayName($p->brand_key);
                            $brandColor = Product::brandColor($p->brand_key, $p->brand_color);
                        @endphp
                        <x-home.brand-card
                            :name="$label"
                            :price-range="$p->priceRangeLabel()"
                            :rating="$brandRatings[$p->brand_key] ?? null"
                            :href="route('shop.brand', ['brandSlug' => Product::brandSlug($p->brand_key)])"
                            :card-class="$logo ? 'bg-[#ffffff]' : ($brandColor ? '' : 'bg-zinc-100')"
                            :style="! $logo && $brandColor ? 'background-color: ' . $brandColor . ';' : false"
                        >
                            @if ($logo)
                                <img src="{{ $logo }}" alt="{{ __('home.alt_gift_card', ['name' => $label]) }}" class="h-full w-full object-cover" loading="lazy">
                            @else
                                <span class="px-3 text-center text-xl font-black uppercase leading-tight tracking-tight sm:text-2xl {{ $brandColor ? 'text-white' : 'text-zinc-700' }}">{{ $label }}</span>
                            @endif
                        </x-home.brand-card>
                    @endforeach
                </x-home.brand-row>
            </div>
        @endif
    @endforeach

    {{-- eSIMs, flights and stays --}}
    <div class="mt-12">
        <x-home.explore-row />
    </div>

    {{-- Mobile top-ups. Same carousel row as the gift-card rows above - the
         region carries 15 operators, more than a fixed grid would hold, and
         they are brand tiles like any other, so there is no reason for this
         one category to invent its own card. --}}
    @if ($topupBrands->isNotEmpty())
        <div class="mt-12">
            <x-home.brand-row
                :title="__('home.row_instant_topups')"
                title-size="text-lg sm:text-[30px] sm:tracking-tight"
                :view-all-href="route('shop.topups')"
                :cols="5"
                :carousel="true"
                :rows="2"
                :mobile-rows="3"
            >
                @foreach ($topupBrands as $p)
                    @php
                        $logo      = Product::brandLogoUrl($p->brand_key, $p->logo_url);
                        $label     = Product::brandDisplayName($p->brand_key);
                        // Operators without artwork fall back to a solid tile,
                        // the same deterministic palette colour the /topups
                        // listing gives them, so a brand keeps one colour
                        // across both pages.
                        $tileColor = Product::tileColor($p->brand_key);
                    @endphp
                    <x-home.brand-card
                        :name="$label"
                        :price-range="$p->priceRangeLabel(fixedFacesFirst: true)"
                        :rating="$brandRatings[$p->brand_key] ?? null"
                        :href="route('shop.topup', ['brandSlug' => Product::brandSlug($p->brand_key), 'country' => $p->country_code])"
                        :card-class="$logo ? 'bg-[#ffffff]' : ''"
                        :style="! $logo ? 'background-color: '.$tileColor.';' : false"
                    >
                        @if ($logo)
                            <img src="{{ $logo }}" alt="{{ __('home.alt_top_up', ['name' => $label]) }}" class="h-full w-full object-cover" loading="lazy">
                        @else
                            <span class="px-3 text-center text-xl font-black uppercase leading-tight tracking-tight text-white sm:text-2xl">{{ $label }}</span>
                        @endif
                    </x-home.brand-card>
                @endforeach
            </x-home.brand-row>
        </div>
    @endif

    {{-- How it works --}}
    <div class="mt-12">
        <x-home.how-it-works />
    </div>

    {{-- Services promo with ambient video bg + referral CTA --}}
    <div class="mt-12">
        <x-home.services-promo />
    </div>

</div>

{{-- Customer reviews — full-bleed so the scroll row spans the entire page.
     pb (not mb): a bottom margin here collapses through the opaque z-10
     wrapper and exposes a strip of the pinned hero right before the footer;
     padding keeps that breathing room inside the wrapper's background so the
     page runs flush into the footer. --}}
<div class="mt-12 pb-10">
    <x-home.customer-reviews />

    {{-- FAQ, last before the footer. Questions come from the admin FAQ editor,
         so this and the /faq page can never drift apart. --}}
    {{-- Same opening motion as every other FAQ on the site. 1100 is the
         start width, not a measurement of the section above: here the content
         above is already the full 1550, so the panel opens from narrower than
         it and settles flush with it. --}}
    <x-home.faq :grow-from="1100" />

</div>

    </section>
</section>

</x-layouts.app.header>
