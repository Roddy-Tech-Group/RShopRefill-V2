<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Providers\ProviderInterface;
use App\Models\CatalogSyncRun;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Log;

/**
 * High-level service to orchestrate catalog syncing from a provider.
 */
class CatalogSyncService
{
    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly CatalogNormalizerInterface $normalizer
    ) {}

    /**
     * Fetch raw data from the provider and normalize it into our domain models.
     *
     * With a run, every offer the page saved is stamped as seen by the run's
     * feed and the page is counted on the run, which is what lets the run hide
     * the offers it never saw once it completes.
     *
     * @return array{processed: int, failed: int, seen: int, has_more: bool, total: int}
     */
    public function sync(int $page = 1, int $limit = 100, ?CatalogSyncRun $run = null): array
    {
        $rawPayload = $this->provider->fetchCatalog($page, $limit);

        // Providers return different shapes:
        //   Zendit: { "total": int, "limit": int, "offset": int, "list": [ ...items... ] }
        //   Airalo: { "data": [ ...items... ], "links": {...}, "meta": { "current_page", "last_page", "total" } }
        $items = $rawPayload['list'] ?? $rawPayload['data'] ?? [];
        $meta = $rawPayload['meta'] ?? [];
        $total = $rawPayload['total'] ?? ($meta['total'] ?? ($meta['totalCount'] ?? 0));

        $processedItems = 0;
        $failedItems = 0;
        $savedOfferIds = [];

        // One bad item must not take the rest of the page (or the pages after
        // it) down with it. The failure is logged with enough context to find
        // the item, counted, and the sync moves on.
        foreach ($items as $index => $rawItem) {
            try {
                array_push($savedOfferIds, ...$this->normalizer->normalizeAndSave($rawItem, $this->provider->getProviderName()));
                $processedItems++;
            } catch (\Throwable $e) {
                $failedItems++;
                Log::error('Catalog sync item failed', [
                    'provider' => $this->provider->getProviderName(),
                    'page' => $page,
                    'index' => $index,
                    'item_title' => $rawItem['title'] ?? $rawItem['name'] ?? null,
                    'item_slug' => $rawItem['slug'] ?? null,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $savedOfferIds = array_values(array_unique($savedOfferIds));

        if ($run !== null) {
            $this->markSeen($run, $savedOfferIds);

            $run->increment('pages');
            $run->increment('offers_seen', count($savedOfferIds));
            $run->increment('items_failed', $failedItems);
        }

        // Prefer explicit page metadata (Airalo), then a "next" link, then fall back
        // to offset math against the total (Zendit).
        if (isset($meta['current_page'], $meta['last_page'])) {
            $hasMore = (int) $meta['current_page'] < (int) $meta['last_page'];
        } elseif (! empty($rawPayload['links']['next'])) {
            $hasMore = true;
        } else {
            $hasMore = $total > 0 && ($page * $limit) < $total;
        }

        return [
            'processed' => $processedItems,
            'failed' => $failedItems,
            'seen' => count($savedOfferIds),
            'has_more' => $hasMore,
            'total' => $total,
        ];
    }

    /**
     * Claim the saved offers for the run's feed and record that the supplier
     * still lists them. Written through the base query builder so updated_at
     * keeps meaning "the offer's data changed", not "a sync walked past it".
     *
     * A run only ever claims an offer no feed owns yet, or one its own feed
     * already owns. Offer ids are matched on their own, so without this a feed
     * that happened to carry an id another feed owns would take it over, and
     * the losing feed would then hide it as missing on its next clean run.
     *
     * @param  list<string>  $offerIds
     */
    private function markSeen(CatalogSyncRun $run, array $offerIds): void
    {
        foreach (array_chunk($offerIds, 500) as $chunk) {
            ProductVariant::query()
                ->whereIn('provider_offer_id', $chunk)
                ->where(fn ($query) => $query
                    ->whereNull('catalog_feed')
                    ->orWhere('catalog_feed', $run->feed->value))
                ->toBase()
                ->update([
                    'catalog_feed' => $run->feed->value,
                    'last_seen_at' => now(),
                ]);
        }
    }
}
