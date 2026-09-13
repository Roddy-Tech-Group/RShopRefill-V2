<?php

namespace Tests\Feature\Shop;

use App\Domain\Catalog\Support\TopupQuickPicks;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Support\TaggedCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The topup amount selector must speak the same language as the /topups
 * listing: FACE VALUES ("$35", the amount the carrier prints), with the real
 * charge shown separately in Estimated price - exactly like the gift-card
 * flow. It previously labeled denominations with the engine-computed selling
 * price ("$39.39"), which contradicted the listing's "$35 - $55" range and
 * turned the amount picker into a price list nobody recognizes.
 */
class TopupAmountDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pricing.safety_markup_percent' => 10,
            'pricing.min_margin_percent' => 1,
            'pricing.floor_mode' => 'legacy',
        ]);

        // 2% markup so face and charged price are visibly different figures.
        PricingRule::create(['markup_type' => 'percentage', 'markup_value' => 2, 'is_active' => true]);
    }

    private function makeOperator(): Product
    {
        $category = Category::firstOrCreate(['slug' => 'mobile-airtime'], ['name' => 'Mobile Airtime', 'type' => 'digital']);
        $subcategory = Subcategory::firstOrCreate(
            ['category_id' => $category->id, 'slug' => 'mobile-top-up'],
            ['name' => 'Mobile Top Up'],
        );

        return Product::factory()->create([
            'brand_key' => 'FaceTalk',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'is_active' => true,
        ]);
    }

    public function test_credit_denominations_are_labeled_by_face_value_not_charged_price(): void
    {
        $this->withoutVite();
        $product = $this->makeOperator();

        foreach ([[35, 39], [45, 49], [55, 59]] as [$face, $cost]) {
            ProductVariant::factory()->for($product)->create([
                'is_variable' => false,
                'is_available' => true,
                'face_value' => $face,
                'retail_price' => $face,
                'cost_price' => $cost,
                'metadata' => ['subTypes' => ['Mobile Top Up']],
            ]);
        }

        $response = $this->get(route('shop.topup', 'face-talk'))->assertOk();

        // The amount selector speaks face values, matching the listing range.
        $response->assertSee('$35.00', false);
        $response->assertSee('$45.00', false);
        $response->assertSee('$55.00', false);

        // The engine-computed charge still reaches the page (price_usd feeds
        // the Estimated price field): face 35 x 1.02 = 35.70.
        $response->assertSee('35.7', false);

        // The dropdown no longer labels a denomination with its charged price.
        $response->assertDontSee('$35.70', false);
    }

    public function test_bundle_cards_still_show_the_charged_price_but_select_by_face(): void
    {
        $this->withoutVite();
        $product = $this->makeOperator();

        ProductVariant::factory()->for($product)->create([
            'is_variable' => false,
            'is_available' => true,
            'face_value' => 10,
            'retail_price' => 10,
            'cost_price' => 9,
            'metadata' => ['subTypes' => ['Mobile Bundle'], 'dataGB' => 5, 'durationDays' => 30],
        ]);
        // A credit denomination so the page renders the tab switcher.
        ProductVariant::factory()->for($product)->create([
            'is_variable' => false,
            'is_available' => true,
            'face_value' => 20,
            'retail_price' => 20,
            'cost_price' => 18,
            'metadata' => ['subTypes' => ['Mobile Top Up']],
        ]);

        $response = $this->get(route('shop.topup', 'face-talk'))->assertOk();

        // The plan card headlines the printed face...
        $response->assertSee('$10.00', false);
        // ...but clicking one records the face amount, keeping the closed
        // selector consistent with the credit denominations.
        $response->assertSee('amount = 10;', false);
    }

    private function makeNaijaOperator(float $customMin = 5, float $customMax = 150000): Product
    {
        $category = Category::firstOrCreate(['slug' => 'mobile-airtime'], ['name' => 'Mobile Airtime', 'type' => 'digital']);
        $subcategory = Subcategory::firstOrCreate(
            ['category_id' => $category->id, 'slug' => 'mobile-top-up'],
            ['name' => 'Mobile Top Up'],
        );

        $product = Product::factory()->create([
            'brand_key' => 'NairaTel',
            'country_code' => 'NG',
            'currency_code' => 'NGN',
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'is_active' => true,
        ]);

        // A live NGN rate marks this as a large-denomination currency, which
        // activates the ₦500 presentation floor.
        CurrencyRate::firstOrCreate(
            ['code' => 'NGN'],
            ['name' => 'Nigerian Naira', 'type' => 'fiat', 'rate_per_usd' => 1345.622848, 'is_active' => true],
        );

        // Custom-amount option covering the whole range, like MTN NG's.
        ProductVariant::factory()->for($product)->create([
            'is_variable' => true,
            'is_available' => true,
            'currency' => 'NGN',
            'face_value' => 0,
            'retail_price' => 0,
            'cost_price' => 0,
            'min_amount' => $customMin,
            'max_amount' => $customMax,
        ]);

        return $product;
    }

    public function test_long_denomination_lists_collapse_to_five_clean_faces_plus_custom(): void
    {
        $this->withoutVite();
        $product = $this->makeNaijaOperator();

        // Ten synced denominations including two supplier FX artifacts.
        foreach ([100, 200, 500, 1000, 1729, 2161.25, 3000, 5000, 10000, 100000] as $face) {
            ProductVariant::factory()->for($product)->create([
                'is_variable' => false,
                'is_available' => true,
                'currency' => 'NGN',
                'face_value' => $face,
                'retail_price' => $face,
                'cost_price' => round($face / 1500, 4),
                'metadata' => ['subTypes' => ['Mobile Top Up']],
            ]);
        }

        $response = $this->get(route('shop.topup', 'naira-tel'))->assertOk();

        // The ₦500 presentation floor lifts the anchor past the tiny faces:
        // the ladder starts at the ₦500 face, gap-fills ₦750/₦1,250 through
        // the custom path, and tops out at the nice custom maximum.
        $response->assertSee('₦500.00', false);
        $response->assertSee('₦750.00', false);
        $response->assertSee('₦1,000.00', false);
        $response->assertSee('₦1,250.00', false);
        $response->assertSee('₦150,000.00', false);
        // ...sub-floor faces, mid-range values and FX artifacts never render...
        $response->assertDontSee('₦100.00', false);
        $response->assertDontSee('₦200.00', false);
        $response->assertDontSee('₦3,000.00', false);
        $response->assertDontSee('₦1,729.00', false);
        $response->assertDontSee('₦2,161.25', false);
        // ...and everything in between stays reachable through custom input.
        $response->assertSee('Custom amount', false);
    }

    public function test_the_between_line_speaks_operator_currency_faces(): void
    {
        $this->withoutVite();
        $product = $this->makeNaijaOperator();

        ProductVariant::factory()->for($product)->create([
            'is_variable' => false,
            'is_available' => true,
            'currency' => 'NGN',
            'face_value' => 1000,
            'retail_price' => 1000,
            'cost_price' => 0.75,
            'metadata' => ['subTypes' => ['Mobile Top Up']],
        ]);

        // The advertised range is the picks' ends: the ₦500 presentation
        // floor up to the custom maximum - never the supplier's raw ₦5, and
        // never USD engine prices behind a naira symbol ("₦0.28 - ₦78.56").
        $this->get(route('shop.topup', 'naira-tel'))
            ->assertOk()
            ->assertSee('Between ₦500 and ₦150,000', false)
            ->assertDontSee('Between ₦5 and', false)
            ->assertDontSee('₦0.7', false);
    }

    public function test_plan_cards_price_in_the_operator_currency(): void
    {
        $this->withoutVite();

        $product = $this->makeNaijaOperator(); // seeds the NGN rate itself

        // A 2GB bundle with a printed face of ₦2,900: the card headlines the
        // face (what the market calls this product), never the USD engine
        // figure ("₦2.04"); the real charge shows in Estimated price.
        ProductVariant::factory()->for($product)->create([
            'is_variable' => false,
            'is_available' => true,
            'currency' => 'NGN',
            'face_value' => 2900,
            'retail_price' => 2900,
            'cost_price' => 2.00,
            'metadata' => ['subTypes' => ['Mobile Bundle'], 'dataGB' => 2, 'durationDays' => 30],
        ]);

        $this->get(route('shop.topup', 'naira-tel'))
            ->assertOk()
            ->assertSee('₦2,900.00', false)
            ->assertDontSee('₦2.04', false)
            ->assertDontSee('₦3,060.00', false);
    }

    /**
     * The /topups listing range and the detail page's amount selector must
     * speak the same numbers. The selector lists CREDIT denominations, so the
     * listing range must too - MTN NG's data plans start at ₦350 while its
     * credit starts at ₦1,000, and mixing them made the two pages contradict
     * each other (the US operators only agreed by luck of their catalogs).
     */
    public function test_listing_range_ignores_data_plans_and_custom_bounds(): void
    {
        $product = $this->makeNaijaOperator();

        // A data plan with the smallest face on the product...
        ProductVariant::factory()->for($product)->create([
            'is_variable' => false,
            'is_available' => true,
            'currency' => 'NGN',
            'face_value' => 350,
            'retail_price' => 350,
            'cost_price' => 0.25,
            'metadata' => ['subTypes' => ['Mobile Data'], 'dataGB' => 1],
        ]);
        // ...and clean credit denominations from ₦1,000 to ₦100,000.
        foreach ([1000, 2000, 3000, 5000, 100000] as $face) {
            ProductVariant::factory()->for($product)->create([
                'is_variable' => false,
                'is_available' => true,
                'currency' => 'NGN',
                'face_value' => $face,
                'retail_price' => $face,
                'cost_price' => round($face / 1500, 4),
                'metadata' => ['subTypes' => ['Mobile Top Up']],
            ]);
        }

        // The ₦500 presentation floor leads; the custom maximum (₦150,000)
        // is nice and higher than the biggest face, so it tops the range -
        // exactly the first and last options the selector offers.
        $this->assertSame(
            '₦500 - ₦150,000',
            $product->fresh()->load('variants')->priceRangeLabel(fixedFacesFirst: true),
        );
    }

    /**
     * MTN NG's exact shape: clean faces at 1,000/1,500/3,000/3,500 with the
     * 2,000/2,500 slots occupied by FX junk (₦1,729/₦2,161.25). The ladder
     * fills those gaps with synthesized custom amounts so the customer sees
     * 1,000 / 1,500 / 2,000 / 2,500 / 100,000 - never the junk, and never a
     * jump straight to 3,000.
     */
    public function test_gaps_in_a_clean_ladder_are_filled_through_the_custom_path(): void
    {
        $this->withoutVite();
        $product = $this->makeNaijaOperator();
        $product->variants()->first()->update(['min_amount' => 5, 'max_amount' => 50000]);

        foreach ([1000, 1500, 1729, 2161.25, 3000, 3500, 100000] as $face) {
            ProductVariant::factory()->for($product)->create([
                'is_variable' => false,
                'is_available' => true,
                'currency' => 'NGN',
                'face_value' => $face,
                'retail_price' => $face,
                'cost_price' => round($face / 1500, 4),
                'metadata' => ['subTypes' => ['Mobile Top Up']],
            ]);
        }

        $response = $this->get(route('shop.topup', 'naira-tel'))->assertOk();

        $response->assertSee('₦500.00', false); // the presentation floor leads
        $response->assertSee('₦1,000.00', false);
        $response->assertSee('₦1,500.00', false);
        $response->assertSee('₦2,000.00', false); // synthesized: no such face
        $response->assertSee('₦100,000.00', false); // biggest clean face
        $response->assertDontSee('₦3,000.00', false);
        $response->assertDontSee('₦1,729.00', false);
        $response->assertDontSee('₦2,161.25', false);
    }

    /**
     * Airtel NG's shape: every credit face is an FX artifact (₦882.43,
     * ₦1,316.64...) with a wide custom range behind it. The junk must never
     * render; the dropdown offers synthesized round amounts through the
     * custom path, and the listing ranges over the same synthesis.
     */
    public function test_junk_faced_operators_get_synthesized_round_amounts(): void
    {
        $this->withoutVite();
        $product = $this->makeNaijaOperator(); // custom ₦5 - ₦150,000

        foreach ([882.43, 1316.64, 1764.85, 2199.06, 2634.12, 26343.75, 87812.5] as $face) {
            ProductVariant::factory()->for($product)->create([
                'is_variable' => false,
                'is_available' => true,
                'currency' => 'NGN',
                'face_value' => $face,
                'retail_price' => $face,
                'cost_price' => round($face / 1500, 4),
                'metadata' => ['subTypes' => ['Mobile Top Up']],
            ]);
        }

        $response = $this->get(route('shop.topup', 'naira-tel'))->assertOk();

        // Round synthesized picks, led by the ₦500 presentation floor.
        $response->assertSee('₦500.00', false);
        $response->assertSee('₦1,000', false);
        $response->assertSee('₦1,500', false);
        $response->assertSee('₦2,000', false);
        $response->assertSee('₦150,000', false);
        // The FX junk never renders.
        $response->assertDontSee('₦882.43', false);
        $response->assertDontSee('₦1,316.64', false);

        // And the listing speaks the same synthesized range, presentation
        // floor included.
        $this->assertSame(
            '₦500 - ₦150,000',
            $product->fresh()->load('variants')->priceRangeLabel(fixedFacesFirst: true),
        );
    }

    /**
     * The route resolver must cache only the product ID: a serialized product
     * with hundreds of metadata-heavy variants (Boost US carries 296) exceeds
     * MySQL's max_allowed_packet, and the failed cache write 500s the page.
     */
    public function test_topup_resolver_caches_the_product_id_not_the_model(): void
    {
        $this->withoutVite();
        $product = $this->makeNaijaOperator();

        $this->get(route('shop.topup', 'naira-tel'))->assertOk();

        $cached = TaggedCache::for(['catalog'])->get('topup_product_id_NairaTel_US');
        $this->assertIsInt($cached);
        $this->assertSame($product->id, $cached);
    }

    /**
     * Koodo CA's shape: six fixed faces, four of them non-clean CAD
     * conversions (C$20.01, C$25.02...), and NO custom amount. With nothing
     * to carry a dropped face, every real denomination must stay purchasable
     * and the listing must speak the full true range.
     */
    public function test_fixed_only_operators_keep_every_face_even_unclean_ones(): void
    {
        $this->withoutVite();
        $category = Category::firstOrCreate(['slug' => 'mobile-airtime'], ['name' => 'Mobile Airtime', 'type' => 'digital']);
        $subcategory = Subcategory::firstOrCreate(
            ['category_id' => $category->id, 'slug' => 'mobile-top-up'],
            ['name' => 'Mobile Top Up'],
        );
        $product = Product::factory()->create([
            'brand_key' => 'MapleTel',
            'country_code' => 'CA',
            'currency_code' => 'CAD',
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'is_active' => true,
        ]);
        CurrencyRate::firstOrCreate(
            ['code' => 'CAD'],
            ['name' => 'Canadian Dollar', 'type' => 'fiat', 'rate_per_usd' => 1.386295, 'is_active' => true],
        );

        foreach ([10, 15, 20.01, 25.02, 50.02, 100.04] as $face) {
            ProductVariant::factory()->for($product)->create([
                'is_variable' => false,
                'is_available' => true,
                'currency' => 'CAD',
                'face_value' => $face,
                'retail_price' => $face,
                'cost_price' => round($face * 0.8118, 2),
                'metadata' => ['subTypes' => ['Mobile Top Up']],
            ]);
        }

        $response = $this->get(route('shop.topup', 'maple-tel'))->assertOk();

        // All six faces render, the non-clean CAD conversions included.
        $response->assertSee('20.01', false);
        $response->assertSee('25.02', false);
        $response->assertSee('50.02', false);
        $response->assertSee('100.04', false);

        // And the listing speaks the full true range, not a clean subset.
        $this->assertSame(
            'CA$10 - CA$100.04',
            $product->fresh()->load('variants')->priceRangeLabel(fixedFacesFirst: true),
        );
    }

    /**
     * Glo NG's shape: a junk-faced catalog whose custom range neither starts
     * at ₦5 (min ₦1,450) nor ends nice (max ₦73,950), and whose biggest FX
     * face (₦47,995) is divisible by 5 - clean by arithmetic, junk to the
     * eye. The ladder starts at the first nice rung above the supplier
     * minimum and the big pick is a synthesized round ₦70,000, never the
     * junk face.
     */
    public function test_divisible_by_five_junk_faces_never_become_the_big_pick(): void
    {
        $this->withoutVite();
        $product = $this->makeNaijaOperator(customMin: 1450, customMax: 73950);

        foreach ([1000.50, 2001, 3001.50, 4495, 10005, 24505, 47995] as $face) {
            ProductVariant::factory()->for($product)->create([
                'is_variable' => false,
                'is_available' => true,
                'currency' => 'NGN',
                'face_value' => $face,
                'retail_price' => $face,
                'cost_price' => round($face / 1345, 4),
                'metadata' => ['subTypes' => ['Mobile Top Up']],
            ]);
        }

        $response = $this->get(route('shop.topup', 'naira-tel'))->assertOk();

        $response->assertSee('₦1,500', false);
        $response->assertSee('₦2,000', false);
        $response->assertSee('₦2,500', false);
        $response->assertSee('₦3,000', false);
        $response->assertSee('₦70,000', false);
        // The multiple-of-5 FX artifacts stay hidden with the rest.
        $response->assertDontSee('₦47,995', false);
        $response->assertDontSee('₦4,495', false);
        $response->assertDontSee('₦1,000.50', false);
        // The custom input advertises the same nice bounds as the ladder,
        // never the raw supplier range.
        $response->assertSee('Enter an amount between ₦1,500 and ₦70,000.', false);
        $response->assertDontSee('₦1,450', false);
        $response->assertDontSee('₦73,950', false);

        $this->assertSame(
            '₦1,500 - ₦70,000',
            $product->fresh()->load('variants')->priceRangeLabel(fixedFacesFirst: true),
        );
    }

    /** Orange CM's shape: the custom minimum sits above the small ladder. */
    public function test_synthesized_amounts_respect_a_high_custom_minimum(): void
    {
        $picks = TopupQuickPicks::synthesize(1968, 25000);

        $this->assertSame([2000.0, 2500.0, 3000.0, 4000.0, 25000.0], $picks);
    }
}
