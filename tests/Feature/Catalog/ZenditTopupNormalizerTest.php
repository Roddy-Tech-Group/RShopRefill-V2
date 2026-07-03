<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Services\ZenditTopupNormalizer;
use App\Models\Product;
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
