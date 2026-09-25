<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Faq;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\AffiliateFaqSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The Influencers and Affiliates page makes its case before it asks anyone to
 * apply: who it is for, what the four stores hold, why a recommendation is easy
 * to stand behind, the topic's FAQ, and a closing ask that links down to the
 * form. Every figure is the catalogue's own, and the page promises no rates,
 * commissions or response times.
 */
class AffiliatesPageContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::flush();
    }

    private function page(): string
    {
        return $this->get(route('shop.affiliates'))->assertOk()->getContent();
    }

    private function category(string $slug): Category
    {
        return Category::create(['name' => ucfirst($slug), 'slug' => $slug, 'type' => 'digital']);
    }

    public function test_the_page_makes_its_case_in_order_and_then_asks(): void
    {
        $html = $this->page();

        $this->assertLessThan(strpos($html, 'What you can share with your audience'), strpos($html, 'Who this is for'));
        $this->assertLessThan(strpos($html, 'Why creators work with us'), strpos($html, 'What you can share with your audience'));
        $this->assertLessThan(strpos($html, 'Ready to work together?'), strpos($html, 'Why creators work with us'));
        $this->assertLessThan(strpos($html, '>Join us today</h2>'), strpos($html, 'Ready to work together?'));

        // The closing ask links down to the form on this page.
        $this->assertStringContainsString('href="#apply"', $html);
        $this->assertStringContainsString('<section id="apply"', $html);

        // Three audiences, four stores, four reasons.
        foreach (['Creators and influencers', 'Publishers and bloggers', 'Community leaders'] as $audience) {
            $this->assertStringContainsString($audience, $html);
        }
        foreach (['shop.gift-cards', 'shop.esims', 'shop.topups', 'shop.bills'] as $store) {
            $this->assertStringContainsString('href="'.route($store).'"', $html);
        }
        $this->assertStringContainsString('Delivered the moment payment clears', $html);
    }

    public function test_the_store_cards_carry_the_catalogues_own_figures(): void
    {
        $giftCards = $this->category('gift-cards');
        $esims = $this->category('esims');
        Product::factory()->create(['category_id' => $giftCards->id, 'brand_key' => 'Alpha', 'country_code' => 'US']);
        Product::factory()->create(['category_id' => $giftCards->id, 'brand_key' => 'Beta', 'country_code' => 'US']);
        foreach (['FR', 'DE', 'ES'] as $country) {
            Product::factory()->create(['category_id' => $esims->id, 'brand_key' => 'Roam', 'country_code' => $country]);
        }

        $html = $this->page();

        $this->assertStringContainsString('2 brands to choose from', $html);
        $this->assertStringContainsString('Data plans for 3 countries', $html);
        // Stores with nothing on the shelf describe themselves instead of saying zero.
        $this->assertStringContainsString('Airtime and data, credited to the number', $html);
        $this->assertStringContainsString('Utilities and services, paid online', $html);
        $this->assertStringNotContainsString('0 networks', $html);
    }

    public function test_headline_figures_appear_only_when_there_is_something_to_say(): void
    {
        $this->assertStringNotContainsString('customers already shopping with us', $this->page());

        User::factory()->count(150)->create();
        Cache::flush();

        $this->assertStringContainsString('100+', $this->page());
        $this->assertStringContainsString('customers already shopping with us', $this->page());
    }

    public function test_the_faq_appears_once_its_topic_is_seeded(): void
    {
        $this->assertStringNotContainsString('affiliates-faq-heading', $this->page());

        $this->seed(AffiliateFaqSeeder::class);
        Cache::flush();

        $html = $this->page();
        $this->assertStringContainsString('affiliates-faq-heading', $html);
        $this->assertStringContainsString('Does it cost anything to join?', $html);
        $this->assertStringContainsString('How are the terms of a partnership decided?', $html);

        // Seeding twice updates rather than duplicates.
        $this->seed(AffiliateFaqSeeder::class);
        $this->assertSame(8, Faq::where('topic', 'Influencers and Affiliates')->count());
    }

    public function test_the_page_promises_no_business_terms(): void
    {
        // The rendered copy, FAQ included, not the sources: the comments that
        // explain this rule are allowed to name the words the page may not.
        $this->seed(AffiliateFaqSeeder::class);
        Cache::flush();
        $html = $this->page();
        $pitch = substr($html, strpos($html, 'Who this is for'), strpos($html, '<section id="apply"') - strpos($html, 'Who this is for'));

        foreach (['commission', 'payout', 'within 24', 'within 48', 'business days'] as $term) {
            $this->assertStringNotContainsStringIgnoringCase($term, $pitch, "the partner page must not state a business term: {$term}");
        }

        // No decorative arrows on its links or buttons.
        $this->assertStringNotContainsString('M13.5 4.5L21 12', File::get(resource_path('views/shop/_affiliates-pitch.blade.php')));
    }

    public function test_the_request_a_product_page_keeps_only_its_form(): void
    {
        $html = $this->get(route('shop.request-product'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Who this is for', $html);
        $this->assertStringContainsString('<section id="apply"', $html);
    }
}
