<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Providers\ProviderInterface;
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
}
