<?php

namespace Tests\Feature\Localization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Native English/French localisation, replacing the Google Translate widget.
 *
 * The resolution order under test, highest first: the signed-in account, the
 * session, the cookie, Accept-Language on a first visit, then the configured
 * default. Each source is exercised on its own AND against the one above it,
 * because the ordering is the part that breaks silently - a locale that reads
 * correctly in isolation can still be overridden by the wrong source.
 */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    private function cookieName(): string
    {
        return config('localization.cookie.name');
    }

    // ── Defaults ────────────────────────────────────────────────────────

    public function test_english_is_the_default_locale(): void
    {
        $this->withoutVite()->get('/')->assertOk();

        $this->assertSame('en', app()->getLocale());
        $this->assertSame('en', config('localization.default'));
    }

    /**
     * The storefront ships in the two business languages. Eight more are
     * translated and sitting in lang/, one config entry away each, but they
     * are not offered - see config/localization.php.
     */
    /**
     * All ten built translations are offered. The eight beyond English and
     * French were complete and sitting in lang/ for a while before being
     * switched on, which is why the invariant that matters is the second
     * assertion: nothing may be offered that has no lang directory behind it.
     */
    public function test_every_built_language_is_offered(): void
    {
        $this->assertSame(
            ['en', 'fr', 'es', 'pt', 'de', 'it', 'sw', 'ha', 'zh', 'ar'],
            array_keys(config('localization.supported'))
        );

        foreach (array_keys(config('localization.supported')) as $locale) {
            $this->assertDirectoryExists(lang_path($locale));
        }
    }

    // ── Switching ───────────────────────────────────────────────────────

    public function test_a_guest_can_switch_from_english_to_french(): void
    {
        $this->from('/')->post(route('locale.update'), ['locale' => 'fr'])
            ->assertRedirect('/');

        $this->assertSame('fr', session('locale'));
        $this->withoutVite()->get('/')->assertOk()->assertSee('lang="fr"', false);
    }

    public function test_a_guest_can_switch_back_from_french_to_english(): void
    {
        $this->post(route('locale.update'), ['locale' => 'fr']);
        $this->assertSame('fr', session('locale'));

        $this->post(route('locale.update'), ['locale' => 'en']);
        $this->assertSame('en', session('locale'));

        $this->withoutVite()->get('/')->assertOk()->assertSee('lang="en"', false);
    }

    public function test_the_choice_is_stored_in_both_the_session_and_a_cookie(): void
    {
        $response = $this->post(route('locale.update'), ['locale' => 'fr']);

        // Session carries it within the browser session; the cookie is what
        // survives the session expiring, which is the longer-lived guarantee.
        $this->assertSame('fr', session('locale'));
        $response->assertCookie($this->cookieName(), 'fr');
    }

    /** A returning visitor with only the cookie is still served their language. */
    /**
     * withCookie, not withUnencryptedCookie: the locale cookie is not in
     * the encryptCookies exception list, so an unencrypted one fails to
     * decrypt and reads as absent - the request then falls through to the
     * default and the assertion below is what catches it.
     *
     * Asserted against the <html> element specifically. A bare
     * assertSee('lang="fr"') passed for years on the language switcher's
     * own row markup while the document stayed English.
     */
    public function test_the_cookie_alone_restores_the_choice(): void
    {
        $this->withoutVite()
            ->withCookie($this->cookieName(), 'fr')
            ->get('/')
            ->assertOk()
            ->assertSee('<html lang="fr"', false);
    }

    // ── Validation ──────────────────────────────────────────────────────

    public function test_an_unsupported_locale_is_rejected(): void
    {
        // A real locale the app does not offer. 'de' is deliberately NOT used
        // here even though it is unoffered: lang/de exists, so it tests the
        // gate rather than the absence of files, which is covered separately.
        $this->post(route('locale.update'), ['locale' => 'ja'])
            ->assertSessionHasErrors('locale');

        $this->assertNotSame('ja', session('locale'));
    }

    /**
     * A locale becomes a path segment inside the translator, so an unvalidated
     * one is a traversal primitive rather than just a bad label.
     */
    public function test_a_traversal_attempt_is_rejected(): void
    {
        $this->post(route('locale.update'), ['locale' => '../../etc/passwd'])
            ->assertSessionHasErrors('locale');

        $this->withoutVite()->get('/')->assertOk();
        $this->assertSame('en', app()->getLocale());
    }

    public function test_a_bad_cookie_value_never_reaches_the_translator(): void
    {
        $this->withoutVite()
            ->withUnencryptedCookie($this->cookieName(), '../../secrets')
            ->get('/')
            ->assertOk()
            ->assertSee('lang="en"', false);
    }

    // ── Signed-in customers ─────────────────────────────────────────────

    public function test_an_authenticated_choice_is_saved_to_the_account(): void
    {
        $user = User::factory()->create(['locale' => null]);

        $this->actingAs($user)->post(route('locale.update'), ['locale' => 'fr']);

        $this->assertSame('fr', $user->fresh()->locale);
    }

    public function test_a_saved_account_locale_wins_on_a_later_session(): void
    {
        $user = User::factory()->create(['locale' => 'fr']);

        // Fresh session, and a browser asking for English. The account still
        // wins - someone who chose French should not be flipped back because
        // they opened the site on a machine configured in English.
        $this->withoutVite()
            ->actingAs($user)
            ->withHeader('Accept-Language', 'en-GB,en;q=0.9')
            ->get('/')
            ->assertOk()
            ->assertSee('lang="fr"', false);
    }

    public function test_the_browser_language_is_used_only_on_a_first_visit(): void
    {
        $this->withoutVite()
            ->withHeader('Accept-Language', 'fr-CA,fr;q=0.9,en;q=0.8')
            ->get('/')
            ->assertOk()
            // fr-CA is French: refusing a region subtag would send most French
            // speakers an English page.
            ->assertSee('lang="fr"', false);
    }

    public function test_an_unsupported_browser_language_falls_back_to_english(): void
    {
        // Japanese: a language the app ships nothing for. German used to play
        // this part and is now one of the ten.
        $this->withoutVite()
            ->withHeader('Accept-Language', 'ja-JP,ja;q=0.9')
            ->get('/')
            ->assertOk()
            ->assertSee('lang="en"', false);
    }

    /**
     * A browser asking for a language that is translated but not offered gets
     * English, not the unoffered translation. The config list is the gate, not
     * the presence of a lang directory.
     */
    /**
     * A language the site does not carry falls back to the default rather
     * than being served half-translated.
     *
     * Japanese, because it is the case that still exists: every language with
     * a lang directory is now offered, so the only way to be unoffered is to
     * have no translation at all.
     */
    public function test_a_language_the_site_does_not_carry_falls_back(): void
    {
        $this->assertDirectoryDoesNotExist(lang_path('ja'));

        $this->withoutVite()
            ->withHeader('Accept-Language', 'ja-JP,ja;q=0.9')
            ->get('/')
            ->assertOk()
            ->assertSee('lang="en"', false);
    }

    /** A built language IS served when the browser asks for it. */
    public function test_a_built_language_is_served_when_requested(): void
    {
        $this->assertDirectoryExists(lang_path('es'));

        $this->withoutVite()
            ->withHeader('Accept-Language', 'es-MX,es;q=0.9')
            ->get('/')
            ->assertOk()
            ->assertSee('lang="es"', false);
    }

    // ── Rendering ───────────────────────────────────────────────────────

    public function test_the_html_lang_attribute_matches_the_active_locale(): void
    {
        foreach (['en', 'fr'] as $locale) {
            $this->post(route('locale.update'), ['locale' => $locale]);

            $this->withoutVite()->get('/')->assertOk()->assertSee('lang="'.$locale.'"', false);
        }
    }

    public function test_the_interface_renders_in_the_selected_language(): void
    {
        $this->post(route('locale.update'), ['locale' => 'fr']);

        $this->withoutVite()->get('/')
            ->assertOk()
            ->assertSee('Cartes cadeaux')
            ->assertSee('Recharge mobile')
            ->assertSee('Paiement de factures')
            ->assertDontSee('>Mobile Refill<', false);
    }

    public function test_livewire_auth_pages_render_in_the_selected_language(): void
    {
        $this->post(route('locale.update'), ['locale' => 'fr']);

        $this->withoutVite()->get('/login')
            ->assertOk()
            ->assertSee('lang="fr"', false)
            ->assertSee('Inscription');
    }

    public function test_validation_errors_are_shown_in_the_active_language(): void
    {
        $this->post(route('locale.update'), ['locale' => 'fr']);

        $response = $this->post(route('locale.update'), ['locale' => 'nope']);

        $errors = session('errors');
        $this->assertNotNull($errors);
        $this->assertStringNotContainsString('validation.', $errors->first('locale'));
    }

    // ── Values that must NOT move with the language ─────────────────────

    /**
     * Currency codes, amounts and product identifiers are values, not copy.
     * Translating them would change what a customer is agreeing to pay.
     */
    public function test_currency_codes_and_amounts_do_not_change_with_the_language(): void
    {
        $english = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->post(route('locale.update'), ['locale' => 'fr']);
        $french = $this->withoutVite()->get('/')->assertOk()->getContent();

        // 'EN / USD' is no longer a literal anywhere: the pill's language half
        // renders server-side per locale (EN /, FR /), by design.
        foreach (['USD'] as $value) {
            $this->assertSame(
                substr_count($english, $value),
                substr_count($french, $value),
                $value.' should read identically in both languages.'
            );
        }
    }

    public function test_the_brand_name_is_never_translated(): void
    {
        $this->post(route('locale.update'), ['locale' => 'fr']);

        // Spelled as the brand spells it - the point is that it is passed
        // through verbatim, so the assertion has to match verbatim too.
        $this->withoutVite()->get('/')->assertOk()->assertSee('RshopRefills', false);
    }

    // ── The translate engine ────────────────────────────────────────────

    /**
     * Whole-page machine translation is how this site reaches its other
     * languages: lang files cover the interface, and the engine covers
     * everything else - catalogue copy, articles, anything an editor typed.
     * Every customer-facing layout serves it, auth screens included: the one
     * step that asks someone to type is the worst place to hand them English.
     */
    public function test_no_customer_layout_loads_a_translation_widget(): void
    {
        foreach (['/', '/login'] as $url) {
            $html = $this->withoutVite()->get($url)->assertOk()->getContent();

            foreach ([
                'googleTranslateElementInit',
                'google_translate_element',
                'rshopSetTranslateCookie',
                'translate_a/element.js',
                'translate.google.com',
            ] as $artefact) {
                $this->assertStringNotContainsString($artefact, $html, $url.' still carries '.$artefact);
            }
        }
    }

    /**
     * Brand names and prices are identifiers, not prose. Machine
     * translation turns "Free Fire" into a sentence about flames, so both
     * are pinned with translate="no" wherever a tile renders them.
     */
    public function test_brand_names_and_prices_are_pinned_against_translation(): void
    {
        foreach ([
            'views/components/shop/brand-tile.blade.php',
            'views/components/home/brand-card.blade.php',
        ] as $view) {
            $markup = File::get(resource_path($view));

            $this->assertSame(2, substr_count($markup, 'translate="no"'), $view.' should pin its name and its price');
        }
    }

    public function test_the_translate_engine_partial_is_gone(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/partials/translate-engine.blade.php'));
    }

    /**
     * googtrans was the widget's language choice. Nothing reads or writes it
     * now, and a stale one left over from before the widget was removed must
     * not influence anything: the locale comes from the account, the session,
     * our own cookie, or Accept-Language, in that order and nowhere else.
     */
    public function test_a_stale_translate_cookie_changes_nothing(): void
    {
        $this->withoutVite()
            ->withUnencryptedCookie('googtrans', '/en/fr')
            ->get('/')
            ->assertOk()
            ->assertCookieMissing('googtrans');

        $this->assertSame('en', app()->getLocale());
    }

    // ── Wiring ──────────────────────────────────────────────────────────

    /**
     * SetLocale must run AFTER EncryptCookies and StartSession. Prepended to the
     * web group it runs ahead of both, where the cookie is still encrypted and
     * there is no session - so those two sources read as absent and every
     * request quietly falls back to English.
     */
    public function test_the_middleware_runs_after_the_session_is_started(): void
    {
        $bootstrap = File::get(base_path('bootstrap/app.php'));

        $append = substr($bootstrap, (int) strpos($bootstrap, 'web(append:'));
        $this->assertStringContainsString('SetLocale::class', $append);

        $prepend = substr($bootstrap, (int) strpos($bootstrap, 'web(prepend:'));
        $prepend = substr($prepend, 0, (int) strpos($prepend, ']);'));
        $this->assertStringNotContainsString('SetLocale::class', $prepend);
    }

    /**
     * One list of APP locales, so the server can never accept a locale the
     * UI cannot offer. The language switcher is deliberately not in this
     * set any more: it lists the translate engine's languages, which is a
     * far longer list than the locales this application ships strings for.
     */
    public function test_the_supported_list_is_not_duplicated(): void
    {
        $controller = File::get(app_path('Http/Controllers/LocaleController.php'));
        $middleware = File::get(app_path('Http/Middleware/SetLocale.php'));

        foreach ([$controller, $middleware] as $source) {
            $this->assertStringContainsString('localization.supported', $source);
        }

        // No hand-written locale list anywhere but the config.
        $this->assertStringNotContainsString("['en', 'fr']", $controller);
        $this->assertStringNotContainsString("['en', 'fr']", $middleware);

        // And the switcher takes its list in, rather than hard-coding one.
        $switcher = File::get(resource_path('views/components/nav/language-switcher.blade.php'));
        $this->assertStringContainsString("@props(['languages'", $switcher);
    }

    public function test_the_switcher_posts_rather_than_relying_on_javascript(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('action="'.route('locale.update').'"', $html);
        $this->assertStringContainsString('name="locale"', $html);
        $this->assertStringContainsString('name="_token"', $html);
    }

    public function test_the_user_model_declares_its_locale_preference(): void
    {
        $french = User::factory()->create(['locale' => 'fr']);
        $unset = User::factory()->create(['locale' => null]);

        $this->assertSame('fr', $french->preferredLocale());

        // Falls back rather than returning null: null would leave a queued job
        // on whatever locale the worker was last left in.
        $this->assertSame('en', $unset->preferredLocale());
    }
}
