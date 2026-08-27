<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Providers\AiraloEsimProvider;
use App\Domain\Catalog\Services\AiraloEsimNormalizer;
use App\Domain\Catalog\Services\CatalogSyncService;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncAiraloEsimsCommand extends Command
{
    protected $signature = 'airalo:sync-esims {--page=1 : Page number to start syncing from}';

    protected $description = 'Sync the Airalo eSIM catalog synchronously, page by page';

    public function handle(): int
    {
        $syncService = new CatalogSyncService(new AiraloEsimProvider, new AiraloEsimNormalizer);

        $page = max(1, (int) $this->option('page'));
        $totalProcessed = 0;
        $totalFailed = 0;

        $this->info('Syncing Airalo eSIMs...');

        do {
            try {
                $result = $syncService->sync($page, 100);
            } catch (\Throwable $e) {
                // A whole page failing (API down, auth broken) is not something
                // a later page can recover from; report it with the exception
                // class so the scheduler log says more than "exit code 1".
                $this->error("Page {$page} failed: [".get_class($e)."] {$e->getMessage()}");
                Log::error('Airalo eSIM sync page failed', [
                    'page' => $page,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);

                return self::FAILURE;
            }

            $totalProcessed += $result['processed'];
            $totalFailed += $result['failed'];
            $this->line("  page {$page}: {$result['processed']} offers".($result['failed'] > 0 ? ", {$result['failed']} failed" : ''));
            $page++;

            // Be respectful to Airalo's rate limits
            if ($result['has_more']) {
                usleep(250000); // 250ms delay between pages
            }
        } while ($result['has_more']);

        $this->info("Done. Processed {$totalProcessed} total Airalo offers.");

        $airaloCount = ProductVariant::where('metadata->provider', 'airalo')->count();
        $this->info("Total Airalo variants in DB: {$airaloCount}");

        if ($totalFailed > 0) {
            $this->error("{$totalFailed} item(s) failed to sync; see laravel.log for each one.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
