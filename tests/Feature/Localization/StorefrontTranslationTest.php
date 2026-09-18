<?php

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage, not wiring: LocalizationTest proves the locale resolves and the
 * switcher works. This proves the pages actually CHANGE - that the strings a
 * customer reads sit behind translation keys rather than hardcoded English.
 *
 * Each area gets a French string it must show and an English one it must not.
 * Asserting only the French would pass on a page that renders both.
 */
class StorefrontTranslationTest extends TestCase
{
    use RefreshDatabase;

    private function french(string $url): string
    {
        $this->post(route('locale.update'), ['locale' => 'fr']);

        return $this->withoutVite()->get($url)->assertOk()->getContent();
    }

    public function test_the_footer_renders_in_french(): void
    {
        $html = $this->french('/');

        // Written as Blade emits them: {{ }} escapes an apostrophe to &#039;,
        // so "Centre d'aide" never appears literally in the HTML. Accents pass
        // through untouched - htmlspecialchars only touches & < > " '.
        foreach ([
            'Boutique',                 // Shop column heading
            'Cartes cadeaux',
            'eSIM de voyage',
            'Centre d&#039;aide',
            'Conditions d&#039;utilisation',
            'Suivez-nous',
            'Paramètres des cookies',
        ] as $french) {
            $this->assertStringContainsString($french, $html, 'Missing footer French: '.$french);
        }

        foreach (['>Help center<', '>Cookie settings<', '>Follow us<', '>Refund policy<'] as $english) {
            $this->assertStringNotContainsString($english, $html, 'Untranslated footer English: '.$english);
        }
    }

    /**
     * The operator's legal identity is not copy. A translator restating the
     * company name, RC number or registered address would change who the
     * customer is contracting with, so those arrive as placeholders.
     */
    public function test_the_operator_identity_is_never_translated(): void
    {
        $html = $this->french('/');

        foreach ([
            'Roddy Technologies Ltd',
            '9385422',
            '5 Tetlow Road, Njemenze, Owerri, Imo State, Nigeria',
        ] as $verbatim) {
            $this->assertStringContainsString($verbatim, $html);
        }

        // And they are not baked into either translation.
        foreach (['en', 'fr'] as $locale) {
            $strings = require lang_path($locale.'/footer.php');
            $this->assertStringNotContainsString('Roddy Technologies', $strings['legal_line']);
            $this->assertStringNotContainsString('9385422', $strings['legal_line']);
            $this->assertStringContainsString(':company', $strings['legal_line']);
        }
    }

    /**
     * Brand names stay verbatim in both languages.
     *
     * This used to read the homepage, where the footer's App Store and Google
     * Play download badges were. Those badges are gone - there is no app to
     * download - so the assertion moved to How it works, which lists the gift
     * card brands and is the page where the rule now has something to protect.
     */
    public function test_store_brands_are_not_translated(): void
    {
        $html = $this->french('/how-it-works');

        foreach (['Google Play', 'PlayStation', 'Netflix', 'Apple'] as $brand) {
            $this->assertStringContainsString($brand, $html, $brand.' is a brand, not copy.');
        }
    }

    /** Both languages define exactly the same keys, or French silently falls back. */
    public function test_english_and_french_define_the_same_footer_keys(): void
    {
        $en = require lang_path('en/footer.php');
        $fr = require lang_path('fr/footer.php');

        $this->assertSame(array_keys($en), array_keys($fr));
        $this->assertEmpty(array_filter($fr, fn ($v) => $v === ''));
    }
}
