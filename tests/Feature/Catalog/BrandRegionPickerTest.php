<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\TaggedCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
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

        // Three options, each one sending the region to the shared state rather
        // than opening the global modal.
        $this->assertSame(3, substr_count($picker, 'role="option"'));
        $this->assertStringContainsString("\$dispatch('pick-region', { country: 'United Kingdom'", $picker);
        $this->assertStringContainsString("countryCode: 'GB', currency: 'GBP'", $picker);
        $this->assertStringNotContainsString('Find your country', $html);
    }

    /**
     * The storefront wraps the whole page in storefrontLocale(), the dashboard
     * only its locale modal. The picker used to assign the region keys itself,
     * which reached that scope on the storefront but no watching scope at all on
     * the dashboard, so the menu closed and nothing switched. It now sends an
     * event, and whatever applies it has to sit inside storefrontLocale() on
     * both surfaces.
     *
     * @return array<string, array{0: bool}>
     */
    public static function surfaces(): array
    {
        return [
            'storefront' => [false],
            'dashboard' => [true],
        ];
    }

    #[DataProvider('surfaces')]
    public function test_picking_a_region_reaches_the_shared_locale_scope(bool $inDashboard): void
    {
        $this->row('US');
        $this->row('GB');

        TaggedCache::for(['catalog'])->flush();

        $request = $this->withoutVite();

        if ($inDashboard) {
            $request->actingAs(User::factory()->create());
        }

        $html = $request
            ->get(route($inDashboard ? 'dashboard.shop.brand' : 'shop.brand', ['brandSlug' => 'acme', 'country' => 'US']))
            ->assertOk()
            ->getContent();

        $picker = $this->picker($html);
        $this->assertStringContainsString("\$dispatch('pick-region'", $picker);
        $this->assertStringNotContainsString('countryCode = ', $picker);

        $this->assertSame(1, preg_match_all('/x-on:pick-region\.window="([^"]*)"/', $html, $listener), 'Exactly one element should apply a picked region.');

        foreach (['country', 'countryFlag', 'countryCode', 'currency', 'currencySymbol'] as $key) {
            $this->assertStringContainsString("{$key} = \$event.detail.{$key};", $listener[1][0]);
        }

        $this->assertTrue(
            $this->isNestedIn($html, 'x-data="storefrontLocale()"', 'x-on:pick-region.window'),
            'The pick-region listener is outside the storefrontLocale() scope, so its writes fire no watcher.'
        );
    }

    /**
     * Whether $needle sits inside the element carrying $wrapperAttribute.
     *
     * Counted by tags rather than walked in a DOM: PHP's HTML parser drops
     * most of these pages (custom elements, Alpine's @ and : attributes), the
     * locale modal with them.
     */
    private function isNestedIn(string $html, string $wrapperAttribute, string $needle): bool
    {
        $needleAt = strpos($html, $needle);
        $this->assertNotFalse($needleAt, "{$needle} did not render.");

        $wrapperAt = strrpos(substr($html, 0, $needleAt), $wrapperAttribute);
        $this->assertNotFalse($wrapperAt, "No {$wrapperAttribute} before {$needle}.");

        $tagAt = strrpos(substr($html, 0, $wrapperAt), '<');
        preg_match('/^<([a-z][a-z0-9-]*)/i', substr($html, $tagAt), $tag);

        // Script bodies can hold markup strings that are not tags.
        $between = preg_replace('#<script\b[^>]*>.*?</script>#is', '', substr($html, $tagAt, $needleAt - $tagAt));
        preg_match_all('#<(/?)'.preg_quote($tag[1], '#').'\b#i', $between, $tags, PREG_SET_ORDER);

        // The wrapper opens at depth 1; closing back to 0 before the needle
        // means the needle comes after it, not inside it.
        $depth = 0;
        foreach ($tags as $index => $found) {
            $depth += $found[1] === '/' ? -1 : 1;

            if ($index > 0 && $depth < 1) {
                return false;
            }
        }

        return true;
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
