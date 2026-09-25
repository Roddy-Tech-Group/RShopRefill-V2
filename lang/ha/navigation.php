<?php

/**
 * Nav bars, the category rail and the footer. Category labels live here rather
 * than in products.php because these are the NAVIGATION labels - the catalogue's
 * own category names come from the database.
 */
return [
    'popular_in' => 'Shahararru a :country',
    'categories' => 'Rukunoni',
    'featured' => 'Zaɓaɓɓu',
    'gift_cards' => 'Katunan Kyauta',
    'esims' => 'eSIM',
    'mobile_refill' => 'Cajin Waya',
    'bill_payments' => 'Bili',
    'flights' => 'Jirage',
    'stays' => 'Masauki',
    'search_placeholder' => 'Nemi alama, rukuni, ƙasa',
    'sign_in' => 'Shiga',
    'cart' => 'Kwando',
    'account' => 'Asusu',
    'my_orders' => 'Odojina',
    'rcoin_rewards' => 'Ladan Rcoin',
    'settings' => 'Saituna',
    'limits' => 'Iyaka',
    'logout' => 'Fita',
    'shop' => 'Siyayya',
    'company' => 'Kamfani',
    'legal' => 'Doka',
    'get_the_app' => 'Sami manhaja',
    'follow_us' => 'Bi mu',
    'help_center' => 'Taimako',
    'faqs' => 'Tambayoyi',
    'contact_us' => 'Tuntuɓe mu',
    'how_it_works' => 'Yadda yake aiki',
    'earn_points' => 'Sami maki',
    'terms_of_service' => 'Sharuɗɗan Amfani',
    'privacy_policy' => 'Manufar Sirri',
    'cookie_policy' => 'Manufar Kuki',
    'compliance' => 'Bin ƙa\'idoji',
    'refund_policy' => 'Manufar maido da kuɗi',
    'accessibility' => 'Sauƙin amfani',
    'sitemap' => 'Taswirar shafi',

    // Region suggestion popup under the header's region pill.
    'region_suggest_body' => 'Kana ganin kayayyaki da ake amfani da su a :country. Duba kayayyaki na:',
    'region_suggest_switch' => 'Canza',
    'region_suggest_dismiss' => 'Yi watsi da shawarar yanki',

    // Header category rail and the phone menu's Mobile Refill page.
    'product_categories' => 'Rukunonin kayayyaki',
    'topups_all_networks' => 'Duk hanyoyin sadarwa',
    'topups_credit_only' => 'Kiredit kawai',
    'topups_bundles' => 'Kiredit, data da kira',

    // Region pill. :language is filled on the server; :country and :currency
    // in the browser, where the live values are.
    'region_pill_label' => 'Yanki da kuɗi: :country, :language, :currency. Canza',

    // Search panel. The type labels say what a result row IS.
    'search_no_results' => 'Babu sakamako don ":query"',
    'search_group_bill_payments' => 'Biyan bili',
    'search_group_suggestions' => 'Shawarwari',
    'search_type_gift_card' => 'Katin kyauta',
    'search_type_topup' => 'Cajin waya',
    'search_type_bill' => 'Biyan bili',
    'search_type_esim' => 'eSIM',
    'search_type_page' => 'Shafi',

    // Account chip and the cart popup. :amount is a formatted price.
    'your_account' => 'Asusunka',
    'cart_decrease' => 'Rage',
    'cart_increase' => 'Ƙara',
    'cart_unit_card' => 'Katin :amount',
    'cart_unit_esim' => 'eSIM na :amount',
    'cart_unit_topup' => 'Cajin :amount',
    'cart_unit_bill' => 'Bilin :amount',

    // Referral panel (the nav's Invite a Friend pill and the dashboard top bar).
    'referral_title' => 'Gayyaci abokai, sami Rcoin',
    'referral_title_up_to' => 'Gayyaci abokai, sami har zuwa :amount a Rcoin',
    'referral_body_any_order' => 'Raba hanyar haɗinka. Kana samun Rcoin idan wanda ka gayyata ya kammala oda. Yin rajista kawai ba ya kawo komai.',
    'referral_body_first_order' => 'Raba hanyar haɗinka. Kana samun Rcoin idan wanda ka gayyata ya kammala odarsa ta farko. Yin rajista kawai ba ya kawo komai.',
    'referral_see_rewards' => 'Duba ladan Rcoin ɗinka',
    'referral_sign_in' => 'Shiga don samun hanyar haɗinka',
    'referral_new_here' => 'Sabo ne a nan? :link kuma hanyar haɗinka za ta shirya nan take.',
    'referral_create_account' => 'Buɗe asusu',

    // Cookie banner. :site is the site name, :policy the Cookie Policy link.
    'cookie_consent' => 'Izinin kuki',
    'cookie_body' => 'Muna amfani da kuki don ci gaba da shigar da kai, kare biyan kuɗinka da inganta :site. Duba :policy ɗinmu.',
    'cookie_decline' => 'Ƙi',
    'cookie_accept' => 'Karɓi kuki',

    // Feedback widget.
    'feedback_region' => 'Ra’ayi kan shafin',
    'feedback_tab' => 'Ra’ayi',
    'feedback_tab_label' => 'Aiko mana da ra’ayinka. Ja don motsawa, ko yi amfani da maɓallan kibiya.',
    'feedback_drag' => 'Ja don motsawa',
    'feedback_title' => 'Aika ra’ayi',
    'feedback_email_placeholder' => 'Imel ɗinka',
    'feedback_message_placeholder' => 'Ra’ayinka...',
    'feedback_send' => 'Aika',
    'feedback_sending' => 'Ana aikawa...',
    'feedback_thanks' => 'Mun gode da ra’ayinka',
    'feedback_ticket_opened' => 'An buɗe tikiti ga ƙungiyar.',
    'feedback_send_another' => 'Aika wani',
    'feedback_security_failed' => 'Tabbatar da tsaro ya kasa. Da fatan a sake gwadawa.',

    // Currency names in the preferences sheet. The codes beside them are not copy.
    'currency_names' => [
        'USD' => 'Dalar Amurka',
        'EUR' => 'Yuro',
        'GBP' => 'Fam na Birtaniya',
        'NGN' => 'Nairar Najeriya',
        'XAF' => 'Sefa na Afirka ta Tsakiya',
        'ZAR' => 'Rand na Afirka ta Kudu',
        'KES' => 'Shilin na Kenya',
        'GHS' => 'Sidi na Ghana',
        'EGP' => 'Fam na Masar',
        'MAD' => 'Dirham na Maroko',
        'CAD' => 'Dalar Kanada',
        'AUD' => 'Dalar Ostiraliya',
        'JPY' => 'Yen na Japan',
        'CNY' => 'Yuan na Sin',
        'INR' => 'Rupee na Indiya',
        'BRL' => 'Real na Brazil',
        'AED' => 'Dirham na Haɗaɗɗiyar Daular Larabawa',
        'SAR' => 'Riyal na Saudiyya',
        'TRY' => 'Lira na Turkiyya',
        'CHF' => 'Faran na Suwizalan',
    ],
];
