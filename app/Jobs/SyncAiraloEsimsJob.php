<?php

namespace App\Jobs;

use App\Domain\Catalog\Enums\CatalogFeed;
use App\Domain\Catalog\Providers\AiraloEsimProvider;
use App\Domain\Catalog\Services\AiraloEsimNormalizer;
use App\Domain\Catalog\Services\CatalogSyncRunService;
use App\Domain\Catalog\Services\CatalogSyncService;
use App\Models\CatalogSyncRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncAiraloEsimsJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 600;

    /**
     * A run id travels with every page so the last page can hide the offers
     * no page saw. A first page without one opens the run and hands off, so
     * a retried page never opens a second run.
     */
    public function __construct(private int $page = 1, private ?int $runId = null) {}

    public function handle(CatalogSyncRunService $runs): void
    {
        if ($this->runId === null && $this->page === 1) {
            self::dispatch(1, $runs->start(CatalogFeed::AiraloEsims)->id)->onQueue('airalo');

            return;
        }

        $run = $this->runId !== null ? CatalogSyncRun::find($this->runId) : null;

        $provider = new AiraloEsimProvider;
        $normalizer = new AiraloEsimNormalizer;
        $syncService = new CatalogSyncService($provider, $normalizer);

        Log::info("Starting Airalo eSIM Sync for page {$this->page}");

        try {
            $result = $syncService->sync($this->page, 100, $run);

            Log::info("Airalo eSIM Sync page {$this->page} completed. Processed {$result['processed']} items.");

            if ($result['has_more']) {
                self::dispatch($this->page + 1, $this->runId)->onQueue('airalo')->delay(now()->addSeconds(2));
            } else {
                Log::info('Airalo eSIM Sync fully completed.');

                if ($run !== null) {
                    $runs->complete($run);
                }
            }
        } catch (\Exception $e) {
            Log::error("Airalo eSIM Sync failed on page {$this->page}", [
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
