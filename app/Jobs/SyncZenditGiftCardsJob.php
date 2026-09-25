<?php

namespace App\Jobs;

use App\Domain\Catalog\Enums\CatalogFeed;
use App\Domain\Catalog\Providers\ZenditProvider;
use App\Domain\Catalog\Services\CatalogSyncRunService;
use App\Domain\Catalog\Services\CatalogSyncService;
use App\Domain\Catalog\Services\ZenditNormalizer;
use App\Models\CatalogSyncRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncZenditGiftCardsJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 600; // 10 minutes max for large catalogs

    public int $tries = 3;

    public int $maxExceptions = 1;

    /**
     * A run id travels with every page so the last page can hide the offers
     * no page saw. A first page without one opens the run and hands off, so
     * a retried page never opens a second run.
     */
    public function __construct(private int $page = 1, private ?int $runId = null)
    {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('zendit');
    }

    public function handle(CatalogSyncRunService $runs): void
    {
        if ($this->runId === null && $this->page === 1) {
            self::dispatch(1, $runs->start(CatalogFeed::ZenditVouchers)->id);

            return;
        }

        $run = $this->runId !== null ? CatalogSyncRun::find($this->runId) : null;

        $provider = new ZenditProvider;
        $normalizer = new ZenditNormalizer;
        $syncService = new CatalogSyncService($provider, $normalizer);

        Log::info("Starting Zendit Gift Card Sync for page {$this->page}");

        try {
            $result = $syncService->sync($this->page, 100, $run);

            Log::info("Zendit Gift Card Sync page {$this->page} completed. Processed {$result['processed']} items.");

            if ($result['has_more']) {
                self::dispatch($this->page + 1, $this->runId)->delay(now()->addSeconds(2)); // Dispatch next page with delay
            } else {
                Log::info('Zendit Gift Card Sync fully completed.');

                if ($run !== null) {
                    $runs->complete($run);
                }

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

    public function failed(?Throwable $exception): void
    {
        if ($this->runId !== null && ($run = CatalogSyncRun::find($this->runId))) {
            app(CatalogSyncRunService::class)->fail($run, "Page {$this->page}: ".($exception?->getMessage() ?? 'job failed'));
        }
    }
}
