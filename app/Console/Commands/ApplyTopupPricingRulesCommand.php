<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Applies the agreed mobile-airtime markup structure (Sep 2026 pricing review):
 *
 *   category default  8%  - international topups. Zendit's own suggested retail
 *                           runs a median ~11% above our cost across the fixed
 *                           catalog, so 8% stays under the market reference
 *                           nearly everywhere while ending the accidental 2%.
 *   Nigerian pins     2%  - product-level rules for every NG operator. Zendit
 *                           charges at/above face for NG airtime, so any higher
 *                           markup only widens the gap to local VTU apps; 2% is
 *                           the deliberate convenience margin.
 *
 * Fee-loaded prepaid (US/Caribbean plans whose supplier cost sits at or above
 * the printed face value) deliberately rides the 8% default too: Maxwell's
 * call, 2026-09-02. At 2% a $49 plan earned $0.98, which transaction fees
 * alone can swallow; being visibly above the printed price on a product that
 * is already above it is the acceptable cost of an actual margin. The enforce
 * floor guarantees nothing sells below cost regardless.
 *
 * Idempotent: re-running updates the same rows and never duplicates. Re-run
 * after catalog syncs introduce new Nigerian operators, since new products
 * fall to the 8% category default until pinned.
 */
class ApplyTopupPricingRulesCommand extends Command
{
    private const INTERNATIONAL_PERCENT = 8.0;

    private const PINNED_PERCENT = 2.0;

    protected $signature = 'pricing:apply-topup-rules {--dry-run : Report what would change without saving}';

    protected $description = 'Apply the mobile-airtime markup structure: 8% category default with 2% pins for Nigerian operators.';

    public function handle(): int
    {
        $category = Category::where('slug', 'mobile-airtime')->first();

        if ($category === null) {
            $this->error('Category mobile-airtime not found; nothing applied.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $pinned = Product::query()
            ->where('category_id', $category->id)
            ->where('country_code', 'NG')
            ->orderBy('id')
            ->get(['id', 'name']);

        $categoryRule = PricingRule::whereNull('product_id')
            ->whereNull('subcategory_id')
            ->where('category_id', $category->id)
            ->whereNull('provider_name')
            ->first();

        $this->info(sprintf(
            'Category rule: %s -> %.1f%%',
            $categoryRule ? sprintf('%.1f%% (%s)', (float) $categoryRule->markup_value, $categoryRule->markup_type) : 'none',
            self::INTERNATIONAL_PERCENT,
        ));

        $this->info(sprintf(
            'Product pins at %.1f%%: %d Nigerian operators.',
            self::PINNED_PERCENT,
            $pinned->count(),
        ));

        if ($dryRun) {
            $this->info('DRY RUN: no rules written.');

            return self::SUCCESS;
        }

        PricingRule::updateOrCreate(
            [
                'category_id' => $category->id,
                'subcategory_id' => null,
                'product_id' => null,
                'provider_name' => null,
            ],
            [
                'markup_type' => 'percentage',
                'markup_value' => self::INTERNATIONAL_PERCENT,
                'is_active' => true,
            ],
        );

        foreach ($pinned as $product) {
            PricingRule::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'provider_name' => null,
                ],
                [
                    'category_id' => null,
                    'subcategory_id' => null,
                    'markup_type' => 'percentage',
                    'markup_value' => self::PINNED_PERCENT,
                    'is_active' => true,
                ],
            );
        }

        $this->info('Applied. The pricing cache is busted automatically; prices are live now.');
        $this->line('Re-run this command after catalog syncs add new Nigerian operators.');

        return self::SUCCESS;
    }
}
