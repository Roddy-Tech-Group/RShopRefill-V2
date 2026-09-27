<?php

namespace App\Support;

use App\Domain\Cart\Services\CartPricingService;
use App\Http\Controllers\EsimStoreController;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The products on the For Partners "Refer a product" cards, each with the
 * public page a customer can share.
 *
 * The front of the row is pinned from config/dashboard.php: the listed eSIM
 * regions and gift card brands, taking turns (Global eSIM, Apple, Africa eSIM,
 * Amazon, and so on). Whatever is left over is filled by margin, the two
 * categories again taking turns (see PAGES).
 *
 * HighMarginBrands answers a narrower question, which BRANDS to put on the
 * referral hero's logo tiles; this one returns whole products.
 *
 * Margin is not a stored column: checkout prices a variant through
 * CartPricingService (retail minus supplier cost), and the catalogue holds one
 * product per denomination, so pricing everything on a page load would be slow.
 * Same two-step shape as HighMarginBrands, cached for six hours:
 *
 *   1. SQL shortlist: available variants of active products, ordered by the gap
 *      between face value and supplier cost, the main driver of margin before
 *      the markup rules apply. The ratio is currency-agnostic (both columns are
 *      in the variant's own currency), so no currency filter is needed here.
 *   2. Exact pass: one variant per product is priced with the real pricing
 *      service and the products are ranked by that margin. The categories then
 *      take turns, so one cannot fill the row on its own.
 *
 * Margin decides WHICH products are featured and is never shown: a referral
 * pays a share of the order, not of the margin. What each card does carry is
 * maxUsd, the largest denomination someone could be sent to, which the view
 * turns into that product's best possible referral reward.
 */
final class TopMarginProducts
{
    /** How many variant rows the SQL shortlist hands to the exact pass. */
    private const SHORTLIST = 240;

    /** How many of those get priced exactly, the expensive step. */
    private const PRICE_LIMIT = 48;

    /** How recently a product must have been added to count as new. */
    private const NEW_DAYS = 30;

    /**
     * Every category we can build a public product page for, and the route that
     * does it. A brand can be pinned from any of these.
     */
    private const PAGES = [
        'gift-cards' => 'shop.brand',
        'esims' => 'shop.esim',
        'mobile-airtime' => 'shop.topup',
        'bill-payments' => 'shop.bill',
    ];

    /**
     * The categories allowed to FILL the leftover slots by margin. Narrower than
     * PAGES on purpose.
     *
     * Top-ups and bills belong on this row only when someone pinned them in
     * config, so the fill cannot surface a random utility brand. eSIMs are out
     * for a different reason: only the multi-country destinations have a
     * branded storefront card, so a filler eSIM (an island, a single country)
     * would land here as a plain logo tile beside eight branded ones. Every
     * eSIM on this row therefore comes from the pinned region list, which is
     * exactly the set EsimCardPresenter brands.
     */
    private const FILL_CATEGORIES = ['gift-cards'];

    /**
     * @return list<array{id: int, brandKey: ?string, countryCode: string, name: string, category: string, categoryLabel: string, logo: ?string, color: ?string, description: ?string, url: string, routeName: string, routeParams: array<string, string>, margin: ?float, maxUsd: float, popular: bool, isNew: bool}>
     */
    public static function top(int $count = 9): array
    {
        // The key carries a fingerprint of the three config lists that decide
        // what this row says, so editing a blurb or reordering the brands takes
        // effect on the next request instead of waiting out the six hours or
        // needing a cache:clear. Catalogue changes still wait for the TTL.
        $fingerprint = substr(md5(serialize([
            config('dashboard.refer_product_brands'),
            config('dashboard.refer_product_esim_regions'),
            config('dashboard.refer_product_blurbs'),
        ])), 0, 8);

        return Cache::remember(
            'refer-products:v10:'.$count.':'.$fingerprint,
            now()->addHours(6),
            fn () => self::compute($count)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function compute(int $count): array
    {
        $featured = self::featured();
        $cards = $featured;
        $seen = array_column($featured, 'url');

        foreach (self::byMargin() as $card) {
            if (count($cards) >= $count) {
                break;
            }
            if (in_array($card['url'], $seen, true)) {
                continue;
            }
            $cards[] = $card;
            $seen[] = $card['url'];
        }

        return self::decorate(array_slice($cards, 0, $count));
    }

    /**
     * The pinned picks: the configured eSIM regions and gift card brands, taking
     * turns so the row opens with one of each rather than eight of one. Anything
     * the catalogue cannot serve right now is skipped, so the row never carries a
     * card that leads nowhere.
     *
     * @return list<array<string, mixed>>
     */
    private static function featured(): array
    {
        $esims = self::pinnedEsims();
        $brands = self::pinnedBrands();
        $cards = [];

        for ($i = 0; $i < max(count($esims), count($brands)); $i++) {
            if (isset($esims[$i])) {
                $cards[] = $esims[$i];
            }
            if (isset($brands[$i])) {
                $cards[] = $brands[$i];
            }
        }

        return $cards;
    }

    /**
     * One card per configured brand, in the configured order. Any category with
     * a public page qualifies, so a top-up brand can be pinned beside the gift
     * cards; the brand page it links to serves every country that brand covers,
     * so one card is enough for all of them.
     *
     * A brand with no artwork still gets a card: the tile falls back to its name
     * on its own colour, the same as the storefront's.
     *
     * @return list<array<string, mixed>>
     */
    private static function pinnedBrands(): array
    {
        $cards = [];
        $region = strtoupper((string) (request()->attributes->get('region') ?: 'US'));

        foreach ((array) config('dashboard.refer_product_brands', []) as $brandKey) {
            $product = Product::query()
                ->with('category:id,name,slug')
                ->where('brand_key', $brandKey)
                ->where('is_active', true)
                ->whereHas('category', fn ($q) => $q->whereIn('slug', array_keys(self::PAGES)))
                // The visitor's own country first, then the US, then whatever exists.
                ->orderByRaw('country_code = ? DESC', [$region])
                ->orderByRaw("country_code = 'US' DESC")
                ->first();

            if (! $product) {
                continue;
            }

            $card = self::card($product, (string) $product->category->slug, null);
            if ($card !== null) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /**
     * One card per configured eSIM region, in the configured order.
     *
     * The regions come from EsimCardPresenter, the same source the home row and
     * /esims render, so the product a card points at is exactly the one the
     * storefront would show for that region, and the branded card on this page
     * can be matched back to it by URL. An exact name wins; otherwise the first
     * region containing the configured word does.
     *
     * @return list<array<string, mixed>>
     */
    private static function pinnedEsims(): array
    {
        $wanted = (array) config('dashboard.refer_product_esim_regions', []);
        if ($wanted === []) {
            return [];
        }

        $regions = EsimCardPresenter::regions();
        $cards = [];

        foreach ($wanted as $region) {
            $needle = mb_strtolower(trim((string) $region));
            $entry = $regions->first(fn (array $card) => mb_strtolower((string) $card['name']) === $needle)
                ?? $regions->first(fn (array $card) => str_contains(mb_strtolower((string) $card['name']), $needle));

            if (! $entry) {
                continue;
            }

            $product = Product::query()
                ->with('category:id,name,slug')
                ->where('slug', $entry['slug'])
                ->where('is_active', true)
                ->first();

            $card = $product ? self::card($product, 'esims', null) : null;
            if ($card === null) {
                continue;
            }

            // The storefront's own name for the region, not one re-derived here.
            $card['name'] = (string) $entry['name'];
            // Keyed by the CONFIGURED region, not the card's display name: the
            // storefront calls the global plan "Global eSIM+", which no sane
            // config key would match.
            $card['description'] = self::blurbFor((string) $region)
                ?? (string) ($entry['description'] ?? $card['description']);
            $cards[] = $card;
        }

        return $cards;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function byMargin(): array
    {
        $pricing = app(CartPricingService::class);

        $variants = ProductVariant::query()
            ->select('product_variants.*')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.is_active', true)
            ->where('product_variants.is_available', true)
            // USD rows only. On a local-currency row face_value is in that
            // currency while cost_price is in USD, so the ratio below would be
            // comparing two different monies and read as a ~99% margin.
            ->where('product_variants.currency', 'USD')
            ->where('product_variants.face_value', '>', 0)
            ->where('product_variants.cost_price', '>', 0)
            ->whereColumn('product_variants.cost_price', '<', 'product_variants.face_value')
            ->orderByRaw('(product_variants.face_value - product_variants.cost_price) / product_variants.face_value DESC')
            ->limit(self::SHORTLIST)
            ->with('product.category:id,name,slug')
            ->get();

        $byCategory = [];
        $seen = [];
        $priced = 0;

        foreach ($variants as $variant) {
            if ($priced >= self::PRICE_LIMIT) {
                break;
            }

            $product = $variant->product;
            $category = $product?->category?->slug;
            if (! $product || ! in_array($category, self::FILL_CATEGORIES, true)) {
                continue;
            }

            // One card per brand per category (the catalogue carries a product
            // per denomination, so a brand would otherwise take the whole row).
            $key = $category.'|'.($product->brand_key ?: $product->slug);
            if (isset($seen[$key])) {
                continue;
            }

            try {
                $price = $pricing->calculatePricing($variant, 1);
            } catch (\Throwable) {
                continue;
            }
            $priced++;

            $unit = (float) $price['unit_price_snapshot'];
            if ($unit <= 0) {
                continue;
            }

            $card = self::card($product, $category, round((float) $price['markup_amount'] / $unit, 4));
            if ($card === null) {
                continue;
            }

            $seen[$key] = true;
            $byCategory[$category][] = $card;
        }

        foreach ($byCategory as $slug => $rows) {
            usort($rows, fn (array $a, array $b) => $b['margin'] <=> $a['margin']);
            $byCategory[$slug] = $rows;
        }

        return self::interleave($byCategory);
    }

    /**
     * One card, or null when the product has no public page to send anyone to.
     *
     * @return array<string, mixed>|null
     */
    private static function card(Product $product, string $category, ?float $margin): ?array
    {
        $url = self::pageFor($category, $product);
        if ($url === null) {
            return null;
        }

        $isEsim = $category === 'esims';
        $meta = $isEsim
            ? EsimStoreController::regionMeta($product->country_code, (string) $product->slug, $product->name)
            : null;

        return [
            'id' => (int) $product->id,
            'brandKey' => $product->brand_key ? (string) $product->brand_key : null,
            'countryCode' => strtoupper((string) $product->country_code),
            'name' => $meta
                ? $meta['name'].' eSIM'
                : ($product->brand_key ? Product::brandDisplayName($product->brand_key) : (string) $product->name),
            'category' => $category,
            'categoryLabel' => (string) ($product->category->name ?? $category),
            'logo' => self::pathIfOurs($isEsim && blank($product->logo_url)
                ? EsimStoreController::globalFlag()
                : Product::brandLogoUrl($product->brand_key, $product->logo_url ?? null)),
            'color' => Product::brandColor($product->brand_key, $product->brand_color ?? null),
            'description' => self::blurbFor($product->brand_key) ?? (filled($product->description) ? (string) $product->description : null),
            'url' => $url,
            // The route this product lives at, so the caller can build either
            // side of it: the storefront one to SHARE (the person receiving a
            // referral link is not signed in) and the dashboard mirror to LINK
            // (a customer browsing the dashboard stays in the dashboard, which
            // matters for the PWA). The two route names are identical bar the
            // prefix, so one name serves both.
            'routeName' => self::PAGES[$category],
            'routeParams' => $isEsim
                ? ['slug' => (string) $product->slug]
                : ['brandSlug' => Product::brandSlug((string) $product->brand_key)],
            'margin' => $margin,
            // Filled in by decorate(), once the whole row is known.
            'maxUsd' => 0.0,
            'popular' => false,
            'isNew' => $product->created_at !== null && $product->created_at->gt(now()->subDays(self::NEW_DAYS)),
        ];
    }

    /**
     * What this product is FOR, in our words, from config/dashboard.php. Keyed
     * by brand_key for gift cards and by region name for eSIMs; null when we
     * have written nothing for it, which leaves the caller's own fallback in
     * place rather than an empty line.
     */
    private static function blurbFor(?string $key): ?string
    {
        if (blank($key)) {
            return null;
        }

        $blurbs = (array) config('dashboard.refer_product_blurbs', []);

        // Case-insensitive, so "middle east" in the region list still finds
        // "Middle East" here and a brand key's casing cannot silently miss.
        foreach ($blurbs as $candidate => $line) {
            if (strcasecmp((string) $candidate, $key) === 0) {
                return filled($line) ? (string) $line : null;
            }
        }

        return null;
    }

    /**
     * The public page for a product, or null when it cannot be linked. A gift
     * card has a brand page; an eSIM has its own page, by slug.
     */
    private static function pageFor(string $category, Product $product): ?string
    {
        $url = match (true) {
            $category === 'esims' => $product->slug ? route('shop.esim', ['slug' => $product->slug]) : null,
            (bool) $product->brand_key => route(self::PAGES[$category], ['brandSlug' => Product::brandSlug($product->brand_key)]),
            default => null,
        };

        return $url === null ? null : (string) self::pathIfOurs($url);
    }

    /**
     * An address this row can safely keep for hours: our own links and images
     * come back as a path, anything on another host stays as it is.
     *
     * The row is cached, and a cache can be warmed from the console, where
     * there is no request and the host falls back to APP_URL. Storing absolute
     * URLs there hands every visitor images and links pointing at whatever that
     * happened to be. A path has no host to be wrong about; the view turns it
     * back into a full URL against the request when it needs one.
     */
    private static function pathIfOurs(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $ours = [parse_url((string) config('app.url'), PHP_URL_HOST), request()->getHost()];

        if ($host !== null && ! in_array($host, array_filter($ours), true)) {
            return $url;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = parse_url($url, PHP_URL_QUERY);

        return $query ? $path.'?'.$query : $path;
    }

    /**
     * Adds what only the finished row can know: the largest USD denomination
     * behind each card, and whether the brand is on the storefront's popular
     * list. The catalogue ships one product per denomination, so a gift card's
     * ceiling is the highest face value across its whole BRAND, not this one
     * product; an eSIM's is its own. Variable-amount variants contribute their
     * max_amount. Two grouped queries, whatever the size of the row.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    private static function decorate(array $cards): array
    {
        $popular = (array) config('popular_brands.keys', []);
        $rates = self::usdRates();
        $brandKeys = array_values(array_filter(array_column($cards, 'brandKey')));
        $esimIds = array_values(array_map(
            fn (array $card) => $card['id'],
            array_filter($cards, fn (array $card) => $card['brandKey'] === null)
        ));

        // Grouped by currency as well as by product, because one brand can be
        // sold in several: the ceiling is the highest of them once each is in
        // USD, and a currency we hold no rate for contributes nothing at all.
        $highest = function ($rows) use ($rates): array {
            $out = [];
            foreach ($rows as $row) {
                $local = max((float) ($row->mr ?? 0), (float) ($row->mm ?? 0));
                // Some synced variants carry no currency of their own; the
                // product's declared one stands in. When neither says, nothing
                // does: a blank is not a USD, and the blanks in this catalogue
                // sit under BDT, TTD and MXN products as well as US ones.
                $currency = trim((string) $row->cur) ?: trim((string) ($row->pcur ?? ''));
                $usd = $currency === '' ? null : self::toUsd($local, $currency, $rates);
                if ($usd === null) {
                    continue;
                }
                // Keyed in PHP, not in SQL: string concatenation is || on one
                // engine and CONCAT() on another, and this runs on both.
                $key = isset($row->ck) ? $row->k.'|'.strtoupper((string) $row->ck) : $row->k;
                $out[$key] = max($out[$key] ?? 0.0, $usd);
            }

            return $out;
        };

        $byBrand = [];
        if ($brandKeys !== []) {
            $byBrand = $highest(self::ceilings()
                ->whereIn('products.brand_key', $brandKeys)
                ->groupBy('products.brand_key', 'products.country_code', 'product_variants.currency', 'products.currency_code')
                ->selectRaw('products.brand_key as k, products.country_code as ck, product_variants.currency as cur, products.currency_code as pcur, MAX(product_variants.retail_price) as mr, MAX(product_variants.max_amount) as mm')
                ->get());
        }

        $byProduct = [];
        if ($esimIds !== []) {
            $byProduct = $highest(self::ceilings()
                ->whereIn('products.id', $esimIds)
                ->groupBy('products.id', 'product_variants.currency', 'products.currency_code')
                ->selectRaw('products.id as k, product_variants.currency as cur, products.currency_code as pcur, MAX(product_variants.retail_price) as mr, MAX(product_variants.max_amount) as mm')
                ->get());
        }

        foreach ($cards as $i => $card) {
            $cards[$i]['maxUsd'] = $card['brandKey'] !== null
                ? (float) ($byBrand[$card['brandKey'].'|'.$card['countryCode']] ?? 0.0)
                : (float) ($byProduct[$card['id']] ?? 0.0);
            $cards[$i]['popular'] = $card['brandKey'] !== null && in_array($card['brandKey'], $popular, true);
        }

        return $cards;
    }

    /** The shared half of the two ceiling queries: every available variant. */
    private static function ceilings(): Builder
    {
        return ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.is_active', true)
            ->where('product_variants.is_available', true);
    }

    /**
     * A price in its own currency, expressed in USD, or null when we hold no
     * active rate for it.
     *
     * Every figure on these cards has to be USD before it can become a reward,
     * because that is what RewardEngine is given. The catalogue does NOT store
     * one currency per row: a top-up row carries face_value and retail_price in
     * the local currency (36,347 XAF of airtime) while cost_price on the SAME
     * row is USD. Reading those as one money is how a $64 top-up turns into a
     * $36,347 one, so this converts explicitly and returns null rather than
     * guessing when no rate exists, which leaves the card with no reward badge.
     *
     * @param  array<string, float>  $rates  base currency => rate to USD
     */
    private static function toUsd(float $amount, string $currency, array $rates): ?float
    {
        $currency = strtoupper($currency);

        if ($currency === 'USD') {
            return $amount;
        }

        $rate = $rates[$currency] ?? null;

        return $rate !== null && $rate > 0 ? round($amount * $rate, 4) : null;
    }

    /**
     * Every active rate INTO USD, keyed by the currency it converts from. One
     * query, read from the same table checkout settles against.
     *
     * @return array<string, float>
     */
    private static function usdRates(): array
    {
        return ExchangeRate::query()
            ->where('is_active', true)
            ->where('target_currency', 'USD')
            ->pluck('rate', 'base_currency')
            ->map(fn ($rate) => (float) $rate)
            ->all();
    }

    /**
     * The categories take turns, best margin first, so the row shows both rather
     * than whichever one happens to price highest.
     *
     * @param  array<string, list<array<string, mixed>>>  $byCategory
     * @return list<array<string, mixed>>
     */
    private static function interleave(array $byCategory): array
    {
        // Categories in catalogue order, not alphabetical or margin order.
        $order = array_values(array_filter(array_keys(self::PAGES), fn ($slug) => ! empty($byCategory[$slug])));
        $out = [];
        $round = 0;

        while ($order !== []) {
            $tookOne = false;
            foreach ($order as $slug) {
                if (! isset($byCategory[$slug][$round])) {
                    continue;
                }
                $out[] = $byCategory[$slug][$round];
                $tookOne = true;
            }
            if (! $tookOne) {
                break;
            }
            $round++;
        }

        return $out;
    }
}
