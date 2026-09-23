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

    /*
    |--------------------------------------------------------------------------
    | Sync Cleanup Guards
    |--------------------------------------------------------------------------
    |
    | After a full, clean catalog sync, offers the supplier no longer lists are
    | taken off sale. These guards stop that cleanup when the run looks like a
    | short feed rather than real removals, and send an alert instead:
    |
    | - min_feed_ratio: the run must see at least this share of the offers
    |   the last completed run of the same feed saw.
    | - max_delist_ratio: the cleanup may hide at most this share of the
    |   feed's live offers in one run.
    |
    | A sync command run with --force-cleanup skips both (never the rule that
    | every page and item must have succeeded).
    |
    */

    'sync_cleanup' => [
        'min_feed_ratio' => (float) env('CATALOG_SYNC_MIN_FEED_RATIO', 0.7),
        'max_delist_ratio' => (float) env('CATALOG_SYNC_MAX_DELIST_RATIO', 0.3),
    ],

];
