<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PreviewsCatalogCleanup;
use App\Domain\Catalog\Enums\CatalogFeed;
use App\Domain\Catalog\Providers\ZenditTopupProvider;
use App\Domain\Catalog\Services\CatalogSyncRunService;
use App\Domain\Catalog\Services\CatalogSyncService;
use App\Domain\Catalog\Services\ZenditTopupNormalizer;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;

class SyncTopupsCommand extends Command
{
    use PreviewsCatalogCleanup;

    protected $signature = 'topups:sync
        {--page=1 : Page number to start syncing from (a partial run never hides offers)}
        {--force-cleanup : Hide offers Zendit no longer lists even when the shrink guards would hold back}
        {--dry-run : Report which offers the cleanup would hide, and change nothing}';

    protected $description = 'Sync the Zendit mobile top-up catalog synchronously, page by page, hide offers Zendit no longer lists, then report the operators pulled';

    public function handle(CatalogSyncRunService $runs): int
    {
        $syncService = new CatalogSyncService(new ZenditTopupProvider, new ZenditTopupNormalizer);

        $page = max(1, (int) $this->option('page'));
        $totalProcessed = 0;

        // Only a pass from the first page sees the whole feed, so only it may
        // decide what Zendit stopped listing.
        $run = $page === 1 ? $runs->start(CatalogFeed::ZenditTopups, (bool) $this->option('force-cleanup')) : null;

        $this->info('Syncing Zendit mobile top-ups...');

        $knownTotal = 0;
        $failedPages = [];

        while (true) {
            try {
                $result = $syncService->sync($page, 100, $run);

                $totalProcessed += $result['processed'];
                $knownTotal = $result['total'] ?: $knownTotal;
                $this->line("  page {$page}: {$result['processed']} offers  (running total {$totalProcessed} of {$result['total']})");
                $hasMore = $result['has_more'];
            } catch (\Throwable $e) {
                // A single bad page must NOT abort the whole catalog - skip it and keep
                // going so later pages (where most Data/Bundle plans live) still sync.
                $this->error("Page {$page} failed: {$e->getMessage()} - skipping.");
                $failedPages[] = $page;
                // We couldn't read this page's total/has_more; infer from the last
                // known total, with a small floor so an early failure still probes on.
                $hasMore = $knownTotal > 0 ? ($page * 100) < $knownTotal : ($page < 25);
            }

            $page++;

            if (! $hasMore) {
                break;
            }
        }

        $this->info("Done. Processed {$totalProcessed} offers.");

        if (! empty($failedPages)) {
            $this->warn('Skipped pages after retries: '.implode(', ', $failedPages).' - re-run topups:sync to backfill them.');
        }

        if ($run !== null) {
            if (! empty($failedPages)) {
                $runs->fail($run, 'Pages skipped: '.implode(', ', $failedPages));
                $this->info($run->refresh()->summary());
            } elseif ($this->option('dry-run')) {
                $this->reportCleanupPreview($runs, $run);
            } else {
                $runs->complete($run);
                $this->info($run->refresh()->summary());
            }
        }

        // Summary — operators are Products grouped under the mobile-airtime category.
        $category = Category::where('slug', 'mobile-airtime')->first();
        $operators = $category
            ? Product::where('category_id', $category->id)
                ->withCount('variants')
                ->orderBy('country_code')
                ->orderBy('name')
                ->get()
            : collect();

        $this->newLine();
        $this->info("Mobile top-up operators: {$operators->count()}");

        if ($operators->isNotEmpty()) {
            $this->table(
                ['Country', 'Operator', 'Amounts'],
                $operators->map(fn (Product $p) => [$p->country_code, $p->name, $p->variants_count])
            );
        }

        return self::SUCCESS;
    }
}
