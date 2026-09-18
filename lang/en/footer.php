<?php

/*
 * Footer.
 *
 * The brand line, four link columns, the social row, the settings row and the
 * legal lines - every storefront page carries these, so they are the strings a
 * visitor sees most.
 *
 * Each link lives in exactly one column. Privacy and Terms used to appear in
 * the Legal column AND a bottom link bar; the bar is gone.
 *
 * The company name, RC number and registered address are NOT translated: they
 * are the legal identity of the operator. They arrive as :company, :rc and
 * :address so a translator cannot restate them.
 */

return [

    // Column headings. The keys predate the renames ("help" reads Help &
    // Support, "legal" reads Legal & Trust) and are kept so the column ids
    // built from them stay stable.
    'shop' => 'Shop',
    'help' => 'Help & Support',
    'company' => 'Company',
    'legal' => 'Legal & Trust',

    // Brand column
    'tagline' => 'Your marketplace for gift cards, travel eSIMs, mobile top-ups and bill payments.',

    // Shop column
    'gift_cards' => 'Gift Cards',
    'travel_esims' => 'Travel eSIMs',
    'mobile_topups' => 'Mobile Top-Ups',
    'data_bundles' => 'Data Bundles',
    'bill_payments' => 'Bill Payments',
    'request_a_product' => 'Request a product',
    'flights' => 'Flights',
    'stays' => 'Stays',

    // Help & Support column
    'help_center' => 'Help Center',
    'faqs' => 'FAQs',
    'contact_support' => 'Contact Support',
    'how_it_works' => 'How It Works',
    'refund_policy' => 'Refund Policy',
    'esim_installation_guide' => 'eSIM Installation Guide',

    // Company column
    'about_us' => 'About Us',
    'blog' => 'Blog',
    'customer_reviews' => 'Customer Reviews',
    'influencers_affiliates' => 'Influencers and Affiliates',
    'press_media' => 'Press & Media',

    // Legal & Trust column
    'terms_of_service' => 'Terms of Service',
    'privacy_policy' => 'Privacy Policy',
    'cookie_policy' => 'Cookie Policy',
    'compliance' => 'Compliance',
    'security' => 'Security',
    'accessibility' => 'Accessibility',

    // Social row - the network names themselves are brands, never translated
    'follow_us' => 'Follow us',
    'social_facebook' => 'Visit our Facebook page',
    'social_x' => 'Follow us on X',
    'social_tiktok' => 'Follow us on TikTok',
    'social_instagram' => 'Follow us on Instagram',
    'social_linkedin' => 'Follow us on LinkedIn',
    'social_whatsapp' => 'Chat with us on WhatsApp',
    'social_discord' => 'Join us on Discord',

    // Settings row and legal lines
    'theme' => 'Theme',
    'cookie_settings' => 'Cookie Settings',
    'all_rights_reserved' => 'All rights reserved.',
    'legal_line' => 'Owned and operated by :company, RC :rc, registered at :address.',
    'verify_company' => 'Verify company',

    // Newsletter row, above the footer columns.
    'newsletter_pitch' => 'Get gift card, eSIM and airtime deals delivered to your inbox',
    'newsletter_label' => 'Email address',
    'newsletter_placeholder' => 'you@example.com',
    'newsletter_cta' => 'Sign Up',
    'newsletter_sending' => 'Signing up',
    'newsletter_done' => 'You are on the list. Thanks!',
];
