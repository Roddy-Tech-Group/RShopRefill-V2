<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Jobs\SyncZenditEsimsJob;
use App\Jobs\SyncZenditGiftCardsJob;
use App\Jobs\SyncZenditTopupsJob;
use App\Models\Coupon;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\PricingCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminCatalogController extends Controller
{
    public function syncZendit()
    {
        // Dispatch the job to sync the first page
        SyncZenditGiftCardsJob::dispatch(1);

        return response()->json([
            'message' => 'Zendit catalog sync job dispatched successfully.',
        ]);
    }

    public function syncZenditEsims()
    {
        SyncZenditEsimsJob::dispatch(1);

        return response()->json([
            'message' => 'Zendit eSIM catalog sync job dispatched successfully.',
        ]);
    }

    public function syncZenditTopups()
    {
        SyncZenditTopupsJob::dispatch(1);

        return response()->json([
            'message' => 'Zendit mobile top-up catalog sync job dispatched successfully.',
        ]);
    }

    public function products(Request $request)
    {
        $products = Product::with(['category', 'subcategory', 'variants'])
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->latest()
            ->paginate((int) $request->query('per_page', 25));

        return ProductResource::collection($products);
    }

    public function toggleActive(Product $product)
    {
        $product->update(['is_active' => ! $product->is_active]);

        return response()->json(['is_active' => $product->is_active]);
    }

    /**
     * Mark a whole product out of stock (or back in stock). Unlike is_active - which
     * HIDES the product - out-of-stock keeps the product VISIBLE to customers but
     * greyed with an "Out of stock" label and non-purchasable. Product-level (all
     * variants) to match the drawer's "mark this product out of stock" control.
     */
    public function setOutOfStock(Request $request, Product $product)
    {
        $validated = $request->validate(['is_out_of_stock' => ['required', 'boolean']]);
        $product->update(['is_out_of_stock' => (bool) $validated['is_out_of_stock']]);

        return response()->json(['is_out_of_stock' => (bool) $product->is_out_of_stock]);
    }

    /**
     * Storefront curation slots: at most this many products may carry each
     * of the Featured / Popular badges at once. Keeps the homepage rows a
     * deliberate shortlist instead of an ever-growing pile.
     */
    private const MAX_FLAGGED_PRODUCTS = 10;

    public function toggleFeatured(Product $product)
    {
        return $this->toggleCurationFlag($product, 'is_featured', 'Featured');
    }

    public function togglePopular(Product $product)
    {
        return $this->toggleCurationFlag($product, 'is_popular', 'Popular');
    }

    /**
     * Flip a curation flag with the slot cap enforced atomically: the count
     * and the write run inside one transaction with the flagged rows locked,
     * so two admins toggling at the same moment can never overshoot the cap.
     * Responses always carry the live slot usage so the UI can surface
     * "X of 10 used" without a second request.
     */
    private function toggleCurationFlag(Product $product, string $flag, string $label)
    {
        return DB::transaction(function () use ($product, $flag, $label) {
            $product = Product::whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            $flaggedCount = Product::where($flag, true)->lockForUpdate()->count();

            if (! $product->{$flag} && $flaggedCount >= self::MAX_FLAGGED_PRODUCTS) {
                return response()->json([
                    'message' => "{$label} limit reached: up to ".self::MAX_FLAGGED_PRODUCTS." products can carry the {$label} badge at once. Remove one first.",
                    'flagged_count' => $flaggedCount,
                    'max' => self::MAX_FLAGGED_PRODUCTS,
                ], 422);
            }

            $product->update([$flag => ! $product->{$flag}]);

            return response()->json([
                $flag => $product->{$flag},
                'flagged_count' => $flaggedCount + ($product->{$flag} ? 1 : -1),
                'max' => self::MAX_FLAGGED_PRODUCTS,
            ]);
        });
    }

    /**
     * Set the admin sales-price override on a single variant. Bypasses the
     * pricing-rule chain — used when the admin wants a fixed USD price on this
     * SKU regardless of category/global markup rules.
     *
     * The override does NOT silently bypass the cost floor: a price below
     * supplier cost × (1 + min margin) is rejected unless the request
     * explicitly sends allow_below_cost, which is persisted on the variant and
     * logged so deliberate loss-leaders leave an audit trail.
     */
    public function setVariantPrice(Request $request, ProductVariant $variant)
    {
        $validated = $request->validate([
            'manual_retail_price_usd' => ['required', 'numeric', 'min:0.01'],
            'allow_below_cost' => ['sometimes', 'boolean'],
        ]);

        $price = (float) $validated['manual_retail_price_usd'];
        $allowBelowCost = (bool) ($validated['allow_below_cost'] ?? false);
        $costUsd = (float) $variant->cost_price;
        $costFloor = round($costUsd * (1 + (float) config('pricing.min_margin_percent', 1) / 100), 4);

        if ($costUsd > 0 && $price < $costFloor && ! $allowBelowCost) {
            return response()->json([
                'message' => sprintf(
                    'This price is below the supplier cost floor of $%s (cost $%s + %s%% margin). Send allow_below_cost to sell at a deliberate loss.',
                    number_format($costFloor, 2),
                    number_format($costUsd, 2),
                    (float) config('pricing.min_margin_percent', 1),
                ),
                'cost_floor_usd' => $costFloor,
            ], 422);
        }

        $variant->update([
            'manual_retail_price_usd' => $price,
            'allow_below_cost' => $allowBelowCost,
        ]);

        if ($allowBelowCost && $costUsd > 0 && $price < $costFloor) {
            Log::info('pricing.override_below_cost_authorized', [
                'variant_id' => $variant->id,
                'price_usd' => $price,
                'cost_usd' => $costUsd,
                'admin_id' => $request->user()?->id,
            ]);
        }

        PricingCache::bustComputedPriceCaches();

        return response()->json([
            'manual_retail_price_usd' => (float) $variant->manual_retail_price_usd,
            'allow_below_cost' => (bool) $variant->allow_below_cost,
        ]);
    }

    /**
     * Clear the override so pricing falls back to the rules chain. Also resets
     * the allow_below_cost flag — it authorises a specific override, not the
     * variant forever.
     */
    public function clearVariantPrice(ProductVariant $variant)
    {
        $variant->update(['manual_retail_price_usd' => null, 'allow_below_cost' => false]);

        PricingCache::bustComputedPriceCaches();

        return response()->json(['manual_retail_price_usd' => null]);
    }

    /**
     * Toggle a single variant's storefront availability. Disabled variants
     * stop appearing in catalog listings + can't be added to cart.
     *
     * Switching OFF records that an admin did it, which is what makes the
     * decision survive the six-hourly sync; before this the next run read the
     * supplier's flag straight over the top and the product came back on sale
     * with nobody told.
     *
     * Switching ON only hands the offer back to the supplier's word: it returns
     * to sale if the feed still lists it and no cleanup has delisted it. An
     * admin cannot force an offer on sale that the supplier has withdrawn,
     * because that just moves the failure to the customer at fulfilment.
     */
    public function setVariantAvailability(Request $request, ProductVariant $variant)
    {
        $validated = $request->validate(['is_available' => ['required', 'boolean']]);

        if ($validated['is_available']) {
            $variant->clearAdminDisable();
        } else {
            $variant->adminDisable(auth('admin')->id());
        }

        $variant->refresh();

        return response()->json([
            'is_available' => (bool) $variant->is_available,
            'admin_disabled' => $variant->isAdminDisabled(),
            'supplier_available' => (bool) $variant->supplier_available,
            // The switch was turned on but the offer stayed off, so the panel
            // can say why instead of looking broken.
            'blocked_by_supplier' => $validated['is_available'] && ! $variant->is_available,
        ]);
    }

    /**
     * Upsert a per-product PricingRule directly from the product drawer.
     * Creates one rule per product (product_id set, category/subcategory null)
     * so the most-specific layer of the rules chain takes effect immediately.
     */
    public function setProductMarkup(Request $request, Product $product)
    {
        $validated = $request->validate([
            'markup_type' => ['required', 'in:percentage,fixed'],
            'markup_value' => ['required', 'numeric', 'min:0'],
        ]);

        $rule = PricingRule::updateOrCreate(
            ['product_id' => $product->id],
            [
                'category_id' => $product->category_id,
                'subcategory_id' => $product->subcategory_id,
                'markup_type' => $validated['markup_type'],
                'markup_value' => $validated['markup_value'],
                'is_active' => true,
            ],
        );

        return response()->json([
            'markup_type' => $rule->markup_type,
            'markup_value' => (float) $rule->markup_value,
        ]);
    }

    /**
     * Remove the per-product PricingRule so pricing falls back to the
     * subcategory / category / global rule chain.
     */
    public function clearProductMarkup(Product $product)
    {
        PricingRule::where('product_id', $product->id)->delete();

        return response()->json(['markup_type' => null, 'markup_value' => null]);
    }

    /**
     * List coupons attached to a variant. Returns redeemability state so the
     * drawer can render the right badge (active / expired / used-up).
     */
    public function listCoupons(ProductVariant $variant)
    {
        $coupons = $variant->coupons()->latest()->get()->map(fn (Coupon $c) => [
            'id' => $c->id,
            'code' => $c->code,
            'discount_type' => $c->discount_type,
            'discount_value' => (float) $c->discount_value,
            'max_uses' => $c->max_uses,
            'used_count' => $c->used_count,
            'valid_from' => $c->valid_from?->toIso8601String(),
            'valid_until' => $c->valid_until?->toIso8601String(),
            'is_active' => $c->is_active,
            'is_redeemable' => $c->isRedeemable(),
            'is_expired' => $c->isExpired(),
            'is_used_up' => $c->isUsedUp(),
        ]);

        return response()->json(['coupons' => $coupons]);
    }

    /**
     * Create a coupon for a variant. Code is uppercased + uniqueness-checked
     * across the whole table — a customer types ONE code at checkout, so it
     * can't collide with another variant's coupon.
     */
    public function createCoupon(Request $request, ProductVariant $variant)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:64'],
            'discount_type' => ['required', 'in:percent,fixed'],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'valid_until' => ['nullable', 'date', 'after:now'],
        ]);

        $code = Str::upper(trim($validated['code']));

        if (Coupon::where('code', $code)->exists()) {
            return response()->json([
                'message' => 'That coupon code is already taken.',
                'errors' => ['code' => ['That coupon code is already taken.']],
            ], 422);
        }

        if ($validated['discount_type'] === 'percent' && $validated['discount_value'] > 100) {
            return response()->json([
                'message' => 'Percent discount cannot exceed 100.',
                'errors' => ['discount_value' => ['Percent discount cannot exceed 100.']],
            ], 422);
        }

        $coupon = $variant->coupons()->create([
            'code' => $code,
            'discount_type' => $validated['discount_type'],
            'discount_value' => $validated['discount_value'],
            'max_uses' => $validated['max_uses'] ?? null,
            'valid_until' => $validated['valid_until'] ?? null,
            'is_active' => true,
            'created_by' => (string) ($request->user()?->id ?? 'system'),
        ]);

        return response()->json([
            'coupon' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'discount_type' => $coupon->discount_type,
                'discount_value' => (float) $coupon->discount_value,
                'max_uses' => $coupon->max_uses,
                'used_count' => $coupon->used_count,
                'valid_from' => $coupon->valid_from?->toIso8601String(),
                'valid_until' => $coupon->valid_until?->toIso8601String(),
                'is_active' => $coupon->is_active,
                'is_redeemable' => $coupon->isRedeemable(),
                'is_expired' => $coupon->isExpired(),
                'is_used_up' => $coupon->isUsedUp(),
            ],
        ]);
    }

    public function deleteCoupon(Coupon $coupon)
    {
        $coupon->delete();

        return response()->json(['deleted' => true]);
    }
}
