<?php

namespace Tests\Feature\Fulfillment;

use App\Domain\Fulfillment\Enums\EsimStatus;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Services\EsimRegistry;
use App\Models\Category;
use App\Models\Esim;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The esims table: one row per eSIM delivered, written the moment the
 * delivery is saved, whichever path saved it.
 */
class EsimRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $slug = 'esims'): Category
    {
        return Category::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'type' => 'digital', 'is_active' => true, 'sort_order' => 0]);
    }

    /**
     * An Airalo delivery payload the way AiraloFulfillmentProvider normalises
     * it: the first sim flattened, every sim kept in raw_response.
     *
     * @param  array<int, string>  $iccids
     * @return array<string, mixed>
     */
    private function airaloPayload(array $iccids, string $package = 'Change-7 days-5GB'): array
    {
        return [
            'iccid' => $iccids[0] ?? null,
            'esim' => ['iccid' => $iccids[0] ?? null],
            'raw_response' => ['data' => [
                'id' => 991,
                'package' => $package,
                'sims' => array_map(fn (string $iccid) => ['iccid' => $iccid], $iccids),
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function esimItem(array $overrides = [], string $slug = 'esims'): OrderItem
    {
        $category = $this->category($slug);
        $product = Product::factory()->create(['category_id' => $category->id, 'name' => 'United States eSIM']);

        return OrderItem::factory()->create(array_merge([
            'order_id' => Order::factory()->create(['user_id' => User::factory()->create()->id])->id,
            'product_id' => $product->id,
            'product_variant_id' => ProductVariant::factory()->create(['product_id' => $product->id])->id,
            'category_id' => $category->id,
            'subcategory_id' => Subcategory::factory()->create(['category_id' => $category->id])->id,
            'provider_name' => 'airalo',
            'provider_offer_id' => 'airalo_change-plus-7days-5gb',
        ], $overrides));
    }

    public function test_a_delivered_esim_is_recorded_when_its_delivery_is_saved(): void
    {
        $item = $this->esimItem();

        $item->update([
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'fulfillment_payload' => $this->airaloPayload(['8900000000000000001']),
            'delivered_at' => Carbon::parse('2026-09-10 08:00:00'),
        ]);

        $esim = Esim::sole();
        $this->assertSame('8900000000000000001', $esim->iccid);
        $this->assertSame($item->id, $esim->order_item_id);
        $this->assertSame($item->order->user_id, $esim->user_id);
        $this->assertSame('airalo', $esim->provider);
        $this->assertSame('Change-7 days-5GB', $esim->package_name);
        $this->assertSame('airalo_change-plus-7days-5gb', $esim->package_code);
        $this->assertSame(EsimStatus::Inactive, $esim->status);
        $this->assertSame('2026-09-10 08:00:00', $esim->purchased_at->format('Y-m-d H:i:s'));
    }

    /**
     * Three eSIMs bought on one line: Airalo issues three, the normalised
     * payload keeps only the first. Every one is recorded.
     */
    public function test_every_esim_of_a_multi_unit_line_is_recorded(): void
    {
        $item = $this->esimItem(['quantity' => 3]);

        $item->update([
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'fulfillment_payload' => $this->airaloPayload(['8900000000000000001', '8900000000000000002', '8900000000000000003']),
        ]);

        $this->assertSame(
            ['8900000000000000001', '8900000000000000002', '8900000000000000003'],
            Esim::orderBy('iccid')->pluck('iccid')->all(),
        );
    }

    public function test_saving_the_same_delivery_again_records_nothing_twice(): void
    {
        $item = $this->esimItem();
        $item->update(['fulfillment_status' => FulfillmentStatus::Fulfilled, 'fulfillment_payload' => $this->airaloPayload(['8900000000000000001'])]);

        $item->update(['fulfillment_payload' => $this->airaloPayload(['8900000000000000001'], 'Renamed')]);
        app(EsimRegistry::class)->recordFor($item->fresh());

        $this->assertSame(1, Esim::count());
    }

    public function test_an_item_that_has_not_delivered_is_not_recorded(): void
    {
        $item = $this->esimItem();

        $item->update(['fulfillment_status' => FulfillmentStatus::Failed, 'fulfillment_payload' => $this->airaloPayload(['8900000000000000001'])]);

        $this->assertSame(0, Esim::count());
    }

    /**
     * A top-up refills an eSIM that already has a row. It never becomes an
     * eSIM of its own.
     */
    public function test_a_top_up_is_not_a_new_esim(): void
    {
        $item = $this->esimItem(['metadata' => ['parent_iccid' => '8900000000000000001']]);

        $item->update(['fulfillment_status' => FulfillmentStatus::Fulfilled, 'fulfillment_payload' => $this->airaloPayload(['8900000000000000001'])]);

        $this->assertSame(0, Esim::count());
    }

    public function test_a_gift_card_is_not_an_esim(): void
    {
        $item = $this->esimItem(slug: 'gift-cards');

        $item->update(['fulfillment_status' => FulfillmentStatus::Fulfilled, 'fulfillment_payload' => ['iccid' => '8900000000000000001']]);

        $this->assertSame(0, Esim::count());
    }

    /**
     * A Zendit eSIM carries its ICCID only in the normalised payload, and no
     * supplier package name, so the package falls back to the product's.
     */
    public function test_an_esim_without_a_raw_sim_list_is_read_from_the_normalised_payload(): void
    {
        $item = $this->esimItem(['provider_name' => 'zendit']);

        $item->update(['fulfillment_status' => FulfillmentStatus::Fulfilled, 'fulfillment_payload' => ['esim' => ['iccid' => ' 8900000000000000009 ']]]);

        $esim = Esim::sole();
        $this->assertSame('8900000000000000009', $esim->iccid);
        $this->assertSame('zendit', $esim->provider);
        $this->assertSame('United States eSIM', $esim->package_name);
    }

    /**
     * The customer already has the eSIM. A record that fails to write must
     * never fail the delivery.
     */
    public function test_a_registry_failure_never_fails_the_delivery(): void
    {
        $item = $this->esimItem();
        $this->mock(EsimRegistry::class, fn ($mock) => $mock->shouldReceive('recordFor')->andThrow(new \RuntimeException('registry down')));

        $item->update(['fulfillment_status' => FulfillmentStatus::Fulfilled, 'fulfillment_payload' => $this->airaloPayload(['8900000000000000001'])]);

        $this->assertSame(FulfillmentStatus::Fulfilled, $item->fresh()->fulfillment_status);
    }

    // ---------------------------------------------------------- backfill

    public function test_the_backfill_records_esims_delivered_before_the_table_existed(): void
    {
        $item = $this->esimItem();
        OrderItem::withoutEvents(fn () => $item->update([
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'fulfillment_payload' => $this->airaloPayload(['8900000000000000001', '8900000000000000002']),
        ]));
        $this->assertSame(0, Esim::count());

        $this->artisan('esims:backfill', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN: 1 eSIM items scanned; 2 eSIMs would be recorded; 0 already recorded.')
            ->assertSuccessful();
        $this->assertSame(0, Esim::count());

        $this->artisan('esims:backfill')->assertSuccessful();
        $this->artisan('esims:backfill')
            ->expectsOutputToContain('0 eSIMs recorded; 2 already recorded.')
            ->assertSuccessful();

        $this->assertSame(2, Esim::count());
    }

    public function test_the_backfill_flags_a_delivered_esim_with_no_iccid(): void
    {
        $item = $this->esimItem();
        OrderItem::withoutEvents(fn () => $item->update(['fulfillment_status' => FulfillmentStatus::Fulfilled, 'fulfillment_payload' => ['qrcode_url' => 'x']]));

        $this->artisan('esims:backfill')
            ->expectsOutputToContain('1 delivered eSIM items carry no ICCID')
            ->assertSuccessful();
    }

    // ---------------------------------------------------------- status

    public function test_supplier_statuses_map_to_ours(): void
    {
        $this->assertSame(EsimStatus::Inactive, EsimStatus::fromProvider('NOT_ACTIVE', 5120, 5120, false));
        $this->assertSame(EsimStatus::Active, EsimStatus::fromProvider('ACTIVE', 3000, 5120, false));
        $this->assertSame(EsimStatus::DataLow, EsimStatus::fromProvider('ACTIVE', 1280, 5120, false));
        $this->assertSame(EsimStatus::Active, EsimStatus::fromProvider('ACTIVE', 0, 0, true));
        $this->assertSame(EsimStatus::DataUsedUp, EsimStatus::fromProvider('FINISHED', 0, 5120, false));
        $this->assertSame(EsimStatus::Expired, EsimStatus::fromProvider('EXPIRED', 100, 5120, false));
        $this->assertSame(EsimStatus::Recycled, EsimStatus::fromProvider('RECYCLED', null, null, false));
        $this->assertSame(EsimStatus::Unknown, EsimStatus::fromProvider('SOMETHING_NEW', null, null, false));
        $this->assertSame(EsimStatus::Unknown, EsimStatus::fromProvider(null, null, null, false));
    }
}
