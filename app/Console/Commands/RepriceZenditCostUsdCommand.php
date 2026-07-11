<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Services\ConvertsCostToUsd;
use App\Models\ProductVariant;
use Illuminate\Console\Command;

/**
 * One-off (re-runnable) backfill for the Zendit cost-currency bug: cost_price was
 * stored in the card's own currency instead of USD, so every non-USD gift card /
 * top-up was overpriced by its FX rate. This re-derives cost_price in USD from the
 * cost block preserved in each variant's stored Zendit metadata, converting by the
 * variant's currency.
 *
 * Idempotent: it always recomputes from the raw metadata, never from the current
 * cost_price, so running it twice (or after a corrected re-sync) is a no-op. USD
 * variants convert by rate 1.0 and are left untouched.
 */
class RepriceZenditCostUsdCommand extends Command
{
    use ConvertsCostToUsd;

    protected $signature = 'zendit:reprice-cost-usd {--dry-run : Report what would change without writing}';

    protected $description = 'Re-derive cost_price in USD for Zendit variants whose cost was stored in the card currency (idempotent, from stored metadata).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $scanned = $repriced = $unchanged = $noBlock = 0;

        ProductVariant::query()
            ->whereHas('product', fn ($q) => $q->where('provider_name', 'zendit'))
            ->whereNotNull('metadata')
            ->chunkById(500, function ($variants) use (&$scanned, &$repriced, &$unchanged, &$noBlock, $dry) {
                foreach ($variants as $variant) {
                    $scanned++;
                    $meta = (array) ($variant->metadata ?? []);
                    // Gift cards / top-ups store the raw offer at the top level;
                    // eSIMs nest it under raw_payload.
                    $cost = $meta['cost'] ?? ($meta['raw_payload']['cost'] ?? null);

                    if (! is_array($cost) || ! isset($cost['fixed'])) {
                        $noBlock++;

                        continue;
                    }

                    $div = ((float) ($cost['currencyDivisor'] ?? 100)) ?: 100;
                    $local = (float) $cost['fixed'] / $div;
                    $newCost = $this->costToUsd($local, (string) $variant->currency);
                    $old = round((float) $variant->cost_price, 4);

                    if (abs($newCost - $old) < 0.0001) {
                        $unchanged++;

                        continue;
                    }

                    $this->line(sprintf('  variant %s [%s] %.4f -> %.4f USD', $variant->id, $variant->currency, $old, $newCost));

                    if (! $dry) {
                        $variant->forceFill(['cost_price' => $newCost])->save();
                    }
                    $repriced++;
                }
            });

        $this->newLine();
        $this->info(($dry ? '[DRY RUN] ' : '')."scanned={$scanned} repriced={$repriced} unchanged={$unchanged} no-cost-block={$noBlock}");

        return self::SUCCESS;
    }
}
