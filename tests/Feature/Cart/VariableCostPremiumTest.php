<?php

namespace Tests\Feature\Cart;

use App\Domain\Cart\Services\CartManager;
use App\Domain\Cart\Services\CartPricingService;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Variable topup amounts must price from the operator's REAL supplier cost,
 * estimated as requested x the premium the same operator's fixed rows carry.
 * Pricing them as if cost = face at the official rate sold every custom
 * amount ~4% under its actual cost (MTN NG: a ₦1,000 fixed face charged
 * ₦1,060 while a ₦2,000 custom charged ₦2,040) - inconsistent on screen and
 * a quiet loss underneath.
 */
class VariableCostPremiumTest extends TestCase
{
    use RefreshDatabase;

    private const NGN_RATE = 1345.622848;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
            'pricing.floor_mode' => 'legacy',
        ]);
        PricingRule::create(['markup_type' => 'percentage', 'markup_value' => 2, 'is_active' => true]);
        CurrencyRate::create(['code' => 'NGN', 'name' => 'Nigerian Naira', 'type' => 'fiat', 'rate_per_usd' => self::NGN_RATE, 'is_active' => true]);
    }

    /** MTN NG's shape: every fixed row costs ~3.6% above its official-rate face. */
    private function operatorWithPremium(string $categorySlug = 'mobile-airtime'): Product
    {
        $category = Category::firstOrCreate(['slug' => $categorySlug], ['name' => ucfirst($categorySlug), 'type' => 'digital']);
        $subcategory = Subcategory::firstOrCreate(
            ['category_id' => $category->id, 'slug' => $categorySlug.'-sub'],
            ['name' => 'Sub'],
        );
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'currency_code' => 'NGN',
        ]);

        foreach ([1000, 1500, 3000] as $face) {
            ProductVariant::factory()->for($product)->create([
                'is_variable' => false,
                'is_available' => true,
                'currency' => 'NGN',
                'face_value' => $face,
                'retail_price' => $face,
                // cost = face at official rate x 1.0361 (the operator premium)
                'cost_price' => round(($face / self::NGN_RATE) * 1.0361, 4),
                'metadata' => ['subTypes' => ['Mobile Top Up']],
            ]);
        }

        ProductVariant::factory()->for($product)->create([
            'is_variable' => true,
            'is_available' => true,
            'currency' => 'NGN',
            'face_value' => 0,
            'retail_price' => 0,
            'cost_price' => 0,
            'min_amount' => 5,
            'max_amount' => 150000,
        ]);

        return $product;
    }

    public function test_the_premium_is_measured_from_the_operators_fixed_rows(): void
    {
        $product = $this->operatorWithPremium();

        $premium = app(CartPricingService::class)->variableCostPremium($product);

        $this->assertEqualsWithDelta(1.0361, $premium, 0.001);
    }

    public function test_custom_amounts_price_and_cost_through_the_premium(): void
    {
        $product = $this->operatorWithPremium();
        $variant = $product->variants()->where('is_variable', true)->first();

        $requestedUsd = 2000 / self::NGN_RATE; // customer typed ₦2,000
        $manager = app(CartManager::class);
        $cart = $manager->resolveCart(User::factory()->create()->id, null);
        $item = $manager->addItem($cart, $variant, 1, $requestedUsd);

        $expectedCost = $requestedUsd * 1.0361;
        $this->assertEqualsWithDelta($expectedCost, (float) $item->provider_cost_usd, 0.002,
            'the cost snapshot must carry the operator premium, not the face assumption');
        $this->assertEqualsWithDelta($expectedCost * 1.02, (float) $item->unit_price_snapshot, 0.002,
            'the price must be premium-adjusted cost + the markup rule');
    }

    public function test_re_adding_the_same_amount_still_merges_into_one_line(): void
    {
        $product = $this->operatorWithPremium();
        $variant = $product->variants()->where('is_variable', true)->first();

        $requestedUsd = 2000 / self::NGN_RATE;
        $manager = app(CartManager::class);
        $cart = $manager->resolveCart(User::factory()->create()->id, null);
        $manager->addItem($cart, $variant, 1, $requestedUsd);
        $manager->addItem($cart, $variant, 1, $requestedUsd);

        $this->assertSame(1, $cart->items()->count(), 'the premium must not break duplicate-request merging');
        $this->assertSame(2, (int) $cart->items()->sole()->quantity);
    }

    public function test_non_topup_categories_keep_the_face_as_cost_behaviour(): void
    {
        $product = $this->operatorWithPremium('gift-cards');

        $this->assertSame(1.0, app(CartPricingService::class)->variableCostPremium($product));
    }

    public function test_the_premium_is_clamped_against_corrupt_cost_data(): void
    {
        $product = $this->operatorWithPremium();
        // Poison every row with an absurd cost (ratio ~2.0).
        $product->variants()->where('is_variable', false)
            ->get()
            ->each(fn ($v) => $v->update(['cost_price' => round(($v->face_value / self::NGN_RATE) * 2.0, 4)]));
        Cache::flush();

        $this->assertSame(1.25, app(CartPricingService::class)->variableCostPremium($product));
    }
}
