<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the admin sales-price override guardrail: an override below the
 * supplier-cost floor is rejected with a 422 that names the floor, unless the
 * admin explicitly sends allow_below_cost — which is persisted on the variant
 * (so the engine honours it in enforce mode) and reset when the override is
 * cleared.
 */
class AdminVariantPriceFloorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pricing.min_margin_percent' => 1]);
    }

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    private function variant(float $cost = 46.35): ProductVariant
    {
        $category = Category::firstOrCreate(['slug' => 'mobile-topups'], ['name' => 'Mobile Top-ups']);
        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Straight Talk US',
            'slug' => 'straight-talk-'.uniqid(),
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'provider_offer_id' => 'offer-'.uniqid(),
            'currency' => 'USD',
            'face_value' => 45.00,
            'cost_price' => $cost,
            'retail_price' => 45.00,
        ]);
    }

    public function test_override_below_the_cost_floor_is_rejected(): void
    {
        $variant = $this->variant();

        $this->actingAs($this->admin(), 'admin')
            ->patchJson(route('admin.api.catalog.variants.price.set', $variant), [
                'manual_retail_price_usd' => 40.00,
            ])
            ->assertStatus(422)
            ->assertJsonPath('cost_floor_usd', 46.8135);

        $this->assertNull($variant->fresh()->manual_retail_price_usd);
    }

    public function test_override_below_floor_is_accepted_with_explicit_allow_below_cost(): void
    {
        $variant = $this->variant();

        $this->actingAs($this->admin(), 'admin')
            ->patchJson(route('admin.api.catalog.variants.price.set', $variant), [
                'manual_retail_price_usd' => 40.00,
                'allow_below_cost' => true,
            ])
            ->assertOk()
            ->assertJsonPath('allow_below_cost', true);

        $fresh = $variant->fresh();
        $this->assertEqualsWithDelta(40.00, (float) $fresh->manual_retail_price_usd, 0.001);
        $this->assertTrue((bool) $fresh->allow_below_cost);
    }

    public function test_override_above_the_floor_is_accepted_without_the_flag(): void
    {
        $variant = $this->variant();

        $this->actingAs($this->admin(), 'admin')
            ->patchJson(route('admin.api.catalog.variants.price.set', $variant), [
                'manual_retail_price_usd' => 49.99,
            ])
            ->assertOk()
            ->assertJsonPath('allow_below_cost', false);

        $this->assertEqualsWithDelta(49.99, (float) $variant->fresh()->manual_retail_price_usd, 0.001);
    }

    public function test_zero_cost_variants_accept_any_override(): void
    {
        // No cost data means no floor to compare against — the audit command
        // reports these separately; blocking the admin here would help nobody.
        $variant = $this->variant(0.0);

        $this->actingAs($this->admin(), 'admin')
            ->patchJson(route('admin.api.catalog.variants.price.set', $variant), [
                'manual_retail_price_usd' => 1.00,
            ])
            ->assertOk();
    }

    public function test_clearing_the_override_resets_allow_below_cost(): void
    {
        $variant = $this->variant();
        $variant->update(['manual_retail_price_usd' => 40.00, 'allow_below_cost' => true]);

        $this->actingAs($this->admin(), 'admin')
            ->deleteJson(route('admin.api.catalog.variants.price.clear', $variant))
            ->assertOk();

        $fresh = $variant->fresh();
        $this->assertNull($fresh->manual_retail_price_usd);
        $this->assertFalse((bool) $fresh->allow_below_cost);
    }
}
