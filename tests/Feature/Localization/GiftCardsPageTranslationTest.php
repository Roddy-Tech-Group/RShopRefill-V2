<?php

namespace Tests\Feature\Localization;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * The /gift-cards listing's own copy goes through the translator.
 *
 * The page title, the tile badges, the paused-category notice and the
 * category pill were written straight into the view and its shop components,
 * so a French visitor saw an English tab title and "Popular" badges on an
 * otherwise French page. Brand names and prices are data and stay as stored.
 *
 * Each check pairs the French it must show with the English it must not:
 * asserting only the French would pass on a page that renders both.
 */
class GiftCardsPageTranslationTest extends TestCase
{
    use RefreshDatabase;

    private function french(string $url): string
    {
        $this->post(route('locale.update'), ['locale' => 'fr']);

        return $this->withoutVite()->get($url)->assertOk()->getContent();
    }

    /**
     * One gift card brand in the default US region, with a single
     * denomination whose availability decides the out-of-stock badge.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function giftCardBrand(string $brandKey, array $attributes = [], bool $available = true): Product
    {
        $category = Category::firstOrCreate(['slug' => 'gift-cards'], ['name' => 'Gift Cards', 'type' => 'digital']);

        $product = Product::create(array_merge([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'brand_key' => $brandKey,
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => $brandKey.' Gift Card',
            'slug' => strtolower($brandKey).'-gift-card',
            'is_active' => true,
        ], $attributes));

        ProductVariant::factory()->for($product)->create([
            'currency' => 'USD',
            'face_value' => 25,
            'retail_price' => 25,
            'manual_retail_price_usd' => 25,
            'is_available' => $available,
            'is_variable' => false,
        ]);

        return $product;
    }

    public function test_the_gift_cards_page_chrome_renders_in_french(): void
    {
        $this->giftCardBrand('Acme', ['is_popular' => true]);
        $this->giftCardBrand('Globex', ['is_featured' => true]);
        $this->giftCardBrand('Initech', [], available: false);

        $html = $this->french('/gift-cards');

        $this->assertStringContainsString('<html lang="fr"', $html);
        $this->assertMatchesRegularExpression('/<title>\s*Cartes cadeaux \| /', $html);

        foreach ([
            'Toutes les cartes cadeaux',
            '>Populaire</span>',
            '>En vedette</span>',
            'Rupture de stock',
        ] as $french) {
            $this->assertStringContainsString($french, $html, 'Missing gift cards French: '.$french);
        }

        $this->assertDoesNotMatchRegularExpression('/<title>\s*Gift Cards \|/', $html);

        foreach (['>Popular</span>', '>Featured</span>', '>All gift cards<'] as $english) {
            $this->assertStringNotContainsString($english, $html, 'Untranslated gift cards English: '.$english);
        }
        $this->assertDoesNotMatchRegularExpression('/>\s*Out of stock\s*</', $html);
    }

    public function test_brand_names_stay_verbatim_in_french(): void
    {
        $this->giftCardBrand('Acme', ['is_popular' => true]);

        $html = $this->french('/gift-cards');

        $this->assertStringContainsString('Acme', $html);
    }

    public function test_the_paused_category_notice_renders_in_french(): void
    {
        SiteSetting::put('features.gift_cards_enabled', 'off', 'features');
        $this->giftCardBrand('Acme');

        $html = $this->french('/gift-cards');

        $this->assertStringContainsString('Vous pouvez parcourir le catalogue, mais les achats sont suspendus pour le moment.', $html);
        $this->assertMatchesRegularExpression('/>\s*Indisponible\s*</', $html);

        $this->assertStringNotContainsString('You can browse the catalog, but purchases are paused right now.', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*Unavailable\s*</', $html);
    }

    /** Every locale defines exactly the keys English does, none left empty. */
    public function test_every_locale_defines_the_same_gift_card_keys(): void
    {
        // Dot-flattened, because the curated category names are a nested array.
        $en = Arr::dot(require lang_path('en/giftcards.php'));

        foreach (['fr', 'es', 'pt', 'de', 'it', 'sw', 'ha', 'zh', 'ar'] as $locale) {
            $strings = Arr::dot(require lang_path($locale.'/giftcards.php'));

            $this->assertSame(array_keys($en), array_keys($strings), $locale.'/giftcards.php keys differ from English.');
            $this->assertEmpty(array_filter($strings, fn ($value) => trim($value) === ''), $locale.'/giftcards.php has an empty string.');
        }
    }
}
