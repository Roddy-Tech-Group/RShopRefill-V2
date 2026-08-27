<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Services\AiraloEsimNormalizer;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiraloEsimNormalizerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes): Product
    {
        $category = Category::firstOrCreate(['slug' => 'esims'], ['name' => 'eSIMs', 'type' => 'digital']);

        return Product::factory()->create(['category_id' => $category->id] + $attributes);
    }

    private function sync(): void
    {
        // One operator that CLAIMS voice at the operator level (the old false-flag
        // trigger), but only one of its packages actually carries minutes.
        $rawItem = [
            'country_code' => 'US',
            'title' => 'United States',
            'operators' => [[
                'title' => 'TestNet',
                'plan_type' => 'data-voice-text',
                'coverages' => [[
                    'name' => 'United States',
                    'networks' => [
                        ['name' => 'T-Mobile', 'types' => ['5G']],
                        ['name' => 'Verizon', 'types' => [['name' => '5G']]],
                    ],
                ]],
                'packages' => [
                    ['id' => 'us-1gb-7d', 'data' => '1 GB', 'day' => 7, 'price' => 9, 'net_price' => 3, 'voice' => null, 'text' => null],
                    ['id' => 'us-5gb-voice', 'data' => '5 GB', 'day' => 30, 'price' => 50, 'net_price' => 36, 'voice' => 50, 'text' => 50],
                ],
            ]],
        ];

        (new AiraloEsimNormalizer)->normalizeAndSave($rawItem, 'airalo');
    }

    public function test_data_only_package_is_not_flagged_as_voice(): void
    {
        $this->sync();

        $variant = ProductVariant::where('provider_offer_id', 'airalo_us-1gb-7d')->firstOrFail();

        $this->assertFalse($variant->metadata['supports_voice']);
        $this->assertFalse($variant->metadata['supports_sms']);
        $this->assertNull($variant->metadata['voice_limit']);   // no "Included" placeholder
        $this->assertNull($variant->metadata['sms_limit']);
        $this->assertSame('data_only', $variant->metadata['plan_type']);
    }

    public function test_real_voice_package_captures_the_actual_minutes(): void
    {
        $this->sync();

        $variant = ProductVariant::where('provider_offer_id', 'airalo_us-5gb-voice')->firstOrFail();

        $this->assertTrue($variant->metadata['supports_voice']);
        $this->assertTrue($variant->metadata['supports_sms']);
        $this->assertSame('50', $variant->metadata['voice_limit']);
        $this->assertSame('50', $variant->metadata['sms_limit']);
        $this->assertSame('voice_sms_data', $variant->metadata['plan_type']);
    }

    public function test_carrier_networks_are_captured_from_operator_coverages(): void
    {
        $this->sync();

        $variant = ProductVariant::where('provider_offer_id', 'airalo_us-1gb-7d')->firstOrFail();
        $detail = $variant->metadata['networks_detail'];

        $names = array_column($detail, 'name');
        $this->assertContains('T-Mobile', $names);
        $this->assertContains('Verizon', $names);
        // Speed parsed from both ['5G'] (string) and [['name'=>'5G']] (object) shapes.
        foreach ($detail as $net) {
            $this->assertSame('5G', $net['speed']);
        }
    }

    public function test_pricing_uses_net_price_as_cost_and_retail_as_face(): void
    {
        $this->sync();

        $variant = ProductVariant::where('provider_offer_id', 'airalo_us-5gb-voice')->firstOrFail();

        $this->assertEqualsWithDelta(36.0, (float) $variant->cost_price, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $variant->face_value, 0.001);
    }

    public function test_non_country_product_is_named_by_region_not_generic_global(): void
    {
        // Airalo sends no country code for regional/worldwide products, so they
        // are stored under the 'WW' marker. They must still carry their real
        // region name (not a blanket "Global eSIM") so the admin can tell them
        // apart.
        $rawItem = [
            'country_code' => '',
            'title' => 'Middle East and North Africa',
            'operators' => [[
                'title' => 'Menalink',
                'plan_type' => 'data',
                'packages' => [
                    ['id' => 'mena-30days-unlimited', 'data' => 'Unlimited', 'day' => 30, 'price' => 51.9, 'net_price' => 51.9, 'is_unlimited' => true],
                ],
            ]],
        ];

        (new AiraloEsimNormalizer)->normalizeAndSave($rawItem, 'airalo');

        $product = Product::where('slug', 'esim-ww-middle-east-and-north-africa')->firstOrFail();

        $this->assertSame('WW', $product->country_code);
        $this->assertSame('Middle East and North Africa eSIM', $product->name);
    }

    /**
     * @return array<string, mixed>
     */
    private function globalItem(string $title, string $airaloSlug = 'discover-global', string $packageId = 'discover-1gb-7d'): array
    {
        return [
            'country_code' => '',
            'slug' => $airaloSlug,
            'title' => $title,
            'operators' => [[
                'title' => 'Discover',
                'plan_type' => 'data',
                'packages' => [
                    ['id' => $packageId, 'data' => '1 GB', 'day' => 7, 'price' => 9, 'net_price' => 5],
                ],
            ]],
        ];
    }

    public function test_slug_owned_by_another_provider_gets_a_provider_suffix_instead_of_crashing(): void
    {
        // Production 2026-08-27: Zendit already owns esim-ww-global and Airalo
        // renamed "Discover Global" to "Global". The unique index on slug made
        // the insert throw and killed the whole sync page.
        $zendit = $this->product(['provider_name' => 'zendit', 'slug' => 'esim-ww-global']);

        (new AiraloEsimNormalizer)->normalizeAndSave($this->globalItem('Global', 'world'), 'airalo');

        $airalo = Product::where('provider_name', 'airalo')->where('provider_reference', 'world')->firstOrFail();
        $this->assertSame('esim-ww-global-airalo', $airalo->slug);
        $this->assertSame('Global eSIM', $airalo->name);
        $this->assertSame('esim-ww-global', $zendit->fresh()->slug);

        $variant = ProductVariant::where('provider_offer_id', 'airalo_discover-1gb-7d')->firstOrFail();
        $this->assertSame($airalo->id, $variant->product_id);
    }

    public function test_renamed_product_updates_the_existing_row_via_provider_reference(): void
    {
        $normalizer = new AiraloEsimNormalizer;
        $normalizer->normalizeAndSave($this->globalItem('Discover Global'), 'airalo');
        $original = Product::where('slug', 'esim-ww-discover-global')->firstOrFail();

        $normalizer->normalizeAndSave($this->globalItem('Global'), 'airalo');

        $this->assertSame(1, Product::where('provider_name', 'airalo')->count());
        $original->refresh();
        // The storefront URL never moves under a rename.
        $this->assertSame('esim-ww-discover-global', $original->slug);
        $this->assertSame('discover-global', $original->provider_reference);
        $this->assertStringContainsString('High-speed eSIM for Global', $original->description);
        $this->assertSame($original->id, ProductVariant::where('provider_offer_id', 'airalo_discover-1gb-7d')->firstOrFail()->product_id);
    }

    public function test_renamed_product_synced_before_provider_reference_existed_is_found_through_its_variants(): void
    {
        // Rows synced by the old normalizer carry no provider_reference, so a
        // rename can only be recognised by the packages already attached.
        $legacy = $this->product([
            'provider_name' => 'airalo',
            'provider_reference' => null,
            'slug' => 'esim-ww-discover-global',
            'name' => 'Discover Global eSIM',
        ]);
        ProductVariant::factory()->create([
            'product_id' => $legacy->id,
            'provider_offer_id' => 'airalo_discover-1gb-7d',
        ]);
        $this->product(['provider_name' => 'zendit', 'slug' => 'esim-ww-global']);

        (new AiraloEsimNormalizer)->normalizeAndSave($this->globalItem('Global'), 'airalo');

        $this->assertSame(1, Product::where('provider_name', 'airalo')->count());
        $legacy->refresh();
        $this->assertSame('esim-ww-discover-global', $legacy->slug);
        $this->assertSame('discover-global', $legacy->provider_reference);
        $this->assertSame('Discover Global eSIM', $legacy->name);
    }

    public function test_provider_suffixed_slug_is_itself_deduplicated(): void
    {
        $this->product(['provider_name' => 'zendit', 'slug' => 'esim-ww-global']);
        $this->product(['provider_name' => 'other', 'slug' => 'esim-ww-global-airalo']);

        (new AiraloEsimNormalizer)->normalizeAndSave($this->globalItem('Global', 'world'), 'airalo');

        $this->assertDatabaseHas('products', ['provider_name' => 'airalo', 'slug' => 'esim-ww-global-airalo-2']);
    }
}
