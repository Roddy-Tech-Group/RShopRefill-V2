<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Services\ZenditNormalizer;
use App\Domain\Catalog\Services\ZenditTopupNormalizer;
use App\Domain\Catalog\Support\FaceValueCheck;
use App\Models\CurrencyRate;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Two guards on what a Zendit offer says its face value is (see
 * FaceValueCheck): an empty currency label falls through to the price block
 * instead of being stored, and a face that sits a factor of 2.5 or more from
 * its USD cost is kept but not offered. The shapes are live rows found on
 * 2026-09-20.
 */
class FaceValueCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // The base migration seeds some of these codes, so they are set, not created.
        foreach (['AED' => 3.6725, 'EUR' => 0.8713, 'PEN' => 3.3645] as $code => $rate) {
            CurrencyRate::updateOrCreate(['code' => $code], ['name' => $code, 'type' => 'fiat', 'rate_per_usd' => $rate, 'is_active' => true]);
        }

        // An official rate a long way from the street, and not payable here.
        CurrencyRate::updateOrCreate(['code' => 'SDG'], ['name' => 'SDG', 'type' => 'fiat', 'rate_per_usd' => 510.6, 'is_active' => false]);
    }

    /**
     * The PlayStation UAE offer: "10 AED" that costs $9.79.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function giftCard(array $overrides = []): array
    {
        return array_merge([
            'brand' => 'Playstation',
            'brandName' => 'PlayStation',
            'country' => 'AE',
            'offerId' => 'PLAYSTATION_AE_10',
            'priceType' => 'FIXED',
            'enabled' => true,
            'subTypes' => ['Gaming'],
            'send' => ['currency' => 'AED', 'currencyDivisor' => 100, 'fixed' => 1000],
            'price' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 1006],
            'cost' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 979],
        ], $overrides);
    }

    /**
     * The Bitel Peru bundle: "3 PEN" that costs $3.40.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function topup(array $overrides = []): array
    {
        return array_merge([
            'brand' => 'Bitel',
            'brandName' => 'Bitel',
            'country' => 'PE',
            'offerId' => 'BITEL_PE_001',
            'priceType' => 'FIXED',
            'enabled' => true,
            'send' => ['currency' => 'PEN', 'currencyDivisor' => 100, 'fixed' => 300],
            'price' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 400],
            'cost' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 340],
        ], $overrides);
    }

    private function variant(string $offerId): ProductVariant
    {
        return ProductVariant::where('provider_offer_id', $offerId)->firstOrFail();
    }

    public function test_an_empty_currency_label_falls_through_to_the_price_block(): void
    {
        $this->assertSame('USD', FaceValueCheck::currency(['currency' => ''], ['currency' => 'USD'], []));
        $this->assertSame('GBP', FaceValueCheck::currency(['currency' => ' gbp '], ['currency' => 'USD'], []));
        $this->assertSame('USD', FaceValueCheck::currency([], [], []));

        // The Amazon US shape that left 15 rows with no currency at all.
        app(ZenditNormalizer::class)->normalizeAndSave($this->giftCard([
            'offerId' => 'AMAZON_US_002_EGIFT',
            'brand' => 'Amazon',
            'country' => 'US',
            'send' => ['currency' => ''],
            'price' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 1000],
            'cost' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 1000],
        ]), 'zendit');

        $variant = $this->variant('AMAZON_US_002_EGIFT');
        $this->assertSame('USD', $variant->currency);
        $this->assertSame(10.0, (float) $variant->face_value);
        $this->assertTrue($variant->is_available);
        $this->assertSame('ok', $variant->metadata['face_check']['status']);
    }

    public function test_a_face_far_from_its_cost_is_kept_but_not_offered(): void
    {
        $normalizer = app(ZenditNormalizer::class);
        $normalizer->normalizeAndSave($this->giftCard(), 'zendit');
        // A believable neighbour: 10 AED for $2.65.
        $normalizer->normalizeAndSave($this->giftCard([
            'offerId' => 'PLAYSTATION_AE_10_REAL',
            'cost' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 265],
        ]), 'zendit');

        $suspect = $this->variant('PLAYSTATION_AE_10');
        $this->assertFalse($suspect->is_available, 'a "10 AED" card that costs $9.79 is not a 10 AED card');
        $this->assertSame('suspect', $suspect->metadata['face_check']['status']);
        $this->assertEqualsWithDelta(0.278, $suspect->metadata['face_check']['ratio'], 0.001);
        // The row and the supplier's own data stay, for whoever relabels it.
        $this->assertSame('AED', $suspect->currency);
        $this->assertSame(1000, $suspect->metadata['send']['fixed']);

        $real = $this->variant('PLAYSTATION_AE_10_REAL');
        $this->assertTrue($real->is_available);
        $this->assertSame('ok', $real->metadata['face_check']['status']);
    }

    public function test_a_face_far_above_its_cost_is_caught_too(): void
    {
        // Castorama: the offer named ..._100 says 500 EUR for $115.97.
        app(ZenditNormalizer::class)->normalizeAndSave($this->giftCard([
            'offerId' => 'CASTORAMA_FR_100',
            'brand' => 'Castorama',
            'country' => 'FR',
            'send' => ['currency' => 'EUR', 'currencyDivisor' => 100, 'fixed' => 50000],
            'cost' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 11597],
        ]), 'zendit');

        $this->assertFalse($this->variant('CASTORAMA_FR_100')->is_available);
        $this->assertSame('suspect', $this->variant('CASTORAMA_FR_100')->metadata['face_check']['status']);
    }

    public function test_top_ups_are_checked_the_same_way(): void
    {
        app(ZenditTopupNormalizer::class)->normalizeAndSave($this->topup(), 'zendit');

        $variant = $this->variant('BITEL_PE_001');
        $this->assertFalse($variant->is_available, 'a "3 PEN" bundle that costs $3.40');
        $this->assertSame('suspect', $variant->metadata['face_check']['status']);
    }

    public function test_a_currency_without_a_live_rate_and_a_variable_amount_are_not_judged(): void
    {
        // 1000 SDG for $0.48: the official rate says four times that, the
        // street does not, and a rate we do not trust cannot call a row wrong.
        app(ZenditTopupNormalizer::class)->normalizeAndSave($this->topup([
            'offerId' => 'SUDANI_SD_1000',
            'brand' => 'Sudani',
            'country' => 'SD',
            'send' => ['currency' => 'SDG', 'currencyDivisor' => 100, 'fixed' => 100000],
            'cost' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 48],
        ]), 'zendit');

        $this->assertTrue($this->variant('SUDANI_SD_1000')->is_available);
        $this->assertSame('unchecked', $this->variant('SUDANI_SD_1000')->metadata['face_check']['status']);

        // A variable amount has no single face to judge.
        app(ZenditNormalizer::class)->normalizeAndSave($this->giftCard([
            'offerId' => 'PLAYSTATION_AE_VAR',
            'priceType' => 'VARIABLE',
            'send' => ['currency' => 'AED', 'currencyDivisor' => 100, 'min' => 1000, 'max' => 50000],
        ]), 'zendit');

        $this->assertTrue($this->variant('PLAYSTATION_AE_VAR')->is_available);
        $this->assertSame('unchecked', $this->variant('PLAYSTATION_AE_VAR')->metadata['face_check']['status']);
    }

    public function test_a_corrected_offer_is_offered_again_on_the_next_sync(): void
    {
        $normalizer = app(ZenditNormalizer::class);
        $normalizer->normalizeAndSave($this->giftCard(), 'zendit');
        $this->assertFalse($this->variant('PLAYSTATION_AE_10')->is_available);

        // The supplier relabels the face as the dollars it always was.
        $normalizer->normalizeAndSave($this->giftCard([
            'send' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 1000],
        ]), 'zendit');

        $variant = $this->variant('PLAYSTATION_AE_10');
        $this->assertTrue($variant->is_available);
        $this->assertSame('USD', $variant->currency);
        $this->assertSame('ok', $variant->metadata['face_check']['status']);
    }
}
