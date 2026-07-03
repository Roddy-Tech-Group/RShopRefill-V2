<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Services\AiraloTopupCatalogService;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Providers\AiraloFulfillmentProvider;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Airalo top-ups become real catalog rows under an "Airalo Top-Up" subcategory so
 * admins can see + price them in Admin -> Products, and the top-up checkout prices
 * from those catalog variants (honouring per-variant overrides + availability).
 */
class AiraloTopupCatalogTest extends TestCase
{
    use RefreshDatabase;

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

    private function fulfilledEsimItem(Product $parent): OrderItem
    {
        $variant = ProductVariant::factory()->for($parent)->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $parent->category_id]);

        $order = Order::create([
            'user_id' => User::factory()->create([
                'transaction_pin' => Hash::make('1234'),
                'transaction_pin_set_at' => now(),
            ])->id,
            'order_number' => 'RSR-PARENT-'.uniqid(),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 0,
            'markup_amount' => 0,
            'total_amount' => 0,
            'payment_method' => 'wallet',
            'payment_status' => PaymentStatus::Paid,
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'order_status' => OrderStatus::Completed,
        ]);

        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $parent->id,
            'product_variant_id' => $variant->id,
            'category_id' => $parent->category_id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'airalo',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 5.00,
            'provider_cost_usd' => 5.00,
            'markup_amount' => 0,
            'subtotal_amount' => 5.00,
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'fulfillment_payload' => ['iccid' => '8944000000000000001'],
        ]);
    }

    public function test_upsert_creates_the_airalo_topup_subcategory_product_and_variants(): void
    {
        $parent = $this->parentEsim();

        $map = app(AiraloTopupCatalogService::class)->upsertForParent($parent, [
            ['id' => 'topup-1', 'net_price' => 5.0, 'price' => 10.0, 'data' => '1 GB', 'day' => 7],
            ['id' => 'topup-2', 'net_price' => 12.0, 'price' => 20.0, 'data' => '3 GB', 'day' => 30],
        ]);

        $this->assertCount(2, $map);

        $sub = Subcategory::where('slug', 'airalo-topup')->first();
        $this->assertNotNull($sub);
        $this->assertSame('Airalo Top-Up', $sub->name);

        $variant = ProductVariant::where('provider_offer_id', 'airalo_topup_topup-1')->first();
        $this->assertNotNull($variant);
        $this->assertSame(5.0, (float) $variant->cost_price);
        $this->assertSame(10.0, (float) $variant->face_value);
        $this->assertTrue((bool) $variant->is_available);
        $this->assertSame($sub->id, $variant->subcategory_id);
        $this->assertTrue((bool) ($variant->metadata['is_topup'] ?? false));

        // Grouped under a "United States Top-Up" product in the eSIMs category.
        $product = $variant->product;
        $this->assertSame('airalo', $product->provider_name);
        $this->assertSame($sub->id, $product->subcategory_id);
        $this->assertSame('United States Top-Up', $product->name);

        // Hidden from the storefront eSIM store (which filters is_active=true) so
        // customers never see a top-up as a buyable fresh eSIM - it's admin-only.
        $this->assertFalse((bool) $product->is_active);
        $storefrontEsims = Product::where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('slug', 'esims'))
            ->pluck('slug');
        $this->assertNotContains($product->slug, $storefrontEsims);
    }

    public function test_resync_is_idempotent_and_preserves_admin_edits(): void
    {
        $parent = $this->parentEsim();
        $svc = app(AiraloTopupCatalogService::class);

        $svc->upsertForParent($parent, [['id' => 'topup-1', 'net_price' => 5.0, 'price' => 10.0]]);

        // Admin disables it and pins a manual price.
        ProductVariant::where('provider_offer_id', 'airalo_topup_topup-1')
            ->update(['is_available' => false, 'manual_retail_price_usd' => 7.77]);

        // Airalo re-syncs with a changed cost.
        $svc->upsertForParent($parent, [['id' => 'topup-1', 'net_price' => 6.0, 'price' => 11.0]]);

        $variant = ProductVariant::where('provider_offer_id', 'airalo_topup_topup-1')->get();
        $this->assertCount(1, $variant); // no duplicate row
        $variant = $variant->first();
        $this->assertSame(6.0, (float) $variant->cost_price);                 // cost refreshed
        $this->assertFalse((bool) $variant->is_available);                    // admin toggle kept
        $this->assertSame(7.77, (float) $variant->manual_retail_price_usd);   // override kept
    }

    public function test_sync_topups_command_backfills_from_a_fulfilled_esim(): void
    {
        $parent = $this->parentEsim();
        $this->fulfilledEsimItem($parent);

        $this->mock(AiraloFulfillmentProvider::class)
            ->shouldReceive('listTopupsForIccid')
            ->andReturn([['id' => 'tp-1', 'net_price' => 5.0, 'price' => 10.0]]);

        $this->artisan('airalo:sync-topups')->assertSuccessful();

        $this->assertDatabaseHas('product_variants', ['provider_offer_id' => 'airalo_topup_tp-1']);
    }

    public function test_topup_page_prices_from_the_catalog_variant_honouring_an_admin_override(): void
    {
        $this->withoutVite();

        $parent = $this->parentEsim();
        $item = $this->fulfilledEsimItem($parent);
        $user = $item->order->user;

        $this->mock(AiraloFulfillmentProvider::class)
            ->shouldReceive('listTopupsForIccid')
            ->andReturn([['id' => 'tp-1', 'net_price' => 5.0, 'price' => 10.0, 'data' => '1 GB', 'day' => 7]]);

        // Seed the catalog variant, then an admin pins a custom price.
        app(AiraloTopupCatalogService::class)->upsertForParent($parent, [['id' => 'tp-1', 'net_price' => 5.0, 'price' => 10.0]]);
        ProductVariant::where('provider_offer_id', 'airalo_topup_tp-1')->update(['manual_retail_price_usd' => 42.42]);

        $this->actingAs($user)
            ->get(route('dashboard.esim.topup', $item))
            ->assertOk()
            ->assertSee('42.42');   // admin override shown, not the ~$11 computed price
    }

    public function test_topup_page_hides_a_variant_the_admin_marked_unavailable(): void
    {
        $this->withoutVite();

        $parent = $this->parentEsim();
        $item = $this->fulfilledEsimItem($parent);
        $user = $item->order->user;

        $this->mock(AiraloFulfillmentProvider::class)
            ->shouldReceive('listTopupsForIccid')
            ->andReturn([['id' => 'tp-1', 'net_price' => 5.0, 'price' => 10.0, 'data' => '9 GB special', 'day' => 7]]);

        app(AiraloTopupCatalogService::class)->upsertForParent($parent, [['id' => 'tp-1', 'net_price' => 5.0, 'price' => 10.0]]);
        ProductVariant::where('provider_offer_id', 'airalo_topup_tp-1')->update(['is_available' => false]);

        $this->actingAs($user)
            ->get(route('dashboard.esim.topup', $item))
            ->assertOk()
            ->assertDontSee('9 GB special');   // hidden package not rendered
    }
}
