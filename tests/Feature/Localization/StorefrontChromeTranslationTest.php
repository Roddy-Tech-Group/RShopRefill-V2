<?php

namespace Tests\Feature\Localization;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The shared chrome every storefront page carries: the sign-in modal, the
 * cookie banner, the nav and its panels, the feedback tab and the
 * preferences sheet. StorefrontTranslationTest covers the footer and the page
 * bodies; this covers what sits around them.
 *
 * Same method as there: each area gets French it must show and English it
 * must not, because asserting only the French passes on a page that renders
 * both.
 */
class StorefrontChromeTranslationTest extends TestCase
{
    use RefreshDatabase;

    private function page(string $locale, string $url = '/'): string
    {
        $this->post(route('locale.update'), ['locale' => $locale]);

        return $this->withoutVite()->get($url)->assertOk()->getContent();
    }

    /**
     * Boolean checks with a short message. The stock string assertions print
     * the whole page on failure, and a diff of a full storefront render stalls
     * PHPUnit for minutes instead of reporting what was missing.
     */
    private function assertSees(string $needle, string $html, string $message = ''): void
    {
        $this->assertTrue(str_contains($html, $needle), $message ?: 'Missing: '.$needle);
    }

    private function assertDoesNotSee(string $needle, string $html, string $message = ''): void
    {
        $this->assertFalse(str_contains($html, $needle), $message ?: 'Unexpected: '.$needle);
    }

    private function assertMatches(string $pattern, string $html, string $message = ''): void
    {
        $this->assertSame(1, preg_match($pattern, $html), $message ?: 'No match for: '.$pattern);
    }

    public function test_the_auth_modal_renders_in_french(): void
    {
        $html = $this->page('fr');

        // Escaped the way Blade emits them: {{ }} leaves accents and the
        // typographic apostrophe alone. The step labels travel inside @js, so
        // they are only checked where they carry no accent.
        foreach ([
            'Bon retour parmi nous',
            'Se souvenir de moi',
            'Mot de passe oublié ?',
            'Continuer avec Google',
            'Vous n’avez pas de compte ?',
            'Vos informations',
            'Numéro de téléphone',
            'Choisir l’indicatif du pays',
            'Confirmer le mot de passe',
            'Homme',
            'Femme',
            'Code de parrainage',
            '(facultatif)',
            'conditions d’utilisation',
            'Créer un compte',
            'Vous avez déjà un compte ?',
            'Compte créé',
            'Accéder à mon compte',
            'vous@example.com',
        ] as $french) {
            $this->assertSees($french, $html, 'Missing auth modal French: '.$french);
        }

        foreach ([
            '>Welcome back<',
            'Remember me',
            'Forgot password?',
            "Don't have an account?",
            'Your details',
            'Select country dialing code',
            'Confirm password',
            "'Male'",
            '>Account created<',
            'Continue to my account',
            'Show password',
            'Step ${step} of 3',
        ] as $english) {
            $this->assertDoesNotSee($english, $html, 'Untranslated auth modal English: '.$english);
        }
    }

    public function test_the_auth_modal_links_stay_intact_inside_the_translated_sentence(): void
    {
        $html = $this->page('fr');

        // The consent sentence is one key with the two links as placeholders:
        // the anchors must survive, and no raw :terms may leak through.
        $this->assertMatches(
            '#les <a href="[^"]+" target="_blank"[^>]*>conditions d’utilisation</a> et la <a href="[^"]+" target="_blank"[^>]*>politique de confidentialité</a>#u',
            $html,
        );
        $this->assertDoesNotSee(':terms', $html);
        $this->assertDoesNotSee(':privacy', $html);
    }

    public function test_the_cookie_banner_renders_in_french(): void
    {
        $html = $this->page('fr');

        foreach ([
            'Nous utilisons des cookies pour vous garder connecté',
            'Politique relative aux cookies</a>',
            'Refuser',
            'Accepter les cookies',
            'aria-label="Consentement aux cookies"',
        ] as $french) {
            $this->assertSees($french, $html, 'Missing cookie banner French: '.$french);
        }

        foreach (['We use cookies', 'Accept cookies', 'aria-label="Cookie consent"', ':policy'] as $english) {
            $this->assertDoesNotSee($english, $html, 'Untranslated cookie banner English: '.$english);
        }
    }

    public function test_the_site_name_is_never_translated_in_the_chrome(): void
    {
        $siteName = (string) SiteSetting::get('site.name', 'RshopRefill');
        $html = $this->page('fr');

        $this->assertSees('améliorer '.e($siteName).'.', $html);
        $this->assertSees('Connectez-vous à votre compte '.e($siteName), $html);
        $this->assertDoesNotSee(' :site', $html);
    }

    public function test_the_nav_feedback_tab_and_referral_panel_render_in_french(): void
    {
        $html = $this->page('fr');

        foreach ([
            'aria-label="Catégories de produits"',
            'Tous les réseaux',
            'Crédit uniquement',
            'Parrainez vos amis, gagnez',
            'Connectez-vous pour obtenir votre lien',
            'Créez un compte</a> et votre lien est prêt immédiatement.',
            'Donner votre avis',
            'Votre avis...',
            'title="Faites glisser pour déplacer"',
        ] as $french) {
            $this->assertSees($french, $html, 'Missing nav French: '.$french);
        }

        foreach ([
            'Product categories',
            'All networks',
            'Credit only',
            'Sign in to get your link',
            'Send feedback',
            'Drag to move',
            "' item' +",
            'aria-label="Decrease"',
            'aria-label="Your account"',
            "'Region and currency: '",
        ] as $english) {
            $this->assertDoesNotSee($english, $html, 'Untranslated nav English: '.$english);
        }
    }

    /**
     * The chip that bit: the region pill sliced two letters off the English
     * display name ("French" -> "FR" by luck, "Spanish" -> "SP", "German" ->
     * "GE") and fell back to "English". It now renders the locale's own short
     * code, and the preferences sheet names the language in that language.
     */
    public function test_the_language_chip_shows_the_active_language(): void
    {
        foreach (['fr' => ['FR', 'Français'], 'es' => ['ES', 'Español'], 'de' => ['DE', 'Deutsch']] as $locale => [$short, $native]) {
            $html = $this->page($locale);

            $this->assertSees('<span>'.$short.'</span> / <span x-text="currency', $html, "{$locale} pill short code");
            $this->assertMatches('#x-text="nativeFor\(draftLanguage\)">'.preg_quote($native, '#').'</span>#u', $html, "{$locale} sheet language row");
        }
    }

    public function test_the_currency_names_follow_the_language_and_the_codes_do_not(): void
    {
        $html = $this->page('fr');

        // @js escapes non-ASCII, so check ones that carry no accent.
        $this->assertSees('Livre sterling', $html);
        $this->assertSees('Franc suisse', $html);
        $this->assertDoesNotSee('British Pound', $html);
        // @js writes each quote as the six characters backslash-u-0-0-2-2.
        $this->assertSees(str_replace('"', '\\u0022', '"code":"GBP"'), $html);
    }

    public function test_auth_modal_validation_names_the_field_in_french(): void
    {
        App::setLocale('fr');

        $component = Volt::test('auth.auth-modal')
            ->set('loginEmail', '')
            ->set('loginPassword', '')
            ->call('login')
            ->assertHasErrors(['loginEmail', 'loginPassword']);

        $messages = $component->errors()->all();

        $this->assertContains('Le champ adresse e-mail est obligatoire.', $messages);
        $this->assertContains('Le champ mot de passe est obligatoire.', $messages);
        foreach ($messages as $message) {
            $this->assertDoesNotSee('login', strtolower($message));
        }
    }

    public function test_the_chrome_is_unchanged_in_english(): void
    {
        $html = $this->page('en');

        foreach ([
            '>Welcome back<',
            'Remember me',
            'Don&#039;t have an account?',
            'We use cookies to keep you signed in, secure your payments and improve',
            'Accept cookies',
            'aria-label="Product categories"',
            'All networks',
            'Credit, Data &amp; Calls',
            'Send feedback',
            'Refer friends, earn',
            '* I declare that I have read and agree to the <a',
            'British Pound',
        ] as $english) {
            $this->assertSees($english, $html, 'English changed: '.$english);
        }
    }
}
