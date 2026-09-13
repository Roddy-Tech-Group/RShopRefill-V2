<?php

namespace Tests\Feature\Localization;

use App\Support\Languages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The languages a visitor can reach, and which mechanism produces each.
 *
 * Two systems render language on this site: Laravel's own lang files, and a
 * browser translate widget. The rules under test are the ones that keep them
 * from colliding or drifting:
 *
 *   - the picker offers exactly what config says and nothing else,
 *   - a language Laravel renders is never ALSO handed to the widget,
 *   - the widget is told the language the page is genuinely in,
 *   - and a visitor is never left in a language the picker cannot un-pick.
 *
 * The last one is why the offered list shrank: when the picker showed ten and
 * the engine could reach eighty-eight, auto-detection could strand a visitor
 * in a language with no row to change it from.
 */
class TranslateEngineTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): string
    {
        return File::get(resource_path('views/partials/translate-engine.blade.php'));
    }

    private function modal(): string
    {
        return File::get(resource_path('views/components/nav/locale-modal.blade.php'));
    }

    // ─────────────────────────────────────────────────────────────────
    //  The offered list
    // ─────────────────────────────────────────────────────────────────

    public function test_the_offered_languages_are_exactly_the_configured_ones(): void
    {
        $this->assertSame([
            'English', 'French', 'Spanish', 'Portuguese', 'Standard Arabic',
            'Swahili', 'Hausa', 'German', 'Mandarin Chinese', 'Italian',
        ], Languages::names());
    }

    /**
     * Order is config's, not insertion order across two arrays. The business
     * languages lead because they are the ones most customers want.
     */
    public function test_the_picker_order_leads_with_the_business_languages(): void
    {
        $names = Languages::names();

        $this->assertSame('English', $names[0]);
        $this->assertSame('French', $names[1]);
    }

    /**
     * A language missing from picker_order is a typo in config, and hiding it
     * would be a worse failure than listing it last.
     */
    public function test_a_language_left_out_of_the_order_is_appended_not_dropped(): void
    {
        config()->set('localization.picker_order', ['en']);

        $names = Languages::names();

        $this->assertSame('English', $names[0]);
        $this->assertContains('Swahili', $names);
        $this->assertCount(10, $names);
    }

    // ─────────────────────────────────────────────────────────────────
    //  The two mechanisms never overlap
    // ─────────────────────────────────────────────────────────────────

    /**
     * The collision this whole change exists to remove. French has 577 lines
     * of real translation; handing it to the widget as well meant French text
     * was machine-translated a second time as though it were English.
     */
    public function test_a_language_laravel_renders_is_never_given_to_the_widget(): void
    {
        $engineMap = Languages::engineMap();

        foreach (array_keys(config('localization.supported')) as $locale) {
            $name = Languages::nameFor($locale);

            $this->assertArrayNotHasKey(
                $name,
                $engineMap,
                "{$name} has lang files, so the widget must not also translate it."
            );
        }
    }

    public function test_every_machine_language_carries_an_engine_code(): void
    {
        $engineMap = Languages::engineMap();

        $this->assertSame([
            'Spanish' => 'es',
            'Portuguese' => 'pt',
            'Standard Arabic' => 'ar',
            'Swahili' => 'sw',
            'Hausa' => 'ha',
            'German' => 'de',
            // Not "zh": the widget's code for Mandarin carries the script.
            'Mandarin Chinese' => 'zh-CN',
            'Italian' => 'it',
        ], $engineMap);
    }

    /**
     * A machine language is painted over a page the server rendered, so the
     * server has to render the default locale for it - not whatever locale
     * happened to be in force.
     */
    public function test_a_machine_language_asks_the_server_for_the_default_locale(): void
    {
        $map = Languages::serverLocaleMap();

        $this->assertSame('en', $map['English']);
        $this->assertSame('fr', $map['French']);

        foreach (['Spanish', 'Swahili', 'Hausa', 'Mandarin Chinese'] as $machine) {
            $this->assertSame('en', $map[$machine]);
        }
    }

    /**
     * Every locale the picker can post has to survive LocaleController, which
     * validates against localization.supported. A machine language that posted
     * its own key would 422 the Save.
     */
    public function test_every_posted_locale_is_one_the_server_supports(): void
    {
        $supported = array_keys(config('localization.supported'));

        foreach (Languages::serverLocaleMap() as $name => $locale) {
            $this->assertContains($locale, $supported, "{$name} would post an unsupported locale.");
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Nobody gets stranded
    // ─────────────────────────────────────────────────────────────────

    public function test_a_retired_language_is_no_longer_offered(): void
    {
        // Hindi was reachable while the engine carried 88 languages.
        $this->assertFalse(Languages::offers('Hindi'));
        $this->assertFalse(Languages::offers('Esperanto'));
        $this->assertFalse(Languages::offers(null));
        $this->assertTrue(Languages::offers('Swahili'));
    }

    /**
     * The stored preference is retired in the browser too. Without this the
     * visitor keeps the old cookie and sees a page in a language the picker
     * shows no checked row for.
     */
    public function test_the_engine_retires_a_stored_language_it_no_longer_offers(): void
    {
        $engine = $this->engine();

        $this->assertStringContainsString('if (stored && !isOffered(stored))', $engine);
        $this->assertStringContainsString('clearGoogTrans();', $engine);
    }

    /**
     * Auto-detection may only land on an offered language. Falling through to
     * the widget's nearest match is what stranded people.
     */
    public function test_detection_only_resolves_to_offered_languages(): void
    {
        $offered = Languages::names();

        foreach (Languages::detectionMap() as $tag => $name) {
            $this->assertContains($name, $offered, "Tag {$tag} detects into an unoffered language.");
        }
    }

    public function test_detection_covers_the_tags_browsers_actually_report(): void
    {
        $map = Languages::detectionMap();

        $this->assertSame('French', $map['fr']);
        $this->assertSame('French', $map['fr-ca']);
        $this->assertSame('Portuguese', $map['pt-br']);
        $this->assertSame('Mandarin Chinese', $map['zh-cn']);
        $this->assertSame('Swahili', $map['sw']);

        // Retired languages must not sneak back in through a tag.
        $this->assertArrayNotHasKey('hi', $map);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Speed
    // ─────────────────────────────────────────────────────────────────

    /**
     * The single biggest cost this removes. English and French are most of the
     * traffic and neither needs the widget, so the third-party request has to
     * sit behind the machine-language guard rather than run for everyone.
     */
    public function test_the_widget_is_only_fetched_for_a_machine_language(): void
    {
        $engine = $this->engine();

        $guard = strpos($engine, 'if (!activeCode) { return; }');
        $fetch = strpos($engine, 'translate_a/element.js');

        $this->assertNotFalse($guard, 'The machine-language guard is missing.');
        $this->assertNotFalse($fetch);
        $this->assertLessThan($fetch, $guard, 'The widget is fetched before the guard that should gate it.');
    }

    public function test_the_widget_host_is_preconnected_only_when_it_will_be_used(): void
    {
        $engine = $this->engine();

        $guard = strpos($engine, 'if (!activeCode) { return; }');
        $preconnect = strpos($engine, "pre.rel = 'preconnect'");

        $this->assertNotFalse($preconnect);
        $this->assertLessThan($preconnect, $guard);
    }

    /**
     * The old re-apply polled every 60ms up to 80 times, a 4.8 second tail on
     * every SPA navigation, looking for a <select> the navigation had already
     * destroyed. Rebuilding the widget is both correct and immediate.
     */
    public function test_navigation_rebuilds_the_widget_instead_of_polling_for_it(): void
    {
        $engine = $this->engine();

        $this->assertStringContainsString('reapply(10)', $engine);
        $this->assertStringContainsString('if (initElement()) { return; }', $engine);

        $this->assertStringNotContainsString('triggerCombo', $engine);
        $this->assertStringNotContainsString(', 80)', $engine);
    }

    // ─────────────────────────────────────────────────────────────────
    //  One list, and a truthful source language
    // ─────────────────────────────────────────────────────────────────

    /**
     * The engine used to carry its own 88-entry map and the picker its own ten
     * names. Two copies is how they drifted apart.
     */
    public function test_neither_view_keeps_a_language_list_of_its_own(): void
    {
        $engine = $this->engine();
        $modal = $this->modal();

        $this->assertStringContainsString('Languages::engineMap()', $engine);
        $this->assertStringContainsString('Languages::names()', $modal);

        // Names from the retired 88 must not survive as quoted literals. The
        // quotes matter: the modal also renders the COUNTRY list, where
        // Turkmenistan legitimately contains "Turkmen", and a bare substring
        // search would fail on a country rather than on a language.
        foreach (['Esperanto', 'Hawaiian', 'Turkmen', 'Hindi'] as $retired) {
            $this->assertStringNotContainsString("'{$retired}'", $engine);
            $this->assertStringNotContainsString("\"{$retired}\"", $engine);
            $this->assertStringNotContainsString("'{$retired}'", $modal);
            $this->assertStringNotContainsString("\"{$retired}\"", $modal);
        }
    }

    public function test_the_engine_is_told_the_locale_the_page_was_actually_rendered_in(): void
    {
        $engine = $this->engine();

        $this->assertStringContainsString('pageLanguage: PAGE_LOCALE', $engine);
        $this->assertStringNotContainsString("pageLanguage: 'en'", $engine);
    }

    /**
     * End to end: a French page must declare French as the widget's source,
     * or the widget would treat French copy as English.
     */
    /** The value of one emitted `var NAME = ...;` line, as rendered. */
    private function emitted(string $html, string $var): string
    {
        $this->assertMatchesRegularExpression(
            '/var\s+'.preg_quote($var, '/').'\s*=\s*(.+?);/s',
            $html,
            "The engine did not emit {$var}."
        );

        preg_match('/var\s+'.preg_quote($var, '/').'\s*=\s*(.+?);/s', $html, $m);

        return trim($m[1]);
    }

    public function test_a_french_page_declares_french_as_the_source(): void
    {
        $html = $this->withoutVite()
            ->withCookie(config('localization.cookie.name'), 'fr')
            ->get('/')
            ->assertOk()
            ->getContent();

        $this->assertSame('"fr"', $this->emitted($html, 'PAGE_LOCALE'));
    }

    public function test_an_english_page_declares_english_as_the_source(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertSame('"en"', $this->emitted($html, 'PAGE_LOCALE'));
    }

    /**
     * What actually reaches the browser: the engine's map, carrying the eight
     * machine languages and neither of the two Laravel renders.
     *
     * Asserted against the ENGINE line alone, not the whole document. The page
     * also carries SERVER_LOCALE, which legitimately maps English to en and
     * French to fr, so a document-wide search would find those pairs and prove
     * nothing.
     */
    public function test_the_rendered_engine_map_excludes_the_server_languages(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $engine = json_decode($this->emitted($html, 'ENGINE'), true);

        $this->assertIsArray($engine);
        $this->assertSame('sw', $engine['Swahili']);
        $this->assertSame('zh-CN', $engine['Mandarin Chinese']);

        $this->assertArrayNotHasKey('English', $engine);
        $this->assertArrayNotHasKey('French', $engine);
        $this->assertCount(8, $engine);
    }

    public function test_the_picker_reaches_the_browser_with_every_offered_language(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        foreach (Languages::names() as $name) {
            $this->assertStringContainsString($name, $html);
        }
    }
}
