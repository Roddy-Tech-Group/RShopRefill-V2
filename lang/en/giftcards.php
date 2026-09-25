<?php

/**
 * The gift card storefront's own copy: the /gift-cards listing, the shared
 * shop components it is built from, and the brand review carousel.
 *
 * The chrome lives here, plus the curated category names under
 * `categories`. The synced subcategory names are translated in the database
 * (Database\Seeders\CategoryTranslationsSeeder). Brand names, prices and
 * country names are data and render as they are stored. Labels the whole
 * site already shares (products.php, navigation.php, common.php) are reused
 * from there rather than repeated.
 */
return [
    // Browser tab title. :site is the configured site name.
    'page_title' => 'Gift Cards | :site',

    // Notice shown while the gift card category is switched off.
    'purchases_paused_hint' => 'You can browse the catalog, but purchases are paused right now. Please check back soon.',

    // Badge on a brand tile while the category is switched off.
    'badge_unavailable' => 'Unavailable',

    // Storefront names in the shop sidebar and the mobile category sheet.
    'category_mobile_topup_data' => 'Mobile top up & data',
    'category_mobile_topups' => 'Mobile top-ups',
    'category_bill_payments' => 'Bill payments',

    // Global product search field.
    'search_placeholder' => 'Search all products - brands, eSIMs, countries',

    // Brand review carousel.
    'brand_reviews_heading' => 'What customers say about :brand',
    'reviews_show_previous' => 'Show previous reviews',
    'reviews_show_next' => 'Show more reviews',

    // Curated category names, keyed by their slug in
    // config/gift_card_categories.php. The config label is the English
    // source; a slug missing here falls back to it.
    'categories' => [
        'online-marketplaces' => 'Online Marketplaces',
        'entertainment' => 'Entertainment',
        'department-stores' => 'Department Stores',
        'home-diy' => 'Home & DIY',
        'gifts' => 'Gifts',
        'kids' => 'Kids',
        'transportation' => 'Transportation',
        'auto-fuel' => 'Auto & Fuel',
        'multi-brand' => 'Multi-Brand',
        'pets' => 'Pets',
        'travel' => 'Travel',
        'gaming' => 'Gaming',
        'fashion' => 'Fashion',
        'food-groceries' => 'Food & Groceries',
        'restaurants' => 'Restaurants',
        'electronics' => 'Electronics',
        'sports-outdoors' => 'Sports & Outdoors',
        'health-beauty' => 'Health & Beauty',
        'digital-apps' => 'Digital Apps',
        'books-music' => 'Books & Music',
        'charity' => 'Charity',
    ],
];
