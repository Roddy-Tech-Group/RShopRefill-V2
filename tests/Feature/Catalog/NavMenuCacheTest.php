<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Providers\ProviderInterface;
use App\Domain\Catalog\Services\CatalogNormalizerInterface;
use App\Domain\Catalog\Services\CatalogSyncService;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\GiftCardCategories;
use App\Support\TaggedCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The gift card nav menus are cached, and a sync clears them.
 *
 * Building that menu means reading every stocked gift card - over a thousand
 * rows - to work out which of about twenty curated categories have anything in
 * them. It ran on every storefront request, for every visitor, to produce a
 * list that only changes when the catalogue does. So it is cached against the
 * catalogue's tag for an hour.
 *
 * The cost of caching it is staleness, and the sync is what pays it off: a
 * brand synced into stock has to appear in the menu straight away, or the sync
 * looks like it did not work.
 */
class NavMenuCacheTest extends TestCase
{
    use RefreshDatabase;

    private function stockAGiftCard(string $brandKey, string $country = 'US'): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'gift-cards'],
            ['name' => 'Gift Cards', 'is_active' => true]
        );

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_key' => $brandKey,
            'country_code' => $country,
            'is_active' => true,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'is_available' => true,
        ]);

        return $product;
    }

    /** The menu is read once and answered from the cache after that. */
    public function test_the_menu_is_built_once_and_cached(): void
    {
        $this->stockAGiftCard('apple');

        $first = GiftCardCategories::navMenu('US');

        // once() memoises within a request; a fresh one has to come from cache.
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->refreshApplication();
        $second = GiftCardCategories::navMenu('US');

        $this->assertSame(
            $first['categories']->toArray(),
            $second['categories']->toArray(),
            'The cached menu should be the menu that was built.'
        );
        $this->assertSame(0, $queries, 'The second read queried the database.');
    }

    /** Each region gets its own, because popular brands are region-filtered. */
    public function test_each_region_is_cached_separately(): void
    {
        $this->stockAGiftCard('apple', 'US');

        GiftCardCategories::navMenu('US');
        GiftCardCategories::navMenu('NG');

        $this->assertNotNull(TaggedCache::for(['catalog'])->get('gift_card_nav_menu_US'));
        $this->assertNotNull(TaggedCache::for(['catalog'])->get('gift_card_nav_menu_NG'));
    }

    /** Forgetting clears every region, not just the one that was asked for. */
    public function test_forgetting_clears_every_region(): void
    {
        $this->stockAGiftCard('apple');

        GiftCardCategories::navMenu('US');
        GiftCardCategories::navMenu('NG');

        GiftCardCategories::forgetNavMenus();

        $this->assertNull(TaggedCache::for(['catalog'])->get('gift_card_nav_menu_US'));
        $this->assertNull(TaggedCache::for(['catalog'])->get('gift_card_nav_menu_NG'));
    }

    /**
     * A sync that wrote anything drops the menus, so a brand it brought into
     * stock is in the menu the next time someone looks - not up to an hour
     * later.
     */
    public function test_a_sync_that_changed_something_clears_the_menus(): void
    {
        $this->stockAGiftCard('apple');
        GiftCardCategories::navMenu('US');
        $this->assertNotNull(TaggedCache::for(['catalog'])->get('gift_card_nav_menu_US'));

        $this->syncService(['list' => [['title' => 'Something new']], 'total' => 1])->sync();

        $this->assertNull(
            TaggedCache::for(['catalog'])->get('gift_card_nav_menu_US'),
            'A sync left the nav menu holding the catalogue it had before.'
        );
    }

    /** A sync that wrote nothing leaves the cache alone. */
    public function test_a_sync_that_changed_nothing_leaves_the_menus_alone(): void
    {
        $this->stockAGiftCard('apple');
        GiftCardCategories::navMenu('US');

        $this->syncService(['list' => [], 'total' => 0])->sync();

        $this->assertNotNull(
            TaggedCache::for(['catalog'])->get('gift_card_nav_menu_US'),
            'An empty sync page threw the menu away for nothing.'
        );
    }

    /**
     * A cache that cannot be cleared must not fail the sync.
     *
     * The rows are already written by the time the flush runs, and every entry
     * has an hour on it, so the worst a failure costs is a stale menu. Failing
     * the sync over it would cost the catalogue.
     */
    public function test_a_cache_failure_does_not_fail_the_sync(): void
    {
        Cache::shouldReceive('getStore')->andThrow(new \RuntimeException('cache down'));

        $result = $this->syncService(['list' => [['title' => 'Something new']], 'total' => 1])->sync();

        $this->assertSame(1, $result['processed']);
    }

    /** A sync service over a fixed payload, counting what it normalises. */
    private function syncService(array $payload): CatalogSyncService
    {
        $provider = new class($payload) implements ProviderInterface
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

        $normalizer = new class implements CatalogNormalizerInterface
        {
            public function normalizeAndSave(array $rawItem, string $providerName): void {}
        };

        return new CatalogSyncService($provider, $normalizer);
    }
}
