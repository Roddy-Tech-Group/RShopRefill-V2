<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Providers\ProviderInterface;
use App\Support\GiftCardCategories;
use App\Support\TaggedCache;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;
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
     * @return array{processed: int, failed: int, has_more: bool, total: int}
     */
    public function sync(int $page = 1, int $limit = 100): array
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

        // One bad item must not take the rest of the page (or the pages after
        // it) down with it. The failure is logged with enough context to find
        // the item, counted, and the sync moves on.
        foreach ($items as $index => $rawItem) {
            try {
                $this->normalizer->normalizeAndSave($rawItem, $this->provider->getProviderName());
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

        // A page that changed anything invalidates what the storefront caches
        // about the catalogue: the nav menus' category and popular lists, and
        // the brand pages' country lists. Without this a brand synced into
        // stock is absent from the menus until the cache ages out, which reads
        // as a sync that did not work.
        if ($processedItems > 0) {
            $this->forgetCatalogCache();
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
            'has_more' => $hasMore,
            'total' => $total,
        ];
    }

    /**
     * Drop the storefront's cached views of the catalogue.
     *
     * On a store that supports tags this clears the catalog tag, which is
     * every one of them. On a store that does not - file, database - tagging
     * degrades to the default store, and flushing THAT would empty the whole
     * cache: sessions, settings, rates, everything the site holds. So there it
     * names the keys it knows instead, and the rest age out on their own.
     *
     * Never lets a cache problem fail a sync. The rows are already written by
     * the time this runs and every entry has a TTL behind it, so the worst a
     * failure here costs is an hour of a stale menu.
     */
    private function forgetCatalogCache(): void
    {
        try {
            if (Cache::getStore() instanceof TaggableStore) {
                TaggedCache::for(['catalog'])->flush();

                return;
            }

            GiftCardCategories::forgetNavMenus();
        } catch (\Throwable $e) {
            Log::warning('Catalog cache flush failed after sync', [
                'provider' => $this->provider->getProviderName(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
