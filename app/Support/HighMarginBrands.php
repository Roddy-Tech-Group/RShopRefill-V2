<?php

namespace App\Support;

use App\Domain\Cart\Services\CartPricingService;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Cache;

/**
 * The catalogue brands that earn the most per sale, for promotional spots
 * (the For Partners referral hero's floating logo tiles).
 *
 * Margin is not stored: checkout prices a variant through CartPricingService
 * (retail minus supplier cost). Pricing every variant on a page load would be
 * slow, since the catalogue holds one product per denomination, so this works
 * in two steps and caches the result:
 *
 *   1. SQL shortlist: available USD variants of active, branded products,
 *      ordered by the gap between face value and supplier cost, the main
 *      driver of margin before the markup rules apply.
 *   2. Exact pass: each shortlisted brand's best variant is priced with the
 *      real pricing service, and brands are ranked by that margin (markup as a
 *      share of the selling price). Brands without a logo are skipped.
 */
final class HighMarginBrands
{
    /** How many variant rows the SQL shortlist hands to the exact pass. */
    private const SHORTLIST = 80;

    /**
     * @return list<array{brand: string, name: string, logo: string, margin: float}>
     */
    public static function top(int $count = 4): array
    {
        return Cache::remember('high-margin-brands:v1:'.$count, now()->addHours(6), fn () => self::compute($count));
    }

    /**
     * @return list<array{brand: string, name: string, logo: string, margin: float}>
     */
    private static function compute(int $count): array
    {
        $pricing = app(CartPricingService::class);

        $variants = ProductVariant::query()
            ->select('product_variants.*')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.is_active', true)
            ->whereNotNull('products.brand_key')
            ->where('product_variants.is_available', true)
            ->where('product_variants.currency', 'USD')
            ->where('product_variants.face_value', '>', 0)
            ->where('product_variants.cost_price', '>', 0)
            ->orderByRaw('(product_variants.face_value - product_variants.cost_price) / product_variants.face_value DESC')
            ->limit(self::SHORTLIST)
            ->with('product')
            ->get();

        $brands = [];

        foreach ($variants as $variant) {
            $product = $variant->product;
            if (! $product || isset($brands[$product->brand_key])) {
                continue;
            }

            $logo = Product::brandLogoUrl($product->brand_key, $product->logo_url ?? null);
            if (blank($logo)) {
                continue;
            }

            try {
                $price = $pricing->calculatePricing($variant, 1);
            } catch (\Throwable) {
                continue;
            }

            $unit = (float) $price['unit_price_snapshot'];
            if ($unit <= 0) {
                continue;
            }

            $brands[$product->brand_key] = [
                'brand' => (string) $product->brand_key,
                'name' => Product::brandDisplayName($product->brand_key),
                'logo' => (string) $logo,
                'margin' => round((float) $price['markup_amount'] / $unit, 4),
            ];
        }

        usort($brands, fn (array $a, array $b) => $b['margin'] <=> $a['margin']);

        return array_slice(array_values($brands), 0, $count);
    }
}
