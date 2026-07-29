<?php

namespace App\Jobs;

use App\Domain\Catalog\Providers\ZenditProvider;
use App\Domain\Catalog\Services\CatalogSyncService;
use App\Domain\Catalog\Services\ZenditNormalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncZenditGiftCardsJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 600; // 10 minutes max for large catalogs

    public int $tries = 3;

    public int $maxExceptions = 1;

    public function __construct(private int $page = 1)
    {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('zendit');
    }

    public function handle(): void
    {
        $provider = new ZenditProvider;
        $normalizer = new ZenditNormalizer;
        $syncService = new CatalogSyncService($provider, $normalizer);

        Log::info("Starting Zendit Gift Card Sync for page {$this->page}");

        try {
            $result = $syncService->sync($this->page, 100);

            Log::info("Zendit Gift Card Sync page {$this->page} completed. Processed {$result['processed']} items.");

            if ($result['has_more']) {
                self::dispatch($this->page + 1)->delay(now()->addSeconds(2)); // Dispatch next page with delay
            } else {
                Log::info('Zendit Gift Card Sync fully completed.');

                // Catalog is fully populated — now hydrate brand assets (logos, hero
                // art, brand colour, redemption text) from the /brands/* endpoints.
                // This MUST run after the catalog so every Product + brand_key exists
                // for the brand sync to match on.
                SyncZenditBrandsJob::dispatch();
            }
        } catch (\Exception $e) {
            Log::error("Zendit Gift Card Sync failed on page {$this->page}", [
                'error' => $e->getMessage(),
            ]);

            // Re-throw to trigger job failure and retry mechanisms
            throw $e;
        }
    }
}
