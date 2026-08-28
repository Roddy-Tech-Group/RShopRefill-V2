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
     * How long the guest's choice is remembered. A year: the preference is a
     * convenience, not a credential, and re-picking a language on every visit is
     * the failure this exists to prevent.
     */
    'cookie' => [
        'name' => 'rshop_locale',
        'minutes' => 60 * 24 * 365,
    ],
];
