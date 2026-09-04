<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Brand Blocklist
    |--------------------------------------------------------------------------
    |
    | Brands removed from sale after trademark / IP takedown requests. The
    | catalog sync refuses to create products for these brands and force-
    | deactivates any existing rows it encounters; `php artisan
    | catalog:enforce-brand-blocklist` applies the list immediately.
    |
    | Matching is case- and separator-insensitive ("GolfTown", "golf-town"
    | and "golftown" are the same brand), so entries may be copied from a
    | Zendit brand key or a storefront URL slug.
    |
    */

    'blocked_brands' => [
        'GolfTown', // Sporting Life Group takedown via PhishLabs + Cloudflare, 2026-09-04
    ],

];
