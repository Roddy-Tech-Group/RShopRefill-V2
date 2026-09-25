<?php

/**
 * Nav bars, the category rail and the footer. Category labels live here rather
 * than in products.php because these are the NAVIGATION labels - the catalogue's
 * own category names come from the database.
 */
return [
    'popular_in' => 'Popular in :country',
    'categories' => 'Categories',
    'featured' => 'Featured',
    'gift_cards' => 'Gift Cards',
    'esims' => 'eSIMs',
    'mobile_refill' => 'Mobile Refill',
    'bill_payments' => 'Bills',
    'flights' => 'Flights',
    'stays' => 'Stays',
    'search_placeholder' => 'Search brands, categories, countries',
    'sign_in' => 'Login',
    'cart' => 'Cart',
    'account' => 'Account',
    'my_orders' => 'My orders',
    'rcoin_rewards' => 'Rcoin Rewards',
    'settings' => 'Settings',
    'limits' => 'Limits',
    'logout' => 'Logout',
    'shop' => 'Shop',
    'company' => 'Company',
    'legal' => 'Legal',
    'get_the_app' => 'Get the app',
    'follow_us' => 'Follow us',
    'help_center' => 'Help',
    'faqs' => 'FAQs',
    'contact_us' => 'Contact us',
    'how_it_works' => 'How it works',
    'earn_points' => 'Earn points',
    'terms_of_service' => 'Terms of Service',
    'privacy_policy' => 'Privacy Policy',
    'cookie_policy' => 'Cookie Policy',
    'compliance' => 'Compliance',
    'refund_policy' => 'Refund policy',
    'accessibility' => 'Accessibility',
    'sitemap' => 'Sitemap',

    // Region suggestion popup under the header's region pill.
    'region_suggest_body' => 'You are seeing products redeemable in :country. View products for:',
    'region_suggest_switch' => 'Switch',
    'region_suggest_dismiss' => 'Dismiss region suggestion',

    // Header category rail and the phone menu's Mobile Refill page.
    'product_categories' => 'Product categories',
    'topups_all_networks' => 'All networks',
    'topups_credit_only' => 'Credit only',
    'topups_bundles' => 'Credit, Data & Calls',

    // Region pill. :language is filled on the server; :country and :currency
    // in the browser, where the live values are.
    'region_pill_label' => 'Region and currency: :country, :language, :currency. Change',

    // Search panel. The type labels say what a result row IS.
    'search_no_results' => 'No results for ":query"',
    'search_group_bill_payments' => 'Bill Payments',
    'search_group_suggestions' => 'Suggestions',
    'search_type_gift_card' => 'Gift card',
    'search_type_topup' => 'Mobile top-up',
    'search_type_bill' => 'Bill payment',
    'search_type_esim' => 'eSIM',
    'search_type_page' => 'Page',

    // Account chip and the cart popup. :amount is a formatted price.
    'your_account' => 'Your account',
    'cart_decrease' => 'Decrease',
    'cart_increase' => 'Increase',
    'cart_unit_card' => ':amount card',
    'cart_unit_esim' => ':amount eSIM',
    'cart_unit_topup' => ':amount top-up',
    'cart_unit_bill' => ':amount bill payment',

    // Referral panel (the nav's Invite a Friend pill and the dashboard top bar).
    'referral_title' => 'Refer friends, earn Rcoin',
    'referral_title_up_to' => 'Refer friends, earn up to :amount in Rcoin',
    'referral_body_any_order' => 'Share your link. You earn Rcoin when someone you refer completes an order. Signing up alone earns nothing.',
    'referral_body_first_order' => 'Share your link. You earn Rcoin when someone you refer completes their first order. Signing up alone earns nothing.',
    'referral_see_rewards' => 'See your Rcoin rewards',
    'referral_sign_in' => 'Sign in to get your link',
    'referral_new_here' => 'New here? :link and your link is ready straight away.',
    'referral_create_account' => 'Create an account',

    // Cookie banner. :site is the site name, :policy the Cookie Policy link.
    'cookie_consent' => 'Cookie consent',
    'cookie_body' => 'We use cookies to keep you signed in, secure your payments and improve :site. See our :policy.',
    'cookie_decline' => 'Decline',
    'cookie_accept' => 'Accept cookies',

    // Feedback widget.
    'feedback_region' => 'Site feedback',
    'feedback_tab' => 'Feedback',
    'feedback_tab_label' => 'Send us feedback. Drag to move, or use the arrow keys.',
    'feedback_drag' => 'Drag to move',
    'feedback_title' => 'Send feedback',
    'feedback_email_placeholder' => 'Your email',
    'feedback_message_placeholder' => 'Your feedback...',
    'feedback_send' => 'Send',
    'feedback_sending' => 'Sending...',
    'feedback_thanks' => 'Thanks for the feedback',
    'feedback_ticket_opened' => 'A ticket has been opened with the team.',
    'feedback_send_another' => 'Send another',
    'feedback_security_failed' => 'Security verification failed. Please try again.',

    // Currency names in the preferences sheet. The codes beside them are not copy.
    'currency_names' => [
        'USD' => 'United States Dollar',
        'EUR' => 'Euro',
        'GBP' => 'British Pound',
        'NGN' => 'Nigerian Naira',
        'XAF' => 'Central African CFA Franc',
        'ZAR' => 'South African Rand',
        'KES' => 'Kenyan Shilling',
        'GHS' => 'Ghanaian Cedi',
        'EGP' => 'Egyptian Pound',
        'MAD' => 'Moroccan Dirham',
        'CAD' => 'Canadian Dollar',
        'AUD' => 'Australian Dollar',
        'JPY' => 'Japanese Yen',
        'CNY' => 'Chinese Yuan',
        'INR' => 'Indian Rupee',
        'BRL' => 'Brazilian Real',
        'AED' => 'UAE Dirham',
        'SAR' => 'Saudi Riyal',
        'TRY' => 'Turkish Lira',
        'CHF' => 'Swiss Franc',
    ],
];
