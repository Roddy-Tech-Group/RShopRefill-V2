<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PreviewsCatalogCleanup;
use App\Domain\Catalog\Enums\CatalogFeed;
use App\Domain\Catalog\Providers\ZenditProvider;
use App\Domain\Catalog\Services\CatalogSyncRunService;
use App\Domain\Catalog\Services\CatalogSyncService;
use App\Domain\Catalog\Services\ZenditNormalizer;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Console\Command;

class SyncGiftCardsCommand extends Command
{
    use PreviewsCatalogCleanup;

    protected $signature = 'giftcards:sync
        {--page=1 : Page number to start syncing from (a partial run never hides offers)}
        {--force-cleanup : Hide offers Zendit no longer lists even when the shrink guards would hold back}
        {--dry-run : Report which offers the cleanup would hide, and change nothing}';

    protected $description = 'Sync the Zendit gift-card (/vouchers/offers) catalog synchronously, page by page, hide offers Zendit no longer lists, then report counts';

    public function handle(CatalogSyncRunService $runs): int
    {
        $syncService = new CatalogSyncService(new ZenditProvider, new ZenditNormalizer);

        $page = max(1, (int) $this->option('page'));
        $totalProcessed = 0;

        // Only a pass from the first page sees the whole feed, so only it may
        // decide what Zendit stopped listing.
        $run = $page === 1 ? $runs->start(CatalogFeed::ZenditVouchers, (bool) $this->option('force-cleanup')) : null;

        $this->info('Syncing Zendit gift cards (/vouchers/offers)...');

        do {
            try {
                $result = $syncService->sync($page, 100, $run);
            } catch (\Throwable $e) {
                $this->error("Page {$page} failed: {$e->getMessage()}");

                if ($run !== null) {
                    $runs->fail($run, "Page {$page}: {$e->getMessage()}");
                }

                return self::FAILURE;
            }

            $totalProcessed += $result['processed'];
            $this->line("  page {$page}: {$result['processed']} offers  (running total {$totalProcessed} of {$result['total']})");
            $page++;
        } while ($result['has_more']);

        $this->info("Done. Processed {$totalProcessed} offers.");

        if ($run !== null) {
            if ($this->option('dry-run')) {
                $this->reportCleanupPreview($runs, $run);
            } else {
                $this->info($runs->complete($run)->summary());
            }
        }

        // Breakdown — offers map to variants; products are brand x country rows.
        $giftCat = Category::where('slug', 'gift-cards')->first();
        $billCat = Category::where('slug', 'bill-payments')->first();

        $this->newLine();
        $this->info('Catalog now holds:');
        $this->line('  Gift-card products:    '.Product::where('category_id', $giftCat?->id ?? 0)->count().'  (brand x country rows)');
        $this->line('  Gift-card brands:      '.Product::where('category_id', $giftCat?->id ?? 0)->whereNotNull('brand_key')->distinct()->count('brand_key').'  (1 card per brand — what the storefront shows)');
        $this->line('  Bill-payment products: '.Product::where('category_id', $billCat?->id ?? 0)->count());
        $this->line('  Total products:        '.Product::count());
        $this->line('  Total variants:        '.ProductVariant::count());
        $this->newLine();
        $this->comment('Logos/redemption text come from a separate brand sync (SyncZenditBrandsJob).');

        return self::SUCCESS;
    }
}
