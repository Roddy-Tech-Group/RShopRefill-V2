<?php

namespace App\Console\Commands;

use App\Domain\Cart\Services\CartPricingService;
use App\Enums\PricingBasis;
use App\Models\Product;
use App\Support\PricingCache;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Stage 5 of the pricing rollout: deliberately re-base a category (e.g.
 * Mobile Airtime -> supplier_cost) with the price deltas on the table BEFORE
 * anything persists. Default is a dry run printing every variant whose price
 * would move; --apply persists.
 *
 * A basis flip moves prices in BOTH directions: broken SKUs (cost above face)
 * rise — the fix — but discounted SKUs (cost below face) get CHEAPER under
 * supplier_cost, silently handing the supplier discount to the customer.
 * Price drops are therefore flagged separately; review them before --apply,
 * or scope the run with --only-below-cost to touch only the broken products.
 */
class MigratePricingBasisCommand extends Command
{
    protected $signature = 'pricing:migrate-basis
        {--category= : Category slug to migrate (required)}
        {--to= : Target basis: face_value | supplier_cost | provider_retail (required)}
        {--only-below-cost : Only migrate products with at least one variant currently priced below supplier cost}
        {--only-cost-over-face : Only migrate products with at least one fixed USD variant whose supplier cost exceeds its face value}
        {--apply : Persist the new basis; without this the command is a dry-run delta report}';

    protected $description = 'Re-base a category to an explicit pricing basis, with a variant-level price delta report (dry-run by default).';

    public function handle(CartPricingService $pricing): int
    {
        $categorySlug = (string) $this->option('category');
        $target = PricingBasis::tryFrom((string) $this->option('to'));

        if ($categorySlug === '' || $target === null) {
            $this->error('Usage: pricing:migrate-basis --category=<slug> --to=face_value|supplier_cost|provider_retail [--only-below-cost] [--apply]');

            return self::FAILURE;
        }

        $products = Product::query()
            ->whereHas('category', fn ($q) => $q->where('slug', $categorySlug))
            ->with(['variants' => fn ($q) => $q->where('is_available', true)])
            ->orderBy('name')
            ->get();

        if ($products->isEmpty()) {
            $this->warn("No products found in category '{$categorySlug}'.");

            return self::SUCCESS;
        }

        $rows = [];
        $raises = 0;
        $drops = 0;
        $migrated = [];

        foreach ($products as $product) {
            $deltas = $this->variantDeltas($pricing, $product, $target);

            if ($this->option('only-below-cost') && ! $deltas['has_below_cost']) {
                continue;
            }

            // Fee-loaded prepaid (cost above the printed face): face-basis
            // pricing lands these at the enforce floor's 1%, never the real
            // markup, so they are flipped to supplier_cost as a set even when
            // the floor keeps them technically above cost.
            if ($this->option('only-cost-over-face') && ! $deltas['has_cost_over_face']) {
                continue;
            }

            $migrated[] = $product;

            foreach ($deltas['rows'] as $row) {
                $rows[] = $row;
                $row['delta'] > 0 ? $raises++ : $drops++;
            }
        }

        if ($rows !== []) {
            usort($rows, fn ($a, $b) => $a['delta'] <=> $b['delta']);
            $this->table(
                ['Product', 'Variant', 'Cost$', 'Now$', 'New$', 'Delta'],
                array_map(fn ($r) => [
                    Str::limit($r['product'], 34),
                    $r['variant_id'],
                    number_format($r['cost'], 2),
                    number_format($r['old'], 2),
                    number_format($r['new'], 2),
                    sprintf('%+.2f (%+.1f%%)', $r['delta'], $r['delta_pct']),
                ], $rows),
            );
        }

        $this->newLine();
        $this->info(sprintf(
            '%d products in scope, %d variant prices move: %d raise, %d DROP.',
            count($migrated), count($rows), $raises, $drops
        ));

        if ($drops > 0) {
            $this->warn('Price DROPS give away supplier discount — review each before applying, or scope with --only-below-cost.');
        }

        if (! $this->option('apply')) {
            $this->comment('DRY RUN — nothing saved. Re-run with --apply to persist.');

            return self::SUCCESS;
        }

        foreach ($migrated as $product) {
            $product->update(['pricing_basis' => $target]);
        }

        PricingCache::bustComputedPriceCaches();

        $this->info(sprintf('Applied %s to %d products and busted the computed-price caches.', $target->value, count($migrated)));

        return self::SUCCESS;
    }

    /**
     * Per-variant price movement if $product were re-based to $target,
     * simulated in memory — nothing persists here.
     *
     * @return array{rows: array<int, array<string, mixed>>, has_below_cost: bool, has_cost_over_face: bool}
     */
    private function variantDeltas(CartPricingService $pricing, Product $product, PricingBasis $target): array
    {
        $rows = [];
        $hasBelowCost = false;
        $hasCostOverFace = false;
        $originalBasis = $product->pricing_basis;

        foreach ($product->variants as $variant) {
            $variant->setRelation('product', $product);

            $product->pricing_basis = $originalBasis;
            $old = (float) $pricing->calculatePricing($variant, 1)['unit_price_snapshot'];

            $product->pricing_basis = $target;
            $new = (float) $pricing->calculatePricing($variant, 1)['unit_price_snapshot'];

            $cost = (float) $variant->cost_price;
            if ($cost > 0 && $old < $cost - 0.005) {
                $hasBelowCost = true;
            }

            if (! $variant->is_variable
                && strtoupper((string) ($variant->currency ?? 'USD')) === 'USD'
                && (float) $variant->face_value > 0
                && $cost > (float) $variant->face_value + 0.01) {
                $hasCostOverFace = true;
            }

            if (abs($new - $old) > 0.005) {
                $rows[] = [
                    'product' => (string) $product->name,
                    'variant_id' => $variant->id,
                    'cost' => $cost,
                    'old' => $old,
                    'new' => $new,
                    'delta' => $new - $old,
                    'delta_pct' => $old > 0 ? (($new - $old) / $old) * 100 : 100.0,
                ];
            }
        }

        $product->pricing_basis = $originalBasis;

        return ['rows' => $rows, 'has_below_cost' => $hasBelowCost, 'has_cost_over_face' => $hasCostOverFace];
    }
}
