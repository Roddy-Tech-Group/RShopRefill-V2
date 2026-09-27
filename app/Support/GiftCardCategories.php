<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

/**
 * The one place that decides what a gift-card category is.
 *
 * Two sources feed it, in this order:
 *
 *   1. Curated categories from config/gift_card_categories.php - shopper-facing
 *      groupings we assign by hand, because the supplier taxonomy files brands
 *      by what the supplier calls them.
 *   2. The supplier's own subcategories, exactly as synced.
 *
 * Both are exposed through the same shape, so the listing page, its sidebar,
 * the mobile sheet and the home-page row all render the same categories in the
 * same order from the same call. Before this, each surface built its own list
 * and they drifted.
 *
 * Nothing here writes: the sync stays the sole author of products and
 * subcategories, and a curated category is a view over what it produced.
 */
class GiftCardCategories
{
    /**
     * Curated categories resolved against a set of products.
     *
     * A category with nothing in it is dropped - a menu entry whose only
     * destination is the empty state is a dead end, not a shortcut.
     *
     * @param  Collection  $products  one representative product per brand
     * @return Collection<int, array{slug: string, name: string, icon: string, products: Collection}>
     */
    public static function curated(Collection $products): Collection
    {
        // Case-insensitive: the catalogue stores PlayStation as "Playstation",
        // and an exact match drops a brand with nothing to show for it.
        $byBrand = $products->keyBy(fn ($product) => strtolower((string) $product->brand_key));

        return collect(config('gift_card_categories', []))
            ->map(fn (array $category, string $slug) => [
                'slug' => $slug,
                'name' => self::label($slug, $category['label']),
                'icon' => $category['icon'] ?? 'default',
                'products' => collect($category['brands'] ?? [])
                    ->map(fn ($brand) => $byBrand[strtolower($brand)] ?? null)
                    ->filter()
                    ->values(),
            ])
            ->filter(fn (array $category) => $category['products']->isNotEmpty())
            ->values();
    }

    /**
     * Supplier subcategories that currently have something available.
     *
     * Read off the VARIANTS' subcategory rather than the product's own column:
     * that is what the storefront's subcategory filter matches on, so a brand
     * lands in exactly the categories whose filter would return it.
     *
     * @return Collection<int, Subcategory>
     */
    public static function supplierSubcategories(?Category $giftCards): Collection
    {
        if (! $giftCards) {
            return collect();
        }

        $stocked = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.is_active', true)
            ->where('products.category_id', $giftCards->id)
            ->where('product_variants.is_available', true)
            ->whereNotNull('product_variants.subcategory_id')
            ->distinct()
            ->pluck('product_variants.subcategory_id');

        return Subcategory::query()
            ->where('category_id', $giftCards->id)
            ->whereIn('id', $stocked)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    /**
     * Every category a customer can browse, curated first then supplier, as
     * ['slug' => ..., 'name' => ..., 'icon' => ...] - the shape the home-page
     * row and both menus need.
     *
     * @return Collection<int, array{slug: string, name: string, icon: string}>
     */
    public static function menu(Collection $products, ?Category $giftCards): Collection
    {
        $curated = self::curated($products)
            ->map(fn (array $category) => [
                'slug' => $category['slug'],
                'name' => $category['name'],
                'icon' => $category['icon'],
            ]);

        // Curated only. The supplier's subcategories still drive the filter and
        // are still what the sync writes - they are just not offered as
        // navigation, because that taxonomy carries a misspelling beside its
        // own twin, two supplier artifacts and three names for "shopping".
        return $curated->values();
    }

    /**
     * Everything the Gift Cards nav menus render, in one call.
     *
     * Both menus - the desktop hover panel and the phone sheet's drill-down -
     * read this, so they can never disagree about what the catalogue offers.
     * Memoized per request (once()) because both render on the same page load.
     *
     * The brand list is restricted to brands with an AVAILABLE variant, the
     * same rule the home row and the listing sidebar apply: a category whose
     * every brand is sold out is a link to the empty state, not a shortcut.
     *
     * "Popular" is the curated featured list narrowed to what the visitor's
     * region actually stocks, so the menus never offer a brand their
     * catalogue cannot sell. $region is the customer's SELECTED country,
     * shared to every view by ResolveRegion.
     *
     * @return array{categories: Collection<int, array{slug: string, name: string, icon: string}>, popular: Collection<int, string>, regionName: string}
     */
    public static function navMenu(?string $region): array
    {
        $region = strtoupper((string) ($region ?: 'US'));

        return once(function () use ($region): array {
            $menu = TaggedCache::for(['catalog'])->remember(
                "gift_card_nav_menu_{$region}",
                3600,
                fn (): array => self::buildNavMenu($region)
            );

            // The cache is keyed by region, not language, so whatever name it
            // holds was written in the language of the request that built it.
            // The name is resolved again from the slug on every read.
            $menu['categories'] = collect($menu['categories'])
                ->map(fn (array $category) => [...$category, 'name' => self::label($category['slug'], $category['name'])])
                ->values();

            return $menu;
        });
    }

    /**
     * A curated category's name in the active language.
     *
     * The config label is the English source. A language without a
     * translation for the slug falls back to it rather than printing the key.
     */
    public static function label(string $slug, string $fallback): string
    {
        $key = "giftcards.categories.{$slug}";

        return Lang::has($key) ? __($key) : $fallback;
    }

    /**
     * Forget every region's cached menu.
     *
     * For stores that cannot hold tags, where flushing the tag would flush the
     * whole cache. The menu is keyed by the visitor's region, so every country
     * the site knows gets its key dropped, plus the US default a visitor with
     * no region resolved falls back to.
     */
    public static function forgetNavMenus(): void
    {
        $regions = array_merge(['US'], array_values(config('countries.codes', [])));

        foreach (array_unique($regions) as $region) {
            TaggedCache::for(['catalog'])->forget('gift_card_nav_menu_'.strtoupper((string) $region));
        }
    }

    /**
     * The uncached build behind navMenu().
     *
     * Held apart because it is the expensive half: it reads every stocked gift
     * card to work out which curated categories have anything in them, which is
     * over a thousand rows hydrated to produce a list of about twenty names.
     * That ran on every storefront request, for a menu that changes when the
     * catalogue does - so it is cached against the catalogue's own tag and the
     * sync's flush clears it, the same arrangement the brand pages use.
     *
     * @return array{categories: Collection<int, array{slug: string, name: string, icon: string}>, popular: Collection<int, string>, regionName: string}
     */
    private static function buildNavMenu(string $region): array
    {
        $giftCards = Category::where('slug', 'gift-cards')->first();

        $brandProducts = $giftCards
            ? Product::query()
                ->where('category_id', $giftCards->id)
                ->where('is_active', true)
                ->whereNotNull('brand_key')
                ->whereHas('variants', fn ($variant) => $variant->where('is_available', true))
                ->select('id', 'brand_key', 'logo_url', 'country_code')
                ->get()
                ->unique('brand_key')
                ->values()
            : collect();

        $inRegion = $giftCards
            ? Product::query()
                ->where('category_id', $giftCards->id)
                ->where('is_active', true)
                ->where('country_code', $region)
                ->whereNotNull('brand_key')
                ->pluck('brand_key')
                ->map(fn ($key) => strtolower($key))
                ->flip()
            : collect();

        $countryNames = array_flip(config('countries.codes', []));

        return [
            'categories' => self::menu($brandProducts, $giftCards),
            'popular' => collect(config('popular_brands.featured', []))
                ->filter(fn ($key) => $inRegion->has(strtolower($key)))
                ->take(7)
                ->values(),
            'regionName' => $countryNames[$region] ?? $region,
        ];
    }
}
