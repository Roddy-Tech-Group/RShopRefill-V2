<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Services\ZenditTopupNormalizer;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A top-up product groups every operator offer, so a single offer's per-bundle
 * shortNotes ("3GB + Call (30d)") must not become the brand-level description -
 * that is what made a bundle name show under the product title.
 */
class ZenditTopupNormalizerTest extends TestCase
{
    use RefreshDatabase;

    /** A representative Zendit top-up offer carrying a bundle-style shortNotes. */
    private function bundleOffer(): array
    {
        return [
            'brand' => 'Orange',
            'brandName' => 'Orange',
            'country' => 'CM',
            'offerId' => 'orange-cm-bundle-3gb',
            'priceType' => 'FIXED',
            'shortNotes' => '3GB + Call (30d)',
            'enabled' => true,
            'send' => ['currency' => 'XAF', 'currencyDivisor' => 100, 'fixed' => 200000],
            'price' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 350],
            'cost' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 330],
        ];
    }

    /**
     * STRAIGHTTALK_US_38's shape: cost fixed 3900 plus a separate fee of 50
     * minor units. The wallet is debited $39.50, so cost_price must store
     * $39.50 — a fee left out of the markup base is a silent below-cost sale
     * once the floor prices the row. feePct (a fraction) must scale the base.
     */
    public function test_cost_fee_and_fee_pct_land_in_cost_price(): void
    {
        Queue::fake();

        $normalizer = app(ZenditTopupNormalizer::class);

        $feeOffer = $this->bundleOffer();
        $feeOffer['offerId'] = 'straighttalk-us-38';
        $feeOffer['brand'] = 'StraightTalk';
        $feeOffer['country'] = 'US';
        $feeOffer['send'] = ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 3500];
        $feeOffer['cost'] = ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 3900, 'fee' => 50, 'feePct' => 0];
        $normalizer->normalizeAndSave($feeOffer, 'zendit');

        $pctOffer = $feeOffer;
        $pctOffer['offerId'] = 'straighttalk-us-48';
        $pctOffer['cost'] = ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 4000, 'fee' => 0, 'feePct' => 0.05];
        $normalizer->normalizeAndSave($pctOffer, 'zendit');

        $this->assertEqualsWithDelta(
            39.50,
            (float) ProductVariant::where('provider_offer_id', 'straighttalk-us-38')->value('cost_price'),
            0.0001,
        );
        $this->assertEqualsWithDelta(
            42.00,
            (float) ProductVariant::where('provider_offer_id', 'straighttalk-us-48')->value('cost_price'),
            0.0001,
        );
    }

    public function test_offer_bundle_notes_do_not_become_the_product_description(): void
    {
        Queue::fake();

        app(ZenditTopupNormalizer::class)->normalizeAndSave($this->bundleOffer(), 'zendit');

        $product = Product::where('slug', 'topup-orange-cm-zendit')->firstOrFail();

        $this->assertNull($product->description);
    }

    public function test_an_admin_edited_description_is_preserved_on_resync(): void
    {
        Queue::fake();

        $normalizer = app(ZenditTopupNormalizer::class);
        $normalizer->normalizeAndSave($this->bundleOffer(), 'zendit');

        $product = Product::where('slug', 'topup-orange-cm-zendit')->firstOrFail();
        $product->update(['description' => 'Custom admin blurb']);

        // A routine re-sync must not clobber the admin's copy.
        $normalizer->normalizeAndSave($this->bundleOffer(), 'zendit');

        $this->assertSame('Custom admin blurb', $product->fresh()->description);
    }
}
