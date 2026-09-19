<?php

namespace Tests\Feature\Localization;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The brand detail page's own copy goes through the translator.
 *
 * One Blade view renders every gift card, top-up and bill payment brand, and
 * all of its labels, badges, FAQ answers and long-form copy were written
 * straight into it. A French visitor could switch the language and still be
 * asked for a "Quantity" and an "Estimated price" on the page where they pay.
 *
 * Brand names, country names and amounts are data, so they pass through
 * unchanged; only the sentences around them move with the language.
 */
class ProductPageTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * A French visitor's request. SetLocale resolves the locale from the
     * session on every request, so the session is what a real switch writes.
     */
    private function french(string $url): string
    {
        return $this->withSession(['locale' => 'fr'])->get($url)->assertOk()->getContent();
    }

    /**
     * "Acme" has no display-name alias and no description, so the heading and
     * the fallback intro both render from the translation files.
     */
    private function giftCardBrand(): void
    {
        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);

        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'brand_key' => 'Acme',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Acme Gift Card',
            'slug' => 'acme-gift-card',
            'is_active' => true,
        ]);

        foreach ([10, 50] as $value) {
            ProductVariant::factory()->for($product)->create([
                'currency' => 'USD',
                'face_value' => $value,
                'retail_price' => $value,
                'manual_retail_price_usd' => $value,
                'is_available' => true,
                'is_variable' => false,
            ]);
        }
    }

    /** A top-up operator with a data plan, so the plan switcher renders. */
    private function topupOperator(): void
    {
        $category = Category::firstOrCreate(['slug' => 'mobile-airtime'], ['name' => 'Mobile Airtime', 'type' => 'digital']);
        $subcategory = Subcategory::firstOrCreate(['category_id' => $category->id, 'slug' => 'mobile-top-up'], ['name' => 'Mobile Top Up']);

        $product = Product::factory()->create([
            'brand_key' => 'Testop',
            'country_code' => 'US',
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'is_active' => true,
        ]);

        ProductVariant::factory()->for($product)->create([
            'is_variable' => false, 'is_available' => true, 'face_value' => 5, 'retail_price' => 5,
            'metadata' => ['subTypes' => ['Mobile Top Up']],
        ]);
        ProductVariant::factory()->for($product)->create([
            'is_variable' => false, 'is_available' => true, 'face_value' => 3, 'retail_price' => 3,
            'metadata' => ['subTypes' => ['Mobile Data'], 'dataGB' => 2, 'durationDays' => 30],
        ]);
    }

    public function test_a_gift_card_page_renders_french_copy(): void
    {
        $this->giftCardBrand();

        $html = $this->french(route('shop.brand', ['brandSlug' => 'acme']));

        foreach ([
            __('products.detail_name_gift_card', ['brand' => 'Acme'], 'fr'),
            // The line under the name says what the card pays for; this fixture has
            // no subcategory, so it is the general one.
            __('products.detail_purpose_general', ['brand' => 'Acme', 'country' => 'United States'], 'fr'),
            __('products.detail_badge_online_redeemable', [], 'fr'),
            __('products.detail_quantity', [], 'fr'),
            // A gift card prices in its purchase card and names the pay currency
            // beside its values, so it carries both labels.
            __('products.detail_estimated_price', [], 'fr'),
            __('common.payment_currency', [], 'fr'),
            __('products.detail_points_you_earn', [], 'fr'),
            __('products.detail_redeemable_in', ['country' => 'United States'], 'fr'),
            __('products.detail_faq_gift_card_failed_q', [], 'fr'),
            __('products.detail_about_heading_gift_card', ['brand' => 'Acme', 'country' => 'United States'], 'fr'),
            __('products.detail_how_to_gift_card', ['brand' => 'Acme'], 'fr'),
        ] as $french) {
            $this->assertStringContainsString(e($french), $html, 'Missing product page French: '.$french);
        }

        $this->assertStringNotContainsString(':brand', $html, 'a placeholder reached the page unsubstituted');
    }

    /**
     * Asserting only the French would pass on a page that renders both, so the
     * distinctive English it used to show must be gone too.
     */
    public function test_the_english_copy_is_absent_for_a_french_visitor(): void
    {
        $this->giftCardBrand();

        $html = $this->french(route('shop.brand', ['brandSlug' => 'acme']));

        foreach ([
            'Estimated price',
            'Payment currency',
            'Points you earn',
            'Online redeemable',
            'May only be redeemable in',
            'Find your country',
            'How to buy Acme gift card',
            'What happens if the code',
            // The amount selector's placeholder lives in an Alpine expression.
            "'Select amount'",
        ] as $english) {
            $this->assertStringNotContainsString($english, $html, 'Untranslated product page English: '.$english);
        }
    }

    /** The top-up plan chips keep the supplier's figures inside French copy. */
    public function test_a_top_up_page_renders_its_plan_copy_in_french(): void
    {
        $this->topupOperator();

        $html = $this->french(route('shop.topup', 'testop'));

        foreach ([
            __('products.detail_choose_topup', [], 'fr'),
            __('products.detail_data_size', ['size' => '2GB'], 'fr'),
            __('products.detail_validity_days', ['count' => '30'], 'fr'),
            __('products.detail_phone_label', ['country' => 'United States'], 'fr'),
            __('products.detail_faq_topup_failed_q', [], 'fr'),
            // The buy panel, which only the other kinds still draw, keeps this label.
            __('products.detail_estimated_price', [], 'fr'),
        ] as $french) {
            $this->assertStringContainsString(e($french), $html, 'Missing top-up French: '.$french);
        }

        foreach (['Choose how to top up', '2GB data', '30-day validity', 'phone number to refill', 'Estimated price'] as $english) {
            $this->assertStringNotContainsString($english, $html, 'Untranslated top-up English: '.$english);
        }
    }

    /** The English source still reads exactly as it did before extraction. */
    public function test_an_english_visitor_still_sees_the_original_copy(): void
    {
        $this->giftCardBrand();

        $html = $this->get(route('shop.brand', ['brandSlug' => 'acme']))->assertOk()->getContent();

        foreach ([
            'Acme gift card</h1>',
            'Pays for anything Acme sells in United States, in store and online.',
            'Estimated price',
            'Payment currency',
            'Points you earn',
            'May only be redeemable in United States',
            // "Not in X? Find your country" is a top-up and bill line now: a gift
            // card sold in one country has nowhere to send you, and one sold in
            // several offers its own regions instead (BrandRegionPickerTest).
            'Buy Acme Gift Card in United States',
            'How to buy Acme gift card',
            'Receive your redemption code by email instantly, then redeem it at Acme.',
            // Gift cards pick from a standing grid of values now, not the
            // dropdown whose placeholder this used to pin (GiftCardDenominationGridTest).
            'role="radiogroup"',
        ] as $english) {
            $this->assertStringContainsString($english, $html, 'English product page copy changed: '.$english);
        }
    }

    /** Both languages define exactly the same keys, or French silently falls back. */
    public function test_english_and_french_define_the_same_product_keys(): void
    {
        $en = require lang_path('en/products.php');
        $fr = require lang_path('fr/products.php');

        $this->assertSame([], array_keys(array_diff_key($en, $fr)), 'French is missing product keys');
        $this->assertSame([], array_keys(array_diff_key($fr, $en)), 'French defines product keys English does not');
        $this->assertEmpty(array_filter($fr, fn ($v) => trim((string) $v) === ''), 'French has blank product strings');
    }
}
