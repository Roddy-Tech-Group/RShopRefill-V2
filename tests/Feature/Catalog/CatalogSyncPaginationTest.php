<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Providers\ProviderInterface;
use App\Domain\Catalog\Services\CatalogNormalizerInterface;
use App\Domain\Catalog\Services\CatalogSyncService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CatalogSyncPaginationTest extends TestCase
{
    private function provider(array $payload): ProviderInterface
    {
        return new class($payload) implements ProviderInterface
        {
            public function __construct(private array $payload) {}

            public function getProviderName(): string
            {
                return 'test';
            }

            public function fetchCatalog(int $page = 1, int $limit = 100): array
            {
                return $this->payload;
            }

            public function fetchOfferDetails(string $providerReference): array
            {
                return [];
            }
        };
    }

    private function normalizer(): CatalogNormalizerInterface
    {
        return new class implements CatalogNormalizerInterface
        {
            public int $count = 0;

            public function normalizeAndSave(array $rawItem, string $providerName): void
            {
                $this->count++;
            }
        };
    }

    public function test_one_failing_item_is_logged_and_counted_without_stopping_the_page(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'Catalog sync item failed'
                && $context['provider'] === 'test'
                && $context['page'] === 1
                && $context['index'] === 1
                && $context['item_title'] === 'Global'
                && $context['exception'] === \RuntimeException::class);

        $normalizer = new class implements CatalogNormalizerInterface
        {
            public array $seen = [];

            public function normalizeAndSave(array $rawItem, string $providerName): void
            {
                if ($rawItem['title'] === 'Global') {
                    throw new \RuntimeException('Duplicate entry');
                }
                $this->seen[] = $rawItem['title'];
            }
        };

        $service = new CatalogSyncService($this->provider([
            'data' => [['title' => 'Japan'], ['title' => 'Global'], ['title' => 'Oceania']],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 3],
        ]), $normalizer);

        $result = $service->sync(1, 100);

        $this->assertSame(['Japan', 'Oceania'], $normalizer->seen);
        $this->assertSame(2, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertFalse($result['has_more']);
    }

    public function test_airalo_meta_pagination_reports_more_pages(): void
    {
        $service = new CatalogSyncService($this->provider([
            'data' => [['id' => 1], ['id' => 2]],
            'meta' => ['current_page' => 1, 'last_page' => 3, 'total' => 250],
        ]), $this->normalizer());

        $result = $service->sync(1, 100);

        $this->assertSame(2, $result['processed']);
        $this->assertTrue($result['has_more']);
    }

    public function test_airalo_last_page_reports_no_more(): void
    {
        $service = new CatalogSyncService($this->provider([
            'data' => [['id' => 1]],
            'meta' => ['current_page' => 3, 'last_page' => 3, 'total' => 250],
        ]), $this->normalizer());

        $this->assertFalse($service->sync(3, 100)['has_more']);
    }

    public function test_zendit_total_pagination_still_works(): void
    {
        $payload = ['total' => 250, 'list' => [['offerId' => 'a'], ['offerId' => 'b']]];

        $first = (new CatalogSyncService($this->provider($payload), $this->normalizer()))->sync(1, 100);
        $this->assertTrue($first['has_more']);

        $last = (new CatalogSyncService($this->provider($payload), $this->normalizer()))->sync(3, 100);
        $this->assertFalse($last['has_more']);
    }
}
