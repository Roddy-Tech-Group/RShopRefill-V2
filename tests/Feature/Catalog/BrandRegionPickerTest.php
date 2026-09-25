<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\TaggedCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A gift card sold in several countries lets you move to the one you are buying
 * for, from the line that tells you where it can be redeemed. The list is the
 * brand's own countries: the global locale modal it replaces could land you on a
 * country the brand has no card in, and the brand resolver would quietly serve
 * another region's card instead.
 *
 * A brand sold in one country says so and offers nothing to choose.
 */
class BrandRegionPickerTest extends TestCase
{
    use RefreshDatabase;

    private Category $giftCards;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->giftCards = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);
    }

    private function row(string $country, array $extra = [], ?Category $category = null): Product
    {
        $category ??= $this->giftCards;

        $product = Product::create(array_merge([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'brand_key' => 'Acme',
            'country_code' => $country,
            'currency_code' => 'USD',
            'name' => "Acme Gift Card ({$country})",
            'slug' => 'acme-'.strtolower($country).'-zendit',
            'is_active' => true,
        ], $extra));

        ProductVariant::factory()->for($product)->create([
            'currency' => 'USD',
            'face_value' => 10,
            'retail_price' => 10,
            'manual_retail_price_usd' => 10,
            'is_available' => true,
            'is_variable' => false,
        ]);

        return $product;
    }

    private function page(string $country = 'US'): string
    {
        TaggedCache::for(['catalog'])->flush();

        return $this->withoutVite()
            ->get(route('shop.brand', ['brandSlug' => 'acme', 'country' => $country]))
            ->assertOk()
            ->getContent();
    }

    /**
     * The picker's own menu. The page carries other listboxes (quantity, pay
     * currency) and the locale modal lists every country on earth, so a
     * page-wide assertion about country names proves nothing.
     */
    private function picker(string $html): string
    {
        $start = strpos($html, 'Choose another region');
        $this->assertNotFalse($start, 'The region picker did not render.');

        $menu = strpos($html, 'role="listbox"', $start);
        $this->assertNotFalse($menu, 'The region picker has no menu.');

        return substr($html, $menu, strpos($html, '</div>', $menu) - $menu);
    }

    public function test_a_brand_in_several_countries_offers_them_all(): void
    {
        $this->row('US');
        $this->row('GB');
        $this->row('DE');

        $html = $this->page();

        $this->assertStringContainsString('Choose another region', $html);

        $picker = $this->picker($html);
        foreach (['United States', 'United Kingdom', 'Germany'] as $name) {
            $this->assertStringContainsString('>'.$name.'</span>', $picker);
        }

        // Three options, each one setting the shared region state rather than
        // opening the global modal.
        $this->assertSame(3, substr_count($picker, 'role="option"'));
        $this->assertStringContainsString("countryCode = 'GB'", $picker);
        $this->assertStringNotContainsString('Find your country', $html);
    }

    public function test_a_brand_in_one_country_offers_nothing(): void
    {
        $this->row('US');

        $html = $this->page();

        $this->assertStringContainsString('Redeemable only in United States, or on an account or device set to the United States region', $html);
        $this->assertStringNotContainsString('Choose another region', $html);
        $this->assertStringNotContainsString('Find your country', $html);
    }

    /** The country you are on is marked, so the list says where you are. */
    public function test_the_current_country_is_marked_and_leads(): void
    {
        $this->row('US');
        $this->row('GB');

        $html = $this->page('GB');

        $this->assertStringContainsString('Redeemable only in United Kingdom, or on an account or device set to the United Kingdom region', $html);

        $picker = $this->picker($html);
        $this->assertMatchesRegularExpression('/aria-selected="true"[\s\S]{0,600}?United Kingdom/', $picker);

        // The current country is first in the list.
        $this->assertLessThan(
            strpos($picker, '>United States</span>'),
            strpos($picker, '>United Kingdom</span>')
        );
    }

    /**
     * One brand_key can span two categories, so the list has to be scoped to
     * this one: an airtime country is not a country this gift card is sold in.
     */
    public function test_another_categorys_countries_are_not_offered(): void
    {
        $airtime = Category::create(['name' => 'Mobile Airtime', 'slug' => 'mobile-airtime', 'type' => 'digital']);

        $this->row('US');
        $this->row('GB');
        $this->row('CM', ['slug' => 'acme-cm-airtime'], $airtime);

        $html = $this->page();

        $picker = $this->picker($html);

        $this->assertStringContainsString('>United Kingdom</span>', $picker);
        $this->assertStringNotContainsString('>Cameroon</span>', $picker);
        $this->assertSame(2, substr_count($picker, 'role="option"'));
    }

    /** A region you cannot buy in is not a region worth offering. */
    public function test_an_out_of_stock_country_is_not_offered(): void
    {
        $this->row('US');
        $this->row('GB', ['is_out_of_stock' => true]);

        $html = $this->page();

        $this->assertStringNotContainsString('Choose another region', $html);
        $this->assertStringNotContainsString('>United Kingdom</span>', $html);
    }

    /** Top-ups are a network in one country, not a set of interchangeable regions. */
    public function test_top_ups_keep_the_global_country_link(): void
    {
        $source = file_get_contents(resource_path('views/shop/_country-availability.blade.php'));

        $this->assertStringContainsString('$availabilityCanSwitch = ($isGiftCard ?? false) && $availabilityCountries->count() > 1;', $source);
        // Top-ups keep the global link only while there are refill countries to
        // offer; the branch is scoped to that, not just to "not a gift card".
        $this->assertStringContainsString('@elseif (! ($isGiftCard ?? false) && $availabilityRefillCountries->isNotEmpty())', $source);
        $this->assertStringContainsString("__('products.detail_find_your_country')", $source);
    }
}
