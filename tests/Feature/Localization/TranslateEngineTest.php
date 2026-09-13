<?php

namespace Tests\Feature\Localization;

use App\Support\Languages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every offered language renders server-side, from its own lang directory.
 *
 * There used to be two mechanisms: Laravel's lang files for English and
 * French, and a browser translate widget painting the other eight over an
 * English page. That split caused three problems, and this suite is the guard
 * against each coming back:
 *
 *   - it was SLOW. The widget was fetched on every page load for every
 *     visitor, then rewrote the document after it had already painted.
 *   - it was WRONG. The widget was told every page was English, so French
 *     copy from lang/fr was machine-translated a second time.
 *   - it STRANDED people. The widget reached far more languages than the
 *     picker listed, so auto-detection could leave a visitor in a language
 *     with no row to change it from.
 *
 * The fix was to give all ten languages real translations and delete the
 * widget. What remains to protect is that the list stays single, complete and
 * genuinely translated.
 */
class TranslateEngineTest extends TestCase
{
    use RefreshDatabase;

    /** Every leaf key of a nested array, dot-joined. */
    private function leaves(array $a, string $prefix = ''): array
    {
        $out = [];

        foreach ($a as $k => $v) {
            $out = array_merge($out, is_array($v) ? $this->leaves($v, "{$prefix}{$k}.") : ["{$prefix}{$k}"]);
        }

        return $out;
    }

    /** Distinct :placeholder tokens in a string, sorted. */
    private function placeholders(string $s): array
    {
        preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $s, $m);
        $u = array_values(array_unique($m[0]));
        sort($u);

        return $u;
    }

    private function value(array $a, string $path): mixed
    {
        foreach (explode('.', $path) as $seg) {
            if (! is_array($a) || ! array_key_exists($seg, $a)) {
                return null;
            }
            $a = $a[$seg];
        }

        return $a;
    }

    /** The locales the picker offers. */
    private function locales(): array
    {
        return array_keys(config('localization.supported'));
    }

    /**
     * Every lang directory on disk, offered or not.
     *
     * Eight translations are built but not currently offered. They are checked
     * anyway: an unoffered translation that silently rots is worse than none,
     * because turning it back on is meant to be a single config line.
     */
    private function translated(): array
    {
        return array_values(array_diff(
            array_map(fn ($d) => basename($d), File::directories(lang_path())),
            ['en']
        ));
    }

    private function files(): array
    {
        return array_map('basename', File::files(lang_path('en')));
    }

    // ─────────────────────────────────────────────────────────────────
    //  The list
    // ─────────────────────────────────────────────────────────────────

    public function test_the_offered_languages_are_exactly_the_configured_ones(): void
    {
        $this->assertSame(['English', 'French'], Languages::names());
    }

    /**
     * The storefront ships in the two business languages. Eight more are
     * translated and sitting in lang/ ready to be switched on; they must not
     * leak into the picker until someone lists them in config.
     */
    public function test_the_unoffered_translations_are_not_in_the_picker(): void
    {
        foreach (['Spanish', 'Standard Arabic', 'Swahili', 'German'] as $name) {
            $this->assertFalse(Languages::offers($name), $name.' should not be offered yet.');
        }

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('Kiswahili', $html);
    }

    public function test_the_picker_order_leads_with_the_business_languages(): void
    {
        $names = Languages::names();

        $this->assertSame('English', $names[0]);
        $this->assertSame('French', $names[1]);
    }

    /** A language missing from picker_order is a typo, not a reason to hide it. */
    public function test_a_language_left_out_of_the_order_is_appended_not_dropped(): void
    {
        config()->set('localization.picker_order', ['fr']);

        $names = Languages::names();

        $this->assertSame('French', $names[0]);
        $this->assertContains('English', $names);
        $this->assertCount(2, $names);
    }

    /**
     * Re-enabling a built translation is meant to be one config entry and
     * nothing else. If this breaks, that promise is broken.
     */
    public function test_a_built_translation_can_be_offered_with_one_config_entry(): void
    {
        config()->set('localization.supported.sw', [
            'name' => 'Swahili', 'native_name' => 'Kiswahili', 'short' => 'SW',
        ]);
        config()->set('localization.picker_order', ['en', 'fr', 'sw']);

        $this->assertSame(['English', 'French', 'Swahili'], Languages::names());
        $this->assertTrue(Languages::supports('sw'));
        $this->assertSame('sw', Languages::localeMap()['Swahili']);

        $this->post(route('locale.update'), ['locale' => 'sw'])->assertSessionHasNoErrors();

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('lang="sw"', $html);
        $this->assertStringContainsString('window.rshopLanguage = "Swahili"', $html);
    }

    /**
     * The whole point of the rebuild: no language is offered that the server
     * cannot render itself. An entry without a lang directory would fall back
     * to English while the picker claimed otherwise.
     */
    public function test_every_offered_language_has_its_own_lang_directory(): void
    {
        foreach ($this->locales() as $locale) {
            $this->assertDirectoryExists(lang_path($locale), "{$locale} is offered but has no lang directory.");
        }
    }

    public function test_every_locale_the_picker_can_post_is_one_the_server_supports(): void
    {
        foreach (Languages::localeMap() as $name => $locale) {
            $this->assertContains($locale, $this->locales(), "{$name} would post an unsupported locale.");
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  The translations are complete and intact
    // ─────────────────────────────────────────────────────────────────

    /**
     * A missing key silently renders the dotted key path to a customer, which
     * looks like a bug report waiting to happen rather than a missing string.
     */
    public function test_no_locale_is_missing_a_key_english_has(): void
    {
        foreach ($this->translated() as $locale) {
            foreach ($this->files() as $file) {
                $path = lang_path("{$locale}/{$file}");
                $this->assertFileExists($path, "{$locale} is missing {$file}.");

                $missing = array_diff(
                    $this->leaves(require lang_path("en/{$file}")),
                    $this->leaves(require $path)
                );

                $this->assertSame([], array_values($missing), "{$locale}/{$file} is missing keys.");
            }
        }
    }

    /**
     * Placeholders are substituted at runtime. One dropped in translation
     * renders as literal text, so ":amount" reaches a customer where a sum
     * should be. Compared as distinct sets: a translation may legitimately
     * mention a token once where English repeats it.
     */
    public function test_no_translation_loses_or_invents_a_placeholder(): void
    {
        foreach ($this->translated() as $locale) {
            foreach ($this->files() as $file) {
                $en = require lang_path("en/{$file}");
                $lo = require lang_path("{$locale}/{$file}");

                foreach ($this->leaves($en) as $key) {
                    $ev = $this->value($en, $key);
                    $lv = $this->value($lo, $key);

                    if (! is_string($ev) || ! is_string($lv)) {
                        continue;
                    }

                    $this->assertSame(
                        $this->placeholders($ev),
                        $this->placeholders($lv),
                        "{$locale}/{$file} key {$key} changed its placeholders."
                    );
                }
            }
        }
    }

    /**
     * Laravel picks a plural form by counting pipe-separated segments. A
     * translation with a different count silently returns the wrong branch.
     */
    public function test_no_translation_changes_the_plural_segment_count(): void
    {
        foreach ($this->translated() as $locale) {
            foreach ($this->files() as $file) {
                $en = require lang_path("en/{$file}");
                $lo = require lang_path("{$locale}/{$file}");

                foreach ($this->leaves($en) as $key) {
                    $ev = $this->value($en, $key);
                    $lv = $this->value($lo, $key);

                    if (! is_string($ev) || ! is_string($lv)) {
                        continue;
                    }

                    $this->assertSame(
                        substr_count($ev, '|'),
                        substr_count($lv, '|'),
                        "{$locale}/{$file} key {$key} changed its plural segments."
                    );
                }
            }
        }
    }

    /**
     * Field names appear inside every validation message, so leaving them
     * untranslated puts English nouns in the middle of a translated sentence.
     */
    public function test_every_locale_names_its_validation_attributes(): void
    {
        $expected = array_keys(require lang_path('fr/validation.php'))
            ? array_keys((require lang_path('fr/validation.php'))['attributes'])
            : [];

        $this->assertNotEmpty($expected);

        foreach ($this->translated() as $locale) {
            $attributes = (require lang_path("{$locale}/validation.php"))['attributes'] ?? [];

            $this->assertSame($expected, array_keys($attributes), "{$locale} names a different set of attributes.");

            foreach ($attributes as $field => $label) {
                $this->assertNotSame('', trim((string) $label), "{$locale} left {$field} blank.");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  The widget is gone
    // ─────────────────────────────────────────────────────────────────

    public function test_the_translate_widget_partial_no_longer_exists(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/partials/translate-engine.blade.php'));
    }

    /**
     * The speed fix. A page that fetches a third-party script cannot be
     * instant, and this one fetched it for every visitor in every language.
     */
    public function test_no_page_requests_a_third_party_translation_script(): void
    {
        foreach (['/', '/login'] as $url) {
            $html = $this->withoutVite()->get($url)->assertOk()->getContent();

            foreach ([
                'translate.google.com',
                'translate_a/element.js',
                'googleTranslateElementInit',
                'google_translate_element',
                'googtrans',
            ] as $artefact) {
                $this->assertStringNotContainsString($artefact, $html, "{$url} still carries {$artefact}.");
            }
        }
    }

    public function test_no_view_still_references_the_widget(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            $this->assertStringNotContainsString(
                'translate-engine',
                File::get($file->getPathname()),
                $file->getRelativePathname().' still references the widget.'
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  It actually renders
    // ─────────────────────────────────────────────────────────────────

    /**
     * Switching is one POST and the redirect renders the new language. Driven
     * through the real endpoint rather than by setting the cookie twice: the
     * session outranks the cookie in SetLocale's resolution order, so a second
     * request in the same test would inherit the first request's locale and
     * quietly prove nothing.
     */
    public function test_a_page_renders_in_the_language_the_visitor_picked(): void
    {
        $english = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('window.rshopLanguage = "English"', $english);
        $this->assertStringContainsString('lang="en"', $english);

        $this->post(route('locale.update'), ['locale' => 'fr']);

        $french = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('window.rshopLanguage = "French"', $french);
        $this->assertStringContainsString('lang="fr"', $french);

        $this->assertNotSame($english, $french, 'A French request rendered identical markup to an English one.');
    }

    /** Every language actually renders, not just the two that always did. */
    public function test_every_offered_language_renders_its_own_page(): void
    {
        foreach (Languages::all() as $locale => $entry) {
            $this->post(route('locale.update'), ['locale' => $locale])
                ->assertSessionHasNoErrors();

            $html = $this->withoutVite()->get('/')->assertOk()->getContent();

            $this->assertStringContainsString('lang="'.$locale.'"', $html, "{$locale} did not render.");
            $this->assertStringContainsString(
                'window.rshopLanguage = '.json_encode($entry['name']),
                $html,
                "{$locale} did not announce its name."
            );
        }
    }

    /**
     * Right-to-left support is wired even though nothing offered uses it yet.
     * Arabic is translated and one config entry away, and the layouts read
     * direction() unconditionally, so this is what stops it regressing in the
     * meantime. Without an explicit dir an Arabic document keeps ltr, which
     * reorders punctuation and any Latin run inside a sentence, such as a
     * currency code or a brand name.
     */
    public function test_right_to_left_is_wired_for_when_arabic_returns(): void
    {
        config()->set('localization.supported.ar', [
            'name' => 'Standard Arabic', 'native_name' => 'العربية', 'short' => 'AR', 'rtl' => true,
        ]);

        $this->assertTrue(Languages::isRtl('ar'));
        $this->assertSame('rtl', Languages::direction('ar'));
        $this->assertSame('ltr', Languages::direction('en'));

        $this->post(route('locale.update'), ['locale' => 'ar'])->assertSessionHasNoErrors();

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('dir="rtl"', $html);
    }

    public function test_the_layouts_always_declare_a_direction(): void
    {
        foreach ([
            'views/components/layouts/admin.blade.php',
            'views/components/layouts/auth.blade.php',
            'views/components/layouts/dashboard.blade.php',
            'views/components/layouts/app/header.blade.php',
        ] as $layout) {
            $this->assertStringContainsString(
                'dir="{{ \App\Support\Languages::direction() }}"',
                File::get(resource_path($layout)),
                $layout.' should declare a direction.'
            );
        }
    }

    public function test_everything_offered_today_reads_left_to_right(): void
    {
        foreach ($this->locales() as $locale) {
            $this->assertSame('ltr', Languages::direction($locale));
        }
    }

    public function test_the_picker_reaches_the_browser_with_every_offered_language(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        foreach (Languages::names() as $name) {
            $this->assertStringContainsString($name, $html);
        }
    }

    /**
     * The picker used to hold its own array of names and the widget its own
     * map of eighty-eight. Two copies is how they drifted apart.
     */
    public function test_the_picker_keeps_no_language_list_of_its_own(): void
    {
        $modal = File::get(resource_path('views/components/nav/locale-modal.blade.php'));

        $this->assertStringContainsString('Languages::names()', $modal);

        foreach (['Esperanto', 'Hawaiian', 'Hindi'] as $retired) {
            $this->assertStringNotContainsString("'{$retired}'", $modal);
            $this->assertStringNotContainsString("\"{$retired}\"", $modal);
        }
    }
}
