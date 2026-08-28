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

    // Covered by an existing asset - reused so a category reads the same here
    // as it does in the nav, the sidebar and the mobile sheet.
    'travel-experience' => ['asset' => 'flight 2.svg'],
    'gaming-entertainment' => ['asset' => 'gaming two.svg'],
    'egift' => ['asset' => 'gift cards.svg'],
    'digital-apps' => ['asset' => 'mobile-top-ups.svg'],
    'imtu-internet' => ['asset' => 'global svg.svg'],
    'home-garden' => ['asset' => 'Home.svg'],
    'general-merchandise' => ['asset' => 'Shop.svg'],
    'general-merchadising' => ['asset' => 'Shop.svg'],
    'shopping' => ['asset' => 'Shop.svg'],
    'online-shopping' => ['asset' => 'new cart.svg'],
    'supermarket' => ['asset' => 'new cart.svg'],

    // No asset covers these, so they are drawn here in the same idiom.
    'restaurant' => ['path' => 'M5 3v8a2 2 0 0 0 2 2h0a2 2 0 0 0 2-2V3M7 13v8M17 3c-1.7 0-3 2-3 5s1.3 4 3 4v9'],
    'food-beverage' => ['path' => 'M6 8h11a3 3 0 0 1 0 6h-1M6 8v9a3 3 0 0 0 3 3h4a3 3 0 0 0 3-3V8zM8 3v2M12 3v2'],
    'clothing-accessories' => ['path' => 'M9 3 5.5 4.8a2 2 0 0 0-1 2.4l1 3.3 2-.7V21h9V9.8l2 .7 1-3.3a2 2 0 0 0-1-2.4L15 3a3 3 0 0 1-6 0z'],
    'electronics' => ['path' => 'M4 4h16v12H4zM2 20h20M9 16v4M15 16v4'],
    'auto-moto' => ['path' => 'M4 14h16M5 14l1.6-5a2 2 0 0 1 1.9-1.4h7a2 2 0 0 1 1.9 1.4L19 14M4 14v4h3v-4M17 14v4h3v-4M7.5 17h9'],
    'health-beauty' => ['path' => 'M12 3v4M12 17v4M4.9 7.5 8.4 9.5M15.6 14.5l3.5 2M4.9 16.5l3.5-2M15.6 9.5l3.5-2M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z'],
    'sports-outdoors' => ['path' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 3c2.5 2.4 4 5.6 4 9s-1.5 6.6-4 9M12 3C9.5 5.4 8 8.6 8 12s1.5 6.6 4 9M3.5 9h17M3.5 15h17'],
    'dating' => ['path' => 'M12 20.5 4.8 13a4.6 4.6 0 0 1 0-6.5 4.6 4.6 0 0 1 6.5 0l.7.7.7-.7a4.6 4.6 0 0 1 6.5 0 4.6 4.6 0 0 1 0 6.5z'],

];
