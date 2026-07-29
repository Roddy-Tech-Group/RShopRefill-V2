<?php

namespace App\Jobs;

use App\Domain\Catalog\Providers\ZenditTopupProvider;
use App\Domain\Catalog\Services\CatalogSyncService;
use App\Domain\Catalog\Services\ZenditTopupNormalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncZenditTopupsJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 600;

    public int $tries = 3;

    public int $maxExceptions = 1;

    public function __construct(private int $page = 1)
    {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('zendit');
    }

    public function handle(): void
    {
        $provider = new ZenditTopupProvider;
        $normalizer = new ZenditTopupNormalizer;
        $syncService = new CatalogSyncService($provider, $normalizer);

        Log::info("Starting Zendit Top-up Sync for page {$this->page}");

        try {
            $result = $syncService->sync($this->page, 100);

            Log::info("Zendit Top-up Sync page {$this->page} completed. Processed {$result['processed']} items.");

            if ($result['has_more']) {
                self::dispatch($this->page + 1)->delay(now()->addSeconds(2));
            } else {
                Log::info('Zendit Top-up Sync fully completed.');
            }
        } catch (\Exception $e) {
            Log::error("Zendit Top-up Sync failed on page {$this->page}", [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
