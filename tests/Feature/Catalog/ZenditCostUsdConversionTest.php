<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Services\ZenditNormalizer;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zendit's cost block is denominated in the card's OWN currency (mislabeled
 * "USD"). cost_price must be stored in USD - the pricing engine's settlement
 * base - or every non-USD card is overpriced by its FX rate (an AED 100 card
 * was ringing up at ~$110 instead of ~$30).
 */
class ZenditCostUsdConversionTest extends TestCase
{
    use RefreshDatabase;

    private function seedAedRate(): void
    {
        CurrencyRate::create(['code' => 'AED', 'name' => 'UAE Dirham', 'type' => 'fiat', 'rate_per_usd' => 3.6725, 'is_active' => true]);
    }

    private function giftCard(string $currency, int $sendFixed, int $costFixed): array
    {
        return [
            'offerId' => "ZND-{$currency}-{$sendFixed}",
            'brand' => 'TestBrand',
            'brandName' => 'Test Brand',
            'country' => 'AE',
            'priceType' => 'FIXED',
            'subTypes' => ['Digital Apps'],
            // Zendit stamps the cost/price blocks "USD" but the value is in the send currency.
            'send' => ['fixed' => $sendFixed, 'currency' => $currency, 'currencyDivisor' => 100],
            'price' => ['fixed' => $costFixed, 'currency' => 'USD', 'currencyDivisor' => 100],
            'cost' => ['fixed' => $costFixed, 'currency' => 'USD', 'currencyDivisor' => 100],
        ];
    }

    public function test_non_usd_gift_card_cost_is_converted_to_usd(): void
    {
        $this->seedAedRate();
        (new ZenditNormalizer)->normalizeAndSave($this->giftCard('AED', 10000, 9789), 'zendit');

        $v = ProductVariant::where('provider_offer_id', 'ZND-AED-10000')->firstOrFail();
        $this->assertSame('AED', $v->currency);
        // Face value stays native (AED 100); only the cost is converted to USD.
        $this->assertEqualsWithDelta(100.0, (float) $v->face_value, 0.001);
        // 97.89 AED / 3.6725 = ~26.66 USD (was stored as 97.89 before the fix).
        $this->assertEqualsWithDelta(26.6576, (float) $v->cost_price, 0.01);
    }

    public function test_usd_gift_card_cost_is_unchanged(): void
    {
        (new ZenditNormalizer)->normalizeAndSave($this->giftCard('USD', 1000, 950), 'zendit');

        $v = ProductVariant::where('provider_offer_id', 'ZND-USD-1000')->firstOrFail();
        $this->assertEqualsWithDelta(9.50, (float) $v->cost_price, 0.001);
    }

    public function test_backfill_reprices_local_currency_cost_from_metadata(): void
    {
        $this->seedAedRate();
        $category = Category::factory()->create(['slug' => 'gift-cards']);
        $product = Product::factory()->create(['provider_name' => 'zendit', 'category_id' => $category->id]);
        $variant = ProductVariant::factory()->for($product)->create([
            'currency' => 'AED',
            'cost_price' => 97.89, // wrong - stored in AED
            'face_value' => 100,
            'metadata' => ['cost' => ['fixed' => 9789, 'currencyDivisor' => 100, 'currency' => 'USD']],
        ]);

        $this->artisan('zendit:reprice-cost-usd')->assertSuccessful();

        $this->assertEqualsWithDelta(26.6576, (float) $variant->refresh()->cost_price, 0.01);
    }

    public function test_backfill_leaves_usd_variants_untouched(): void
    {
        $category = Category::factory()->create(['slug' => 'gift-cards']);
        $product = Product::factory()->create(['provider_name' => 'zendit', 'category_id' => $category->id]);
        $variant = ProductVariant::factory()->for($product)->create([
            'currency' => 'USD',
            'cost_price' => 9.50,
            'metadata' => ['cost' => ['fixed' => 950, 'currencyDivisor' => 100, 'currency' => 'USD']],
        ]);

        $this->artisan('zendit:reprice-cost-usd')->assertSuccessful();

        $this->assertEqualsWithDelta(9.50, (float) $variant->refresh()->cost_price, 0.001);
    }
}
