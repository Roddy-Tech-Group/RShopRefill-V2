<?php

namespace Tests\Feature\Catalog;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Catalog\Services\ZenditTopupNormalizer;
use App\Models\Admin;
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
    private function offer(bool $enabled = true): array
    {
        return [
            'brand' => 'Orange',
            'brandName' => 'Orange',
            'country' => 'CM',
            'offerId' => 'orange-cm-1000',
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
}
