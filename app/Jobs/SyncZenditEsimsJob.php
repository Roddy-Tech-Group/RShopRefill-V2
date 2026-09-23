<?php

namespace App\Jobs;

use App\Domain\Catalog\Enums\CatalogFeed;
use App\Domain\Catalog\Providers\ZenditEsimProvider;
use App\Domain\Catalog\Services\CatalogSyncRunService;
use App\Domain\Catalog\Services\CatalogSyncService;
use App\Domain\Catalog\Services\ZenditEsimNormalizer;
use App\Models\CatalogSyncRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncZenditEsimsJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 600;

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
            self::dispatch(1, $runs->start(CatalogFeed::ZenditEsims)->id);

            return;
        }

        $run = $this->runId !== null ? CatalogSyncRun::find($this->runId) : null;

        $provider = new ZenditEsimProvider;
        $normalizer = new ZenditEsimNormalizer;
        $syncService = new CatalogSyncService($provider, $normalizer);

        Log::info("Starting Zendit eSIM Sync for page {$this->page}");

        try {
            $result = $syncService->sync($this->page, 100, $run);

            Log::info("Zendit eSIM Sync page {$this->page} completed. Processed {$result['processed']} items.");

            if ($result['has_more']) {
                self::dispatch($this->page + 1, $this->runId)->delay(now()->addSeconds(2));
            } else {
                Log::info('Zendit eSIM Sync fully completed.');

                if ($run !== null) {
                    $runs->complete($run);
                }
            }
        } catch (\Exception $e) {
            Log::error("Zendit eSIM Sync failed on page {$this->page}", [
                'error' => $e->getMessage(),
            ]);
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
