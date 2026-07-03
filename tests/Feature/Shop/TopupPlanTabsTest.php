<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mobile top-up detail page must split offers into Credit / Data / Bundle tabs and
 * show each plan's real benefits (data, minutes, SMS, validity) from the synced
 * Zendit metadata - not collapse everything to a bare "credit" amount.
 */
class TopupPlanTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_topup_detail_shows_credit_data_bundle_tabs_with_benefits(): void
    {
        $category = Category::firstOrCreate(['slug' => 'mobile-airtime'], ['name' => 'Mobile Airtime', 'type' => 'digital']);
        $subcategory = Subcategory::firstOrCreate(['category_id' => $category->id, 'slug' => 'mobile-top-up'], ['name' => 'Mobile Top Up']);

        $product = Product::factory()->create([
            'brand_key' => 'Testop',
            'country_code' => 'US',
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'is_active' => true,
        ]);

        ProductVariant::factory()->for($product)->create([
            'is_variable' => false, 'is_available' => true, 'face_value' => 5, 'retail_price' => 5,
            'metadata' => ['subTypes' => ['Mobile Top Up']],
        ]);
        ProductVariant::factory()->for($product)->create([
            'is_variable' => false, 'is_available' => true, 'face_value' => 3, 'retail_price' => 3,
            'metadata' => ['subTypes' => ['Mobile Data'], 'dataGB' => 2, 'durationDays' => 30],
        ]);
        ProductVariant::factory()->for($product)->create([
            'is_variable' => false, 'is_available' => true, 'face_value' => 10, 'retail_price' => 10,
            'metadata' => ['subTypes' => ['Mobile Bundle'], 'dataGB' => 10, 'voiceMinutes' => 3000, 'smsNumber' => 1500, 'durationDays' => 30],
        ]);

        $response = $this->get(route('shop.topup', 'testop'));

        $response->assertOk();
        $response->assertSee('Choose how to top up');
        // Switcher tabs (Credit panel is the amount selector)
        $response->assertSee('Bundles', false);
        // Benefit chips read from metadata, not hardcoded
        $response->assertSee('2GB data', false);
        // Whole-number GB must not lose its trailing zero (10 must stay "10GB", not "1GB").
        $response->assertSee('10GB data', false);
        $response->assertSee('30-day validity', false);
        $response->assertSee('3000 minutes', false);
        $response->assertSee('1500 SMS', false);
    }
}
