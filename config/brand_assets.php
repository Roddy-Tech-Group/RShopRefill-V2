<?php

/*
 * Override map for brand logos. When a brand has a handcrafted asset saved in
 * `public/assets/`, we prefer that over Zendit's `logo_url` (which is sometimes
 * a wide gray placeholder that renders poorly inside a card tile).
 *
 * Keys must match `products.brand_key` exactly (case-sensitive) — these are
 * the raw Zendit brand identifiers, NOT the friendly display names.
 *
 * Add a row here when you save a new brand logo to public/assets/.
 */

return [
    'logos' => [
        'Apple' => 'apple.webp',
        'DiscordNitro' => 'discord-nitro.webp',
        'Amazon' => 'amazon.webp',
        'AmazonPrime' => 'amazon.webp',
        'GooglePlay' => 'googleplay.webp',
        'Netflix' => 'netflix.webp',
        'Nintendo' => 'nintendo.webp',
        'NintendoSwitch' => 'nintendo.webp',
        'Playstation' => 'playstation.webp',
        'Spotify' => 'spotify.webp',
        'Steam' => 'steam.webp',
        'HuluPlus' => 'hulu.webp',
        'Twitch' => 'twitch.webp',
        'Xbox' => 'x-box_300x190.webp',
        'XboxGamePass' => 'x-box_300x190.webp',
        'XboxLiveGold' => 'x-box_300x190.webp',

        // Mobile network operators (mobile top-up / airtime products). Keyed by
        // brand_key, so each logo shows for that operator in every country it
        // appears in — e.g. MTN across CM/NG/GH, not just Cameroon.
        'MTN' => 'mtn.webp',
        'Orange' => 'orange.webp',
        'Camtel' => 'camtel.png',
        // Nigeria's other three networks. Zendit sends no logo_url for any of
        // them, so without these rows the tiles fall back to a flat colour.
        // Note the key is NineMobile, not 9mobile.
        'Glo' => 'glo.jpg',
        'Airtel' => 'airtel.jpg',
        'NineMobile' => 'ninemobile.jpg',
        'Att' => 'at&t-prepaid_2_300x190.webp',
        'Boost' => 'boost-mobile_300x190.webp',
        'Cricket' => 'cricket-wireless_300x190.webp',
        'GoSmart' => 'go-smart-mobile_300x190.webp',
        'H2O' => 'h2o-unlimited_300x190.webp',
        'LycaMobile' => 'lyca-mobile_300x190.webp',
        'MetroPCS' => 'metro-pcs_300x190.webp',
        'Net10' => 'net10parent_300x190.webp',
        'PagePlus' => 'pageplus-wireless_300x190.webp',
        'StraightTalk' => 'images.webp',
        'Tmobile' => 't-mobile_300x190.webp',
        'TotalbyVerizon' => 'totalbyverizonusa_300x190.webp',
        'UltraMobile' => 'ultra-mobile_300x190.webp',
        'Verizon' => 'verizon_300x190.webp',
    ],
];
