<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Providers\AiraloEsimProvider;
use App\Domain\Catalog\Services\AiraloEsimNormalizer;
use App\Domain\Catalog\Services\CatalogSyncService;
use App\Models\ProductVariant;
use Illuminate\Console\Command;

class SyncAiraloEsimsCommand extends Command
{
    protected $signature = 'airalo:sync-esims {--page=1 : Page number to start syncing from}';

    protected $description = 'Sync the Airalo eSIM catalog synchronously, page by page';

    public function handle(): int
    {
        $syncService = new CatalogSyncService(new AiraloEsimProvider, new AiraloEsimNormalizer);

        $page = max(1, (int) $this->option('page'));
        $totalProcessed = 0;

        $this->info('Syncing Airalo eSIMs...');

        do {
            try {
                $result = $syncService->sync($page, 100);
            } catch (\Throwable $e) {
                $this->error("Page {$page} failed: {$e->getMessage()}");

                return self::FAILURE;
            }

            $totalProcessed += $result['processed'];
            $this->line("  page {$page}: {$result['processed']} offers");
            $page++;

            // Be respectful to Airalo's rate limits
            if ($result['has_more']) {
                usleep(250000); // 250ms delay between pages
            }
        } while ($result['has_more']);

        $this->info("Done. Processed {$totalProcessed} total Airalo offers.");

        $airaloCount = ProductVariant::where('metadata->provider', 'airalo')->count();
        $this->info("Total Airalo variants in DB: {$airaloCount}");

        return self::SUCCESS;
    }
}
