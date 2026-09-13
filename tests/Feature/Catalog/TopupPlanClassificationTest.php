<?php

namespace Tests\Feature\Catalog;

use App\Domain\Cart\Services\CartPricingService;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zendit's `subTypes` is the ONLY classification the storefront trusts. Its
 * catalogue defines three separate product categories - Mobile Top Up (airtime
 * credit), Mobile Data (data plans) and Mobile Bundles (data + minutes + SMS) -
 * and the storefront must mirror that exactly.
 *
 * The trap these tests exist to prevent: allowance fields (dataGB, dataUnlimited,
 * durationDays, voiceMinutes, smsNumber) are populated on PLAIN CREDIT offers
 * too. Straight Talk US ships durationDays 30 and dataUnlimited on offers Zendit
 * lists as pure credit. Inferring the kind from those fields promotes credit
 * denominations into Data/Bundle tabs and advertises plans the carrier does not
 * sell, which is a misrepresentation of the product.
 *
 * A catalogue scan confirms the split: of 9,771 live "Mobile Top Up" variants,
 * 26 carry data fields. They are still credit.
 */
class TopupPlanClassificationTest extends TestCase
{
    use RefreshDatabase;

    private function topupProduct(): Product
    {
        $category = Category::factory()->create([
            'slug' => 'mobile-airtime',
            'name' => 'Mobile top-ups',
        ]);

        return Product::factory()->create([
            'category_id' => $category->id,
            'brand_key' => 'StraightTalk',
            'country_code' => 'US',
            'name' => 'Straight Talk (US)',
        ]);
    }

    private function variant(Product $product, array $metadata, float $price): ProductVariant
    {
        return ProductVariant::factory()->create([
            'product_id' => $product->id,
            'retail_price' => $price,
            'face_value' => $price,
            'metadata' => $metadata,
        ]);
    }

    public function test_a_mobile_top_up_stays_credit_even_when_it_carries_allowance_fields(): void
    {
        $this->withoutVite();
        $product = $this->topupProduct();

        // The exact Straight Talk US shape: credit subtype, but the metadata
        // still carries a validity and an unlimited-data flag.
        $this->variant($product, [
            'subTypes' => ['Mobile Top Up'],
            'durationDays' => 30,
            'dataUnlimited' => true,
            'shortNotes' => '($45 + $5 Fee)  (30d)',
        ], 45);

        $html = $this->get(route('shop.topup', 'straight-talk'))->assertOk()->getContent();

        // Zendit lists this brand as credit only, so neither plan tab may appear.
        $this->assertStringNotContainsString('>Bundles<', $html);
        $this->assertStringNotContainsString('>Data<', $html);
    }

    /**
     * The original complaint: the admin products table showed an offer's
     * benefits while the storefront showed a bare price. The fix is to print
     * the supplier's own description on the credit option - NOT to reclassify
     * the offer as a data or bundle plan.
     */
    public function test_credit_options_show_the_suppliers_benefit_description(): void
    {
        $this->withoutVite();
        $product = $this->topupProduct();

        $this->variant($product, [
            'subTypes' => ['Mobile Top Up'],
            'durationDays' => 30,
            'shortNotes' => '($45 + $5 Fee)  (30d)',
        ], 45);

        $this->get(route('shop.topup', 'straight-talk'))
            ->assertOk()
            ->assertSee('($45 + $5 Fee)', false)
            // Still credit: describing the offer must not promote it to a plan.
            ->assertDontSee('>Bundles<', false);
    }

    public function test_a_mobile_data_subtype_is_offered_as_a_data_plan(): void
    {
        $this->withoutVite();
        $product = $this->topupProduct();

        $this->variant($product, [
            'subTypes' => ['Mobile Data'],
            'dataGB' => 5,
            'durationDays' => 30,
        ], 25);

        $this->get(route('shop.topup', 'straight-talk'))
            ->assertOk()
            ->assertSee('Data', false);
    }

    public function test_a_mobile_bundle_subtype_is_offered_as_a_bundle(): void
    {
        $this->withoutVite();
        $product = $this->topupProduct();

        $this->variant($product, [
            'subTypes' => ['Mobile Bundle'],
            'dataGB' => 3,
            'voiceMinutes' => 200,
            'durationDays' => 30,
        ], 30);

        $this->get(route('shop.topup', 'straight-talk'))
            ->assertOk()
            ->assertSee('Bundles', false);
    }

    /**
     * The amount selector speaks FACE VALUES (the "$35" the carrier prints and
     * the listing advertises) - the 2026-09 reversal of the earlier fix that
     * labeled denominations with the charged price. The engine's charge must
     * STILL reach the page, because it feeds the Estimated price field: the
     * customer sees the printed amount and the real price side by side, and
     * the cart can never disagree with either.
     */
    public function test_face_values_label_the_selector_while_the_real_charge_still_reaches_the_page(): void
    {
        $this->withoutVite();
        $product = $this->topupProduct();

        // Cost above face: the floor has to lift this, so face and charge are
        // guaranteed to be different figures.
        $variant = $this->variant($product, ['subTypes' => ['Mobile Top Up']], 35);
        $variant->update(['cost_price' => 39.00]);

        $charged = round((float) app(CartPricingService::class)
            ->calculatePricing($variant->fresh(), 1)['unit_price_snapshot'], 2);

        $this->assertGreaterThan(35.00, $charged, 'fixture must trip the cost floor to be meaningful');

        $html = $this->get(route('shop.topup', 'straight-talk'))->assertOk()->getContent();

        // The selector labels the printed face...
        $this->assertStringContainsString('$35.00', $html);
        // ...and the engine's charge is on the page for the Estimated price field.
        $this->assertStringContainsString((string) $charged, $html);
    }
}
