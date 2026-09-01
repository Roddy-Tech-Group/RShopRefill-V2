<?php

namespace App\Console\Commands;

use App\Enums\PricingBasis;
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
 *   face-basis pins   2%  - product-level rules for face_value-basis products
 *                           (US/Caribbean prepaid priced at printed face by the
 *                           whole market); 8% on face would overshoot visibly.
 *
 * Idempotent: re-running updates the same rows and never duplicates. Re-run
 * after catalog syncs introduce new NG or face-basis topup products, since new
 * products fall to the 8% category default until pinned.
 */
class ApplyTopupPricingRulesCommand extends Command
{
    private const INTERNATIONAL_PERCENT = 8.0;

    private const PINNED_PERCENT = 2.0;

    protected $signature = 'pricing:apply-topup-rules {--dry-run : Report what would change without saving}';

    protected $description = 'Apply the mobile-airtime markup structure: 8% category default with 2% pins for Nigerian operators and face-value prepaid products.';

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
            ->where(function ($query) {
                $query->where('country_code', 'NG')
                    ->orWhere('pricing_basis', PricingBasis::FaceValue);
            })
            ->orderBy('id')
            ->get(['id', 'name', 'country_code', 'pricing_basis']);

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

        $nigerian = $pinned->where('country_code', 'NG');
        $faceBasis = $pinned->where('country_code', '!=', 'NG');
        $this->info(sprintf(
            'Product pins at %.1f%%: %d Nigerian operators, %d face-basis prepaid products.',
            self::PINNED_PERCENT,
            $nigerian->count(),
            $faceBasis->count(),
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
        $this->line('Re-run this command after catalog syncs add new NG or face-basis topup products.');

        return self::SUCCESS;
    }
}
