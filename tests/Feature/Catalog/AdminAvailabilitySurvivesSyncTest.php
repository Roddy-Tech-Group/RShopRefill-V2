<?php

namespace Tests\Feature\Catalog;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Catalog\Enums\CatalogFeed;
use App\Domain\Catalog\Providers\ProviderInterface;
use App\Domain\Catalog\Services\AiraloTopupCatalogService;
use App\Domain\Catalog\Services\CatalogSyncRunService;
use App\Domain\Catalog\Services\CatalogSyncService;
use App\Domain\Catalog\Services\ZenditTopupNormalizer;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Models\Admin;
use App\Models\CatalogSyncRun;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * An admin switching a variant off has to mean something.
 *
 * All four normalizers wrote `is_available` straight from the supplier feed on
 * every run, so a product taken off sale by hand, because it was mispriced or
 * the supplier was flaky, came back within six hours and nobody was told. The
 * supplier's word and ours now live in different columns, and ProductVariant
 * enforces the invariant rather than each normalizer remembering it.
 *
 * The rule is one-directional on purpose: an admin can force an offer OFF, and
 * can hand it back to the supplier, but can never force on sale something the
 * supplier has withdrawn. That would only move the failure to the customer at
 * fulfilment.
 */
class AdminAvailabilitySurvivesSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /** A representative Zendit top-up offer. */
    private function offer(bool $enabled = true, string $offerId = 'orange-cm-1000'): array
    {
        return [
            'brand' => 'Orange',
            'brandName' => 'Orange',
            'country' => 'CM',
            'offerId' => $offerId,
            'priceType' => 'FIXED',
            'enabled' => $enabled,
            'send' => ['currency' => 'XAF', 'currencyDivisor' => 100, 'fixed' => 100000],
            'price' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 200],
            'cost' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 180],
        ];
    }

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Catalog Admin',
            'email' => 'catalog@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    private function sync(bool $enabled = true): ProductVariant
    {
        app(ZenditTopupNormalizer::class)->normalizeAndSave($this->offer($enabled), 'zendit');

        return ProductVariant::where('provider_offer_id', 'orange-cm-1000')->sole();
    }

    /**
     * One complete run of the top-up feed, listing exactly these offers, with
     * its cleanup applied.
     *
     * @param  list<string>  $offerIds
     */
    private function runFeed(array $offerIds): CatalogSyncRun
    {
        $offers = array_map(fn (string $offerId) => $this->offer(offerId: $offerId), $offerIds);

        $provider = new class($offers) implements ProviderInterface
        {
            public function __construct(private array $offers) {}

            public function getProviderName(): string
            {
                return 'zendit';
            }

            public function fetchCatalog(int $page = 1, int $limit = 100): array
            {
                return ['total' => count($this->offers), 'list' => $this->offers];
            }

            public function fetchOfferDetails(string $providerReference): array
            {
                return [];
            }
        };

        $runs = app(CatalogSyncRunService::class);
        $run = $runs->start(CatalogFeed::ZenditTopups);

        (new CatalogSyncService($provider, app(ZenditTopupNormalizer::class)))->sync(1, 100, $run);

        return $runs->complete($run);
    }

    private function variant(string $offerId): ProductVariant
    {
        return ProductVariant::where('provider_offer_id', $offerId)->sole();
    }

    private function parentEsim(): Product
    {
        $category = Category::firstOrCreate(['slug' => 'esims'], ['name' => 'eSIMs', 'type' => 'digital']);

        return Product::create([
            'category_id' => $category->id,
            'provider_name' => 'airalo',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'United States eSIM',
            'slug' => 'esim-us-united-states',
            'is_active' => true,
        ]);
    }

    /**
     * THE BUG. Without the override this assertion fails: the second run reads
     * the supplier's `enabled` flag straight over the admin's decision.
     */
    public function test_a_variant_switched_off_by_an_admin_stays_off_across_a_sync(): void
    {
        $variant = $this->sync();
        $this->assertTrue($variant->is_available);

        $variant->adminDisable(adminId: 7);

        $afterSync = $this->sync();

        $this->assertFalse($afterSync->is_available, 'the sync put an admin-disabled offer back on sale');
        $this->assertTrue($afterSync->supplier_available, 'the supplier still lists it, and we still record that');
        $this->assertNotNull($afterSync->admin_disabled_at);
        $this->assertSame(7, $afterSync->admin_disabled_by);
    }

    /**
     * The invariant lives on the model, not in the four normalizers, so a
     * future writer cannot forget it.
     */
    public function test_any_save_that_tries_to_switch_an_admin_disabled_variant_on_is_refused(): void
    {
        $variant = $this->sync();
        $variant->adminDisable();

        $variant->update(['is_available' => true]);

        $this->assertFalse($variant->fresh()->is_available);
    }

    public function test_handing_it_back_to_the_supplier_puts_it_on_sale_again(): void
    {
        $variant = $this->sync();
        $variant->adminDisable();

        $variant->clearAdminDisable();

        $this->assertTrue($variant->fresh()->is_available);
        $this->assertNull($variant->fresh()->admin_disabled_at);
    }

    /**
     * An admin cannot overrule the supplier in the permissive direction.
     */
    public function test_handing_back_an_offer_the_supplier_disabled_leaves_it_off_sale(): void
    {
        $variant = $this->sync(enabled: false);
        $this->assertFalse($variant->is_available);

        $variant->adminDisable();
        $variant->clearAdminDisable();

        $this->assertFalse($variant->fresh()->is_available, 'the supplier has this offer off, so it stays off');
        $this->assertNull($variant->fresh()->admin_disabled_at);
    }

    /**
     * Nor can a hand-off-and-on cycle resurrect something a cleanup delisted.
     */
    public function test_handing_back_a_delisted_offer_leaves_it_off_sale(): void
    {
        $variant = $this->sync();
        $variant->forceFill(['delisted_at' => now(), 'is_available' => false])->save();

        $variant->clearAdminDisable();

        $this->assertFalse($variant->fresh()->is_available);
    }

    /**
     * A supplier switching an offer off is still obeyed instantly. The override
     * exists to stop the sync overruling an admin, not the other way round.
     */
    public function test_a_supplier_disabling_an_offer_still_takes_it_off_sale(): void
    {
        $this->sync();

        $this->assertFalse($this->sync(enabled: false)->is_available);
    }

    public function test_the_admin_endpoint_records_who_switched_it_off(): void
    {
        $variant = $this->sync();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->patchJson(route('admin.api.catalog.variants.availability', $variant), ['is_available' => false])
            ->assertOk()
            ->assertJson(['is_available' => false, 'admin_disabled' => true]);

        $this->assertSame($admin->id, $variant->fresh()->admin_disabled_by);
    }

    /**
     * Turning the switch on for an offer the supplier has withdrawn must say so
     * rather than silently bouncing back and looking broken.
     */
    public function test_the_endpoint_reports_when_the_supplier_is_the_blocker(): void
    {
        $variant = $this->sync(enabled: false);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->patchJson(route('admin.api.catalog.variants.availability', $variant), ['is_available' => true])
            ->assertOk()
            ->assertJson([
                'is_available' => false,
                'admin_disabled' => false,
                'supplier_available' => false,
                'blocked_by_supplier' => true,
            ]);
    }

    /**
     * The cleanup only looked at offers on sale, so an offer the supplier
     * dropped while an admin had it off was never marked as dropped. Handing
     * it back then put a withdrawn offer on sale until the first refused order.
     */
    public function test_an_offer_the_supplier_drops_while_switched_off_cannot_be_switched_back_on(): void
    {
        $admin = $this->admin();
        $this->runFeed(['orange-cm-1', 'orange-cm-2', 'orange-cm-3', 'orange-cm-4']);
        $dropped = $this->variant('orange-cm-1');

        $this->actingAs($admin, 'admin')
            ->patchJson(route('admin.api.catalog.variants.availability', $dropped), ['is_available' => false])
            ->assertOk();

        $this->travel(6)->hours();
        $run = $this->runFeed(['orange-cm-2', 'orange-cm-3', 'orange-cm-4']);

        $this->assertSame('applied', $run->cleanup);
        $this->assertNotNull($dropped->fresh()->delisted_at, 'the supplier dropped it, and the cleanup must record that');

        $this->actingAs($admin, 'admin')
            ->patchJson(route('admin.api.catalog.variants.availability', $dropped), ['is_available' => true])
            ->assertOk()
            ->assertJson([
                'is_available' => false,
                'admin_disabled' => false,
                'blocked_by_supplier' => true,
            ]);

        $this->assertFalse($dropped->fresh()->is_available);
    }

    /**
     * Offers an admin already took off sale must not count as offers the
     * cleanup is about to hide. Three of ten would be past the mass-delist
     * ceiling, hold the cleanup back and page someone about offers "still on
     * sale" that were never on sale.
     */
    public function test_switched_off_offers_the_supplier_drops_neither_hold_the_cleanup_back_nor_raise_alerts(): void
    {
        $alerter = $this->spy(OperationalAlerter::class);
        $all = array_map(fn (int $n) => "orange-cm-{$n}", range(1, 10));
        $this->runFeed($all);

        $switchedOff = array_slice($all, 0, 3);
        foreach ($switchedOff as $offerId) {
            $this->variant($offerId)->adminDisable();
        }

        $this->travel(6)->hours();
        $run = $this->runFeed(array_slice($all, 3));

        $this->assertNull($run->cleanup_skipped_reason, 'the guard counted offers that were already off sale');
        $this->assertSame('applied', $run->cleanup);
        $this->assertSame(0, $run->offers_delisted, 'they were already off sale, so this run hid nothing');
        $this->assertSame(3, ProductVariant::whereIn('provider_offer_id', $switchedOff)->whereNotNull('delisted_at')->count());
        $this->assertTrue($this->variant('orange-cm-10')->is_available);
        $alerter->shouldNotHaveReceived('send');
    }

    /**
     * The supplier listing an offer again closes the delisting record but does
     * not overrule the admin, and is not announced as back on sale.
     */
    public function test_an_offer_the_supplier_lists_again_stays_off_until_an_admin_hands_it_back(): void
    {
        $alerter = $this->spy(OperationalAlerter::class);
        $this->runFeed(['orange-cm-1', 'orange-cm-2', 'orange-cm-3', 'orange-cm-4']);
        $this->variant('orange-cm-1')->adminDisable();

        $this->travel(6)->hours();
        $this->runFeed(['orange-cm-2', 'orange-cm-3', 'orange-cm-4']);
        $this->assertNotNull($this->variant('orange-cm-1')->delisted_at);

        $this->travel(6)->hours();
        $run = $this->runFeed(['orange-cm-1', 'orange-cm-2', 'orange-cm-3', 'orange-cm-4']);

        $returned = $this->variant('orange-cm-1');
        $this->assertFalse($returned->is_available, 'the supplier listing it again must not overrule the admin');
        $this->assertTrue($returned->isAdminDisabled());
        $this->assertTrue($returned->supplier_available);
        $this->assertNull($returned->delisted_at);
        $this->assertSame(0, $run->offers_restored);
        $alerter->shouldNotHaveReceived('send');

        $returned->clearAdminDisable();

        $this->assertTrue($returned->fresh()->is_available);
    }

    /**
     * An offer the supplier or a face check already took off can still be
     * pinned off, so it stays off when the supplier switches it back on.
     */
    public function test_an_offer_already_off_at_the_supplier_can_still_be_pinned_off(): void
    {
        $variant = $this->sync(enabled: false);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->patchJson(route('admin.api.catalog.variants.availability', $variant), ['is_available' => false])
            ->assertOk()
            ->assertJson(['is_available' => false, 'admin_disabled' => true, 'supplier_available' => false]);

        $this->assertSame($admin->id, $variant->fresh()->admin_disabled_by);
        $this->assertFalse($this->sync(enabled: true)->is_available, 'the pin must hold when the supplier switches it back on');
    }

    /**
     * The drawer used to skip the request whenever the offer was already off,
     * so Disabled never pinned it. The admin catalog page cannot render under
     * the test database, so this reads the drawer markup directly.
     */
    public function test_the_drawer_pins_an_offer_that_is_off_but_not_yet_switched_off_by_an_admin(): void
    {
        $markup = file_get_contents(resource_path('views/admin/products.blade.php'));

        $this->assertTrue(
            str_contains($markup, "'isAdminDisabled' => \$variant->isAdminDisabled(),"),
            'the drawer payload must say whether an admin pinned the offer off',
        );
        $this->assertTrue(
            str_contains($markup, 'const alreadySet = value ? this.data.isAvailable : this.data.isAdminDisabled;'),
            'Disabled must only be skipped once the offer is pinned off',
        );
        $this->assertFalse(
            str_contains($markup, 'this.data.isAvailable === value'),
            'the old guard skipped Disabled for any offer already off',
        );
    }

    /**
     * Top-up packages have no catalog feed and their writer never recorded a
     * supplier verdict, so the backfill turned a package switched off by hand
     * into one the supplier had withdrawn, and switching it on was refused.
     */
    public function test_a_top_up_package_switched_off_before_the_override_can_be_switched_back_on(): void
    {
        $parent = $this->parentEsim();
        $topups = app(AiraloTopupCatalogService::class);
        $package = ['id' => 'topup-1', 'net_price' => 5.0, 'price' => 10.0];
        $variant = $topups->upsertForParent($parent, [$package])['topup-1'];

        // What the backfill left behind for a package switched off by hand.
        ProductVariant::query()->whereKey($variant->id)->toBase()->update([
            'is_available' => false,
            'supplier_available' => false,
        ]);

        $topups->upsertForParent($parent, [$package]);

        $fetched = $variant->fresh();
        $this->assertTrue($fetched->supplier_available, 'the supplier just listed it');
        $this->assertFalse($fetched->is_available, 'a fetch alone never undoes a hand toggle');

        $this->actingAs($this->admin(), 'admin')
            ->patchJson(route('admin.api.catalog.variants.availability', $variant), ['is_available' => true])
            ->assertOk()
            ->assertJson(['is_available' => true, 'blocked_by_supplier' => false]);
    }

    public function test_a_top_up_package_switched_off_by_an_admin_stays_off_across_a_fetch(): void
    {
        $parent = $this->parentEsim();
        $topups = app(AiraloTopupCatalogService::class);
        $package = ['id' => 'topup-1', 'net_price' => 5.0, 'price' => 10.0];
        $variant = $topups->upsertForParent($parent, [$package])['topup-1'];

        $variant->adminDisable();
        $topups->upsertForParent($parent, [$package]);

        $this->assertFalse($variant->fresh()->is_available);
        $this->assertTrue($variant->fresh()->supplier_available);
    }

    /**
     * Nothing fetches top-up packages on a schedule, so the migration frees the
     * packages the backfill stranded. It must never put anything on sale, and
     * must leave every feed's own verdict alone.
     */
    public function test_the_heal_migration_frees_stranded_top_up_packages_and_puts_nothing_on_sale(): void
    {
        $product = Product::factory()->create();
        $stranded = ProductVariant::factory()->for($product)->create([
            'provider_offer_id' => 'airalo_topup_japan-7days-1gb',
            'catalog_feed' => null,
            'is_available' => false,
            'supplier_available' => false,
        ]);
        $pinned = ProductVariant::factory()->for($product)->create([
            'provider_offer_id' => 'airalo_topup_japan-30days-3gb',
            'catalog_feed' => null,
            'supplier_available' => false,
        ]);
        $pinned->adminDisable();
        $feedOffer = ProductVariant::factory()->for($product)->create([
            'provider_offer_id' => 'orange-cm-1000',
            'catalog_feed' => CatalogFeed::ZenditTopups,
            'is_available' => false,
            'supplier_available' => false,
        ]);

        $migration = require database_path('migrations/2026_09_27_201022_heal_supplier_available_on_topup_package_variants.php');
        $migration->up();

        $this->assertTrue($stranded->fresh()->supplier_available);
        $this->assertFalse($stranded->fresh()->is_available, 'nothing goes on sale from a migration');
        $this->assertTrue($pinned->fresh()->supplier_available);
        $this->assertFalse($pinned->fresh()->is_available);
        $this->assertTrue($pinned->fresh()->isAdminDisabled(), 'an admin pin is left alone');
        $this->assertFalse($feedOffer->fresh()->supplier_available, 'a feed offer keeps the verdict its sync wrote');
    }
}
