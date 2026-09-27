<?php

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The home page's own copy goes through the translator.
 *
 * The nav was translated long before the page it sits on was: a French
 * visitor got "Aide / Panier / Cartes cadeaux" above a hero that still read
 * "Gift Cards, eSIMs, Mobile Top-Ups". The strings were written straight into
 * the blade, so no amount of translation files could reach them.
 */
class HomeCopyTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * A French visitor's request. SetLocale resolves the locale from the
     * session on every request, so App::setLocale() alone is overwritten
     * before the view renders - the session is what a real switch writes.
     */
    private function french(string $url): string
    {
        return $this->withSession(['locale' => 'fr'])->get($url)->assertOk()->getContent();
    }

    public function test_the_hero_renders_french_copy(): void
    {
        $html = $this->french('/');

        $this->assertStringContainsString(__('home.hero_heading_line_one', [], 'fr'), $html);
        $this->assertStringContainsString(__('home.hero_subtitle', [], 'fr'), $html);
        $this->assertStringContainsString(__('home.hero_cta_gift_cards', [], 'fr'), $html);
        $this->assertStringContainsString(__('home.hero_cta_esims', [], 'fr'), $html);
    }

    /** The English source still reads exactly as it did before extraction. */
    public function test_the_hero_is_unchanged_in_english(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Gift Cards, eSIMs, Mobile Top-Ups', $html);
        $this->assertStringContainsString('Shop Gift Cards', $html);
        $this->assertStringContainsString('Explore eSIMs', $html);
    }

    /**
     * The heading is drawn twice - the visible h1 and the cursor-glow overlay
     * that masks a copy of it. Miss one and the glow spells out English
     * letters over a French heading.
     */
    public function test_both_copies_of_the_heading_are_translated(): void
    {
        $hero = File::get(resource_path('views/components/home/hero.blade.php'));

        $this->assertSame(2, substr_count($hero, "__('home.hero_heading_line_one')"));
        $this->assertSame(2, substr_count($hero, "__('home.hero_heading_line_two')"));
        $this->assertStringNotContainsString('Gift Cards, eSIMs, Mobile Top-Ups<br', $hero);
    }

    /** The counts stay live; only the sentence around them is translated. */
    public function test_the_feature_chips_keep_their_live_numbers(): void
    {
        $html = $this->french('/');

        // ":count+ pays couverts" with a real number substituted in.
        $this->assertMatchesRegularExpression('/\d+\+ pays couverts/', $html);
        $this->assertStringNotContainsString(':count', $html, 'a placeholder reached the page unsubstituted');
    }

    /**
     * The desktop category rail reads the same navigation.* keys the phone
     * menu sheet has always read, so the two can no longer name a category
     * differently.
     */
    public function test_the_category_rail_uses_the_shared_navigation_keys(): void
    {
        $nav = File::get(resource_path('views/components/nav/main-nav.blade.php'));

        foreach (['gift_cards', 'esims', 'mobile_refill', 'bill_payments'] as $key) {
            $this->assertStringContainsString("__('navigation.".$key."')", $nav);
        }

        $html = $this->french('/');

        $this->assertStringContainsString(__('navigation.gift_cards', [], 'fr'), $html);
        $this->assertStringContainsString(__('navigation.bill_payments', [], 'fr'), $html);
    }

    /** Every key the English home file defines exists in French too. */
    public function test_the_french_home_file_is_complete(): void
    {
        $en = require lang_path('en/home.php');
        $fr = require lang_path('fr/home.php');

        $this->assertSame([], array_diff_key($en, $fr), 'French is missing home keys');
    }
}
