<?php

namespace App\Domain\Cart\Services;

use App\Domain\Rewards\Services\RewardEngine;
use App\Enums\PricingBasis;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CartPricingService
{
    /** Active pricing rules, loaded once and memoised for this instance. */
    private ?Collection $rules = null;

    /** Forced floor mode for this instance (audits compare modes side by side). */
    private ?string $floorModeOverride = null;

    /**
     * A copy of this service locked to a specific floor mode, regardless of
     * config. Lets `pricing:audit-below-cost` compute the legacy and the
     * cost-anchored price for the same variant and report the delta.
     */
    public function withFloorMode(string $mode): static
    {
        $clone = clone $this;
        $clone->floorModeOverride = $mode;

        return $clone;
    }

    /**
     * Calculate the pricing fields to snapshot on a cart item. Every figure is
     * USD — the settlement currency. Conversion into the customer's display
     * currency happens later, in the presentation layer.
     *
     * @return array<string, mixed>
     */
    public function calculatePricing(ProductVariant $variant, int $quantity): array
    {
        $cost = (float) $variant->cost_price;
        $base = $this->resolveMarkupBase($variant);

        $unitPriceUsd = $this->resolveVariantRetailPrice($variant, $base);
        $subtotalUsd = $unitPriceUsd * $quantity;

        return [
            'provider_cost_usd' => $cost,
            'markup_amount' => $unitPriceUsd - $cost,
            'unit_price_snapshot' => $unitPriceUsd,
            'subtotal_snapshot' => $subtotalUsd,
            // Settlement is USD; the display layer converts from here.
            'display_currency' => 'USD',
            'display_amount' => $subtotalUsd,
            'exchange_rate_snapshot' => 1.0,
        ];
    }

    /**
     * Recalculate USD totals for an entire cart.
     *
     * @return array<string, mixed>
     */
    public function calculateCartTotals(iterable $items): array
    {
        $subtotal = 0;
        $totalMarkup = 0;
        $totalProviderCost = 0;

        foreach ($items as $item) {
            $subtotal += $item->subtotal_snapshot;
            $totalMarkup += ($item->markup_amount * $item->quantity);
            $totalProviderCost += ($item->provider_cost_usd * $item->quantity);
        }

        $rewardEngine = app(RewardEngine::class);
        $cashbackPercentage = (float) Setting::get('cashback_percentage', 0.5);
        $estimatedRcoinReward = $rewardEngine->usdToRcoin($subtotal * ($cashbackPercentage / 100));

        return [
            'currency' => 'USD',
            'subtotal' => $subtotal,
            'total_markup' => $totalMarkup,
            'total_provider_cost' => $totalProviderCost,
            'total' => $subtotal, // No tax/discounts yet
            'estimated_rcoin_reward' => $estimatedRcoinReward,
        ];
    }

    /**
     * The markup parameters for a product, for client-side price estimation on
     * variable-amount products where the cost is only known once the customer
     * enters an amount. The consumer must mirror resolveRetailPrice(): apply the
     * markup, then clamp to the min-margin floor.
     *
     * @return array{type: string, value: float, min_margin_percent: float}
     */
    public function markupDescriptor(?Product $product): array
    {
        $rule = $this->resolveRule($product);

        [$type, $value] = match (true) {
            $rule === null => ['percentage', $this->safetyMarkupPercent()],
            $rule->markup_type === 'fixed' => ['fixed', (float) $rule->markup_value],
            default => ['percentage', (float) $rule->markup_value],
        };

        return [
            'type' => $type,
            'value' => $value,
            'min_margin_percent' => $this->minMarginPercent(),
        ];
    }

    /**
     * The USD figure the markup rules run against. Driven by the product's
     * explicit PricingBasis when set; a NULL basis falls back to the legacy
     * currency heuristic (USD face value if present, else cost) so the column
     * can roll out as a no-op before `pricing:backfill-basis` runs.
     *
     * The currency eligibility guard applies to EVERY face-flavoured path,
     * explicit basis included: face_value is only usable as a USD base when
     * the variant is USD-denominated. For non-USD denominations (e.g. ₦4700
     * bill payment, £25 card) the face_value is a local-currency figure, not
     * dollars — using it directly would charge $4700 instead of ~$4 — so the
     * variant degrades to the supplier's USD cost. This makes a FaceValue
     * basis on a mixed-currency product safe by definition.
     *
     * This is the ONE authoritative base derivation — the admin products grid
     * must call this rather than re-deriving the branch inline.
     */
    public function resolveMarkupBase(ProductVariant $variant): float
    {
        $faceValue = (float) ($variant->face_value ?? $variant->retail_price ?? 0);
        $variantCurrency = strtoupper((string) ($variant->currency ?? 'USD'));
        $faceIsUsd = $variantCurrency === 'USD' || $variantCurrency === '';
        $usableFace = ($faceIsUsd && $faceValue > 0) ? $faceValue : null;

        return match ($variant->product?->pricing_basis) {
            PricingBasis::SupplierCost => (float) $variant->cost_price,
            PricingBasis::FaceValue,
            PricingBasis::ProviderRetail => $usableFace ?? (float) $variant->cost_price,
            default => $usableFace ?? (float) $variant->cost_price, // legacy heuristic (NULL basis)
        };
    }

    /**
     * Variant-aware retail price. Honours the admin-set
     * `manual_retail_price_usd` override when present, otherwise falls through
     * to the rule-chain in `resolveRetailPrice`. The override bypasses the
     * markup RULES by design, but not the cost floor: in enforce mode an
     * override below supplier cost is clamped up to the floor unless the
     * variant is explicitly flagged `allow_below_cost` (deliberate loss-leader,
     * audited via the admin endpoint that sets it).
     */
    public function resolveVariantRetailPrice(ProductVariant $variant, float $base): float
    {
        $costUsd = (float) $variant->cost_price;

        $override = $variant->manual_retail_price_usd;
        if ($override !== null && (float) $override > 0) {
            return $this->guardOverridePrice($variant, (float) $override, $costUsd);
        }

        return $this->resolveRetailPrice($variant->product, $base, $costUsd);
    }

    /**
     * Resolve the marked-up USD retail price for a product. Applies the safety
     * fallback (no rule matched) and the margin floor. Public so satellite
     * flows (eSIM top-ups, manual adjustments) can reuse the markup hierarchy.
     *
     * $base is the figure the markup runs against (face value, provider
     * retail, or cost). $costUsd is the supplier's true USD cost; when omitted
     * the base is assumed to BE the cost (variable-amount flows pass the
     * requested value as both). In enforce mode the floor anchors on
     * max(base, cost) — strictly a ratchet over the legacy base-only floor, so
     * enabling it can raise below-cost prices but never lower an existing one.
     */
    public function resolveRetailPrice(?Product $product, float $base, ?float $costUsd = null): float
    {
        $costUsd ??= $base;
        $rule = $this->resolveRule($product);

        $retail = match (true) {
            $rule === null => $base * (1 + $this->safetyMarkupPercent() / 100),
            $rule->markup_type === 'fixed' => $base + (float) $rule->markup_value,
            default => $base * (1 + (float) $rule->markup_value / 100), // 'percentage'
        };

        $minMultiplier = 1 + $this->minMarginPercent() / 100;
        $baseFloor = $base * $minMultiplier;
        $costFloor = $this->costFloor($costUsd);

        $legacyPrice = max($retail, $baseFloor);

        if ($this->floorMode() === 'enforce') {
            $enforced = max($legacyPrice, $costFloor);
            if ($enforced > $legacyPrice + 0.0001 && $this->floorModeOverride === null) {
                Log::info('pricing.floor_enforced', [
                    'product_id' => $product?->id,
                    'base' => $base,
                    'cost_usd' => $costUsd,
                    'legacy_price' => $legacyPrice,
                    'charged_price' => $enforced,
                ]);
            }

            return $enforced;
        }

        if ($this->floorMode() === 'shadow' && $costFloor > $legacyPrice + 0.0001 && $this->floorModeOverride === null) {
            Log::info('pricing.floor_shadow', [
                'product_id' => $product?->id,
                'base' => $base,
                'cost_usd' => $costUsd,
                'legacy_price' => $legacyPrice,
                'would_charge' => max($legacyPrice, $costFloor),
            ]);
        }

        return $legacyPrice;
    }

    /**
     * Cost-floor guard for the admin manual price override. Legacy mode and
     * `allow_below_cost` variants return the override verbatim; shadow logs
     * the would-be clamp; enforce clamps up to cost × (1 + min margin). Only
     * the cost floor applies here — the override intentionally bypasses the
     * rule chain and the face-value floor.
     */
    private function guardOverridePrice(ProductVariant $variant, float $override, float $costUsd): float
    {
        $costFloor = $this->costFloor($costUsd);

        if ($override >= $costFloor
            || $costUsd <= 0
            || (bool) ($variant->allow_below_cost ?? false)
            || $this->floorMode() === 'legacy') {
            return $override;
        }

        if ($this->floorMode() === 'shadow') {
            if ($this->floorModeOverride === null) {
                Log::info('pricing.override_floor_shadow', [
                    'variant_id' => $variant->id,
                    'override' => $override,
                    'cost_usd' => $costUsd,
                    'would_charge' => $costFloor,
                ]);
            }

            return $override;
        }

        if ($this->floorModeOverride === null) {
            Log::info('pricing.override_floor_enforced', [
                'variant_id' => $variant->id,
                'override' => $override,
                'cost_usd' => $costUsd,
                'charged_price' => $costFloor,
            ]);
        }

        return $costFloor;
    }

    /**
     * Find the markup rule for a product using the hybrid hierarchy:
     * product > subcategory > category > global. Each tier is searched in full
     * before falling through, so a product override always beats a category
     * rule regardless of row order.
     *
     * Within a tier, a rule scoped to the product's supplier outranks the
     * provider-agnostic rule - suppliers price the same category very
     * differently (Airalo eSIMs carry ~50% built-in margin, Zendit eSIMs are
     * thin), so "eSIMs + airalo" wins over plain "eSIMs" for Airalo products
     * while every other supplier stays on the generic rule. A provider-scoped
     * rule NEVER applies to another supplier's products.
     */
    private function resolveRule(?Product $product): ?PricingRule
    {
        $rules = $this->activeRules();
        $provider = $product ? strtolower(trim((string) $product->provider_name)) : '';

        $firstAtTier = function (callable $tierMatch) use ($rules, $provider): ?PricingRule {
            if ($provider !== '') {
                $scoped = $rules->first(fn (PricingRule $r) => $tierMatch($r)
                    && $r->provider_name !== null
                    && strtolower(trim((string) $r->provider_name)) === $provider);
                if ($scoped) {
                    return $scoped;
                }
            }

            return $rules->first(fn (PricingRule $r) => $tierMatch($r) && $r->provider_name === null);
        };

        if ($product) {
            $rule = $firstAtTier(fn (PricingRule $r) => $r->product_id !== null && $r->product_id === $product->id);
            if ($rule) {
                return $rule;
            }

            if ($product->subcategory_id !== null) {
                $rule = $firstAtTier(fn (PricingRule $r) => $r->product_id === null
                    && $r->subcategory_id !== null && $r->subcategory_id === $product->subcategory_id);
                if ($rule) {
                    return $rule;
                }
            }

            if ($product->category_id !== null) {
                $rule = $firstAtTier(fn (PricingRule $r) => $r->product_id === null
                    && $r->subcategory_id === null
                    && $r->category_id !== null && $r->category_id === $product->category_id);
                if ($rule) {
                    return $rule;
                }
            }
        }

        // Global default — a rule with no product, subcategory or category.
        return $firstAtTier(fn (PricingRule $r) => $r->product_id === null
            && $r->subcategory_id === null && $r->category_id === null);
    }

    /**
     * All active pricing rules, loaded once. pricing_rules is a small table, so
     * the whole set is cached in memory and resolved with zero per-product
     * queries (key insight for catalog-listing performance). The cache key is
     * busted by PricingRuleObserver whenever a rule changes.
     *
     * @return Collection<int, PricingRule>
     */
    private function activeRules(): Collection
    {
        return $this->rules ??= Cache::rememberForever(
            'pricing_rules.active',
            fn () => PricingRule::where('is_active', true)->orderBy('id')->get()
        );
    }

    private function safetyMarkupPercent(): float
    {
        return (float) config('pricing.safety_markup_percent', 10);
    }

    private function minMarginPercent(): float
    {
        return (float) config('pricing.min_margin_percent', 1);
    }

    private function minMarginUsd(): float
    {
        return (float) config('pricing.min_margin_usd', 0);
    }

    /**
     * The lowest USD price a unit may be sold at: supplier cost plus the LARGER
     * of the percentage margin and the flat cash margin.
     *
     * The percentage alone leaves cheap-to-buy items earning almost nothing (1%
     * of a $39 top-up is 39 cents), so a flat floor guarantees each sale is
     * worth making. On expensive items the percentage is the larger of the two
     * and still wins, so this only ever lifts the thin end of the catalogue.
     *
     * Single source of truth: the markup path, the manual-override guard, the
     * coupon clamp and the audit command all anchor here, or they would disagree
     * about what "below cost" means.
     */
    public function costFloor(float $costUsd): float
    {
        if ($costUsd <= 0) {
            return 0.0;
        }

        return max(
            $costUsd * (1 + $this->minMarginPercent() / 100),
            $costUsd + $this->minMarginUsd(),
        );
    }

    /**
     * Active floor mode: legacy | shadow | enforce. Unknown config values fall
     * back to legacy so a typo in the env can only under-protect, never
     * misprice. See config/pricing.php for the rollout semantics.
     */
    private function floorMode(): string
    {
        $mode = $this->floorModeOverride ?? (string) config('pricing.floor_mode', 'legacy');

        return in_array($mode, ['shadow', 'enforce'], true) ? $mode : 'legacy';
    }
}
