<?php

namespace App\Console\Commands;

use App\Domain\Cart\Services\CartPricingService;
use App\Models\CurrencyRate;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Read-only pricing audit for gift cards (and utility bills, same feed). For each
 * variant it shows the native face value, the face in USD, the stored USD cost,
 * the price the customer is charged, and the retail/face ratio - then flags the
 * ones that look wrong so a mispricing can't hide in a large catalogue:
 *
 *   COST-IN-LOCAL  cost_price ≈ the native face on a non-USD card - the cost was
 *                  never converted to USD (the FX-overcharge bug). Repair by
 *                  re-syncing the offer or correcting cost_price by the rate.
 *   OVERPRICED     retail is >1.4x the USD face - usually the same un-converted cost.
 *   BELOW-FACE     retail is under the USD face - selling at a loss, usually a
 *                  wrong/stale exchange rate for that currency.
 *   NO-RATE        the card's currency has no active rate, so it can't be priced.
 */
class AuditGiftCardPricingCommand extends Command
{
    protected $signature = 'catalog:audit-gift-card-pricing {--all : List every variant, not only the flagged ones}';

    protected $description = 'List gift-card variants with USD face vs charged price and flag any that look mispriced.';

    public function handle(CartPricingService $pricing): int
    {
        $rates = CurrencyRate::where('is_active', true)->pluck('rate_per_usd', 'code')
            ->map(fn ($r) => (float) $r);

        $products = Product::query()
            ->whereHas('category', fn ($q) => $q->whereIn('slug', ['gift-cards', 'bill-payments']))
            ->with('variants')
            ->orderBy('name')
            ->get();

        $rows = [];
        $counts = ['COST-IN-LOCAL' => 0, 'OVERPRICED' => 0, 'BELOW-FACE' => 0, 'NO-RATE' => 0];

        foreach ($products as $product) {
            foreach ($product->variants as $v) {
                $cur = strtoupper((string) ($v->currency ?: 'USD'));
                $rate = $cur === 'USD' ? 1.0 : (float) ($rates[$cur] ?? 0);
                $faceNative = (float) ($v->face_value ?: $v->max_amount ?: 0);
                $faceUsd = $rate > 0 ? $faceNative / $rate : 0.0;
                $cost = (float) $v->cost_price;
                $retail = round((float) $pricing->calculatePricing($v, 1)['unit_price_snapshot'], 2);
                $ratio = $faceUsd > 0 ? $retail / $faceUsd : 0.0;

                $flag = 'ok';
                if ($rate <= 0) {
                    $flag = 'NO-RATE';
                } elseif ($cur !== 'USD' && $faceNative > 0 && abs($cost - $faceNative) / $faceNative < 0.05) {
                    $flag = 'COST-IN-LOCAL';
                } elseif ($ratio > 1.4) {
                    $flag = 'OVERPRICED';
                } elseif ($faceUsd > 0 && $ratio < 0.98) {
                    $flag = 'BELOW-FACE';
                }

                if ($flag !== 'ok') {
                    $counts[$flag]++;
                }

                if ($flag === 'ok' && ! $this->option('all')) {
                    continue;
                }

                $rows[] = [
                    Str::limit($product->name, 34),
                    $cur,
                    number_format($faceNative, 2),
                    $faceUsd > 0 ? number_format($faceUsd, 2) : '-',
                    number_format($cost, 2),
                    number_format($retail, 2),
                    $faceUsd > 0 ? number_format($ratio, 2).'x' : '-',
                    $flag,
                ];
            }
        }

        if ($rows) {
            $this->table(['Product', 'Cur', 'Face', 'Face$', 'Cost$', 'Retail$', 'Ratio', 'Flag'], $rows);
        }

        $this->newLine();
        $this->info(sprintf(
            'Flagged: %d COST-IN-LOCAL, %d OVERPRICED, %d BELOW-FACE, %d NO-RATE.',
            $counts['COST-IN-LOCAL'], $counts['OVERPRICED'], $counts['BELOW-FACE'], $counts['NO-RATE']
        ));
        if ($counts['COST-IN-LOCAL'] > 0 || $counts['OVERPRICED'] > 0) {
            $this->warn('COST-IN-LOCAL/OVERPRICED costs must be repaired (re-sync the offer from Zendit or correct cost_price by the currency rate) BEFORE enabling the cost-anchored floor — see `pricing:audit-below-cost` for the floor impact.');
        }
        if ($counts['BELOW-FACE'] > 0) {
            $this->warn('BELOW-FACE cards usually mean a stale/wrong exchange rate - check Rate Management.');
        }

        return self::SUCCESS;
    }
}
