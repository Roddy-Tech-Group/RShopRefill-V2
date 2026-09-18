<?php

namespace App\Http\Controllers;

use App\Domain\Cart\Services\CartPricingService;
use App\Domain\Shared\Services\Money;
use App\Models\Category;
use App\Models\Product;
use App\Support\FeatureFlag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Storefront eSIM pages. A country is sold by more than one supplier, each of which
 * syncs its own Product per country. The store merges them: a country page shows
 * every supplier's available plans together (data + voice) under one roof. Regional
 * and global eSIMs (no real 2-letter country code) stay per-product so distinct
 * regions are never collapsed into each other.
 */
class EsimStoreController extends Controller
{
    /** The /esims entry point: a lean landing — hero + browse-by-location grid.
     *  ?scope=popular|local|regional|global|all selects the active category. */
    public function index(Request $request)
    {
        $allowed = ['popular', 'local', 'regional', 'global', 'all'];
        $scope = strtolower((string) $request->query('scope', 'popular'));
        if (! in_array($scope, $allowed, true)) {
            $scope = 'popular';
        }

        return view('shop.esims', ['catalog' => self::catalogSummary(), 'activeScope' => $scope]);
    }

    /**
     * Deduped, cached eSIM catalog summary for the location grids: one entry per
     * country (cheapest "from" price across all suppliers), regional/global kept
     * per-product. Shared by the landing page and the country store.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function catalogSummary(): Collection
    {
        // The destinations under the store's "Popular" tab, from config so the
        // set can change without a deploy. A code we do not sell, or one with no
        // flag, never appears; the reasoning and the USA exception live in
        // config/esims.php.
        $popularIso = (array) config('esims.popular_countries', []);

        // Cache key carries the Zendit-eSIM flag so toggling it doesn't serve a
        // stale catalog (the summary is cached for an hour). v6 added the per-card
        // data-size label; v7 pins the Discover Global USA card first + drops US
        // from Popular.
        $cacheKey = 'esim-catalog-summary-v15-z'.(FeatureFlag::on('zendit_esims') ? '1' : '0');

        return Cache::remember($cacheKey, now()->addHour(), function () use ($popularIso) {
            $pricing = app(CartPricingService::class);
            $cat = Category::where('slug', 'esims')->first();

            $catalog = Product::query()
                ->where('is_active', true)
                ->when($cat, fn ($q) => $q->where('category_id', $cat->id))
                ->tap(fn ($q) => self::applyEsimSupplierFlags($q))
                ->with(['variants' => fn ($q) => $q->where('is_available', true)])
                ->orderBy('name')
                ->get()
                ->groupBy(function ($p) {
                    $cc = strtoupper((string) $p->country_code);

                    return (strlen($cc) === 2 && $cc !== 'WW') ? $cc : 'slug:'.$p->slug;
                })
                ->map(function ($group) use ($pricing, $popularIso) {
                    $rep = $group->first();
                    $meta = self::regionMeta($rep->country_code, $rep->slug, $rep->name);
                    $hasFlag = strlen($meta['cc']) === 2 && $meta['cc'] !== 'WW';

                    $cheapest = $group
                        ->flatMap(fn ($p) => $p->variants->each(fn ($v) => $v->setRelation('product', $p)))
                        ->sortBy('cost_price')
                        ->first();
                    if (! $cheapest) {
                        return null;
                    }
                    $from = round((float) $pricing->calculatePricing($cheapest, 1)['unit_price_snapshot'], 2);

                    // What this destination actually holds, summarised from the
                    // variants already loaded for pricing, so it costs no extra
                    // query: the span of data allowances, the span of validity
                    // days, and whether any package is unlimited. An unlimited
                    // package carries amount 0, so it is counted as unlimited
                    // rather than dragging the low end of the data span to zero.
                    $amounts = [];
                    $days = [];
                    $unlimited = false;
                    $withNumber = false;

                    foreach ($group as $product) {
                        foreach ($product->variants as $variant) {
                            $raw = (array) data_get($variant->metadata, 'raw_payload', []);

                            if (! empty($raw['is_unlimited'])) {
                                $unlimited = true;
                            } elseif (($mb = (float) ($raw['amount'] ?? 0)) > 0) {
                                $amounts[] = $mb;
                            }

                            if (($d = (int) ($raw['day'] ?? 0)) > 0) {
                                $days[] = $d;
                            }

                            // A plan that carries voice or SMS comes with a phone
                            // number; one that does not is data only. Read from
                            // the supplier's own plan_type and its voice/text
                            // allowances rather than inferred from the name.
                            if (str_contains((string) data_get($variant->metadata, 'plan_type', ''), 'voice')
                                || (float) ($raw['voice'] ?? 0) > 0
                                || (float) ($raw['text'] ?? 0) > 0) {
                                $withNumber = true;
                            }
                        }
                    }

                    return [
                        'slug' => $rep->slug,
                        'name' => $meta['name'],
                        'flag' => $hasFlag ? Product::flagUrl($meta['cc']) : self::globalFlag(),
                        'from' => $from,
                        'data' => self::planDataLabel($cheapest),
                        'scope' => $meta['scope'],
                        'cc' => $meta['cc'],
                        // Popular is the curated list PLUS every destination that
                        // sells a phone number, which is the richest thing this
                        // catalogue offers and the reason someone picks an eSIM
                        // over roaming. Making it number AND unlimited only would
                        // leave the tab with three cards.
                        'popular' => $withNumber || ($hasFlag && in_array($meta['cc'], $popularIso, true)),
                        'unlimited' => $unlimited,
                        'data_range' => self::spanLabel(
                            $amounts,
                            fn (float $mb) => self::dataLabel($mb),
                        ),
                        'days_range' => self::spanLabel(
                            $days,
                            // Plain integer. Trimming trailing zeros here, as the
                            // data formatter does, turned 30 days into 3.
                            fn (float $d) => (string) (int) $d,
                            ' days',
                        ),
                        'plan_count' => $group->sum(fn ($p) => $p->variants->count()),
                        // "Data only" unless some package includes calls or texts,
                        // in which case the destination can also be sold with a
                        // phone number.
                        'with_number' => $withNumber,
                    ];
                })
                ->filter()
                // Destinations that sell a phone number come first, then A-Z
                // within each group. A number is the scarce thing here (5 of
                // 214 destinations have one) and the reason someone picks an
                // eSIM over roaming, so it should not be buried in the middle
                // of an alphabetical list.
                // Ranked by what a plan carries, then A-Z inside each rank: a
                // number first, unlimited data next, everything else after.
                ->sortBy(fn ($item) => [
                    $item['with_number'] ? 0 : 1,
                    $item['unlimited'] ? 0 : 1,
                    $item['name'],
                ])
                ->values();

            // Pin the worldwide eSIM to the front of every grid, shown as "Global
            // eSIM" with the US flag (it carries a real +1 number alongside its
            // worldwide coverage) and surfaced in Popular, so country browsers meet
            // it before the A-Z list. The supplier's own product name is not used
            // here: a shopper looking for worldwide coverage does not search for a
            // supplier's brand.
            $key = $catalog->search(fn ($item) => str_contains($item['slug'], 'discover-global'));
            if ($key !== false) {
                $global = $catalog[$key];
                $global['name'] = 'Global eSIM';
                $global['flag'] = Product::flagUrl('US');
                $global['popular'] = true;
                $catalog = $catalog->forget($key)->prepend($global)->values();
            }

            return $catalog;
        });
    }

    /**
     * Clean display name + scope (local / regional / global) for an eSIM product.
     * Suppliers label every region "Global eSIM", so for non-country products we
     * derive the real region from the slug (esim-ww-europe -> "Europe").
     *
     * @return array{cc: string, scope: string, name: string}
     */
    public static function regionMeta(?string $countryCode, string $slug, ?string $name = null): array
    {
        $cc = strtoupper(trim((string) $countryCode));

        // Real ISO country -> local. Always show the canonical full country name
        // (suppliers are inconsistent: some use the ISO code, some abbreviations).
        // Fall back to the cleaned supplier name only when the ISO isn't in our map.
        if (strlen($cc) === 2 && $cc !== 'WW') {
            $full = array_flip(config('countries.codes', []))[$cc] ?? null;
            $clean = (string) str((string) $name)->replaceLast(' Data eSIM', '')->replaceLast(' eSIM', '')->trim();

            return ['cc' => $cc, 'scope' => 'local', 'name' => $full ?: ($clean !== '' ? $clean : $cc)];
        }

        // Non-country product: derive the real region from the slug.
        $label = (string) str($slug)
            ->replaceFirst('esim-ww-', '')
            ->replaceFirst('esim-', '')
            ->replace('-', ' ')
            ->trim()
            ->title();
        $label = str_replace([' And ', ' Of ', ' The '], [' and ', ' of ', ' the '], $label);
        if ($label === '' || strtolower($label) === 'esim') {
            $label = 'Global';
        }

        // The worldwide plan is sold as "Global", not under the supplier's own
        // product name, which is a brand a shopper has no reason to know. Keyed
        // on the slug because that is the stable identifier, and kept here so
        // the page title, heading, breadcrumb and region picker all agree; the
        // catalogue grid adds the " eSIM" suffix for its card label.
        if (str_contains($slug, 'discover-global')) {
            $label = 'Global';
        }

        $lower = strtolower($label);
        if ($cc === '' || str($lower)->contains(['global', 'world', 'discover'])) {
            $scope = 'global';
        } elseif (str($lower)->contains(['africa', 'asia', 'europe', 'oceania', 'america', 'caribbean', 'middle east', 'balkan', 'gulf', 'nordic', 'union', 'mena', 'latin'])) {
            $scope = 'regional';
        } else {
            // A single named territory that isn't an ISO country (Azores, Scotland).
            $scope = 'local';
        }

        return ['cc' => $cc, 'scope' => $scope, 'name' => $label];
    }

    /** Flag image used for non-country (regional / global) eSIMs. */
    public static function globalFlag(): string
    {
        return asset('assets/'.rawurlencode('Global png 11.webp'));
    }

    /** A specific region by slug; still merges by country so every supplier shows. */
    public function show(string $slug)
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('slug', 'esims'))
            ->tap(fn ($q) => self::applyEsimSupplierFlags($q))
            ->firstOrFail();

        return $this->renderCountry($product);
    }

    /** Stable entry point by ISO country code (e.g. /esims/country/US) — used for
     *  footer/nav links that shouldn't depend on sync-generated slugs. */
    public function country(string $code)
    {
        $cc = strtoupper($code);

        $product = Product::query()
            ->where('is_active', true)
            ->where('country_code', $cc)
            ->whereHas('category', fn ($q) => $q->where('slug', 'esims'))
            ->tap(fn ($q) => self::applyEsimSupplierFlags($q))
            ->withCount(['variants' => fn ($q) => $q->where('is_available', true)])
            ->orderByDesc('variants_count')
            ->firstOrFail();

        return $this->renderCountry($product);
    }

    private function renderCountry(Product $product)
    {
        $cc = strtoupper((string) $product->country_code);

        if ($this->isLocalCountry($cc)) {
            $variants = $this->mergedCountryVariants($cc);
        } else {
            // Regional / global: keep this product's own plans only.
            $product->loadMissing(['variants' => fn ($q) => $q->where('is_available', true)]);
            $variants = $product->variants->each(fn ($v) => $v->setRelation('product', $product))->values();
        }

        return view('shop.esim', ['product' => $product, 'variants' => $variants]);
    }

    /**
     * The global-coverage "Discover Global" eSIM and its available plans, priced
     * exactly the way the detail page prices them. Used to surface worldwide eSIM
     * plans on the home page and the dashboard. Returns null when the product
     * isn't in the catalogue (or its supplier is flagged off) so callers render
     * nothing rather than an empty shell.
     *
     * @return array{product: Product, plans: Collection}|null
     */
    public static function discoverGlobal(): ?array
    {
        $product = Product::query()
            ->where('is_active', true)
            ->where('country_code', 'WW')
            ->whereHas('category', fn ($q) => $q->where('slug', 'esims'))
            ->where('slug', 'like', '%discover-global%')
            ->tap(fn ($q) => self::applyEsimSupplierFlags($q))
            ->with(['variants' => fn ($q) => $q->where('is_available', true)->orderBy('cost_price')])
            ->first();

        if (! $product || $product->variants->isEmpty()) {
            return null;
        }

        $pricing = app(CartPricingService::class);

        $plans = $product->variants
            ->map(function ($variant) use ($pricing, $product) {
                $variant->setRelation('product', $product);
                $meta = (array) ($variant->metadata ?? []);
                $raw = (array) ($meta['raw_payload'] ?? []);

                // Mirror the detail page's plan mapping so the home/dashboard cards
                // can render exactly the same content. A plan is "Voice" only when
                // it actually carries minutes or texts.
                $voiceVal = $raw['voice'] ?? ($meta['voice_limit'] ?? null);
                $smsVal = $raw['text'] ?? ($meta['sms_limit'] ?? null);
                $hasVoice = is_numeric($voiceVal) && (float) $voiceVal > 0;
                $hasSms = is_numeric($smsVal) && (float) $smsVal > 0;

                $data = self::planDataLabel($variant) ?? 'Data';
                $isUnlimited = strcasecmp($data, 'Unlimited') === 0;
                $price = round((float) $pricing->calculatePricing($variant, 1)['unit_price_snapshot'], 2);

                return [
                    'id' => $variant->id,
                    'data' => $data,
                    'days' => (int) ($meta['validity_days'] ?? $meta['duration_days'] ?? $raw['durationDays'] ?? 0),
                    'is_voice' => $hasVoice || $hasSms,
                    'voice' => $hasVoice ? $voiceVal.' mins' : null,
                    'sms' => $hasSms ? $smsVal.' SMS' : null,
                    'note' => $isUnlimited ? trim((string) ($raw['shortNotes'] ?? '')) : '',
                    'price' => $price,
                    'price_label' => Money::format($price, 'USD'),
                ];
            })
            ->filter(fn ($p) => $p['price'] > 0)
            ->unique(fn ($p) => ($p['is_voice'] ? 'v' : 'd').'|'.strtolower($p['data']).'|'.$p['days'])
            ->sortBy('price')
            ->values();

        return $plans->isEmpty() ? null : ['product' => $product, 'plans' => $plans];
    }

    /**
     * Hide suppliers switched off by a feature flag. Zendit eSIMs ride behind
     * features.zendit_esims_enabled so they can be turned on/off without a
     * deploy; Airalo (and any other provider) stays visible. NULL provider rows
     * are kept defensively.
     *
     * @param  Builder  $query
     */
    private static function applyEsimSupplierFlags($query)
    {
        if (FeatureFlag::off('zendit_esims')) {
            $query->where(function ($w) {
                $w->where('provider_name', '!=', 'zendit')->orWhereNull('provider_name');
            });
        }

        return $query;
    }

    /**
     * Short data-size label for an eSIM variant ("1 GB", "Unlimited"), used on
     * the location cards so customers see what the cheapest plan offers, not
     * just the "from" price. Mirrors the country page's data logic: prefer the
     * clean data_limit, fall back to the raw payload (Zendit dataGB / unlimited).
     */
    /**
     * "1 GB - 100 GB" from a set of values, or "1 GB" when they are all the
     * same, or null when there are none. Keeps the card honest: a single-plan
     * destination should not read as a range.
     *
     * @param  array<int, float>  $values
     */
    private static function spanLabel(array $values, callable $format, string $suffix = ''): ?string
    {
        if ($values === []) {
            return null;
        }

        $min = min($values);
        $max = max($values);

        return $min === $max
            ? $format($min).$suffix
            : $format($min).' - '.$format($max).$suffix;
    }

    /** Megabytes as the supplier counts them, shown as MB below 1 GB. */
    private static function dataLabel(float $mb): string
    {
        if ($mb >= 1024) {
            $gb = $mb / 1024;

            return rtrim(rtrim(number_format($gb, 2), '0'), '.').' GB';
        }

        return rtrim(rtrim(number_format($mb, 2), '0'), '.').' MB';
    }

    private static function planDataLabel($variant): ?string
    {
        $meta = $variant->metadata ?? [];

        $dl = trim((string) ($meta['data_limit'] ?? ''));
        if ($dl !== '' && strtolower($dl) !== 'unknown') {
            return $dl;
        }

        $raw = (array) ($meta['raw_payload'] ?? []);
        if (! empty($raw['dataUnlimited'])) {
            return 'Unlimited';
        }
        $gb = (float) ($raw['dataGB'] ?? 0);
        if ($gb > 0) {
            return rtrim(rtrim(number_format($gb, 2), '0'), '.').' GB';
        }

        return null;
    }

    /** Every available variant for a country, across all suppliers, each carrying its own product. */
    private function mergedCountryVariants(string $country): EloquentCollection
    {
        $products = Product::query()
            ->where('is_active', true)
            ->where('country_code', $country)
            ->whereHas('category', fn ($q) => $q->where('slug', 'esims'))
            ->tap(fn ($q) => self::applyEsimSupplierFlags($q))
            ->with(['variants' => fn ($q) => $q->where('is_available', true)->orderBy('cost_price')])
            ->get();

        $merged = $products->flatMap(
            fn (Product $p) => $p->variants->each(fn ($v) => $v->setRelation('product', $p))
        )->values();

        return new EloquentCollection($merged->all());
    }

    private function isLocalCountry(string $cc): bool
    {
        return strlen($cc) === 2 && $cc !== 'WW';
    }
}
