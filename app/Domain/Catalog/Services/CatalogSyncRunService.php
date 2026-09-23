<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Enums\CatalogCleanupSkipReason;
use App\Domain\Catalog\Enums\CatalogFeed;
use App\Domain\Catalog\Enums\CatalogSyncRunStatus;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Models\CatalogSyncRun;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Owns the lifecycle of a catalog sync run and the cleanup that ends it.
 *
 * A supplier dropping an offer never tells us; the offer just stops appearing
 * in its feed. Without this, a dropped offer stayed on sale indefinitely and
 * the first sign was a paid order the supplier refused ("offer not found").
 * A run that walks the whole feed without error knows exactly which offers the
 * supplier still lists, so everything else it owns can come off sale, and
 * anything it previously hid that came back is back on sale.
 */
class CatalogSyncRunService
{
    public function __construct(private readonly OperationalAlerter $alerter) {}

    public function start(CatalogFeed $feed, bool $forceCleanup = false): CatalogSyncRun
    {
        return CatalogSyncRun::create([
            'feed' => $feed,
            'status' => CatalogSyncRunStatus::Running,
            'force_cleanup' => $forceCleanup,
            'started_at' => now(),
        ]);
    }

    /**
     * Close a run that could not see the whole feed. Nothing is hidden: offers
     * on the pages it never reached are indistinguishable from dropped ones.
     */
    public function fail(CatalogSyncRun $run, string $reason): void
    {
        $run->refresh();

        if (! $run->isRunning()) {
            return;
        }

        $run->update([
            'status' => CatalogSyncRunStatus::Failed,
            'finished_at' => now(),
            'failure_reason' => $reason,
        ]);

        Log::warning('Catalog sync run failed; no offers hidden', [
            'run_id' => $run->id,
            'feed' => $run->feed->value,
            'reason' => $reason,
        ]);
    }

    /**
     * Close a run that walked the whole feed, then hide what the supplier no
     * longer lists and restore what it listed again, unless a guard says the
     * feed itself looks wrong.
     */
    public function complete(CatalogSyncRun $run): CatalogSyncRun
    {
        $run->refresh();

        if (! $run->isRunning()) {
            return $run;
        }

        $liveCount = $this->feedVariants($run)->where('is_available', true)->count();
        $missingCount = $this->missingVariants($run)->count();
        $previousRun = $this->previousCompletedRun($run);

        $skipReason = $this->skipReason($run, $previousRun, $liveCount, $missingCount);

        if ($skipReason !== null) {
            $run->update([
                'status' => CatalogSyncRunStatus::Completed,
                'finished_at' => now(),
                'cleanup' => 'skipped',
                'cleanup_skipped_reason' => $skipReason,
            ]);

            $this->alertCleanupSkipped($run, $skipReason, $previousRun, $liveCount, $missingCount);

            return $run;
        }

        [$delisted, $restored, $wentDark] = DB::transaction(fn () => $this->applyCleanup($run));

        $run->update([
            'status' => CatalogSyncRunStatus::Completed,
            'finished_at' => now(),
            'cleanup' => 'applied',
            'offers_delisted' => $delisted,
            'offers_restored' => $restored,
        ]);

        Log::info('Catalog sync cleanup applied', [
            'run_id' => $run->id,
            'feed' => $run->feed->value,
            'offers_seen' => $run->offers_seen,
            'offers_delisted' => $delisted,
            'offers_restored' => $restored,
            'products_went_dark' => $wentDark->count(),
        ]);

        if ($wentDark->isNotEmpty()) {
            $this->alertProductsWentDark($run, $wentDark);
        }

        return $run;
    }

    /**
     * @return array{0: int, 1: int, 2: Collection<int, Product>}
     */
    private function applyCleanup(CatalogSyncRun $run): array
    {
        $missing = $this->missingVariants($run)->get(['id', 'product_id']);

        foreach ($missing->pluck('id')->chunk(500) as $chunk) {
            ProductVariant::query()->whereIn('id', $chunk)->toBase()->update([
                'is_available' => false,
                'delisted_at' => now(),
            ]);
        }

        // A returning offer was already switched back on by its normalizer when
        // the run saw it; clearing the marker closes the delisting record.
        $returned = $this->feedVariants($run)
            ->whereNotNull('delisted_at')
            ->where('last_seen_at', '>=', $run->started_at);

        $restored = (clone $returned)->where('is_available', true)->count();
        $returned->toBase()->update(['delisted_at' => null]);

        $wentDark = Product::query()
            ->whereIn('id', $missing->pluck('product_id')->unique())
            ->where('is_active', true)
            ->whereDoesntHave('variants', fn (Builder $variants) => $variants->where('is_available', true))
            ->orderBy('name')
            ->get(['id', 'name']);

        return [$missing->count(), $restored, $wentDark];
    }

    private function skipReason(
        CatalogSyncRun $run,
        ?CatalogSyncRun $previousRun,
        int $liveCount,
        int $missingCount,
    ): ?CatalogCleanupSkipReason {
        // Never overridable: an offer that failed to save was never marked as
        // seen, and an empty feed says nothing about what the supplier sells.
        if ($run->items_failed > 0) {
            return CatalogCleanupSkipReason::ItemsFailed;
        }

        if ($run->offers_seen === 0) {
            return CatalogCleanupSkipReason::FeedShrank;
        }

        if ($run->force_cleanup) {
            return null;
        }

        $minFeedRatio = (float) config('catalog.sync_cleanup.min_feed_ratio', 0.7);

        if ($previousRun !== null && $run->offers_seen < $previousRun->offers_seen * $minFeedRatio) {
            return CatalogCleanupSkipReason::FeedShrank;
        }

        $maxDelistRatio = (float) config('catalog.sync_cleanup.max_delist_ratio', 0.3);

        if ($liveCount > 0 && $missingCount / $liveCount > $maxDelistRatio) {
            return CatalogCleanupSkipReason::TooManyMissing;
        }

        return null;
    }

    /**
     * Every variant this run's feed owns.
     *
     * @return Builder<ProductVariant>
     */
    private function feedVariants(CatalogSyncRun $run): Builder
    {
        return ProductVariant::query()->where('catalog_feed', $run->feed->value);
    }

    /**
     * On-sale variants of the feed that this run never saw.
     *
     * @return Builder<ProductVariant>
     */
    private function missingVariants(CatalogSyncRun $run): Builder
    {
        return $this->feedVariants($run)
            ->where('is_available', true)
            ->where(fn (Builder $query) => $query
                ->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<', $run->started_at));
    }

    private function previousCompletedRun(CatalogSyncRun $run): ?CatalogSyncRun
    {
        return CatalogSyncRun::query()
            ->where('feed', $run->feed->value)
            ->where('status', CatalogSyncRunStatus::Completed->value)
            ->whereKeyNot($run->id)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();
    }

    private function alertCleanupSkipped(
        CatalogSyncRun $run,
        CatalogCleanupSkipReason $reason,
        ?CatalogSyncRun $previousRun,
        int $liveCount,
        int $missingCount,
    ): void {
        // A failed item is already a known defect in our own parsing; it does
        // not put anything wrong on sale that was not there before, so it goes
        // to the working-day channel. A short feed may be a supplier outage or
        // a real mass removal, and someone should look within hours.
        $severity = $reason === CatalogCleanupSkipReason::ItemsFailed ? AlertSeverity::Sev3 : AlertSeverity::Sev2;

        $forceCommand = $this->forceCleanupCommand($run->feed);

        $this->alerter->send(
            severity: $severity,
            key: "catalog-sync:{$run->feed->value}:cleanup-skipped:{$reason->value}",
            title: "{$run->feed->label()} sync did not hide missing offers",
            message: $reason->describe()." {$missingCount} offers the supplier no longer lists are still on sale until this is resolved.",
            context: [
                'run_id' => $run->id,
                'offers_seen' => $run->offers_seen,
                'previous_run_offers_seen' => $previousRun?->offers_seen,
                'items_failed' => $run->items_failed,
                'live_offers' => $liveCount,
                'missing_offers' => $missingCount,
            ],
            runbook: $reason === CatalogCleanupSkipReason::ItemsFailed || $forceCommand === null
                ? 'Check laravel.log for "Catalog sync item failed" and the supplier feed; the next clean run hides the missing offers.'
                : "Compare the supplier dashboard with the missing offers. If the removals are real, run `{$forceCommand}`.",
        );
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function alertProductsWentDark(CatalogSyncRun $run, Collection $products): void
    {
        $names = $products->pluck('name');
        $listed = $names->take(15)->implode(', ').($names->count() > 15 ? ' and '.($names->count() - 15).' more' : '');

        $this->alerter->send(
            severity: AlertSeverity::Sev2,
            key: "catalog-sync:{$run->feed->value}:went-dark:".sha1($products->pluck('id')->implode(',')),
            title: "{$run->feed->label()}: {$names->count()} ".str('product')->plural($names->count()).' went off sale',
            message: "The supplier stopped listing every amount of: {$listed}. They show as out of stock now and come back on their own when the supplier lists them again.",
            context: [
                'run_id' => $run->id,
                'offers_hidden_this_run' => $run->offers_delisted,
            ],
            runbook: 'Ask the supplier account manager whether the removal is temporary if any of these matter commercially.',
        );
    }

    private function forceCleanupCommand(CatalogFeed $feed): ?string
    {
        return match ($feed) {
            CatalogFeed::ZenditVouchers => 'php artisan zendit:sync-giftcards --force-cleanup',
            CatalogFeed::ZenditTopups => 'php artisan topups:sync --force-cleanup',
            CatalogFeed::AiraloEsims => 'php artisan airalo:sync-esims --force-cleanup',
            CatalogFeed::ZenditEsims => null,
        };
    }
}
