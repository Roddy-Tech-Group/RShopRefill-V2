<?php

namespace Tests\Feature\Pricing;

use App\Domain\Cart\Services\CartPricingService;
use App\Enums\PricingBasis;
use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopupPricingRulesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Category $airtime;

    private Category $giftCards;

    private Product $nigerianOperator;

    private Product $internationalOperator;

    private Product $faceBasisPrepaid;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
            'pricing.min_margin_usd' => 0.0,
            'pricing.floor_mode' => 'legacy',
        ]);

        $this->airtime = Category::create(['name' => 'Mobile Airtime', 'slug' => 'mobile-airtime', 'type' => 'digital']);
        $this->giftCards = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);

        $this->nigerianOperator = Product::factory()->create([
            'category_id' => $this->airtime->id,
            'country_code' => 'NG',
            'currency_code' => 'NGN',
            'pricing_basis' => PricingBasis::SupplierCost,
            'name' => 'MTN (NG)',
        ]);

        $this->internationalOperator = Product::factory()->create([
            'category_id' => $this->airtime->id,
            'country_code' => 'CM',
            'pricing_basis' => PricingBasis::SupplierCost,
            'name' => 'Orange (CM)',
        ]);

        $this->faceBasisPrepaid = Product::factory()->create([
            'category_id' => $this->airtime->id,
            'country_code' => 'US',
            'pricing_basis' => PricingBasis::FaceValue,
            'name' => 'T-Mobile (US)',
        ]);
    }

    public function test_it_writes_the_category_default_and_the_nigerian_pins(): void
    {
        $this->artisan('pricing:apply-topup-rules')->assertExitCode(0);

        $categoryRule = PricingRule::whereNull('product_id')->where('category_id', $this->airtime->id)->sole();
        $this->assertSame('percentage', $categoryRule->markup_type);
        $this->assertEqualsWithDelta(8.0, (float) $categoryRule->markup_value, 0.0001);

        $pin = PricingRule::where('product_id', $this->nigerianOperator->id)->sole();
        $this->assertSame('percentage', $pin->markup_type);
        $this->assertEqualsWithDelta(2.0, (float) $pin->markup_value, 0.0001);

        // ONLY Nigeria is pinned. Fee-loaded face-value prepaid deliberately
        // rides the 8% default (2% on a $49 plan earned $0.98, which the
        // transaction fees alone can swallow), so the rule table stays small.
        $this->assertDatabaseMissing('pricing_rules', ['product_id' => $this->internationalOperator->id]);
        $this->assertDatabaseMissing('pricing_rules', ['product_id' => $this->faceBasisPrepaid->id]);
        $this->assertSame(1, PricingRule::whereNotNull('product_id')->count());
    }

    public function test_the_engine_prices_each_tier_as_agreed(): void
    {
        $this->artisan('pricing:apply-topup-rules')->assertExitCode(0);

        $nigerianVariant = ProductVariant::factory()->create([
            'product_id' => $this->nigerianOperator->id,
            'currency' => 'NGN',
            'face_value' => 500,
            'cost_price' => 0.38,
        ]);
        $internationalVariant = ProductVariant::factory()->create([
            'product_id' => $this->internationalOperator->id,
            'currency' => 'USD',
            'face_value' => 5.00,
            'cost_price' => 3.55,
        ]);
        $faceBasisVariant = ProductVariant::factory()->create([
            'product_id' => $this->faceBasisPrepaid->id,
            'currency' => 'USD',
            'face_value' => 35.00,
            'cost_price' => 34.00,
        ]);

        $service = app(CartPricingService::class);
        $priceOf = fn (ProductVariant $variant): float => $service->resolveVariantRetailPrice(
            $variant->fresh('product'),
            $service->resolveMarkupBase($variant->fresh('product')),
        );

        // Nigeria: supplier cost + the pinned 2%.
        $this->assertEqualsWithDelta(0.38 * 1.02, $priceOf($nigerianVariant), 0.0001);

        // International: supplier cost + the 8% category default.
        $this->assertEqualsWithDelta(3.55 * 1.08, $priceOf($internationalVariant), 0.0001);

        // Face-basis prepaid: no pin, so printed face + the 8% default. The
        // deliberate margin decision of 2026-09-02; the enforce floor still
        // guarantees it can never land below cost.
        $this->assertEqualsWithDelta(35.00 * 1.08, $priceOf($faceBasisVariant), 0.0001);
    }

    public function test_it_is_idempotent_and_updates_an_existing_category_rule_in_place(): void
    {
        PricingRule::create([
            'category_id' => $this->airtime->id,
            'markup_type' => 'percentage',
            'markup_value' => 2,
            'is_active' => true,
        ]);

        $this->artisan('pricing:apply-topup-rules')->assertExitCode(0);
        $this->artisan('pricing:apply-topup-rules')->assertExitCode(0);

        $this->assertSame(1, PricingRule::whereNull('product_id')->where('category_id', $this->airtime->id)->count());
        $this->assertSame(1, PricingRule::where('product_id', $this->nigerianOperator->id)->count());
        $this->assertEqualsWithDelta(
            8.0,
            (float) PricingRule::whereNull('product_id')->where('category_id', $this->airtime->id)->sole()->markup_value,
            0.0001,
        );
    }

    public function test_it_leaves_other_categories_rules_alone(): void
    {
        $giftCardRule = PricingRule::create([
            'category_id' => $this->giftCards->id,
            'markup_type' => 'percentage',
            'markup_value' => 12,
            'is_active' => true,
        ]);

        $this->artisan('pricing:apply-topup-rules')->assertExitCode(0);

        $this->assertEqualsWithDelta(12.0, (float) $giftCardRule->fresh()->markup_value, 0.0001);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('pricing:apply-topup-rules --dry-run')->assertExitCode(0);

        $this->assertSame(0, PricingRule::count());
    }
}
