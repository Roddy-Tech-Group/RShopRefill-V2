<?php

/**
 * The ONE list of languages this application supports.
 *
 * Middleware, the locale controller, the switcher component and the tests all
 * resolve through here. A second copy anywhere is how a locale ends up
 * selectable in the UI but rejected by the server, or vice versa.
 *
 * Adding a language is this file plus a lang/{code} directory - nothing else.
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
    ],

    /**
     * Machine-translated languages: offered in the picker, rendered by the
     * browser translate widget (partials/translate-engine) rather than by a
     * lang/{code} directory.
     *
     * These are NOT in `supported` on purpose. `supported` means "the server
     * renders this itself", which is what LocaleController validates against
     * and what App::setLocale() is given. A machine language is a client-side
     * overlay on top of a page the server rendered in a supported locale, so
     * promoting one here would tell the server to load lang files that do not
     * exist.
     *
     * `engine` is the widget's own language code, which is not always the ISO
     * tag: Mandarin is zh-CN, not zh. Nothing outside the engine knows these
     * codes - the picker and the stored preference both use the key.
     *
     * Why these eight and not the ~88 the widget can reach: English and French
     * are the business languages and live in `supported`; the rest follow the
     * catalogue and the top-up networks. Offering every engine locale made the
     * picker a wall to scroll for a choice almost nobody changes, and let a
     * visitor land in a language the picker could not show back to them.
     *
     * Swahili and Hausa are why this stays on the browser widget at all: DeepL,
     * the better engine for the European entries, supports neither.
     */
    'machine' => [
        'es' => ['name' => 'Spanish', 'native_name' => 'Español', 'short' => 'ES', 'engine' => 'es'],
        'pt' => ['name' => 'Portuguese', 'native_name' => 'Português', 'short' => 'PT', 'engine' => 'pt'],
        'ar' => ['name' => 'Standard Arabic', 'native_name' => 'العربية', 'short' => 'AR', 'engine' => 'ar'],
        'sw' => ['name' => 'Swahili', 'native_name' => 'Kiswahili', 'short' => 'SW', 'engine' => 'sw'],
        'ha' => ['name' => 'Hausa', 'native_name' => 'Hausa', 'short' => 'HA', 'engine' => 'ha'],
        'de' => ['name' => 'German', 'native_name' => 'Deutsch', 'short' => 'DE', 'engine' => 'de'],
        'zh' => ['name' => 'Mandarin Chinese', 'native_name' => '中文', 'short' => 'ZH', 'engine' => 'zh-CN'],
        'it' => ['name' => 'Italian', 'native_name' => 'Italiano', 'short' => 'IT', 'engine' => 'it'],
    ],

    /**
     * The order the picker lists languages in, by key across BOTH lists above.
     * The business languages lead; the rest follow the catalogue. Kept here so
     * the picker has no list of its own to drift out of step.
     */
    'picker_order' => ['en', 'fr', 'es', 'pt', 'ar', 'sw', 'ha', 'de', 'zh', 'it'],

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
