<?php

namespace App\Support;

use App\Http\Controllers\EsimStoreController;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Turns the eSIM catalog into branded destination-card data.
 *
 * One card shape covers every destination, because a destination is a product
 * variant and not a separate brand: the only things that change per card are
 * the accent, the background motif and the description line. Nothing here
 * invents a destination - every card is matched against a real, purchasable
 * entry in EsimStoreController::catalogSummary(), so a region or country that
 * is not on sale simply does not produce a card.
 */
class EsimCardPresenter
{
    /**
     * The eight multi-country cards. Keys are stable ids used by the view to
     * pick a background motif; `match` is tested against the catalog's own
     * display name.
     *
     * ORDER IS LOAD-BEARING. The first match wins, so 'middle east' has to sit
     * above 'africa' ("Middle East and North Africa") and above 'north america'
     * ("North America" vs "...North Africa").
     *
     * Every entry carries the SAME motif on purpose. The brief originally asked
     * for a different geographic pattern per region, and the card component
     * still ships all eight (meridians, lattice, waves, parallels, chevrons,
     * radials, archipelago, orbit) - but eight different backgrounds read as
     * eight different products rather than one family, so the orbit rings are
     * now the constant and the ACCENT is what varies. Change a single 'motif'
     * value here to give one region its own art back.
     */
    private const REGIONS = [
        'middle-east' => ['title' => 'Middle East', 'match' => 'middle east', 'accent' => '#0d9488', 'motif' => 'orbit'],
        'north-america' => ['title' => 'North America', 'match' => 'north america', 'accent' => '#fb7185', 'motif' => 'orbit'],
        'latin-america' => ['title' => 'Latin America', 'match' => 'latin america', 'accent' => '#f97316', 'motif' => 'orbit'],
        'caribbean' => ['title' => 'Caribbean', 'match' => 'caribbean', 'accent' => '#2dd4bf', 'motif' => 'orbit'],
        'europe' => ['title' => 'Europe', 'match' => 'europe', 'accent' => '#8b5cf6', 'motif' => 'orbit'],
        'africa' => ['title' => 'Africa', 'match' => 'africa', 'accent' => '#d9a441', 'motif' => 'orbit'],
        'asia' => ['title' => 'Asia', 'match' => 'asia', 'accent' => '#10b981', 'motif' => 'orbit'],
        'global' => ['title' => 'Global', 'match' => 'global', 'accent' => '#22d3ee', 'motif' => 'orbit'],
    ];

    /** Display order for the regional cards, which is not the matching order. */
    private const REGION_ORDER = [
        'global', 'africa', 'europe', 'asia',
        'north-america', 'latin-america', 'middle-east', 'caribbean',
    ];

    /**
     * The featured single-country cards, in the order they should render.
     * Matched on the catalog's canonical country name, which regionMeta()
     * has already resolved from config('countries.codes').
     */
    private const FEATURED_LOCAL = [
        'United States', 'United Kingdom', 'France', 'Nigeria', 'Cameroon',
        'Ghana', 'Canada', 'United Arab Emirates', 'South Africa', 'Turkey',
    ];

    /**
     * The only editorially chosen flags on the whole family.
     *
     * A region has no flag of its own, so the rest of a region card's flags
     * are derived from the plan's REAL coverage - see EsimCoverage::ranked(),
     * which orders covered countries by how many carrier networks the
     * supplier actually reports for each. These two are pinned in front of
     * that because they are product facts, not preferences: Global links to
     * the Discover Global USA plan, and Cameroon is the home market.
     *
     * A pin still has to survive the coverage test like any other flag, so
     * neither can ever advertise a country the plan does not reach.
     */
    private const REGION_LEAD = [
        'global' => 'US',
        'africa' => 'CM',
    ];

    /**
     * How many flags a region card shows. Regions covering fewer than this
     * simply show all of them - the slice is a ceiling, not a quota, so a
     * three-country region shows three rather than padding itself out.
     */
    private const REGION_FLAG_LIMIT = 6;

    /** Local cards share one accent: a destination is a variant, not a brand. */
    private const LOCAL_ACCENT = '#38bdf8';

    /**
     * Every branded card, regions first then featured countries.
     *
     * Cached for an hour alongside catalogSummary(), because the coverage
     * count has to read variant metadata - which is large JSON, and the whole
     * reason EsimCoverage stops at the first variant that carries any.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function family(): Collection
    {
        return Cache::remember('esim-branded-cards-v1', now()->addHour(), function () {
            $catalog = EsimStoreController::catalogSummary();

            $cards = collect();

            foreach (self::REGION_ORDER as $key) {
                $region = self::REGIONS[$key];
                $entry = $catalog->first(fn ($item) => $item['scope'] !== 'local'
                    && str_contains(strtolower($item['name']), $region['match'])
                    && self::regionKeyFor($item['name']) === $key);

                if ($entry) {
                    $cards->push(self::regionCard($key, $region, $entry));
                }
            }

            foreach (self::FEATURED_LOCAL as $country) {
                $entry = $catalog->first(fn ($item) => $item['scope'] === 'local'
                    && strcasecmp($item['name'], $country) === 0);

                if ($entry) {
                    $cards->push(self::localCard($country, $entry));
                }
            }

            return $cards->values();
        });
    }

    /** Just the regional / global cards. */
    public static function regions(): Collection
    {
        return self::family()->where('type', '!==', 'local')->values();
    }

    /**
     * Which region a catalog name belongs to, or null for none. Public so the
     * home row can ask the same question this class answers internally and the
     * two surfaces cannot disagree about what "Africa" means.
     */
    public static function regionKeyFor(string $name): ?string
    {
        $name = strtolower($name);

        foreach (self::REGIONS as $key => $region) {
            if (str_contains($name, $region['match'])) {
                return $key;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $entry */
    private static function regionCard(string $key, array $region, array $entry): array
    {
        $product = self::productFor($entry['slug']);
        $count = $product ? EsimCoverage::count($product->variants) : 0;

        // Global states its reach in words; a region states its country count,
        // and drops the line entirely when the supplier gave us no coverage
        // data rather than claiming "across 0 countries".
        $description = match (true) {
            $key === 'global' => 'USA Data + Real USA Number. Keeps you connected everywhere.',
            $count > 0 => 'Stay connected across '.$count.' countries.',
            default => 'Stay connected across the region.',
        };

        // Straight from coverage: the countries this plan serves with the most
        // carrier networks, with the pinned lead (if any, and if genuinely
        // covered) moved to the front.
        $ranked = $product ? EsimCoverage::ranked($product->variants) : [];
        $lead = self::REGION_LEAD[$key] ?? null;

        if ($lead !== null && in_array($lead, $ranked, true)) {
            $ranked = array_merge([$lead], array_values(array_diff($ranked, [$lead])));
        }

        // Global is the hero of the range and is sold on ONE fact - it carries
        // a real +1 United States number. A stack of six flags would bury that
        // in the same treatment every region gets, so it takes a single large
        // USA flag instead. Every other region stacks up to six.
        $isHero = $key === 'global';
        $flags = $isHero
            ? ['US']
            : array_values(array_slice($ranked, 0, self::REGION_FLAG_LIMIT));

        return self::card([
            'key' => $key,
            'flags' => $flags,
            'flagDisplay' => $isHero ? 'hero' : 'stack',
            // The hero card names the product, not just the region: "Global"
            // alone reads as a coverage area, and this one is a specific plan.
            // "Global eSIM+" - the plus marks it as the tier above the plain
            // regional plans, which is what the USA number buys you.
            'name' => $isHero ? $region['title'].' eSIM+' : $region['title'],
            'type' => $key === 'global' ? 'global' : 'regional',
            'countryCount' => $count > 0 ? $count : null,
            'description' => $description,
            'accentColor' => $region['accent'],
            'backgroundPattern' => $region['motif'],
        ], $entry, $product);
    }

    /** @param  array<string, mixed>  $entry */
    private static function localCard(string $country, array $entry): array
    {
        $product = self::productFor($entry['slug']);

        // "your trip to United States" is wrong; "to the United States" is not.
        // Plural and compound country names take the definite article, and the
        // catalog is full of them - every "United ...", plus the Netherlands,
        // the Philippines and the rest below.
        $needsArticle = (bool) preg_match(
            '/^(United |Netherlands|Philippines|Bahamas|Maldives|Gambia|Seychelles|Comoros|Czech Republic|Dominican Republic|Central African Republic|Democratic Republic)/',
            $country
        );
        $destination = ($needsArticle ? 'the ' : '').$country;

        $cc = strtoupper((string) ($product?->country_code ?? ''));

        return self::card([
            'key' => 'local-'.strtolower(str_replace(' ', '-', $country)),
            'flags' => strlen($cc) === 2 ? [$cc] : [],
            'flagDisplay' => 'stack',
            'name' => $country,
            'type' => 'local',
            'countryCount' => 1,
            'description' => 'Reliable mobile data for your trip to '.$destination.'.',
            'accentColor' => self::LOCAL_ACCENT,
            // Same orbit rings as every other card in the family.
            'backgroundPattern' => 'orbit',
        ], $entry, $product);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $entry
     */
    private static function card(array $base, array $entry, ?Product $product): array
    {
        $networkTypes = $product ? self::networkTypes($product) : [];

        return $base + [
            'flagUrls' => array_values(array_filter(array_map(
                fn ($cc) => Product::flagUrl($cc),
                (array) ($base['flags'] ?? [])
            ))),
            'slug' => $entry['slug'],
            'fromPrice' => $entry['from'],
            'destinationUrl' => route('shop.esim', $entry['slug']),
            'networkTypes' => $networkTypes,
            // No supplier we sync exposes a tethering / hotspot flag, and the
            // brief says to show only features the package confirms. So this
            // stays null (chip hidden) until there is a real field behind it,
            // rather than printing a promise nothing backs.
            'hotspotSupported' => null,
            'availability' => $entry['from'] > 0 ? 'available' : 'unavailable',
        ];
    }

    /**
     * Distinct network generations the product's carriers actually advertise.
     *
     * Read from coverage_networks[].networks[].speed, NOT networks_detail:
     * the latter only carries the first covered country's operators, so a
     * regional eSIM whose first country happens to be all-5G would advertise
     * "5G Available" while 3G and LTE carriers serve the other 41. Values are
     * "5G" / "LTE" / "3G"; LTE is how the suppliers spell what customers read
     * as 4G. networks_detail is kept as a fallback for products that carry it
     * but no per-country breakdown.
     *
     * @return array<int, string>
     */
    private static function networkTypes(Product $product): array
    {
        $speeds = $product->variants
            ->flatMap(function ($v) {
                $meta = (array) ($v->metadata ?? []);
                $fromCoverage = collect((array) ($meta['coverage_networks'] ?? []))
                    ->flatMap(fn ($row) => (array) ($row['networks'] ?? []));

                return $fromCoverage->isNotEmpty()
                    ? $fromCoverage
                    : collect((array) ($meta['networks_detail'] ?? []));
            })
            ->filter(fn ($n) => is_array($n) && ! empty($n['speed']))
            ->map(fn ($n) => strtoupper(trim((string) $n['speed'])))
            ->unique();

        $types = [];
        // LTE is how the suppliers spell 4G; customers read "4G".
        if ($speeds->contains(fn ($s) => str_contains($s, 'LTE') || str_contains($s, '4G'))) {
            $types[] = '4G';
        }
        if ($speeds->contains(fn ($s) => str_contains($s, '5G'))) {
            $types[] = '5G';
        }

        return $types;
    }

    /** @var array<string, Product|null> */
    private static array $productCache = [];

    private static function productFor(string $slug): ?Product
    {
        if (! array_key_exists($slug, self::$productCache)) {
            self::$productCache[$slug] = Product::query()
                ->where('slug', $slug)
                ->with(['variants' => fn ($q) => $q->where('is_available', true)])
                ->first();
        }

        return self::$productCache[$slug];
    }
}
