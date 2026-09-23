<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Enums\CatalogFeed;
use App\Domain\Catalog\Providers\AiraloEsimProvider;
use App\Domain\Catalog\Services\AiraloEsimNormalizer;
use App\Domain\Catalog\Services\CatalogSyncRunService;
use App\Domain\Catalog\Services\CatalogSyncService;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncAiraloEsimsCommand extends Command
{
    protected $signature = 'airalo:sync-esims
        {--page=1 : Page number to start syncing from (a partial run never hides packages)}
        {--force-cleanup : Hide packages Airalo no longer lists even when the shrink guards would hold back}';

    protected $description = 'Sync the Airalo eSIM catalog synchronously, page by page, then hide packages Airalo no longer lists';

    public function handle(CatalogSyncRunService $runs): int
    {
        $syncService = new CatalogSyncService(new AiraloEsimProvider, new AiraloEsimNormalizer);

        $page = max(1, (int) $this->option('page'));
        $totalProcessed = 0;
        $totalFailed = 0;

        // Only a pass from the first page sees the whole feed, so only it may
        // decide what Airalo stopped listing.
        $run = $page === 1 ? $runs->start(CatalogFeed::AiraloEsims, (bool) $this->option('force-cleanup')) : null;

        $this->info('Syncing Airalo eSIMs...');

        do {
            try {
                $result = $syncService->sync($page, 100, $run);
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

                if ($run !== null) {
                    $runs->fail($run, "Page {$page}: {$e->getMessage()}");
                }

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

        if ($run !== null) {
            $this->info($runs->complete($run)->summary());
        }

        if ($totalFailed > 0) {
            $this->error("{$totalFailed} item(s) failed to sync; see laravel.log for each one.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
