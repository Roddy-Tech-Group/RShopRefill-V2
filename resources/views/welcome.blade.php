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
@endphp

<x-layouts.app.header>

{{-- Hero is full-width so the dots backdrop spans the entire page. The
     slider is CMS-driven (admin > Content > Hero Sections); with only the
     built-in section enabled it renders exactly the designed static hero.

     The hero is pinned (sticky) while the sections below scroll up and pass
     over it. The outer relative wrapper bounds the pin, so the hero unpins
     and scrolls away with the page before the footer arrives. --}}
{{-- Home runs its own light-mode tone pair. Everywhere else the page is
     #f4f6f5 with #ffffff cards lifted off it (see LIGHT THEME SURFACES in
     app.css); here the page is #ffffff and the cards sit on #f4f6f5, ringed in
     #dee3e1 - surface, fill and border are three distinct values. The bloom
     inside stays blue on purpose: a neutral edge with a blue wash off it, which
     is the treatment the hero chips use too.

     Both are authored as literal hex, not bg-white, which is this codebase's
     idiom for opting OUT of the dark-mode flip - dark is pinned explicitly by
     the dark: variant beside it. The wrapper needs the fill too, not just the
     inner section: the hero sits above that and would otherwise show <main>'s
     own tone as a band across the top.

     Both are <section>, never <div>. app.css grants the dark PAGE tone only to
     the elements it names (body, main, section, footer, the min-h wrappers);
     on a div the same dark:bg-[#0c1a36] falls through to the CARD tone and the
     hero band comes back a shade lighter than everything under it. --}}
<section class="relative bg-[#ffffff] dark:bg-[#0c1a36]">
    {{-- The hero scrolls with the page (the old sticky pin + nav-height
         tracking was removed). --}}
    <x-home.hero-slider />

    {{-- Content below the hero: opaque page background, stacked above the
         hero so the lifted category cards can overlap its bottom edge. --}}
    <section class="relative z-10 bg-[#ffffff] dark:bg-[#0c1a36]">

<div class="mx-auto w-full max-w-[1350px] px-4 py-6 sm:px-6 sm:py-8 lg:px-8 lg:py-10">

    {{-- Category cards - one tile per catalogue area, first thing under the hero --}}
    <x-home.category-cards />

    {{-- The payment pyramid, in its own panel. The four trust tiles used to
         share this block and have been removed. Stats go below this.

         Fill, ring AND bloom are the category card's, verbatim: white on the
         light page, black in dark, the same 1px edge, and the same wash
         bleeding in from that edge rather than a flat fill. The panel used to
         be a navy island in both themes - see .trust-panel in app.css for why
         that also took a set of scoped ink remaps with it.

         `isolate` is load-bearing: .cat-card-glow sits at z-index -1, so
         without a stacking context here it escapes behind the section's own
         background and vanishes.

         No tex-grain either. That layer paints dark noise at 0.5 opacity, which
         is built for a near-black ground and reads as grey static on a white
         card. The cards it now matches carry no texture. --}}
    <div class="trust-panel isolate mt-8 overflow-hidden rounded-[20px] bg-[#f4f6f5] px-5 py-10 ring-1 ring-[#dee3e1] sm:px-10 sm:py-14 dark:bg-transparent dark:ring-zinc-700/60">

        {{-- The bloom rising from the panel's edge - see .cat-card-glow in
             app.css. Same layer the four cards above use. --}}
        <span aria-hidden="true" class="cat-card-glow"></span>

        <h2 class="mx-auto max-w-[900px] text-center text-[30px] font-extrabold leading-[1.1] tracking-tight text-zinc-900 sm:text-[40px] lg:text-[52px] dark:text-white">
            Instant Delivery, Zero Hassle.
        </h2>
        <p class="mx-auto mt-4 max-w-2xl text-center text-base leading-relaxed text-zinc-600 sm:text-lg dark:text-white/70">
            Clean, broad, and works across gift cards, eSIMs, mobile top-ups, and bill payments.
        </p>

        {{-- Trust signals. A compact row rather than the four bordered cards
             this used to be: the panel is already a surface, and boxes inside
             it read as boxes-in-a-box.

             The payment pyramid used to sit above this, between the heading and
             the rule. It was removed; x-home.payment-methods is now unreferenced
             but left in place rather than deleted. --}}
        <div class="mt-10 border-t border-zinc-200 pt-10 dark:border-white/12">
            <x-home.trust-strip />
        </div>

        {{-- Gift-card categories. Inside this panel, straight after the four
             tiles above it - the tiles say what we sell, these say what kind of
             gift card, which is the question most visitors arrive with. --}}
        <x-home.gift-card-categories />

    </div>

    {{-- Brand rows - all wired to the synced catalog (one card per brand_key).
         Each subcategory row's `href` deep-links to the filtered gift-cards
         page so "View all" goes straight to that category's full brand list. --}}
    @php
        // 5 columns on desktop (lg) for larger, less cramped cards. Each row's
        // brand list is capped at 5 above so the desktop row stays clean.
        $renderRow = [
            ['title' => 'Featured Gift Cards',     'subtitle' => 'Hand-picked highlights from our catalog',        'cols' => 5, 'carousel' => true, 'brands' => $featuredBrands, 'href' => route('shop.gift-cards')],
            ['title' => 'Popular Gift Cards',      'subtitle' => 'Top-rated gift cards across categories',         'cols' => 5, 'carousel' => true, 'brands' => $popularBrands],
            ['title' => 'Gaming & Entertainment',  'subtitle' => 'Game credit, console codes and streaming passes', 'cols' => 5, 'carousel' => true, 'brands' => $brandPicksBySubcategory('gaming-entertainment', 20), 'href' => route('shop.gift-cards', ['subcategory' => 'gaming-entertainment'])],
            ['title' => 'Digital Apps',            'subtitle' => 'App store credit and subscription gift cards',    'cols' => 5, 'carousel' => true, 'brands' => $brandPicksBySubcategory('digital-apps', 20),         'href' => route('shop.gift-cards', ['subcategory' => 'digital-apps'])],
            ['title' => 'Clothing & Accessories',  'subtitle' => 'Fashion and lifestyle gift cards',                'cols' => 5, 'carousel' => true, 'brands' => $brandPicksBySubcategory('clothing-accessories', 20), 'href' => route('shop.gift-cards', ['subcategory' => 'clothing-accessories'])],
            ['title' => 'Food & Beverage',         'subtitle' => 'Cafes, restaurants and grocery gift cards',       'cols' => 5, 'carousel' => true, 'brands' => $brandPicksBySubcategory('food-beverage', 20),        'href' => route('shop.gift-cards', ['subcategory' => 'food-beverage'])],
        ];
    @endphp

    @php
        // One rollup for every row on the page rather than a query per card -
        // see Review::brandRatings(). Keys are collected across all rows first
        // because the same brand can appear in more than one of them.
        $brandRatings = \App\Models\Review::brandRatings(
            collect($renderRow)->pluck('brands')->flatten()->pluck('brand_key')->all()
        );
    @endphp

    @foreach ($renderRow as $row)
        @if ($row['brands']->isNotEmpty())
            <div class="mt-12">
                <x-home.brand-row
                    :title="$row['title']"
                    :subtitle="$row['subtitle']"
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
                                <img src="{{ $logo }}" alt="{{ $label }} gift card" class="h-full w-full object-cover" loading="lazy">
                            @else
                                <span class="px-3 text-center text-xl font-black uppercase leading-tight tracking-tight sm:text-2xl {{ $brandColor ? 'text-white' : 'text-zinc-700' }}">{{ $label }}</span>
                            @endif
                        </x-home.brand-card>
                    @endforeach
                </x-home.brand-row>
            </div>
        @endif
    @endforeach

    {{-- How it works --}}
    <div class="mt-12">
        <x-home.how-it-works />
    </div>

    {{-- eSIMs, flights and stays --}}
    <div class="mt-12">
        <x-home.explore-row />
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
</div>

    </section>
</section>

</x-layouts.app.header>
