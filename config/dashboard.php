<?php

return [

    /*
    | Shop services that just launched, which earn a NEW badge in the customer
    | dashboard sidebar: on the Shop row, and on the service's own link under it.
    |
    | Empty by default. The badge used to be hardcoded on the Shop row forever,
    | which made it mean nothing; list a service here when it genuinely launches
    | and remove it again once it is no longer new.
    |
    | Keys: gift_cards, esims, topups, bills, gaming, more
    | e.g. DASHBOARD_NEW_SERVICES=esims,bills
    */
    'new_services' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DASHBOARD_NEW_SERVICES', ''))
    ))),

    /*
    | The figure in the referral headlines ("Refer & earn up to $N"). It is an
    | ESTIMATE of what referrals can add up to over time, not a payout per
    | referral and not a cap: the reward itself is a percentage of each order
    | (admin > rewards settings), which the page states alongside a worked
    | example. The info popover says so in as many words. Set 0 to drop the
    | figure and let the headlines describe the reward instead.
    | e.g. DASHBOARD_EARN_UP_TO_USD=100
    */
    'earn_up_to_usd' => (int) env('DASHBOARD_EARN_UP_TO_USD', 100),

    /*
    | The brands pinned to the front of the For Partners "Refer a product"
    | cards, in order (brand_key values, so Playstation and GooglePlay rather
    | than their display names). Gift cards mostly, but any brand with a public
    | page qualifies: MTN and Orange are top-ups, and their brand page serves
    | every country that brand covers, so one card carries all of them. A brand with no active product, or no
    | logo, is skipped silently, so listing one the catalogue does not carry yet
    | is harmless. Whatever is left of the row after the pinned picks is filled
    | by margin (App\Support\TopMarginProducts).
    | e.g. DASHBOARD_REFER_PRODUCT_BRANDS=Apple,Amazon
    */
    'refer_product_brands' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DASHBOARD_REFER_PRODUCT_BRANDS', 'Apple,Amazon,GooglePlay,Playstation,Steam,Xbox,Spotify,Netflix,Roblox,MTN,Orange'))
    ))),

    /*
    | The multi-country eSIMs pinned to those same cards, in order. These are
    | REGION NAMES as the eSIM store derives them from a product's slug
    | (EsimStoreController::regionMeta), not slugs: "Europe" matches
    | esim-ww-europe, "Global" matches the storefront's pinned discover-global
    | card. A region with no active product is skipped. Empty means no eSIM is
    | pinned and eSIMs take their turn by margin like everything else.
    | e.g. DASHBOARD_REFER_PRODUCT_ESIM_REGIONS=Global,Europe
    */
    'refer_product_esim_regions' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DASHBOARD_REFER_PRODUCT_ESIM_REGIONS', 'Global,Africa,Europe,Asia,Middle East,North America,Latin America,Caribbean'))
    ))),

    /*
    | What each "Refer a product" card says the product is FOR, so a customer
    | sees the problem it solves without opening the page. Keyed by brand_key
    | for gift cards and by region name for eSIMs (the same spellings as the
    | two lists above). This is our own copy, deliberately: a supplier's
    | description is inventory text, not a reason to buy.
    |
    | A product with no line here falls back to the supplier's own description
    | (gift cards) or to the coverage line the eSIM store writes from real
    | country counts (eSIMs). Keep each to one sentence: the card clamps at two
    | lines, and anything longer is read as blurb, not as an answer.
    */
    'refer_product_blurbs' => [
        // eSIMs, by region.
        'Global' => 'Stay connected worldwide, with a real USA number or data only.',
        'Africa' => 'Data across Africa on your own phone, with no local SIM to buy.',
        'Europe' => 'Data across Europe on your own phone, with no local SIM to buy.',
        'Asia' => 'Data across Asia on your own phone, with no local SIM to buy.',
        'Middle East' => 'Data across the Middle East on your own phone, with no local SIM to buy.',
        'North America' => 'Data across North America on your own phone, with no local SIM to buy.',
        'Latin America' => 'Data across Latin America on your own phone, with no local SIM to buy.',
        'Caribbean' => 'Data across the Caribbean on your own phone, with no local SIM to buy.',

        // Gift cards, by brand_key.
        'Apple' => 'Pay for Apple device bills, subscriptions, games and in-app purchases.',
        'Amazon' => 'Shop anything on Amazon, from everyday things to gifts.',
        'GooglePlay' => 'Pay for Android apps, games, subscriptions and in-app purchases.',
        'Playstation' => 'Buy PlayStation games, add-ons and PS Plus membership.',
        'Steam' => 'Buy PC games, add-ons and in-game items on Steam.',
        'Xbox' => 'Buy Xbox games, add-ons and Game Pass membership.',
        'Spotify' => 'Pay for Spotify Premium, ad-free music and podcasts.',
        'Netflix' => 'Keep a Netflix plan running, with no bank card needed.',
        'Roblox' => 'Buy Robux, avatar items and Roblox membership.',

        // Top-ups, by brand_key.
        'MTN' => 'Send airtime straight to an MTN line, in any country we cover.',
        'Orange' => 'Send airtime straight to an Orange line, in any country we cover.',
    ],

    /*
    | The Rcoin earnings multipliers offered to creators, named in the For
    | Partners info note. A standard account earns 1x. The multiplier actually
    | applied to a customer is users.rcoin_multiplier, set by an admin on the
    | customer's page and read by RewardEngine; these two numbers are only the
    | advertised tiers, kept here so the note and the programme move together.
    | e.g. DASHBOARD_INFLUENCER_MULTIPLIER=2
    */
    'influencer_multiplier' => (float) env('DASHBOARD_INFLUENCER_MULTIPLIER', 2),

    /* The ambassador tier. See influencer_multiplier above. */
    'ambassador_multiplier' => (float) env('DASHBOARD_AMBASSADOR_MULTIPLIER', 3),

    /*
    | The gift card brands featured on the For Partners referral hero's four
    | logo tiles, in order (brand_key values). Each logo comes from
    | config/brand_assets.php, else from an active product of that brand; a
    | brand with neither is skipped and the tile falls back to a high-margin,
    | then a popular, brand. e.g. DASHBOARD_PARTNER_TILE_BRANDS=Apple,Amazon
    */
    'partner_tile_brands' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DASHBOARD_PARTNER_TILE_BRANDS', 'Apple,Amazon,Sephora,DiscordNitro'))
    ))),

];
