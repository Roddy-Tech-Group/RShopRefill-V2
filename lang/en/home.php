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
    'chip_countries_subtitle' => 'eSIMs and local top-ups in over :count destinations',
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

    // Home rows, the how-it-works steps and the services panel. The
    // offering_* words are joined into sentences with the list glue keys,
    // so a language can set its own separators.
    'esim_row_heading' => 'Stay Connected in :count+ Countries with eSIMs',
    'esim_row_prev' => 'Previous eSIMs',
    'esim_row_next' => 'More eSIMs',
    'teaser_flights_and_stays' => 'Flights & stays',
    'teaser_flights_body' => 'Travel smarter with secure bookings, fast confirmations, and global access from anywhere in the world.',
    'teaser_stays_body' => 'Turn digital currency into real-world travel, comfort, and unforgettable experiences.',
    'row_featured_gift_cards' => 'Featured Gift Cards',
    'row_popular_gift_cards' => 'Popular Gift Cards',
    'row_instant_topups' => 'Instant Mobile Top-Ups',
    'alt_gift_card' => ':name gift card',
    'alt_top_up' => ':name top-up',
    'how_heading' => 'How it works',
    'how_step1_title' => '1. Pick a product or service',
    'how_step1_body' => 'Choose from :count+ :offerings made ready for you to simplify your shopping experience.',
    'how_step2_title' => '2. Pay with Cards, Crypto, Mobile Money, Bank Transfers etc',
    'how_step2_body' => 'Access :count+ digital products including :offerings, and more, all in one seamless platform.',
    'how_step3_title' => '3. Receive instantly',
    'how_step3_body' => 'Your product arrives in seconds, to your email address and your clients dashboard if you are signed up with us ready to use.',
    'offering_gift_cards' => 'gift cards',
    'offering_esims' => 'eSIMs',
    'offering_flights' => 'flights',
    'offering_stays' => 'stays',
    'offering_global_stays' => 'global stays',
    'offering_topups' => 'mobile top-ups',
    'offering_bills' => 'bill payments',
    'list_glue' => ', ',
    'list_last_glue' => ' and ',
    'list_last_glue_serial' => ', and ',
    'promo_heading' => 'What we bring',
    'promo_aria' => 'Our services',
    'promo_title' => 'What Rshop has to offer you',
    'promo_services_count' => '23+ digital services',
    'promo_access' => 'Access :services in one powerful ecosystem including :offerings.',
    'promo_together' => ':site brings everything together under one roof, making international shopping, connectivity, travel, and everyday digital payments fast, seamless, and stress-free.',
    'promo_tagline_one' => 'One platform. Endless possibilities.',
    'promo_tagline_two' => 'Built to simplify your life.',
    'promo_copy_manually' => 'Copy this referral link manually:',
    'promo_copy_link_aria' => 'Copy your referral link',
    'promo_share_link' => 'Share your referral link to earn RCoin',
    'promo_set_code' => 'Set your referral code to start earning',
    'promo_open_rewards' => 'Open Rewards',
    'promo_guest_title' => 'Set up your account to start earning RCoin',
    'promo_guest_cta' => 'Create a free account',
];
