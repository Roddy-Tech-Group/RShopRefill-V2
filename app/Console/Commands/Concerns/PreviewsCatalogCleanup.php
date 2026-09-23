<?php

namespace App\Console\Commands\Concerns;

use App\Domain\Catalog\Services\CatalogSyncRunService;
use App\Models\CatalogSyncRun;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * The --dry-run half of every catalog sync command: closes the run without
 * hiding anything and prints exactly which offers a real run would take off
 * sale, so a cleanup large enough to matter can be read before it is trusted.
 */
trait PreviewsCatalogCleanup
{
    private function reportCleanupPreview(CatalogSyncRunService $runs, CatalogSyncRun $run): void
    {
        $preview = $runs->previewCleanup($run);

        /** @var Collection<int, ProductVariant> $delist */
        $delist = $preview['delist'];
        /** @var Collection<int, Product> $dark */
        $dark = $preview['dark'];

        $this->newLine();
        $this->warn('DRY RUN: nothing was hidden, restored or put back on sale.');
        $this->line($run->summary());

        if ($preview['skipped'] !== null) {
            $this->newLine();
            $this->line('  The guards would have held this cleanup back:');
            $this->line('    '.$preview['skipped']->describe());
            $this->line("    {$delist->count()} offers the supplier no longer lists would have stayed on sale.");
            $this->newLine();
            $this->comment('Re-run with --force-cleanup only if you have checked the supplier really did drop them.');

            return;
        }

        if ($delist->isEmpty()) {
            $this->newLine();
            $this->info('  Nothing would be hidden: the supplier still lists every offer on sale.');

            return;
        }

        $this->newLine();
        $this->line("  Would be hidden ({$delist->count()}):");

        foreach ($delist->take(50) as $variant) {
            $this->line('    '.$variant->provider_offer_id.'  '.($variant->product?->name ?? 'unknown product'));
        }

        if ($delist->count() > 50) {
            $this->line('    ... and '.($delist->count() - 50).' more');
        }

        if ($dark->isNotEmpty()) {
            $this->newLine();
            $this->warn("  {$dark->count()} ".str('product')->plural($dark->count()).' would go off sale entirely:');

            foreach ($dark->take(30) as $product) {
                $this->line('    '.$product->name);
            }

            if ($dark->count() > 30) {
                $this->line('    ... and '.($dark->count() - 30).' more');
            }
        }

        if ($preview['restore'] > 0) {
            $this->newLine();
            $this->info("  {$preview['restore']} previously hidden offers would go back on sale:");

            foreach ($preview['back']->take(30) as $product) {
                $this->line('    '.$product->name.($product->offers_back > 1 ? "  ({$product->offers_back} amounts)" : ''));
            }

            if ($preview['back']->count() > 30) {
                $this->line('    ... and '.($preview['back']->count() - 30).' more');
            }
        }

        $this->newLine();
        $this->comment('Run the same command without --dry-run to apply it.');
    }
}
