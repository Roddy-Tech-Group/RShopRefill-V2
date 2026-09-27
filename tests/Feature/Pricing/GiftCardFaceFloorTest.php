<?php

namespace Tests\Feature\Pricing;

use App\Domain\Cart\Services\CartPricingService;
use App\Domain\Shared\Support\CharmPrice;
use App\Enums\PricingBasis;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A gift card never sells under its face value (decided 2026-09-20).
 *
 * The supplier discounts some cards below face, and cost + markup then lands
 * under face in the card's own currency: the Apple MX 100 card, bought for
 * $5.40, sold for 98 MXN. The floor keeps that discount as margin. Top-ups
 * keep their discounts, and a currency we hold no live rate for sets no
 * floor, because its official rate can sit multiples from the street.
 */
class GiftCardFaceFloorTest extends TestCase
{
    use RefreshDatabase;

    private const MXN_PER_USD = 17.212115;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'pricing.safety_markup_percent' => 10,
            'pricing.min_margin_percent' => 1,
            'pricing.floor_mode' => 'legacy',
        ]);

        PricingRule::create(['markup_type' => 'percentage', 'markup_value' => 4.6, 'is_active' => true]);

        CurrencyRate::create(['code' => 'MXN', 'name' => 'Mexican Peso', 'type' => 'fiat', 'rate_per_usd' => self::MXN_PER_USD, 'is_active' => true]);
        // An official rate a long way from the street, and not payable here.
        CurrencyRate::create(['code' => 'SDG', 'name' => 'Sudanese Pound', 'type' => 'fiat', 'rate_per_usd' => 510.6, 'is_active' => false]);
    }

    /**
     * The Apple MX 100 card as synced on 2026-09-20: 100 MXN for $5.40, a
     * 2.9% supplier discount. Cost + 4.6% is $5.6484, which is 97.2 MXN.
     *
     * @param  array<string, mixed>  $variant
     * @param  array<string, mixed>  $product
     */
    private function makeVariant(string $categorySlug = 'gift-cards', array $variant = [], array $product = []): ProductVariant
    {
        $category = Category::firstOrCreate(['slug' => $categorySlug], ['name' => ucfirst($categorySlug), 'type' => 'digital']);

        $product = Product::create(array_merge([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'country_code' => 'MX',
            'currency_code' => 'MXN',
            'name' => 'Apple MX',
            'slug' => 'apple-mx-'.uniqid(),
        ], $product));

        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'provider_offer_id' => 'offer-'.uniqid(),
            'currency' => 'MXN',
            'face_value' => 100.00,
            'cost_price' => 5.40,
            'retail_price' => 100.00,
        ], $variant));
    }

    private function priceUsd(ProductVariant $variant): float
    {
        return (float) (new CartPricingService)->calculatePricing($variant, 1)['unit_price_snapshot'];
    }

    public function test_a_discounted_gift_card_is_held_at_its_face_value(): void
    {
        $usd = $this->priceUsd($this->makeVariant());

        // 100 / 17.212115 = 5.80984..., kept to the four decimals the cart
        // snapshot stores, rounded down.
        $this->assertEqualsWithDelta(5.8098, $usd, 0.00001);
        $this->assertGreaterThan(5.40 * 1.046, $usd);

        // Back in pesos it is the face itself: a hair under, then the charm
        // round-up settles on 100, not on 101.
        $this->assertSame(100.0, CharmPrice::roundUp($usd * self::MXN_PER_USD, self::MXN_PER_USD));
    }

    public function test_a_card_already_above_face_is_not_touched(): void
    {
        // The larger Apple MX cards: $5.82 per 100 MXN, no discount to keep.
        $usd = $this->priceUsd($this->makeVariant(variant: ['cost_price' => 5.82]));

        $this->assertEqualsWithDelta(5.82 * 1.046, $usd, 0.00001);
    }

    public function test_airtime_keeps_the_suppliers_discount(): void
    {
        $usd = $this->priceUsd($this->makeVariant('mobile-airtime'));

        $this->assertEqualsWithDelta(5.40 * 1.046, $usd, 0.00001);
    }

    public function test_a_currency_without_a_live_rate_sets_no_floor(): void
    {
        // 1000 SDG for $0.48. At the official 510.6 the face would be $1.96,
        // four times the price; the street rate says $0.48 is about right.
        $usd = $this->priceUsd($this->makeVariant(variant: ['currency' => 'SDG', 'face_value' => 1000, 'retail_price' => 1000, 'cost_price' => 0.48]));

        $this->assertEqualsWithDelta(0.48 * 1.046, $usd, 0.00001);
    }

    public function test_a_usd_card_floors_at_face_exactly(): void
    {
        // Priced off cost by an explicit basis: $9 + 4.6% = $9.414 for a $10 card.
        $usd = $this->priceUsd($this->makeVariant(
            variant: ['currency' => 'USD', 'face_value' => 10, 'retail_price' => 10, 'cost_price' => 9.00],
            product: ['currency_code' => 'USD', 'country_code' => 'US', 'pricing_basis' => PricingBasis::SupplierCost],
        ));

        $this->assertSame(10.0, $usd);
    }

    public function test_an_admin_override_is_respected_even_under_face(): void
    {
        $usd = $this->priceUsd($this->makeVariant(variant: ['manual_retail_price_usd' => 5.00]));

        $this->assertSame(5.0, $usd);
    }
}
