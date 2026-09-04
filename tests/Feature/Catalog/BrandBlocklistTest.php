<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Services\ZenditNormalizer;
use App\Domain\Catalog\Support\BrandBlocklist;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Brands under a trademark/IP takedown (config/catalog.php `blocked_brands`)
 * must never be sold: the sync must not create them, must deactivate rows
 * that already exist, and the enforcement command must take them off the
 * storefront immediately.
 */
class BrandBlocklistTest extends TestCase
{
    use RefreshDatabase;

    private function giftCardCategory(): Category
    {
        return Category::firstOrCreate(
            ['slug' => 'gift-cards'],
            ['name' => 'Gift Cards', 'type' => 'digital']
        );
    }

    /** A representative Zendit gift-card offer for a given brand. */
    private function giftCardOffer(string $brandKey): array
    {
        return [
            'brand' => $brandKey,
            'brandName' => $brandKey,
            'country' => 'CA',
            'offerId' => strtolower($brandKey).'-ca-25',
            'priceType' => 'FIXED',
            'subTypes' => ['Sports & Outdoors'],
            'enabled' => true,
            'send' => ['currency' => 'CAD', 'currencyDivisor' => 100, 'fixed' => 2500],
            'price' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 1900],
            'cost' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 1800],
        ];
    }

    public function test_matching_is_case_and_separator_insensitive(): void
    {
        config(['catalog.blocked_brands' => ['GolfTown']]);

        $this->assertTrue(BrandBlocklist::isBlocked('GolfTown'));
        $this->assertTrue(BrandBlocklist::isBlocked('golf-town'));
        $this->assertTrue(BrandBlocklist::isBlocked('golftown'));
        $this->assertTrue(BrandBlocklist::isBlocked('GOLF_TOWN'));

        $this->assertFalse(BrandBlocklist::isBlocked('Topgolf'));
        $this->assertFalse(BrandBlocklist::isBlocked(null));
        $this->assertFalse(BrandBlocklist::isBlocked(''));
    }

    public function test_sync_skips_blocked_brand_and_deactivates_existing_rows(): void
    {
        Queue::fake();
        config(['catalog.blocked_brands' => ['GolfTown']]);

        $existing = Product::factory()->create([
            'provider_name' => 'zendit',
            'brand_key' => 'GolfTown',
            'country_code' => 'CA',
            'slug' => 'golftown-ca-zendit',
            'category_id' => $this->giftCardCategory()->id,
            'is_active' => true,
        ]);

        app(ZenditNormalizer::class)->normalizeAndSave($this->giftCardOffer('GolfTown'), 'zendit');

        $this->assertFalse($existing->fresh()->is_active);
        $this->assertSame(1, Product::withTrashed()->where('brand_key', 'GolfTown')->count());
        $this->assertDatabaseMissing('product_variants', ['provider_offer_id' => 'golftown-ca-25']);
    }

    public function test_sync_still_creates_non_blocked_brands(): void
    {
        Queue::fake();
        config(['catalog.blocked_brands' => ['GolfTown']]);

        app(ZenditNormalizer::class)->normalizeAndSave($this->giftCardOffer('Topgolf'), 'zendit');

        $this->assertDatabaseHas('products', ['brand_key' => 'Topgolf', 'is_active' => true]);
    }

    public function test_enforce_command_deactivates_only_blocked_brands_and_flushes_cache(): void
    {
        config(['catalog.blocked_brands' => ['GolfTown']]);

        $categoryId = $this->giftCardCategory()->id;
        $blocked = Product::factory()->create(['brand_key' => 'GolfTown', 'slug' => 'golftown-ca-zendit', 'category_id' => $categoryId]);
        $allowed = Product::factory()->create(['brand_key' => 'Topgolf', 'slug' => 'topgolf-us-zendit', 'category_id' => $categoryId]);

        $this->artisan('catalog:enforce-brand-blocklist')
            ->expectsOutputToContain('Deactivated')
            ->expectsOutputToContain('catalog cache flushed')
            ->assertSuccessful();

        $this->assertFalse($blocked->fresh()->is_active);
        $this->assertTrue($allowed->fresh()->is_active);
    }

    public function test_enforce_command_dry_run_changes_nothing(): void
    {
        config(['catalog.blocked_brands' => ['GolfTown']]);

        $blocked = Product::factory()->create([
            'brand_key' => 'GolfTown',
            'slug' => 'golftown-ca-zendit',
            'category_id' => $this->giftCardCategory()->id,
        ]);

        $this->artisan('catalog:enforce-brand-blocklist', ['--dry-run' => true])
            ->expectsOutputToContain('Would deactivate')
            ->assertSuccessful();

        $this->assertTrue($blocked->fresh()->is_active);
    }

    public function test_blocked_brand_page_redirects_to_gift_cards_listing(): void
    {
        config(['catalog.blocked_brands' => ['GolfTown']]);

        Product::factory()->create([
            'brand_key' => 'GolfTown',
            'slug' => 'golftown-ca-zendit',
            'category_id' => $this->giftCardCategory()->id,
            'is_active' => false,
        ]);

        $this->get('/gift-cards/golf-town')
            ->assertRedirect(route('shop.gift-cards'))
            ->assertStatus(301);
    }
}
