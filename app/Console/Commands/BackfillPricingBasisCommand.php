<?php

namespace App\Console\Commands;

use App\Enums\PricingBasis;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Assigns every product WITHOUT an explicit pricing_basis the basis that
 * reproduces its current computed price — behaviour-preserving by
 * construction, verified by PricingBasisParityTest:
 *
 *   eSIMs                              -> provider_retail (face_value holds the
 *                                         provider's recommended retail)
 *   any USD variant with a face value  -> face_value (legacy heuristic used it)
 *   everything else                    -> supplier_cost (legacy fell back to cost)
 *
 * Non-USD variants under a face_value product are unaffected: the engine's
 * currency eligibility guard degrades them to cost exactly like the legacy
 * heuristic did. Products with a basis already set are never touched —
 * deliberate re-basing is `pricing:migrate-basis`, not this command.
 */
class BackfillPricingBasisCommand extends Command
{
    protected $signature = 'pricing:backfill-basis {--dry-run : Report what would be assigned without saving}';

    protected $description = 'Assign a behaviour-preserving pricing_basis to every product that has none.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counts = ['face_value' => 0, 'supplier_cost' => 0, 'provider_retail' => 0];
        $alreadySet = 0;

        Product::query()
            ->with(['category:id,slug', 'variants:id,product_id,currency,face_value'])
            ->orderBy('id')
            ->chunk(200, function ($products) use ($dryRun, &$counts, &$alreadySet) {
                foreach ($products as $product) {
                    if ($product->pricing_basis !== null) {
                        $alreadySet++;

                        continue;
                    }

                    $basis = $this->deriveLegacyBasis($product);
                    $counts[$basis->value]++;

                    if (! $dryRun) {
                        $product->update(['pricing_basis' => $basis]);
                    }
                }
            });

        $this->info(sprintf(
            '%s: %d face_value, %d supplier_cost, %d provider_retail assigned; %d already set (untouched).',
            $dryRun ? 'DRY RUN' : 'Backfilled',
            $counts['face_value'], $counts['supplier_cost'], $counts['provider_retail'], $alreadySet
        ));

        return self::SUCCESS;
    }

    private function deriveLegacyBasis(Product $product): PricingBasis
    {
        if ($product->category?->slug === 'esims') {
            return PricingBasis::ProviderRetail;
        }

        $hasUsdFace = $product->variants->contains(function ($variant) {
            $currency = strtoupper((string) ($variant->currency ?? 'USD'));

            return ($currency === 'USD' || $currency === '') && (float) $variant->face_value > 0;
        });

        return $hasUsdFace ? PricingBasis::FaceValue : PricingBasis::SupplierCost;
    }
}
