<?php

/*
 * Brand colour overrides for the gift-card tiles.
 *
 * Zendit only returns `brandColor` for a handful of brands, so this map lets us
 * hand-pick the recognisable card colour for popular brands. The card uses this
 * as its background; brands NOT listed here keep the colour Zendit synced (if
 * any) and otherwise stay white.
 *
 * Keys must match `products.brand_key` exactly (case-sensitive).
 * Values are CSS colours (hex). Brands whose real gift card is white (Amazon,
 * Google Play, eBay, Target, Uber Eats) are intentionally omitted so they stay
 * white instead of getting a clashing tint.
 */

return [
    /*
     * Brands whose logo ART already carries the brand name (e.g. Apple's
     * "For everything Apple" card art). The delivered-card templates skip
     * their "For {brand}" caption so the name never shows twice.
     */
    'logo_includes_name' => [
        'Apple',
    ],

    'colors' => [
        'Airbnb' => '#FF5A5F',
        'AmazonPrime' => '#00A8E1',
        // Matches the grey backdrop baked into the Apple card art, so the
        // delivered-card background and the logo tile read as one surface.
        'Apple' => '#ececee',
        'DoorDash' => '#FF3008',
        'HuluPlus' => '#1CE783',
        'Netflix' => '#E50914',
        'Nintendo' => '#E60012',
        'NintendoSwitch' => '#E60012',
        'Playstation' => '#003791',
        'RazerGold' => '#101010',
        'Roblox' => '#111317',
        'Spotify' => '#1DB954',
        'Steam' => '#1B2838',
        'TheHomeDepot' => '#F96302',
        'Twitch' => '#9146FF',
        'Uber' => '#000000',
        'Walmart' => '#0071CE',
        'Xbox' => '#107C10',
        'XboxGamePass' => '#107C10',
        'XboxLiveGold' => '#107C10',
    ],
];
