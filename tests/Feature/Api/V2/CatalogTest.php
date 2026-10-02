<?php

namespace Tests\Feature\Api\V2;

use App\Domain\Catalog\Services\ApiCatalogPricer;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Browsing the shop from the app.
 *
 * The tests that matter most here are about PRICE, because a price is a promise.
 * product_variants.retail_price is not what checkout charges: the real figure comes
 * out of the pricing rule chain, with a manual override and a minimum-margin floor
 * on top. An app that showed the column would contradict checkout in front of the
 * customer, on the one screen where being wrong is unforgivable.
 *
 * So one test proves the price comes from the resolver rather than the column, and
 * another proves the displayed conversion never loses the USD figure we settle in.
 */
class CatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Make a currency convertible. updateOrCreate because migrations already seed
     * this table, and DisplayCurrency's cross-rate needs a row for BOTH sides of
     * the pair, so USD is ensured alongside.
     */
    private function rate(string $code, string $name, float $perUsd, string $type = 'fiat'): void
    {
        CurrencyRate::updateOrCreate(
            ['code' => strtoupper($code)],
            ['name' => $name, 'type' => $type, 'rate_per_usd' => $perUsd, 'is_active' => true, 'sort_order' => 1],
        );

        CurrencyRate::updateOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'type' => 'fiat', 'rate_per_usd' => 1, 'is_active' => true, 'sort_order' => 0],
        );
    }

    private function catalogue(array $variantAttributes = []): array
    {
        $category = Category::factory()->create(['is_active' => true, 'slug' => 'mobile-airtime']);
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id, 'slug' => 'mtn']);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'is_active' => true,
            'slug' => 'mtn-nigeria',
            'name' => 'MTN Nigeria',
            'country_code' => 'NG',
        ]);

        $variant = ProductVariant::factory()->create(array_merge([
            'product_id' => $product->id,
            'subcategory_id' => $subcategory->id,
            'is_available' => true,
            // Deliberately cheap. The rule chain clamps every price up to a
            // minimum margin over cost, so a high cost would silently override the
            // manual prices these tests are checking and prove nothing.
            'cost_price' => 1,
        ], $variantAttributes));

        return [$category, $subcategory, $product, $variant];
    }

    // ------------------------------------------------------------------ config

    public function test_config_states_the_settlement_currency_and_what_may_be_displayed(): void
    {
        $this->rate('NGN', 'Nigerian Naira', 1600);
        $this->rate('BTC', 'Bitcoin', 0.000015, 'crypto');

        $response = $this->getJson('/v2/catalog/config')->assertOk();

        $this->assertSame('USD', $response->json('data.settlement_currency'));

        $codes = array_column($response->json('data.display_currencies'), 'code');

        $this->assertContains('NGN', $codes);
        // Crypto rows in that table are funding rails, not currencies anybody
        // wants a gift card priced in.
        $this->assertNotContains('BTC', $codes);

        $response->assertJsonStructure(['data' => ['rcoin' => ['enabled', 'cashback_percentage', 'redemption']]]);
    }

    // -------------------------------------------------------------- navigation

    public function test_only_active_categories_are_listed(): void
    {
        Category::factory()->create(['name' => 'Airtime', 'is_active' => true, 'sort_order' => 1]);
        Category::factory()->create(['name' => 'Hidden', 'is_active' => false, 'sort_order' => 2]);

        $names = array_column($this->getJson('/v2/catalog/categories')->assertOk()->json('data'), 'name');

        $this->assertSame(['Airtime'], $names);
    }

    public function test_subcategories_can_be_filtered_and_never_leak_from_a_hidden_category(): void
    {
        $visible = Category::factory()->create(['is_active' => true, 'slug' => 'airtime']);
        $hidden = Category::factory()->create(['is_active' => false, 'slug' => 'secret']);

        Subcategory::factory()->create(['category_id' => $visible->id, 'name' => 'MTN']);
        Subcategory::factory()->create(['category_id' => $hidden->id, 'name' => 'Should Not Show']);

        $all = array_column($this->getJson('/v2/catalog/subcategories')->assertOk()->json('data'), 'name');
        $this->assertSame(['MTN'], $all);

        $filtered = $this->getJson('/v2/catalog/subcategories?category=airtime')->assertOk();
        $this->assertCount(1, $filtered->json('data'));

        $this->assertCount(0, $this->getJson('/v2/catalog/subcategories?category=secret')->json('data'));
    }

    // ------------------------------------------------------------------- price

    /**
     * THE one. manual_retail_price_usd is an override the rule chain honours, and
     * the retail_price column says something different. The API must report the
     * resolved figure, because that is what checkout will charge.
     */
    public function test_the_price_comes_from_the_pricing_resolver_not_the_retail_price_column(): void
    {
        [, , , $variant] = $this->catalogue([
            'currency' => 'USD',
            'face_value' => 10,
            'cost_price' => 8,
            'retail_price' => 10,
            'manual_retail_price_usd' => 17.5,
        ]);

        $price = $this->getJson('/v2/catalog/products/mtn-nigeria')
            ->assertOk()
            ->json('data.variants.0.price');

        $this->assertSame('17.5000', $price['usd']);
        $this->assertNotSame('10.0000', $price['usd'], 'the raw retail_price column must not be what is published');

        // And it agrees with the authoritative service, whatever the rules are.
        $this->assertEqualsWithDelta(
            app(ApiCatalogPricer::class)->usdPriceFor($variant->fresh()),
            (float) $price['usd'],
            0.0001,
        );
    }

    public function test_prices_convert_into_the_requested_currency_and_keep_the_usd_figure(): void
    {
        $this->rate('NGN', 'Nigerian Naira', 1600);

        $this->catalogue(['currency' => 'USD', 'face_value' => 10, 'manual_retail_price_usd' => 2.0]);

        $price = $this->getJson('/v2/catalog/products/mtn-nigeria', ['X-Display-Currency' => 'NGN'])
            ->assertOk()
            ->json('data.variants.0.price');

        $this->assertSame('NGN', $price['currency']);
        $this->assertSame('3200.00', $price['amount']);
        // The settlement figure survives conversion, so an order still reconciles
        // months later when the rate has moved.
        $this->assertSame('2.0000', $price['usd']);
    }

    public function test_no_currency_header_means_native_prices(): void
    {
        $this->catalogue(['currency' => 'USD', 'manual_retail_price_usd' => 2.0]);

        $price = $this->getJson('/v2/catalog/products/mtn-nigeria')->assertOk()->json('data.variants.0.price');

        $this->assertSame('USD', $price['currency']);
        $this->assertSame('2.00', $price['amount']);
    }

    /**
     * A £25 card is worth £25 to whoever redeems it, however the buyer is
     * browsing. Converting a face value would misdescribe the product.
     */
    public function test_a_face_value_is_never_converted_into_the_display_currency(): void
    {
        $this->rate('NGN', 'Nigerian Naira', 1600);

        $this->catalogue(['currency' => 'GBP', 'face_value' => 25, 'cost_price' => 30]);

        $variant = $this->getJson('/v2/catalog/products/mtn-nigeria', ['X-Display-Currency' => 'NGN'])
            ->assertOk()
            ->json('data.variants.0');

        $this->assertSame('GBP', $variant['face_value']['currency']);
        $this->assertSame('25.00', $variant['face_value']['amount']);
    }

    public function test_price_from_is_the_cheapest_available_variant(): void
    {
        [, $subcategory, $product] = $this->catalogue(['manual_retail_price_usd' => 9.0]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'subcategory_id' => $subcategory->id,
            'is_available' => true,
            'cost_price' => 1,
            'manual_retail_price_usd' => 3.0,
        ]);

        // Cheaper, but not buyable, so it must not set the "from" price.
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'subcategory_id' => $subcategory->id,
            'is_available' => false,
            'cost_price' => 0.5,
            'manual_retail_price_usd' => 1.0,
        ]);

        $response = $this->getJson('/v2/catalog/products')->assertOk();

        $this->assertSame('3.0000', $response->json('data.0.price_from.usd'));
        $this->assertCount(2, $response->json('data.0.variants'));
    }

    // ---------------------------------------------------------------- browsing

    public function test_inactive_products_are_not_listed_or_fetchable(): void
    {
        [, , $product] = $this->catalogue();
        $product->forceFill(['is_active' => false])->save();

        $this->assertCount(0, $this->getJson('/v2/catalog/products')->assertOk()->json('data'));
        $this->getJson('/v2/catalog/products/mtn-nigeria')->assertNotFound();
    }

    /**
     * Shown, not hidden. A customer searching for a product they know exists
     * should learn it is temporarily unavailable rather than conclude we stopped
     * selling it.
     */
    public function test_an_out_of_stock_product_is_still_listed_with_its_flag(): void
    {
        [, , $product] = $this->catalogue();
        $product->forceFill(['is_out_of_stock' => true])->save();

        $response = $this->getJson('/v2/catalog/products')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertTrue($response->json('data.0.is_out_of_stock'));
    }

    public function test_products_can_be_searched_and_filtered(): void
    {
        $this->catalogue();

        $this->assertCount(1, $this->getJson('/v2/catalog/products?search=MTN')->json('data'));
        $this->assertCount(0, $this->getJson('/v2/catalog/products?search=Airtel')->json('data'));
        $this->assertCount(1, $this->getJson('/v2/catalog/products?country=NG')->json('data'));
        $this->assertCount(0, $this->getJson('/v2/catalog/products?country=GH')->json('data'));
        $this->assertCount(1, $this->getJson('/v2/catalog/products?subcategory=mtn')->json('data'));
    }

    /**
     * A customer searching for "100%" wants products called "100%", not every
     * product in the shop. An unescaped wildcard would match everything.
     */
    public function test_a_wildcard_in_a_search_term_is_treated_as_text(): void
    {
        $this->catalogue();

        $this->assertCount(0, $this->getJson('/v2/catalog/products?search=%')->json('data'));
        $this->assertCount(0, $this->getJson('/v2/catalog/products?search=_TN')->json('data'));
    }

    // -------------------------------------------------------------- pagination

    public function test_listing_pages_by_cursor_without_repeating_or_skipping(): void
    {
        [$category, $subcategory] = $this->catalogue();

        // Five products in total, named so the ordering is predictable.
        foreach (['Airtel', 'Glo', '9mobile', 'Etisalat'] as $index => $name) {
            $product = Product::factory()->create([
                'category_id' => $category->id,
                'subcategory_id' => $subcategory->id,
                'is_active' => true,
                'name' => $name,
                'slug' => 'brand-'.$index,
            ]);

            ProductVariant::factory()->create([
                'product_id' => $product->id,
                'subcategory_id' => $subcategory->id,
                'is_available' => true,
            ]);
        }

        $seen = [];
        $cursor = null;

        do {
            $url = '/v2/catalog/products?per_page=2'.($cursor !== null ? '&cursor='.urlencode($cursor) : '');
            $page = $this->getJson($url)->assertOk();

            $this->assertLessThanOrEqual(2, count($page->json('data')));

            foreach ($page->json('data') as $row) {
                $seen[] = $row['slug'];
            }

            $cursor = $page->json('page.next_cursor');
        } while ($cursor !== null);

        $this->assertCount(5, $seen);
        $this->assertSame($seen, array_unique($seen), 'no product may appear on two pages');
    }

    public function test_an_absurd_page_size_is_clamped(): void
    {
        $this->catalogue();

        $this->assertSame(
            100,
            $this->getJson('/v2/catalog/products?per_page=100000')->assertOk()->json('page.per_page'),
        );
    }

    // ------------------------------------------------------------------- quote

    public function test_a_variable_amount_is_quoted_by_the_server(): void
    {
        [, , , $variant] = $this->catalogue([
            'currency' => 'USD',
            'is_variable' => true,
            'face_value' => null,
            'cost_price' => 0,
            'min_amount' => 1,
            'max_amount' => 100,
        ]);

        $response = $this->postJson('/v2/catalog/quote', [
            'variant_id' => $variant->id,
            'amount' => 20,
        ])->assertOk();

        $this->assertSame($variant->id, $response->json('data.variant_id'));
        // Echoed back in the currency the customer typed in.
        $this->assertSame('20.00', $response->json('data.requested.amount'));
        $this->assertSame('USD', $response->json('data.requested.currency'));
        // A real price, not zero.
        $this->assertGreaterThan(0, (float) $response->json('data.price.usd'));
    }

    /**
     * The quote reuses the cart's own bounds check, so it can never accept an
     * amount checkout would refuse. The message names the customer's currency.
     */
    public function test_an_amount_below_the_minimum_is_refused_with_the_field_named(): void
    {
        [, , , $variant] = $this->catalogue([
            'currency' => 'USD',
            'is_variable' => true,
            'face_value' => null,
            'min_amount' => 50,
            'max_amount' => 100,
        ]);

        $this->postJson('/v2/catalog/quote', ['variant_id' => $variant->id, 'amount' => 5])
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['requested_value']]]);
    }

    public function test_a_fixed_denomination_cannot_be_quoted(): void
    {
        [, , , $variant] = $this->catalogue(['is_variable' => false]);

        $this->postJson('/v2/catalog/quote', ['variant_id' => $variant->id, 'amount' => 20])
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'variant_not_variable');
    }

    // -------------------------------------------------------------------- eSIM

    /**
     * eSIMs are ordinary products in the esims category, so they browse through the
     * same endpoints rather than needing their own. The plan details ride along on
     * the variant.
     */
    public function test_an_esim_plan_publishes_its_allowance_and_validity(): void
    {
        $this->catalogue([
            'metadata' => [
                'plan_type' => 'data_only',
                'data_limit' => '5 GB',
                'validity_days' => 30,
                'supports_data' => true,
                'countries' => ['NG', 'GH'],
                'coverage_networks' => ['MTN', 'Airtel'],
                'activation_policy' => 'automatic',
                'is_rechargeable' => true,
                'operator_info' => ['Tethering allowed.'],
            ],
        ]);

        $esim = $this->getJson('/v2/catalog/products/mtn-nigeria')
            ->assertOk()
            ->json('data.variants.0.esim');

        $this->assertSame('data_only', $esim['plan_type']);
        $this->assertSame('5 GB', $esim['data_limit']);
        $this->assertSame(30, $esim['validity_days']);
        $this->assertSame(['NG', 'GH'], $esim['countries']);
        $this->assertTrue($esim['is_rechargeable']);
        $this->assertSame(['Tethering allowed.'], $esim['operator_info']);
    }

    /**
     * That same metadata blob also holds the supplier's name, their package id and
     * `raw_payload`: the entire upstream response, wholesale cost included. None of
     * it may reach a customer, which is why the resource names its fields one by
     * one. This test is the tripwire for whoever later replaces that allowlist with
     * a convenient passthrough.
     */
    public function test_supplier_identity_and_raw_payloads_never_reach_the_app(): void
    {
        $this->catalogue([
            'metadata' => [
                'plan_type' => 'data_only',
                'validity_days' => 30,
                'provider' => 'airalo',
                'provider_package_id' => 'airalo_pkg_99',
                'raw_payload' => ['net_price' => 1.23, 'secret' => 'wholesale'],
            ],
        ]);

        $body = $this->getJson('/v2/catalog/products/mtn-nigeria')->assertOk()->getContent();

        $this->assertStringNotContainsString('airalo', strtolower($body));
        $this->assertStringNotContainsString('raw_payload', $body);
        $this->assertStringNotContainsString('net_price', $body);
        $this->assertStringNotContainsString('wholesale', $body);
    }

    public function test_a_variant_that_is_not_an_esim_reports_no_plan(): void
    {
        $this->catalogue(['metadata' => ['something' => 'else']]);

        $this->assertNull(
            $this->getJson('/v2/catalog/products/mtn-nigeria')->assertOk()->json('data.variants.0.esim'),
        );
    }

    public function test_an_unavailable_variant_cannot_be_quoted(): void
    {
        [, , , $variant] = $this->catalogue(['is_available' => false, 'is_variable' => true]);

        $this->postJson('/v2/catalog/quote', ['variant_id' => $variant->id, 'amount' => 20])
            ->assertNotFound()
            ->assertJsonPath('error.type', 'variant_unavailable');

        // Same answer for a variant that does not exist, so the endpoint cannot be
        // used to map which rows are hidden.
        $this->postJson('/v2/catalog/quote', ['variant_id' => 999999, 'amount' => 20])
            ->assertNotFound()
            ->assertJsonPath('error.type', 'variant_unavailable');
    }

    // ----------------------------------------------------- what checkout charges

    /**
     * The website's cart and checkout both round a converted price UP to the step
     * the currency is paid in (₦1,056.80 becomes ₦1,060). The app's price has to be
     * that figure, or the basket says one number and the charge another.
     */
    public function test_display_prices_are_rounded_to_the_step_the_currency_is_paid_in(): void
    {
        $this->rate('NGN', 'Nigerian Naira', 1600);
        $this->catalogue(['currency' => 'USD', 'face_value' => 1, 'cost_price' => 0.01, 'manual_retail_price_usd' => 0.6605]);

        $price = $this->getJson('/v2/catalog/products/mtn-nigeria', ['X-Display-Currency' => 'NGN'])
            ->assertOk()
            ->json('data.variants.0.price');

        // 0.6605 x 1600 = 1056.80, charged as 1060.
        $this->assertSame('1060.00', $price['amount']);
        $this->assertSame('NGN', $price['currency']);
        $this->assertSame('0.6605', $price['usd']);
    }

    /**
     * A type-your-own-amount option has no fixed price, and pretending it does
     * showed the raw column, often "$0.00", next to the amount box. It carries a
     * "from" price instead: what its minimum costs.
     */
    public function test_a_variable_variant_has_no_fixed_price_but_says_what_its_minimum_costs(): void
    {
        $this->catalogue([
            'currency' => 'USD',
            'is_variable' => true,
            'face_value' => null,
            'cost_price' => 0,
            'min_amount' => 2,
            'max_amount' => 100,
        ]);

        $response = $this->getJson('/v2/catalog/products/mtn-nigeria')->assertOk();

        $this->assertNull($response->json('data.variants.0.price'));
        $this->assertGreaterThanOrEqual(2.0, (float) $response->json('data.variants.0.price_from.usd'));
        // The tile's "from" price follows, so a variable-only operator is not greyed.
        $this->assertGreaterThanOrEqual(2.0, (float) $response->json('data.price_from.usd'));
    }

    /**
     * An offer in a currency with no live rate cannot be priced, so it is refused.
     * The cart's own validator would have priced it at par with the dollar, and a
     * typed ₦1,000 would have been quoted as $1,000.
     */
    public function test_an_offer_whose_currency_has_no_rate_cannot_be_quoted(): void
    {
        [, , , $variant] = $this->catalogue([
            'currency' => 'ZZZ',
            'is_variable' => true,
            'face_value' => null,
            'cost_price' => 0,
            'min_amount' => 100,
            'max_amount' => 50000,
        ]);

        $this->postJson('/v2/catalog/quote', ['variant_id' => $variant->id, 'amount' => 1000])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['amount']]]);
    }

    // ----------------------------------------------------------------- leaks

    /**
     * Some suppliers' SKUs begin with the supplier's own name. The app addresses a
     * variant by id and never needs the SKU, so it is not published anywhere.
     */
    public function test_a_supplier_sku_is_never_published(): void
    {
        $this->catalogue(['sku' => 'AIRALO-merhaba-7days-1gb']);

        $response = $this->getJson('/v2/catalog/products/mtn-nigeria')->assertOk();

        $this->assertArrayNotHasKey('sku', $response->json('data.variants.0'));
        $this->assertStringNotContainsStringIgnoringCase('airalo', $response->getContent());
    }

    // -------------------------------------------------------------- switches

    public function test_categories_and_products_say_whether_they_are_switched_on(): void
    {
        $this->catalogue();

        $category = $this->getJson('/v2/catalog/categories')->assertOk()->json('data.0');
        $this->assertTrue($category['is_enabled']);

        $product = $this->getJson('/v2/catalog/products/mtn-nigeria')->assertOk()->json('data');
        $this->assertTrue($product['is_available']);
    }

    // ----------------------------------------------------------------- cost

    /**
     * The rule chain reads each variant's product. Without handing it the already
     * loaded one, a page of a hundred products loaded a product per variant.
     */
    public function test_listing_products_does_not_run_a_query_per_variant(): void
    {
        [$category, $subcategory] = $this->catalogue();

        foreach (range(1, 8) as $i) {
            $product = Product::factory()->create([
                'category_id' => $category->id,
                'subcategory_id' => $subcategory->id,
                'is_active' => true,
                'slug' => 'product-'.$i,
                'name' => 'Product '.$i,
            ]);

            ProductVariant::factory()->count(4)->create([
                'product_id' => $product->id,
                'subcategory_id' => $subcategory->id,
                'is_available' => true,
                'cost_price' => 1,
            ]);
        }

        DB::enableQueryLog();

        $this->getJson('/v2/catalog/products?per_page=50')->assertOk();

        $queries = count(DB::getQueryLog());

        // Nine products, thirty-seven variants. A query per variant would be
        // forty-plus; the page itself is a handful.
        $this->assertLessThan(20, $queries, "Listing ran {$queries} queries.");
    }
}
