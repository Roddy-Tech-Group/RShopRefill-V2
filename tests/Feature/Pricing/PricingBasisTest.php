<?php

namespace Tests\Feature\Pricing;

use App\Domain\Cart\Services\CartPricingService;
use App\Domain\Catalog\Services\ZenditTopupNormalizer;
use App\Enums\PricingBasis;
use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Pins the explicit PricingBasis (products.pricing_basis) that replaces the
 * currency heuristic, and the two properties the rollout depends on:
 *
 *  - NULL basis keeps the legacy heuristic bit-for-bit (deploy is a no-op);
 *  - `pricing:backfill-basis` assigns each product the basis that reproduces
 *    its current price (parity), so flipping the engine to read the column
 *    moves no price until a deliberate `pricing:migrate-basis`.
 *
 * The currency eligibility guard is basis-independent: face-flavoured bases
 * degrade to supplier cost for non-USD variants, exactly like the heuristic.
 */
class PricingBasisTest extends TestCase
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

        PricingRule::create(['markup_type' => 'percentage', 'markup_value' => 10, 'is_active' => true]);
    }

    private function makeProduct(string $categorySlug, ?PricingBasis $basis = null): Product
    {
        $category = Category::firstOrCreate(['slug' => $categorySlug], ['name' => ucfirst($categorySlug)]);

        return Product::create([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'pricing_basis' => $basis,
            'name' => ucfirst($categorySlug).' Product',
            'slug' => $categorySlug.'-'.uniqid(),
        ]);
    }

    private function makeVariant(Product $product, array $overrides = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'provider_offer_id' => 'offer-'.uniqid(),
            'currency' => 'USD',
            'face_value' => 45.00,
            'cost_price' => 46.35,
            'retail_price' => 45.00,
            'is_available' => true,
        ], $overrides));
    }

    private function unitPrice(ProductVariant $variant): float
    {
        return (float) (new CartPricingService)->calculatePricing($variant->fresh('product'), 1)['unit_price_snapshot'];
    }

    public function test_supplier_cost_basis_prices_from_cost_even_when_a_usd_face_exists(): void
    {
        $variant = $this->makeVariant($this->makeProduct('mobile-airtime', PricingBasis::SupplierCost));

        // cost 46.35 x 1.10 = 50.985 — face 45 is ignored entirely.
        $this->assertEqualsWithDelta(50.985, $this->unitPrice($variant), 0.001);
    }

    public function test_face_value_basis_matches_the_legacy_heuristic(): void
    {
        $legacy = $this->makeVariant($this->makeProduct('gift-cards', null));
        $explicit = $this->makeVariant($this->makeProduct('gift-cards', PricingBasis::FaceValue));

        // Both: face 45 x 1.10 = 49.50.
        $this->assertEqualsWithDelta(49.50, $this->unitPrice($legacy), 0.001);
        $this->assertEqualsWithDelta($this->unitPrice($legacy), $this->unitPrice($explicit), 0.0001);
    }

    public function test_face_value_basis_degrades_to_cost_for_non_usd_variants(): void
    {
        $variant = $this->makeVariant(
            $this->makeProduct('gift-cards', PricingBasis::FaceValue),
            ['currency' => 'NGN', 'face_value' => 4700, 'cost_price' => 3.10, 'retail_price' => 4700],
        );

        // The eligibility guard must ignore the ₦4700 face: cost 3.10 x 1.10.
        $this->assertEqualsWithDelta(3.41, $this->unitPrice($variant), 0.001);
    }

    public function test_provider_retail_basis_prices_from_the_stored_face_value(): void
    {
        $variant = $this->makeVariant(
            $this->makeProduct('esims', PricingBasis::ProviderRetail),
            ['face_value' => 17.00, 'cost_price' => 12.41, 'retail_price' => 17.00],
        );

        // Airalo/Zendit shape: markup on provider retail 17.00 x 1.10 = 18.70.
        $this->assertEqualsWithDelta(18.70, $this->unitPrice($variant), 0.001);
    }

    public function test_backfill_assigns_behaviour_preserving_bases(): void
    {
        $esim = $this->makeVariant(
            $this->makeProduct('esims'),
            ['face_value' => 17.00, 'cost_price' => 12.41, 'retail_price' => 17.00],
        );
        $usdCard = $this->makeVariant($this->makeProduct('gift-cards'), ['face_value' => 10.00, 'cost_price' => 9.50]);
        $nairaBill = $this->makeVariant(
            $this->makeProduct('bill-payments'),
            ['currency' => 'NGN', 'face_value' => 4700, 'cost_price' => 3.10],
        );

        $before = [
            $this->unitPrice($esim),
            $this->unitPrice($usdCard),
            $this->unitPrice($nairaBill),
        ];

        $this->artisan('pricing:backfill-basis')->assertExitCode(0);

        $this->assertSame(PricingBasis::ProviderRetail, $esim->product->fresh()->pricing_basis);
        $this->assertSame(PricingBasis::FaceValue, $usdCard->product->fresh()->pricing_basis);
        $this->assertSame(PricingBasis::SupplierCost, $nairaBill->product->fresh()->pricing_basis);

        // Parity: the backfilled basis reproduces every price exactly.
        $this->assertEqualsWithDelta($before[0], $this->unitPrice($esim), 0.0001);
        $this->assertEqualsWithDelta($before[1], $this->unitPrice($usdCard), 0.0001);
        $this->assertEqualsWithDelta($before[2], $this->unitPrice($nairaBill), 0.0001);
    }

    public function test_backfill_dry_run_saves_nothing(): void
    {
        $variant = $this->makeVariant($this->makeProduct('gift-cards'));

        $this->artisan('pricing:backfill-basis', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNull($variant->product->fresh()->pricing_basis);
    }

    public function test_backfill_never_touches_an_explicit_basis(): void
    {
        // A USD-face product deliberately set to SupplierCost (a Stage 5
        // migration decision) must survive a later backfill run untouched.
        $variant = $this->makeVariant($this->makeProduct('mobile-airtime', PricingBasis::SupplierCost));

        $this->artisan('pricing:backfill-basis')->assertExitCode(0);

        $this->assertSame(PricingBasis::SupplierCost, $variant->product->fresh()->pricing_basis);
    }

    public function test_topup_sync_stamps_supplier_cost_on_new_products_and_preserves_admin_choice(): void
    {
        $rawItem = [
            'offerId' => 'ST-45-US',
            'country' => 'US',
            'brand' => 'StraightTalk',
            'brandName' => 'Straight Talk',
            'priceType' => 'FIXED',
            'price' => ['fixed' => 4635, 'currencyDivisor' => 100, 'currency' => 'USD'],
            'send' => ['fixed' => 4500, 'currencyDivisor' => 100, 'currency' => 'USD'],
            'cost' => ['fixed' => 4635, 'currencyDivisor' => 100, 'currency' => 'USD'],
            'enabled' => true,
        ];

        (new ZenditTopupNormalizer)->normalizeAndSave($rawItem, 'zendit');

        $product = ProductVariant::where('provider_offer_id', 'ST-45-US')->firstOrFail()->product;
        $this->assertSame(PricingBasis::SupplierCost, $product->pricing_basis);

        // Admin re-bases the product; the 6h re-sync must not revert it.
        $product->update(['pricing_basis' => PricingBasis::FaceValue]);

        (new ZenditTopupNormalizer)->normalizeAndSave($rawItem, 'zendit');

        $this->assertSame(PricingBasis::FaceValue, $product->fresh()->pricing_basis);
    }
}
