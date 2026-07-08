<?php

namespace App\Domain\Cart\Services;

use App\Domain\Rewards\Services\RewardEngine;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CartPricingService
{
    /** Active pricing rules, loaded once and memoised for this instance. */
    private ?Collection $rules = null;

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

        // Use face_value as the markup BASE only when it's in USD — a $15 gift
        // card priced at $15 × markup reads correctly to the customer. For
        // non-USD denominations (e.g. ₦4700 bill payment, £25 card) the
        // face_value is a local-currency figure, not dollars; if we run markup
        // on it directly we'd charge $4700 instead of ~$4. In that case fall
        // back to the supplier's USD cost — already authoritative + currency-safe.
        $faceValue = (float) ($variant->face_value ?? $variant->retail_price ?? 0);
        $variantCurrency = strtoupper((string) ($variant->currency ?? 'USD'));
        $faceIsUsd = $variantCurrency === 'USD' || $variantCurrency === '';

        $base = ($faceIsUsd && $faceValue > 0) ? $faceValue : $cost;

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
     * Variant-aware retail price. Honours the admin-set
     * `manual_retail_price_usd` override when present, otherwise falls through
     * to the rule-chain in `resolveRetailPrice`. The override is a flat USD
     * figure — no markup or floor applied to it (admin is intentionally
     * bypassing the rules).
     */
    public function resolveVariantRetailPrice(ProductVariant $variant, float $cost): float
    {
        $override = $variant->manual_retail_price_usd;
        if ($override !== null && (float) $override > 0) {
            return (float) $override;
        }

        return $this->resolveRetailPrice($variant->product, $cost);
    }

    /**
     * Resolve the marked-up USD retail price for a product. Applies the safety
     * fallback (no rule matched) and the margin floor, so the result is never
     * at or below cost. Public so satellite flows (eSIM top-ups, manual
     * adjustments) can reuse the markup hierarchy.
     */
    public function resolveRetailPrice(?Product $product, float $cost): float
    {
        $rule = $this->resolveRule($product);

        $retail = match (true) {
            $rule === null => $cost * (1 + $this->safetyMarkupPercent() / 100),
            $rule->markup_type === 'fixed' => $cost + (float) $rule->markup_value,
            default => $cost * (1 + (float) $rule->markup_value / 100), // 'percentage'
        };

        // Hard floor — never price at or below cost, whatever a rule or a
        // supplier cost change would otherwise imply.
        $floor = $cost * (1 + $this->minMarginPercent() / 100);

        return max($retail, $floor);
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
}
