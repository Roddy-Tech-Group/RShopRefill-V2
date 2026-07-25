<?php

namespace Tests\Feature\Pricing;

use App\Domain\Cart\Services\CartPricingService;
use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Pins the cost-anchored margin floor (config pricing.floor_mode) — the fix
 * for USD face-value products (US airtime) selling below supplier cost. The
 * legacy floor anchored on the markup BASE, so a variant whose supplier cost
 * exceeded face x (1 + markup) sold at a loss with no signal. Enforce mode
 * floors on max(base, cost): strictly a ratchet — it can raise a below-cost
 * price but can never lower an existing one.
 */
class CostFloorModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'pricing.safety_markup_percent' => 10,
            'pricing.min_margin_percent' => 1,
            'pricing.floor_mode' => 'legacy',
        ]);
    }

    /**
     * A Straight-Talk-shaped variant: USD airtime whose supplier cost is above
     * face value, so the legacy face-based price is a guaranteed loss.
     */
    private function makeVariant(array $overrides = []): ProductVariant
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

        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'provider_offer_id' => 'offer-'.uniqid(),
            'currency' => 'USD',
            'face_value' => 45.00,
            'cost_price' => 46.35,
            'retail_price' => 45.00,
        ], $overrides));
    }

    public function test_legacy_mode_reproduces_the_below_cost_bug(): void
    {
        PricingRule::create(['markup_type' => 'percentage', 'markup_value' => 2, 'is_active' => true]);

        $pricing = (new CartPricingService)->calculatePricing($this->makeVariant(), 1);

        // face 45 x 1.02 = 45.90 — under the 46.35 cost. This is the historic
        // behaviour the floor rollout exists to kill; pinned so the mode flag
        // provably changes it.
        $this->assertEqualsWithDelta(45.90, (float) $pricing['unit_price_snapshot'], 0.001);
        $this->assertLessThan(46.35, (float) $pricing['unit_price_snapshot']);
    }

    public function test_enforce_mode_lifts_a_below_cost_price_to_the_cost_floor(): void
    {
        config(['pricing.floor_mode' => 'enforce']);
        PricingRule::create(['markup_type' => 'percentage', 'markup_value' => 2, 'is_active' => true]);

        $pricing = (new CartPricingService)->calculatePricing($this->makeVariant(), 1);

        // Floor = cost 46.35 x 1.01 = 46.8135.
        $this->assertEqualsWithDelta(46.8135, (float) $pricing['unit_price_snapshot'], 0.001);
        $this->assertGreaterThan(46.35, (float) $pricing['unit_price_snapshot']);
        // markup_amount reflects true margin over cost, not the face-based figure.
        $this->assertEqualsWithDelta(0.4635, (float) $pricing['markup_amount'], 0.001);
    }

    public function test_shadow_mode_charges_the_legacy_price(): void
    {
        config(['pricing.floor_mode' => 'shadow']);
        PricingRule::create(['markup_type' => 'percentage', 'markup_value' => 2, 'is_active' => true]);

        $pricing = (new CartPricingService)->calculatePricing($this->makeVariant(), 1);

        $this->assertEqualsWithDelta(45.90, (float) $pricing['unit_price_snapshot'], 0.001);
    }

    public function test_enforce_mode_never_lowers_a_healthy_price(): void
    {
        config(['pricing.floor_mode' => 'enforce']);

        // Gift-card shape: cost 9.50 under face 10. A zero markup rule makes
        // the legacy base floor (10 x 1.01 = 10.10) the binding one; the lower
        // cost floor (9.595) must NOT replace it — the ratchet only raises.
        $variant = $this->makeVariant(['face_value' => 10.00, 'cost_price' => 9.50, 'retail_price' => 10.00]);
        PricingRule::create(['markup_type' => 'fixed', 'markup_value' => 0, 'is_active' => true]);

        $pricing = (new CartPricingService)->calculatePricing($variant, 1);

        $this->assertEqualsWithDelta(10.10, (float) $pricing['unit_price_snapshot'], 0.001);
    }

    public function test_enforce_mode_is_a_no_op_for_non_usd_variants(): void
    {
        // Non-USD variants already price from cost (base == cost), so both
        // floors coincide and the mode flag must not move the price.
        $variant = $this->makeVariant(['currency' => 'NGN', 'face_value' => 4700, 'cost_price' => 3.10, 'retail_price' => 4700]);
        PricingRule::create(['markup_type' => 'percentage', 'markup_value' => 5, 'is_active' => true]);

        $legacy = (new CartPricingService)->withFloorMode('legacy')->calculatePricing($variant, 1);
        $enforced = (new CartPricingService)->withFloorMode('enforce')->calculatePricing($variant, 1);

        $this->assertEqualsWithDelta(
            (float) $legacy['unit_price_snapshot'],
            (float) $enforced['unit_price_snapshot'],
            0.0001
        );
    }

    public function test_manual_override_below_cost_is_clamped_in_enforce_mode(): void
    {
        config(['pricing.floor_mode' => 'enforce']);

        $variant = $this->makeVariant(['manual_retail_price_usd' => 40.00]);

        $pricing = (new CartPricingService)->calculatePricing($variant, 1);

        // Override 40 sits under cost 46.35; clamped to cost x 1.01.
        $this->assertEqualsWithDelta(46.8135, (float) $pricing['unit_price_snapshot'], 0.001);
    }

    public function test_manual_override_below_cost_is_honoured_when_flagged_allow_below_cost(): void
    {
        config(['pricing.floor_mode' => 'enforce']);

        $variant = $this->makeVariant(['manual_retail_price_usd' => 40.00, 'allow_below_cost' => true]);

        $pricing = (new CartPricingService)->calculatePricing($variant, 1);

        $this->assertEqualsWithDelta(40.00, (float) $pricing['unit_price_snapshot'], 0.001);
    }

    public function test_manual_override_below_cost_is_honoured_in_legacy_mode(): void
    {
        $variant = $this->makeVariant(['manual_retail_price_usd' => 40.00]);

        $pricing = (new CartPricingService)->calculatePricing($variant, 1);

        $this->assertEqualsWithDelta(40.00, (float) $pricing['unit_price_snapshot'], 0.001);
    }

    public function test_manual_override_above_the_floor_is_untouched_in_enforce_mode(): void
    {
        config(['pricing.floor_mode' => 'enforce']);

        $variant = $this->makeVariant(['manual_retail_price_usd' => 49.99]);

        $pricing = (new CartPricingService)->calculatePricing($variant, 1);

        $this->assertEqualsWithDelta(49.99, (float) $pricing['unit_price_snapshot'], 0.001);
    }

    public function test_with_floor_mode_overrides_the_configured_mode(): void
    {
        config(['pricing.floor_mode' => 'enforce']);
        PricingRule::create(['markup_type' => 'percentage', 'markup_value' => 2, 'is_active' => true]);

        $legacy = (new CartPricingService)->withFloorMode('legacy')->calculatePricing($this->makeVariant(), 1);

        $this->assertEqualsWithDelta(45.90, (float) $legacy['unit_price_snapshot'], 0.001);
    }

    public function test_unknown_floor_mode_falls_back_to_legacy(): void
    {
        config(['pricing.floor_mode' => 'enfrce']);
        PricingRule::create(['markup_type' => 'percentage', 'markup_value' => 2, 'is_active' => true]);

        $pricing = (new CartPricingService)->calculatePricing($this->makeVariant(), 1);

        $this->assertEqualsWithDelta(45.90, (float) $pricing['unit_price_snapshot'], 0.001);
    }
}
