<?php

/*
|---------------------------------------------------------------------------
| Gift-card category icons
|---------------------------------------------------------------------------
|
| One mark per gift-card subcategory, keyed by its slug. Two shapes:
|
|   'asset' => a file already in public/assets. Preferred wherever a relevant
|              one exists - Travel takes the same plane the eSIM pages use, so
|              a category means the same thing everywhere on the site.
|
|   'path'  => inline 24x24 path data for the categories no existing asset
|              covers. Drawn in the same idiom as those files: stroke only,
|              no fill, round caps and joins, so the two sets read as one.
|
| A slug with no entry falls back to 'default'. That is deliberate: the
| subcategory list comes from the supplier sync, so a new category can appear
| at any time and must render something sensible rather than a hole.
|
*/

return [

    'default' => ['path' => 'M3 3h18v4H3zM5 7v13h14V7M9 12h6'],

    // Every mark is inline path data now. These used to point at files in
    // public/assets, which cost one HTTP request per category on a row that
    // renders eleven of them, and forced a `dark:brightness-0 dark:invert`
    // hack because the artwork was black rather than currentColor.
    //
    // Inline, they cost nothing extra: the row is already being rendered, the
    // marks inherit the text colour so dark mode needs no correction, and
    // there is no second request, no decode and no layout shift while a
    // lazy-loaded image arrives.
    'travel-experience' => ['path' => 'M21 15v-2l-8-5V3.6a1.6 1.6 0 0 0-3.2 0V8l-8 5v2l8-2.4V17l-2.2 1.6V20L12 19l4.4 1v-1.4L14.2 17v-4.4z'],
    'gaming-entertainment' => ['path' => 'M6.8 7h10.4a4.4 4.4 0 0 1 4.3 5.3l-.9 4.6A3.3 3.3 0 0 1 17.4 19c-1.1 0-1.9-.6-2.4-1.2l-.8-1H9.8l-.8 1c-.5.6-1.3 1.2-2.4 1.2a3.3 3.3 0 0 1-3.2-2.1l-.9-4.6A4.4 4.4 0 0 1 6.8 7zM7 11v3M5.5 12.5h3M16 11.5h.01M18 13.5h.01'],
    'egift' => ['path' => 'M3.5 11h17v9.5h-17zM2.5 7h19v4h-19zM12 7v13.5M12 7S9.6 3 7.6 4.2 9.2 7 12 7zM12 7s2.4-4 4.4-2.8S14.8 7 12 7z'],
    'digital-apps' => ['path' => 'M7.5 2.5h9a1.5 1.5 0 0 1 1.5 1.5v16a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 6 20V4a1.5 1.5 0 0 1 1.5-1.5zM9.5 7h1.5M13 7h1.5M9.5 10.5h1.5M13 10.5h1.5M10.5 18h3'],
    'imtu-internet' => ['path' => 'M2.5 8.6a15 15 0 0 1 19 0M5.6 12.4a10 10 0 0 1 12.8 0M8.8 16.2a5 5 0 0 1 6.4 0M12 19.8h.01'],
    'home-garden' => ['path' => 'M3 10.6 12 3.2l9 7.4M5.4 9.6V20.5h13.2V9.6M9.8 20.5v-5.6h4.4v5.6'],
    'general-merchandise' => ['path' => 'M4 8.6h16l-1.2 11.9H5.2zM4 8.6 5.6 4h12.8L20 8.6M9 12.2a3 3 0 0 0 6 0'],
    'general-merchadising' => ['path' => 'M4 8.6h16l-1.2 11.9H5.2zM4 8.6 5.6 4h12.8L20 8.6M9 12.2a3 3 0 0 0 6 0'],
    'shopping' => ['path' => 'M6 8h12l1.1 12.5H4.9zM9 8V6a3 3 0 0 1 6 0v2M9 11.5v1M15 11.5v1'],
    'online-shopping' => ['path' => 'M2.5 3.5h2.3l2.3 11.3a1.6 1.6 0 0 0 1.6 1.2h8.5a1.6 1.6 0 0 0 1.5-1.2L20.4 7.2H6M9.6 20.5a1.1 1.1 0 1 0 0-2.2 1.1 1.1 0 0 0 0 2.2zM17.4 20.5a1.1 1.1 0 1 0 0-2.2 1.1 1.1 0 0 0 0 2.2z'],
    'supermarket' => ['path' => 'M3 9.2h18l-1.6 9.4a2 2 0 0 1-2 1.7H6.6a2 2 0 0 1-2-1.7zM8.4 9.2 12 3.2l3.6 6M9.8 13v3.4M14.2 13v3.4'],

    // Drawn here in the same idiom.
    'restaurant' => ['path' => 'M5 3v8a2 2 0 0 0 2 2h0a2 2 0 0 0 2-2V3M7 13v8M17 3c-1.7 0-3 2-3 5s1.3 4 3 4v9'],
    'food-beverage' => ['path' => 'M6 8h11a3 3 0 0 1 0 6h-1M6 8v9a3 3 0 0 0 3 3h4a3 3 0 0 0 3-3V8zM8 3v2M12 3v2'],
    'clothing-accessories' => ['path' => 'M9 3 5.5 4.8a2 2 0 0 0-1 2.4l1 3.3 2-.7V21h9V9.8l2 .7 1-3.3a2 2 0 0 0-1-2.4L15 3a3 3 0 0 1-6 0z'],
    'electronics' => ['path' => 'M4 4h16v12H4zM2 20h20M9 16v4M15 16v4'],
    'auto-moto' => ['path' => 'M4 14h16M5 14l1.6-5a2 2 0 0 1 1.9-1.4h7a2 2 0 0 1 1.9 1.4L19 14M4 14v4h3v-4M17 14v4h3v-4M7.5 17h9'],
    'health-beauty' => ['path' => 'M12 3v4M12 17v4M4.9 7.5 8.4 9.5M15.6 14.5l3.5 2M4.9 16.5l3.5-2M15.6 9.5l3.5-2M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z'],
    'sports-outdoors' => ['path' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 3c2.5 2.4 4 5.6 4 9s-1.5 6.6-4 9M12 3C9.5 5.4 8 8.6 8 12s1.5 6.6 4 9M3.5 9h17M3.5 15h17'],
    'dating' => ['path' => 'M12 20.5 4.8 13a4.6 4.6 0 0 1 0-6.5 4.6 4.6 0 0 1 6.5 0l.7.7.7-.7a4.6 4.6 0 0 1 6.5 0 4.6 4.6 0 0 1 0 6.5z'],

];
