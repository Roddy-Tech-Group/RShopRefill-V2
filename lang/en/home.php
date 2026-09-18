<?php

/**
 * The home page's own copy.
 *
 * Separate from products.php and navigation.php: those name things the whole
 * site refers to, this is marketing prose that only the landing page says.
 * Keeping it apart means a copy rewrite here cannot quietly change a label
 * the catalogue depends on.
 */
return [
    // Hero. The headline is two lines on a wide screen and the break sits
    // between them, so it is stored as two keys rather than one string with
    // markup in it - a translator should never have to carry a <br> across.
    'hero_heading_line_one' => 'Gift Cards, eSIMs, Mobile Top-Ups',
    'hero_heading_line_two' => '& Bill Payments, All in One Place',
    'hero_subtitle' => 'Pay easily with Mobile Money, Orange Money, Cards, Bank Transfers, or Crypto for all your Digital Purchases, Subscriptions Top-ups, and Bill payments.',
    'hero_cta_gift_cards' => 'Shop Gift Cards',
    'hero_cta_esims' => 'Explore eSIMs',

    // The feature chips under the hero. :count is the rounded catalogue figure,
    // so the number stays live while the sentence around it is translatable.
    'chip_products_title' => ':count+ digital products',
    'chip_products_subtitle' => 'Gift cards, eSIMs, top-ups and bill payments worldwide',
    'chip_countries_title' => ':count+ countries covered',
    'chip_countries_subtitle' => 'Travel eSIMs and local top-ups in over :count destinations',
    'chip_reviews_title' => 'Verified customer reviews',
    'chip_reviews_subtitle' => 'Real feedback from customers who bought here',
    'chip_devices_title' => 'Works on every device',
    'chip_devices_subtitle' => 'Compatible with laptop, iPad and mobile',
    'chip_search_title' => 'Find what you need fast',
    'chip_search_subtitle' => 'Search gift cards, eSIMs and top-ups in seconds',

    // Assurance strip under the category cards: three promises and the
    // payment methods that back them.
    'assurance_label' => 'What you get and how you pay',
    'assurance_instant_title' => 'Instant Delivery',
    'panel_title' => 'Instant Delivery, Zero Hassle.',
    'assurance_privacy_title' => 'Secure by design',
    'assurance_support_title' => '24/7 human support',
    'assurance_pay_with' => 'Pay with MOMO, OM, Cards, Crypto',
    'assurance_more_methods' => '+:count more',

    // Homepage FAQ. Questions themselves live in the faqs table.
    'faq_heading' => 'Frequently asked questions',
    'faq_subheading' => 'Check out our :link page for more questions',
    'faq_link_label' => 'FAQ',

    // Live counts panel. The numbers come from the database; these are
    // only the labels beside them.
    'counts_customers' => 'Registered Customers',
    'counts_orders' => 'Orders delivered',
    'counts_countries' => 'Countries served',
    'counts_rank_value' => 'Trusted',
    'counts_rank_label' => 'Digital commerce platform',
];
