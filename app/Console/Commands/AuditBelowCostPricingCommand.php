<?php

namespace App\Console\Commands;

use App\Domain\Cart\Services\CartPricingService;
use App\Domain\Catalog\Support\ZenditCostBlocks;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Read-only, catalog-wide margin audit — the Stage 0 gate for the cost-anchored
 * floor rollout (config/pricing.php `floor_mode`). Four sections:
 *
 *   BELOW-COST   variants whose CURRENT (legacy) charged price is under the
 *                supplier's USD cost — actively losing money on every sale.
 *   WOULD-RAISE  variants the cost-anchored floor would reprice upward, with
 *                the delta. Small raises are the fix working; raises above
 *                --suspect-pct almost always mean corrupt cost data (e.g. a
 *                cost stored in local currency) and must be repaired BEFORE
 *                switching floor_mode to enforce, or customers see the spike.
 *   OVERRIDE     manual_retail_price_usd overrides sitting under the cost
 *                floor without allow_below_cost — each needs a human decision
 *                (raise it, or flag it as a deliberate loss-leader).
 *   ORDERS       paid order units in the look-back window whose USD revenue
 *                was below the snapshotted supplier cost — quantifies the
 *                actual bleed, not just the theoretical one.
 *
 * Exits non-zero when BELOW-COST or OVERRIDE findings exist so a scheduled run
 * can alert.
 */
class AuditBelowCostPricingCommand extends Command
{
    protected $signature = 'pricing:audit-below-cost
        {--all : Include unavailable variants and inactive products}
        {--orders-days=30 : Look-back window for the paid-order bleed scan}
        {--suspect-pct=25 : Raise percentage above which cost data is flagged as suspect}';

    protected $description = 'Report variants selling below supplier cost, prices the cost-anchored floor would raise, below-cost manual overrides, and recent paid orders sold below cost.';

    public function handle(CartPricingService $pricing): int
    {
        $legacyPricing = $pricing->withFloorMode('legacy');
        $enforcePricing = $pricing->withFloorMode('enforce');
        $suspectPct = (float) $this->option('suspect-pct');

        $belowCost = [];
        $wouldRaise = [];
        $overrides = [];
        $noCostData = 0;
        $variableAmount = 0;
        $scanned = 0;

        $query = ProductVariant::query()->with('product:id,name,provider_name,category_id');
        if (! $this->option('all')) {
            $query->where('is_available', true)
                ->whereHas('product', fn ($q) => $q->where('is_active', true));
        }

        $query->orderBy('id')->chunk(500, function ($variants) use (
            $legacyPricing, $enforcePricing, $suspectPct,
            &$belowCost, &$wouldRaise, &$overrides, &$noCostData, &$variableAmount, &$scanned
        ) {
            foreach ($variants as $variant) {
                $scanned++;
                $cost = (float) $variant->cost_price;

                if ($cost <= 0) {
                    // A variable-amount variant has no fixed cost by design - the
                    // customer picks the value and resolveRetailPrice() anchors the
                    // floor on that requested amount at cart time. Counting those as
                    // "without cost data" reads as a data gap and invites someone to
                    // hold off on enforce over 148 rows that are already covered.
                    if ($variant->is_variable) {
                        $variableAmount++;
                    } else {
                        $noCostData++;
                    }

                    continue;
                }

                $legacy = (float) $legacyPricing->calculatePricing($variant, 1)['unit_price_snapshot'];
                $enforced = (float) $enforcePricing->calculatePricing($variant, 1)['unit_price_snapshot'];

                // Two Zendit-specific annotations, per Zendit's documented
                // semantics (see ZenditCostBlocks). BAD-CCY marks a cost block
                // labeled in a non-wallet currency - documented as impossible,
                // so the stored USD cost is untrustworthy and the row is
                // suspect whatever its raise. COST>FACE marks offers that
                // genuinely cost more than the value they deliver (fee-loaded
                // US prepaid, Lebanese airtime): the loss is REAL, structural
                // to face-anchored pricing, and exactly what enforce fixes -
                // flagged so nobody mistakes those rows for corrupt data.
                $badCurrency = ZenditCostBlocks::hasWalletCurrencyViolation($variant);
                $costOverFace = ZenditCostBlocks::costExceedsFace($variant);

                if ($legacy < $cost - 0.005) {
                    $belowCost[] = [
                        'variant' => $variant,
                        'cost' => $cost,
                        'price' => $legacy,
                        'loss' => $cost - $legacy,
                        'bad_currency' => $badCurrency,
                        'cost_over_face' => $costOverFace,
                    ];
                }

                if ($enforced > $legacy + 0.005) {
                    $raisePct = $legacy > 0 ? (($enforced - $legacy) / $legacy) * 100 : 100.0;

                    $wouldRaise[] = [
                        'variant' => $variant,
                        'cost' => $cost,
                        'legacy' => $legacy,
                        'enforced' => $enforced,
                        'raise_pct' => $raisePct,
                        'suspect' => $raisePct > $suspectPct || $badCurrency,
                        'bad_currency' => $badCurrency,
                        'cost_over_face' => $costOverFace,
                    ];
                }

                $override = (float) ($variant->manual_retail_price_usd ?? 0);
                $costFloor = $enforcePricing->costFloor($cost);
                if ($override > 0 && $override < $costFloor && ! $variant->allow_below_cost) {
                    $overrides[] = [
                        'variant' => $variant,
                        'cost' => $cost,
                        'override' => $override,
                        'floor' => $costFloor,
                    ];
                }
            }
        });

        $this->renderBelowCost($belowCost);
        $this->renderWouldRaise($wouldRaise);
        $this->renderOverrides($overrides);
        $orderUnits = $this->renderOrderBleed((int) $this->option('orders-days'));

        $suspectCount = count(array_filter($wouldRaise, fn ($r) => $r['suspect']));

        $this->newLine();
        $this->info(sprintf(
            'Scanned %d variants: %d BELOW-COST, %d WOULD-RAISE (%d suspect cost data), %d OVERRIDE under floor, %d paid order units below cost.',
            $scanned, count($belowCost), count($wouldRaise), $suspectCount, count($overrides), $orderUnits
        ));

        // Kept off the line above deliberately: the combined string ran past 200
        // characters and the console wrapped it mid-figure, so a count could be
        // separated from its own label.
        $this->info(sprintf(
            'Cost coverage: %d MISSING cost data, %d variable-amount (floored on the requested value).',
            $noCostData, $variableAmount
        ));

        if ($noCostData > 0) {
            $this->warn(sprintf(
                '%d fixed-value variants carry no cost. The floor cannot protect those - it has nothing to anchor on - so they sell at an unknown margin whatever PRICING_FLOOR_MODE says.',
                $noCostData
            ));
        }

        if ($suspectCount > 0) {
            $this->warn('Repair the suspect-cost variants BEFORE setting PRICING_FLOOR_MODE=enforce — enforcing the floor on corrupt cost data raises storefront prices by the same corrupt factor.');
        }

        return (count($belowCost) > 0 || count($overrides) > 0) ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function renderBelowCost(array $rows): void
    {
        $this->section('SELLING BELOW COST (current price under supplier cost)', $rows, function (array $r) {
            return [
                $r['variant']->id,
                Str::limit($r['variant']->product?->name ?? '-', 34),
                $r['variant']->sku,
                number_format($r['cost'], 2),
                number_format($r['price'], 2),
                '-'.number_format($r['loss'], 2),
                // BAD-CCY: the cost side of this subtraction is untrustworthy.
                // COST>FACE: the loss is real and structural - the offer costs
                // more than the value it delivers, so face-anchored pricing
                // cannot help but sell it below cost.
                match (true) {
                    $r['bad_currency'] ?? false => 'BAD-CCY',
                    $r['cost_over_face'] ?? false => 'COST>FACE',
                    default => 'ok',
                },
            ];
        }, ['Variant', 'Product', 'SKU', 'Cost$', 'Charged$', 'Loss/unit$', 'Flag'], fn ($r) => -$r['loss']);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function renderWouldRaise(array $rows): void
    {
        $this->section('COST FLOOR WOULD RAISE PRICE (enforce mode delta)', $rows, function (array $r) {
            return [
                $r['variant']->id,
                Str::limit($r['variant']->product?->name ?? '-', 34),
                number_format($r['cost'], 2),
                number_format($r['legacy'], 2),
                number_format($r['enforced'], 2),
                '+'.number_format($r['raise_pct'], 1).'%',
                match (true) {
                    $r['bad_currency'] ?? false => 'BAD-CCY',
                    $r['suspect'] => 'SUSPECT-COST',
                    $r['cost_over_face'] ?? false => 'COST>FACE',
                    default => 'ok',
                },
            ];
        }, ['Variant', 'Product', 'Cost$', 'Now$', 'Floored$', 'Raise', 'Flag'], fn ($r) => -$r['raise_pct']);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function renderOverrides(array $rows): void
    {
        $this->section('MANUAL OVERRIDES UNDER THE COST FLOOR (no allow_below_cost)', $rows, function (array $r) {
            return [
                $r['variant']->id,
                Str::limit($r['variant']->product?->name ?? '-', 34),
                number_format($r['cost'], 2),
                number_format($r['override'], 2),
                number_format($r['floor'], 2),
            ];
        }, ['Variant', 'Product', 'Cost$', 'Override$', 'Floor$'], fn ($r) => $r['override'] - $r['floor']);
    }

    /**
     * Paid order units whose USD revenue fell below the snapshotted supplier
     * cost. subtotal_amount is display-currency; convert back through the
     * order's snapshotted exchange rate (Order::usdMarkup() is unusable here —
     * it collapses to 0 on coupon orders).
     */
    private function renderOrderBleed(int $days): int
    {
        $rows = [];

        OrderItem::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->where('provider_cost_usd', '>', 0)
            ->whereHas('order', fn ($q) => $q->where('payment_status', PaymentStatus::Paid->value))
            ->with('order:id,order_number,metadata')
            ->orderBy('id')
            ->chunk(500, function ($items) use (&$rows) {
                foreach ($items as $item) {
                    $rate = (float) ($item->order?->metadata['exchange_rate'] ?? 1.0) ?: 1.0;
                    $revenueUsd = (float) $item->subtotal_amount / $rate;
                    $cost = (float) $item->provider_cost_usd;

                    if ($revenueUsd < $cost - 0.005) {
                        $rows[] = [
                            'order' => $item->order?->order_number ?? $item->order_id,
                            'item' => $item->id,
                            'revenue' => $revenueUsd,
                            'cost' => $cost,
                        ];
                    }
                }
            });

        $this->section("PAID ORDER UNITS BELOW COST (last {$days} days)", $rows, function (array $r) {
            return [
                $r['order'],
                $r['item'],
                number_format($r['revenue'], 2),
                number_format($r['cost'], 2),
                '-'.number_format($r['cost'] - $r['revenue'], 2),
            ];
        }, ['Order', 'Item', 'Revenue$', 'Cost$', 'Loss$'], fn ($r) => $r['revenue'] - $r['cost']);

        return count($rows);
    }

    /**
     * Shared section renderer: header, sorted table (worst first), or an
     * all-clear line when the section is empty.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): array<int, string|int>  $toRow
     * @param  list<string>  $headers
     * @param  callable(array<string, mixed>): float  $sortBy
     */
    private function section(string $title, array $rows, callable $toRow, array $headers, callable $sortBy): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");

        if ($rows === []) {
            $this->info('  none');

            return;
        }

        usort($rows, fn ($a, $b) => $sortBy($a) <=> $sortBy($b));
        $this->table($headers, array_map($toRow, $rows));
    }
}
