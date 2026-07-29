<?php

namespace App\Jobs;

use App\Domain\Catalog\Providers\ZenditEsimProvider;
use App\Domain\Catalog\Services\CatalogSyncService;
use App\Domain\Catalog\Services\ZenditEsimNormalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncZenditEsimsJob implements ShouldQueue
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
        $provider = new ZenditEsimProvider;
        $normalizer = new ZenditEsimNormalizer;
        $syncService = new CatalogSyncService($provider, $normalizer);

        Log::info("Starting Zendit eSIM Sync for page {$this->page}");

        try {
            $result = $syncService->sync($this->page, 100);

            Log::info("Zendit eSIM Sync page {$this->page} completed. Processed {$result['processed']} items.");

            if ($result['has_more']) {
                self::dispatch($this->page + 1)->delay(now()->addSeconds(2));
            } else {
                Log::info('Zendit eSIM Sync fully completed.');
            }
        } catch (\Exception $e) {
            Log::error("Zendit eSIM Sync failed on page {$this->page}", [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
