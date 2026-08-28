<?php

/*
 * Curated "popular" brand list for the home-page Popular row.
 *
 * Values must match `products.brand_key` exactly. Order is the base display order.
 *
 * How it renders (see App\Models\Product::popularForRegion): every brand shows its
 * USA row to everyone, so all these are visible in every region. When a visitor is
 * in a country that has its own row for a brand (e.g. the Cameroon MTN/Orange/Camtel
 * top-ups in CM), that local row is used AND floated to the top - so a Cameroon
 * visitor sees their local brands first, then all the USA gift cards.
 */

return [

    /*
    |-----------------------------------------------------------------------
    | Featured row
    |-----------------------------------------------------------------------
    |
    | The curated section that opens the gift-card listing, in this exact
    | order - it is a shop window, not an alphabetical index, so the order is
    | the point. Keys are brand_key values; anything the catalogue does not
    | carry, or that the visitor's country filter removes, is skipped rather
    | than left as a gap in the row.
    |
    */

    'featured' => [
        'Apple',
        'Amazon',
        'Steam',
        'PlayStation',
        'GooglePlay',
        'Xbox',
        'Netflix',
        'RazerGold',
        'Roblox',
        'Nintendo',
        'Airbnb',
        'eBay',
        'Adidas',
        'UberEats',
        'Uber',
        'Visa',
        'DiscordNitro',
        'FreeFire',
        'EAPlay',
        'MobileLegends',
        'Airalo',
    ],

    /*
     * Gaming brands pinned to the very top of the gift cards listing, in this
     * exact order. Everything else in Gaming & Entertainment follows, then the
     * remaining subcategories. Values must match `products.brand_key` exactly.
     */
    'gaming_leaders' => [
        'FreeFire',
        'PUBG',
        'EAPlay',
        'MobileLegends',
        'DiscordNitro',
    ],

    'keys' => [
        // Gaming leads the storefront (same order as gaming_leaders above).
        'FreeFire',
        'PUBG',
        'EAPlay',
        'MobileLegends',
        'DiscordNitro',

        // USA gift cards (shown to everyone).
        'Apple',
        'Amazon',
        'AmazonPrime',
        'RazerGold',
        'Visa',
        'GooglePlay',
        'eBay',
        'Sephora',

        // Local mobile top-ups (float to the top for that country's visitors).
        'MTN',
        'Orange',
        'Camtel',
    ],
];
