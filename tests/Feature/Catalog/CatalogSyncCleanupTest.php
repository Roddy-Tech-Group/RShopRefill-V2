<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Enums\CatalogCleanupSkipReason;
use App\Domain\Catalog\Enums\CatalogFeed;
use App\Domain\Catalog\Enums\CatalogSyncRunStatus;
use App\Domain\Catalog\Providers\ProviderInterface;
use App\Domain\Catalog\Services\CatalogNormalizerInterface;
use App\Domain\Catalog\Services\CatalogSyncRunService;
use App\Domain\Catalog\Services\CatalogSyncService;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Jobs\SyncZenditBrandsJob;
use App\Jobs\SyncZenditGiftCardsJob;
use App\Models\CatalogSyncRun;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Suppliers never announce a dropped offer; it just stops appearing in their
 * feed. On 2026-09-17, 312 dropped Zendit gift card offers (all of Apple US
 * among them) were still on sale, and customers paid for cards Zendit refused
 * to issue. A clean, complete sync run now takes the offers it did not see off
 * sale, and brings back the ones that returned, unless the feed looks short.
 */
class CatalogSyncCleanupTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{severity: AlertSeverity, key: string, title: string, message: string, context: array<string, mixed>}> */
    private array $alerts = [];

    private Category $category;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $alerts = &$this->alerts;

        $this->app->instance(OperationalAlerter::class, new class($alerts) extends OperationalAlerter
        {
            public function __construct(private array &$sent) {}

            public function send(
                AlertSeverity $severity,
                string $key,
                string $title,
                string $message,
                array $context = [],
                ?string $runbook = null,
            ): bool {
                $this->sent[] = compact('severity', 'key', 'title', 'message', 'context');

                return true;
            }
        });

        $this->category = Category::factory()->create();
        $this->product = Product::factory()->create(['name' => 'Apple (US)', 'provider_name' => 'zendit', 'category_id' => $this->category->id]);
    }

    public function test_a_clean_run_hides_offers_the_supplier_stopped_listing(): void
    {
        $this->existingVariants(['APPLE_US_10', 'APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'EBAY_US_10']);

        $run = $this->runFeed(['APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'EBAY_US_10']);

        $dropped = $this->variant('APPLE_US_10');
        $this->assertFalse($dropped->is_available);
        $this->assertNotNull($dropped->delisted_at);

        $kept = $this->variant('APPLE_US_25');
        $this->assertTrue($kept->is_available);
        $this->assertNull($kept->delisted_at);

        $this->assertSame(CatalogSyncRunStatus::Completed, $run->status);
        $this->assertSame('applied', $run->cleanup);
        $this->assertSame(4, $run->offers_seen);
        $this->assertSame(1, $run->offers_delisted);
    }

    public function test_an_offer_the_supplier_lists_again_goes_back_on_sale(): void
    {
        $this->existingVariants(['APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'EBAY_US_10']);
        $this->existingVariants(['APPLE_US_10'], ['is_available' => false, 'delisted_at' => now()->subDays(3)]);

        $run = $this->runFeed(['APPLE_US_10', 'APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'EBAY_US_10']);

        $returned = $this->variant('APPLE_US_10');
        $this->assertTrue($returned->is_available);
        $this->assertNull($returned->delisted_at);
        $this->assertSame(1, $run->offers_restored);
        $this->assertSame(0, $run->offers_delisted);
    }

    public function test_the_first_run_hides_dropped_offers_nobody_ever_marked_as_seen(): void
    {
        // The migration claims existing variants for their feed without a
        // last_seen_at; the first run must still be able to hide them.
        $this->existingVariants(['APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'EBAY_US_10'], ['last_seen_at' => null]);
        $this->existingVariants(['APPLE_US_10'], ['last_seen_at' => null]);

        $this->runFeed(['APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'EBAY_US_10']);

        $this->assertFalse($this->variant('APPLE_US_10')->is_available);
        $this->assertTrue($this->variant('EBAY_US_10')->is_available);
    }

    public function test_a_failed_run_hides_nothing(): void
    {
        $this->existingVariants(['APPLE_US_10', 'APPLE_US_25']);

        $runs = app(CatalogSyncRunService::class);
        $run = $runs->start(CatalogFeed::ZenditVouchers);
        $this->syncService(['APPLE_US_25'])->sync(1, 100, $run);
        $runs->fail($run, 'Page 2: Zendit timed out');

        $this->assertTrue($this->variant('APPLE_US_10')->is_available);
        $this->assertSame(CatalogSyncRunStatus::Failed, $run->refresh()->status);
        $this->assertNull($run->cleanup);

        // A failed run cannot be completed afterwards by a straggling page.
        $runs->complete($run);
        $this->assertTrue($this->variant('APPLE_US_10')->is_available);
    }

    public function test_an_item_that_failed_to_save_blocks_the_cleanup_even_when_forced(): void
    {
        $this->existingVariants(['APPLE_US_10', 'APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'EBAY_US_10']);

        $run = $this->runFeed(['APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'EBAY_US_10', 'BROKEN'], force: true);

        $this->assertTrue($this->variant('APPLE_US_10')->is_available);
        $this->assertSame('skipped', $run->cleanup);
        $this->assertSame(CatalogCleanupSkipReason::ItemsFailed, $run->cleanup_skipped_reason);
        $this->assertSame(AlertSeverity::Sev3, $this->alerts[0]['severity']);
    }

    public function test_a_feed_that_shrank_against_the_last_run_is_not_trusted(): void
    {
        CatalogSyncRun::factory()->completed(offersSeen: 10)->create(['finished_at' => now()->subHours(6)]);
        $this->existingVariants(['APPLE_US_10', 'APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'EBAY_US_10']);

        // 4 seen against the previous run's 10 is below the 70% floor.
        $run = $this->runFeed(['APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'EBAY_US_10']);

        $this->assertTrue($this->variant('APPLE_US_10')->is_available);
        $this->assertSame(CatalogCleanupSkipReason::FeedShrank, $run->cleanup_skipped_reason);
        $this->assertSame(AlertSeverity::Sev2, $this->alerts[0]['severity']);
        $this->assertSame('catalog-sync:zendit_vouchers:cleanup-skipped:feed_shrank', $this->alerts[0]['key']);
        $this->assertSame(1, $this->alerts[0]['context']['missing_offers']);
    }

    public function test_hiding_too_large_a_share_of_the_live_catalog_at_once_is_held_back(): void
    {
        $this->existingVariants(['APPLE_US_10', 'APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'EBAY_US_10']);

        // 3 of 5 live offers missing is 60%, above the 30% ceiling.
        $run = $this->runFeed(['APPLE_US_25', 'AMAZON_US_10']);

        $this->assertTrue($this->variant('EBAY_US_10')->is_available);
        $this->assertSame(CatalogCleanupSkipReason::TooManyMissing, $run->cleanup_skipped_reason);
        $this->assertSame(0, $run->offers_delisted);
    }

    public function test_force_cleanup_applies_a_real_mass_removal_the_guards_held_back(): void
    {
        $this->existingVariants(['APPLE_US_10', 'APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'EBAY_US_10']);

        $run = $this->runFeed(['APPLE_US_25', 'AMAZON_US_10'], force: true);

        $this->assertSame('applied', $run->cleanup);
        $this->assertSame(3, $run->offers_delisted);
        $this->assertFalse($this->variant('EBAY_US_10')->is_available);
    }

    public function test_an_empty_feed_never_hides_anything_even_when_forced(): void
    {
        $this->existingVariants(['APPLE_US_10']);

        $run = $this->runFeed([], force: true);

        $this->assertTrue($this->variant('APPLE_US_10')->is_available);
        $this->assertSame(CatalogCleanupSkipReason::FeedShrank, $run->cleanup_skipped_reason);
    }

    public function test_variants_owned_by_another_feed_or_by_no_feed_are_never_touched(): void
    {
        $this->existingVariants(['APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'EBAY_US_10', 'APPLE_US_10']);
        $this->existingVariants(['MTN_NG_500'], ['catalog_feed' => CatalogFeed::ZenditTopups]);
        $this->existingVariants(['airalo_topup_japan-7days-1gb'], ['catalog_feed' => null]);

        $this->runFeed(['APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'EBAY_US_10']);

        $this->assertFalse($this->variant('APPLE_US_10')->is_available);
        $this->assertTrue($this->variant('MTN_NG_500')->is_available);
        $this->assertTrue($this->variant('airalo_topup_japan-7days-1gb')->is_available);
    }

    public function test_a_feed_never_claims_an_offer_another_feed_already_owns(): void
    {
        $this->existingVariants(['APPLE_US_10', 'APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25']);

        // Same offer id, owned by the top-ups feed. Offer ids are matched on
        // their own, so a vouchers run that carries this id must not take it
        // over: the top-ups feed would then hide it as missing.
        $this->existingVariants(['SHARED_ID'], [
            'catalog_feed' => CatalogFeed::ZenditTopups,
            'last_seen_at' => now()->subHours(6),
        ]);

        $this->runFeed(['APPLE_US_10', 'APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'SHARED_ID']);

        $shared = $this->variant('SHARED_ID');
        $this->assertSame(CatalogFeed::ZenditTopups, $shared->catalog_feed, 'the other feed must keep its offer');
        $this->assertTrue($shared->last_seen_at->lt(now()->subHour()), 'and must not be marked as seen by this run');
        $this->assertTrue($shared->is_available);
    }

    public function test_a_dry_run_reports_what_it_would_hide_and_changes_nothing(): void
    {
        $ebay = Product::factory()->create(['name' => 'eBay (US)', 'provider_name' => 'zendit', 'category_id' => $this->category->id]);
        $this->existingVariants(['APPLE_US_10', 'APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'AMAZON_US_50', 'AMAZON_US_100']);
        $this->existingVariants(['EBAY_US_10'], ['product_id' => $ebay->id]);

        $runs = app(CatalogSyncRunService::class);
        $run = $runs->start(CatalogFeed::ZenditVouchers);
        $this->syncService(['APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'AMAZON_US_50', 'AMAZON_US_100'])->sync(1, 100, $run);

        $preview = $runs->previewCleanup($run);

        // Everything it would have hidden is untouched.
        $this->assertTrue($this->variant('APPLE_US_10')->is_available);
        $this->assertNull($this->variant('APPLE_US_10')->delisted_at);
        $this->assertTrue($this->variant('EBAY_US_10')->is_available);

        // And it says exactly what a real run would do, including the product
        // that would lose its last amount.
        $this->assertNull($preview['skipped']);
        $this->assertSame(2, $preview['delist']->count());
        $this->assertEqualsCanonicalizing(
            ['APPLE_US_10', 'EBAY_US_10'],
            $preview['delist']->pluck('provider_offer_id')->all(),
        );
        $this->assertSame(['eBay (US)'], $preview['dark']->pluck('name')->all());

        $this->assertSame('preview', $run->refresh()->cleanup);
        $this->assertSame(2, $run->offers_delisted);
        $this->assertStringContainsString('Nothing was changed', $run->summary());
        $this->assertSame([], $this->alerts, 'a dry run must never page anyone');
    }

    public function test_a_dry_run_says_when_the_guards_would_hold_the_cleanup_back(): void
    {
        $this->existingVariants(['APPLE_US_10', 'APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'EBAY_US_10']);

        $runs = app(CatalogSyncRunService::class);
        $run = $runs->start(CatalogFeed::ZenditVouchers);
        $this->syncService(['APPLE_US_25', 'AMAZON_US_10'])->sync(1, 100, $run);

        $preview = $runs->previewCleanup($run);

        $this->assertSame(CatalogCleanupSkipReason::TooManyMissing, $preview['skipped']);
        $this->assertSame(0, $run->refresh()->offers_delisted);
        $this->assertTrue($this->variant('EBAY_US_10')->is_available);
        $this->assertSame([], $this->alerts);
    }

    public function test_the_dry_run_option_hides_nothing_through_the_command(): void
    {
        config(['services.zendit.api_key' => 'live_test', 'services.zendit.base_url' => 'https://api.zendit.io/v1']);

        $this->existingVariants(['APPLE_US_10_EGIFT_WHS']);
        $offers = collect(range(1, 4))->map(fn (int $n) => $this->zenditOffer("AMAZON_US_{$n}_EGIFT"));
        foreach ($offers as $offer) {
            $this->existingVariants([$offer['offerId']]);
        }

        Http::fake([
            'api.zendit.io/v1/vouchers/offers*' => Http::response(['total' => 4, 'list' => $offers->values()->all()]),
        ]);

        $this->artisan('giftcards:sync', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertTrue($this->variant('APPLE_US_10_EGIFT_WHS')->is_available);
        $this->assertSame('preview', CatalogSyncRun::sole()->cleanup);
    }

    public function test_a_product_losing_its_last_amount_raises_one_alert_naming_it(): void
    {
        $ebay = Product::factory()->create(['name' => 'eBay (US)', 'provider_name' => 'zendit', 'category_id' => $this->category->id]);
        $this->existingVariants(['APPLE_US_10', 'APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'AMAZON_US_50', 'AMAZON_US_100']);
        $this->existingVariants(['EBAY_US_10'], ['product_id' => $ebay->id]);

        // Apple loses one of its two amounts and stays on sale; eBay loses its only one.
        $run = $this->runFeed(['APPLE_US_25', 'AMAZON_US_10', 'AMAZON_US_25', 'AMAZON_US_50', 'AMAZON_US_100']);

        $this->assertSame(2, $run->offers_delisted);
        $this->assertCount(1, $this->alerts);
        $this->assertSame(AlertSeverity::Sev2, $this->alerts[0]['severity']);
        $this->assertStringContainsString('eBay (US)', $this->alerts[0]['message']);
        $this->assertStringNotContainsString('Apple (US)', $this->alerts[0]['message']);
    }

    public function test_the_gift_card_job_chain_runs_the_cleanup_after_its_last_page(): void
    {
        Bus::fake([SyncZenditBrandsJob::class]);
        config(['services.zendit.api_key' => 'live_test', 'services.zendit.base_url' => 'https://api.zendit.io/v1']);

        $this->existingVariants(['APPLE_US_10_EGIFT_WHS'], ['product_id' => $this->product->id]);

        $offers = collect(range(1, 150))->map(fn (int $n) => $this->zenditOffer("AMAZON_US_{$n}_EGIFT"));
        foreach ($offers as $offer) {
            $this->existingVariants([$offer['offerId']]);
        }

        Http::fake([
            'api.zendit.io/v1/vouchers/offers?_limit=100&_offset=0' => Http::response(['total' => 150, 'list' => $offers->take(100)->values()->all()]),
            'api.zendit.io/v1/vouchers/offers?_limit=100&_offset=100' => Http::response(['total' => 150, 'list' => $offers->slice(100)->values()->all()]),
        ]);

        SyncZenditGiftCardsJob::dispatch();

        $run = CatalogSyncRun::sole();
        $this->assertSame(CatalogSyncRunStatus::Completed, $run->status);
        $this->assertSame(2, $run->pages);
        $this->assertSame(150, $run->offers_seen);
        $this->assertSame(1, $run->offers_delisted);
        $this->assertFalse($this->variant('APPLE_US_10_EGIFT_WHS')->is_available);
        $this->assertTrue($this->variant('AMAZON_US_150_EGIFT')->is_available);
        $this->assertSame(CatalogFeed::ZenditVouchers, $this->variant('AMAZON_US_150_EGIFT')->catalog_feed);
        Bus::assertDispatched(SyncZenditBrandsJob::class);
    }

    public function test_a_page_that_fails_marks_the_job_chain_run_failed_and_hides_nothing(): void
    {
        config(['services.zendit.api_key' => 'live_test', 'services.zendit.base_url' => 'https://api.zendit.io/v1']);

        $this->existingVariants(['APPLE_US_10_EGIFT_WHS']);
        $offers = collect(range(1, 150))->map(fn (int $n) => $this->zenditOffer("AMAZON_US_{$n}_EGIFT"));

        Http::fake([
            'api.zendit.io/v1/vouchers/offers?_limit=100&_offset=0' => Http::response(['total' => 150, 'list' => $offers->take(100)->values()->all()]),
            'api.zendit.io/v1/vouchers/offers?_limit=100&_offset=100' => Http::response(['message' => 'upstream error'], 502),
        ]);

        try {
            SyncZenditGiftCardsJob::dispatch();
        } catch (RuntimeException) {
            // The sync queue rethrows the page failure after the job's failed() hook ran.
        }

        $run = CatalogSyncRun::sole();
        $this->assertSame(CatalogSyncRunStatus::Failed, $run->status);
        $this->assertStringContainsString('Page 2', (string) $run->failure_reason);
        $this->assertTrue($this->variant('APPLE_US_10_EGIFT_WHS')->is_available);
    }

    public function test_a_command_started_mid_feed_never_hides_offers(): void
    {
        config(['services.zendit.api_key' => 'live_test', 'services.zendit.base_url' => 'https://api.zendit.io/v1']);

        $this->existingVariants(['APPLE_US_10_EGIFT_WHS']);

        Http::fake([
            'api.zendit.io/v1/vouchers/offers*' => Http::response(['total' => 150, 'list' => [$this->zenditOffer('AMAZON_US_150_EGIFT')]]),
        ]);

        $this->artisan('giftcards:sync', ['--page' => 2])->assertSuccessful();

        $this->assertSame(0, CatalogSyncRun::count());
        $this->assertTrue($this->variant('APPLE_US_10_EGIFT_WHS')->is_available);
    }

    /**
     * @param  list<string>  $offerIds
     * @param  array<string, mixed>  $attributes
     */
    private function existingVariants(array $offerIds, array $attributes = []): void
    {
        foreach ($offerIds as $offerId) {
            ProductVariant::factory()->create(array_merge([
                'product_id' => $this->product->id,
                'provider_offer_id' => $offerId,
                'catalog_feed' => CatalogFeed::ZenditVouchers,
                'last_seen_at' => now()->subHours(6),
            ], $attributes));
        }
    }

    private function variant(string $offerId): ProductVariant
    {
        return ProductVariant::where('provider_offer_id', $offerId)->sole();
    }

    /**
     * @param  list<string>  $offerIds
     */
    private function runFeed(array $offerIds, bool $force = false): CatalogSyncRun
    {
        $runs = app(CatalogSyncRunService::class);
        $run = $runs->start(CatalogFeed::ZenditVouchers, $force);

        $this->syncService($offerIds)->sync(1, 100, $run);

        return $runs->complete($run);
    }

    /**
     * A one-page feed; an offer id of "BROKEN" throws while saving, like an
     * item our normalizer cannot parse.
     *
     * @param  list<string>  $offerIds
     */
    private function syncService(array $offerIds): CatalogSyncService
    {
        $provider = new class($offerIds) implements ProviderInterface
        {
            public function __construct(private array $offerIds) {}

            public function getProviderName(): string
            {
                return 'zendit';
            }

            public function fetchCatalog(int $page = 1, int $limit = 100): array
            {
                return [
                    'total' => count($this->offerIds),
                    'list' => array_map(fn (string $offerId) => ['offerId' => $offerId, 'enabled' => true], $this->offerIds),
                ];
            }

            public function fetchOfferDetails(string $providerReference): array
            {
                return [];
            }
        };

        $normalizer = new class($this->product) implements CatalogNormalizerInterface
        {
            public function __construct(private Product $product) {}

            public function normalizeAndSave(array $rawItem, string $providerName): array
            {
                if ($rawItem['offerId'] === 'BROKEN') {
                    throw new RuntimeException('Unparseable offer');
                }

                $variant = ProductVariant::firstOrNew(['provider_offer_id' => $rawItem['offerId']]);
                $variant->fill([
                    'product_id' => $variant->product_id ?? $this->product->id,
                    'currency' => 'USD',
                    'cost_price' => 8,
                    'retail_price' => 10,
                    'is_available' => (bool) $rawItem['enabled'],
                ])->save();

                return [$rawItem['offerId']];
            }
        };

        return new CatalogSyncService($provider, $normalizer);
    }

    /**
     * @return array<string, mixed>
     */
    private function zenditOffer(string $offerId): array
    {
        return [
            'offerId' => $offerId,
            'brand' => 'Amazon',
            'brandName' => 'Amazon',
            'country' => 'US',
            'priceType' => 'FIXED',
            'subTypes' => ['Shopping'],
            'productType' => 'VOUCHER',
            'enabled' => true,
            'send' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 1000],
            'price' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 950],
            'cost' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 900],
        ];
    }
}
