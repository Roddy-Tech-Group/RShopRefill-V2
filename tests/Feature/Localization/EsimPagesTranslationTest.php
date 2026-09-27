<?php

namespace Tests\Feature\Localization;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The eSIM storefront renders in the visitor's language.
 *
 * Same shape as StorefrontTranslationTest: each page gets French strings it
 * must show and English ones it must not, because asserting only the French
 * would pass on a page that renders both. Destination names are catalogue
 * data and must come through untranslated.
 */
class EsimPagesTranslationTest extends TestCase
{
    use RefreshDatabase;

    private function french(string $url): string
    {
        $this->post(route('locale.update'), ['locale' => 'fr']);

        return $this->withoutVite()->get($url)->assertOk()->getContent();
    }

    /** One single-country destination with a data plan, so the grid has a card. */
    private function franceEsim(): Product
    {
        $category = Category::create(['name' => 'eSIMs', 'slug' => 'esims', 'type' => 'digital']);

        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'airalo',
            'country_code' => 'FR',
            'currency_code' => 'USD',
            'name' => 'France Data eSIM',
            'slug' => 'esim-fr-france',
            'is_active' => true,
        ]);

        foreach ([[1024, 7, 4.50], [10240, 30, 14.00]] as [$megabytes, $days, $price]) {
            ProductVariant::create([
                'product_id' => $product->id,
                'provider_offer_id' => "fr-{$days}d",
                'currency' => 'USD',
                'face_value' => $price,
                'cost_price' => $price - 1,
                'retail_price' => $price,
                'is_available' => true,
                'metadata' => [
                    'plan_type' => 'data_only',
                    'validity_days' => $days,
                    'data_limit' => ($megabytes / 1024).' GB',
                    'raw_payload' => ['amount' => $megabytes, 'day' => $days],
                ],
            ]);
        }

        return $product;
    }

    public function test_the_esim_listing_renders_in_french(): void
    {
        $this->franceEsim();

        $html = $this->french(route('shop.esims'));

        // Written as Blade emits them: {{ }} escapes an apostrophe to &#039;,
        // and @js() hex-escapes it, so neither form appears as a raw quote.
        foreach ([
            'Destinations populaires',                  // grid heading
            'Où partez-vous ?',                         // search placeholder
            'Rechercher une destination eSIM',          // search aria-label
            'Voir les forfaits',                        // card action
            'Données uniquement',                       // card chip
            '7 - 30 jours',                             // days span, unit translated
            'Régionales',                               // location tab
            'Profitez de la liberté des données illimitées', // stock hero copy
            'Vérifiez que',                             // purchase-check modal
        ] as $french) {
            $this->assertStringContainsString($french, $html, 'Missing eSIM listing French: '.$french);
        }

        foreach ([
            '>Popular locations<',
            'Where are you travelling?',
            '>View plans<',
            '>Data only<',
            ' days<',
            'Feel the freedom of unlimited data',
            'Only buy this eSIM if you are in',
        ] as $english) {
            $this->assertStringNotContainsString($english, $html, 'Untranslated eSIM listing English: '.$english);
        }

        // The destination is catalogue data, never translated.
        $this->assertStringContainsString('>France<', $html);
    }

    /** An admin's own hero copy is shown as written, in every language. */
    public function test_admin_edited_hero_copy_is_not_replaced_by_a_translation(): void
    {
        SiteSetting::put('esim.promo1_heading', 'Our own first headline', 'esim');

        $html = $this->french(route('shop.esims'));

        $this->assertStringContainsString('Our own first headline', $html);
        $this->assertStringNotContainsString('Profitez de la liberté des données illimitées', $html);
    }

    public function test_an_esim_destination_page_renders_in_french(): void
    {
        $this->franceEsim();

        $html = $this->french(route('shop.esim', 'esim-fr-france'));

        foreach ([
            'Boutique eSIM',                    // breadcrumb
            'eSIM locales',                     // scope crumb
            'Vérifier la compatibilité',
            'Choisissez votre forfait',
            'Comment ajouter votre eSIM',
            'Moyens de paiement acceptés',
            'Changer de pays ou de région',
            'Destination <span class="font-semibold">France</span>',
        ] as $french) {
            $this->assertStringContainsString($french, $html, 'Missing eSIM page French: '.$french);
        }

        foreach ([
            '>eSIM Store<',
            'Check compatibility',
            'Choose your package',
            'How to add your eSIM',
            'Payment methods we accept',
            'Change country or region',
        ] as $english) {
            $this->assertStringNotContainsString($english, $html, 'Untranslated eSIM page English: '.$english);
        }
    }

    /** English is untouched by the move to lang keys. */
    public function test_the_esim_listing_still_reads_the_same_in_english(): void
    {
        $this->franceEsim();

        $html = $this->withoutVite()->get(route('shop.esims'))->assertOk()->getContent();

        foreach (['Popular locations', 'Where are you travelling?', 'View plans', 'Data only', '7 - 30 days', 'Feel the freedom of unlimited data'] as $english) {
            $this->assertStringContainsString($english, $html);
        }
    }
}
