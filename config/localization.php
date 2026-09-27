<?php

/**
 * The ONE list of languages this application supports.
 *
 * Middleware, the locale controller, the switcher component and the tests all
 * resolve through here. A second copy anywhere is how a locale ends up
 * selectable in the UI but rejected by the server, or vice versa.
 *
 * Adding a language is this file plus a lang/{code} directory - nothing else.
 *
 * Every language here is rendered by Laravel from its own lang/{code} files.
 * There is no machine-translation tier: the browser translate widget that used
 * to paint other languages over an English page was removed. It cost a
 * third-party request on every page load, repainted the document after it had
 * already rendered, told the widget every page was English so French copy was
 * machine-translated a second time, and could reach far more languages than
 * the picker offered, which let a visitor land somewhere with no row to
 * un-pick it.
 *
 * EIGHT MORE LANGUAGES ARE BUILT BUT NOT OFFERED.
 * lang/es, lang/pt, lang/ar, lang/sw, lang/ha, lang/de, lang/zh and lang/it
 * are complete and verified: 404 keys each, key-for-key with lang/en, with
 * every placeholder and plural form intact. They are deliberately not listed
 * below, because the storefront ships in the two business languages.
 *
 * Turning one back on is ONE entry here plus its key in picker_order. Nothing
 * else: no code, no views, no new tests. The files stay under test - the
 * localisation suite checks every lang directory on disk, not just the offered
 * ones, so an unoffered translation cannot rot while it waits.
 *
 * If you re-enable Arabic, give it 'rtl' => true. The layouts already read
 * that through App\Support\Languages::direction() for the <html dir>
 * attribute, and the suite already covers it.
 */
return [
    'default' => 'en',

    'supported' => [
        'en' => [
            'name' => 'English',
            'native_name' => 'English',
            'short' => 'EN',
        ],
        'fr' => [
            'name' => 'French',
            'native_name' => 'Français',
            'short' => 'FR',
        ],
        'es' => [
            'name' => 'Spanish',
            'native_name' => 'Español',
            'short' => 'ES',
        ],
        'pt' => [
            'name' => 'Portuguese',
            'native_name' => 'Português',
            'short' => 'PT',
        ],
        'de' => [
            'name' => 'German',
            'native_name' => 'Deutsch',
            'short' => 'DE',
        ],
        'it' => [
            'name' => 'Italian',
            'native_name' => 'Italiano',
            'short' => 'IT',
        ],
        'sw' => [
            'name' => 'Swahili',
            'native_name' => 'Kiswahili',
            'short' => 'SW',
        ],
        'ha' => [
            'name' => 'Hausa',
            'native_name' => 'Hausa',
            'short' => 'HA',
        ],
        'zh' => [
            'name' => 'Chinese',
            'native_name' => '中文',
            'short' => 'ZH',
        ],
        // Right to left. The layouts already read this through
        // Languages::direction() for the <html dir> attribute.
        'ar' => [
            'name' => 'Arabic',
            'native_name' => 'العربية',
            'short' => 'AR',
            'rtl' => true,
        ],
    ],

    /**
     * The order the picker lists languages in.
     *
     * A language missing from this list is still offered, appended last, on
     * the grounds that a forgotten entry is a typo and hiding it silently
     * would be the worse failure.
     */
    'picker_order' => ['en', 'fr', 'es', 'pt', 'de', 'it', 'sw', 'ha', 'zh', 'ar'],

    /**
     * How long the guest's choice is remembered. A year: the preference is a
     * convenience, not a credential, and re-picking a language on every visit is
     * the failure this exists to prevent.
     */
    'cookie' => [
        'name' => 'rshop_locale',
        'minutes' => 60 * 24 * 365,
    ],
];
