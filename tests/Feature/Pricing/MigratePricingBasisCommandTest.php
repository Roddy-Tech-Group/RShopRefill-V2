<?php

namespace Tests\Feature\Pricing;

use App\Domain\Cart\Services\CartPricingService;
use App\Enums\PricingBasis;
use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pins `pricing:migrate-basis` — the Stage 5 category flip. Dry-run must show
 * every price movement (flagging DROPS, which give away supplier discount)
 * and persist nothing; --only-below-cost must scope the flip to broken
 * products; --apply must persist and actually move the engine price.
 */
class MigratePricingBasisCommandTest extends TestCase
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

    /**
     * Two airtime products: one broken (cost above face — sells below cost
     * today) and one discounted (cost below face — a supplier_cost flip
     * would make it cheaper).
     */
    private function makeAirtimePair(): array
    {
        $category = Category::firstOrCreate(['slug' => 'mobile-airtime'], ['name' => 'Mobile Airtime']);

        $make = function (string $name, float $face, float $cost) use ($category) {
            $product = Product::create([
                'category_id' => $category->id,
                'provider_name' => 'zendit',
                'country_code' => 'US',
                'currency_code' => 'USD',
                'pricing_basis' => PricingBasis::FaceValue,
                'name' => $name,
                'slug' => Str::slug($name).'-'.uniqid(),
            ]);
            ProductVariant::create([
                'product_id' => $product->id,
                'provider_offer_id' => 'offer-'.uniqid(),
                'currency' => 'USD',
                'face_value' => $face,
                'cost_price' => $cost,
                'retail_price' => $face,
                'is_available' => true,
            ]);

            return $product;
        };

        return [
            // face 45 x 1.10 = 49.50 vs cost 52 — below cost today; flip raises to 57.20.
            'broken' => $make('Straight Talk US', 45.00, 52.00),
            // face 50 x 1.10 = 55.00 vs cost 45 — healthy; flip DROPS to 49.50.
            'discounted' => $make('AT&T US', 50.00, 45.00),
        ];
    }

    public function test_dry_run_reports_raises_and_drops_without_saving(): void
    {
        $pair = $this->makeAirtimePair();

        $this->artisan('pricing:migrate-basis', ['--category' => 'mobile-airtime', '--to' => 'supplier_cost'])
            ->expectsOutputToContain('1 raise, 1 DROP')
            ->expectsOutputToContain('DRY RUN — nothing saved')
            ->assertExitCode(0);

        $this->assertSame(PricingBasis::FaceValue, $pair['broken']->fresh()->pricing_basis);
        $this->assertSame(PricingBasis::FaceValue, $pair['discounted']->fresh()->pricing_basis);
    }

    public function test_only_below_cost_scopes_the_flip_to_broken_products(): void
    {
        $pair = $this->makeAirtimePair();

        $this->artisan('pricing:migrate-basis', [
            '--category' => 'mobile-airtime',
            '--to' => 'supplier_cost',
            '--only-below-cost' => true,
            '--apply' => true,
        ])
            ->expectsOutputToContain('1 products in scope')
            ->assertExitCode(0);

        $this->assertSame(PricingBasis::SupplierCost, $pair['broken']->fresh()->pricing_basis);
        // The healthy discounted product keeps its face basis — no giveaway.
        $this->assertSame(PricingBasis::FaceValue, $pair['discounted']->fresh()->pricing_basis);
    }

    public function test_apply_persists_and_moves_the_engine_price(): void
    {
        $pair = $this->makeAirtimePair();
        $variant = $pair['broken']->variants()->first();

        $before = (float) (new CartPricingService)->calculatePricing($variant->fresh('product'), 1)['unit_price_snapshot'];
        $this->assertEqualsWithDelta(49.50, $before, 0.001);

        $this->artisan('pricing:migrate-basis', [
            '--category' => 'mobile-airtime',
            '--to' => 'supplier_cost',
            '--only-below-cost' => true,
            '--apply' => true,
        ])->assertExitCode(0);

        $after = (float) (new CartPricingService)->calculatePricing($variant->fresh('product'), 1)['unit_price_snapshot'];

        // cost 52.00 x 1.10 = 57.20 — above cost at last.
        $this->assertEqualsWithDelta(57.20, $after, 0.001);
    }

    public function test_rejects_missing_or_invalid_arguments(): void
    {
        $this->artisan('pricing:migrate-basis', ['--category' => 'mobile-airtime', '--to' => 'nonsense'])
            ->assertExitCode(1);

        $this->artisan('pricing:migrate-basis', ['--to' => 'supplier_cost'])
            ->assertExitCode(1);
    }
}
