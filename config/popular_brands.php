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
