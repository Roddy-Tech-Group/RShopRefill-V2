<?php

/**
 * The eSIM storefront's own copy: the /esims listing, a destination's plan
 * page, the destination cards and the purchase-check modal.
 *
 * Separate from products.php because this is the eSIM store's own voice, not
 * a label the whole catalogue shares. Country and region names, plan names,
 * prices and brand names never live here: they come from the catalogue and
 * stay as supplied, so they arrive through :placeholders.
 */
return [
    // Page titles and headings. :region is the destination's own name.
    'page_title' => ':region eSIM',
    'heading_region' => ':region eSIMs',
    'breadcrumb_store' => 'eSIM Store',

    // Listing hero. The three adverts are admin-editable; these are the stock
    // wording, shown translated only while the setting still holds it.
    'hero_1_heading' => 'Feel the freedom of unlimited data',
    'hero_1_text' => 'Go ahead and watch that video, listen to that song, download that app. Explore our eSIM data packages for an uninterrupted connection in 190+ countries.',
    'hero_2_heading' => 'Stay connected the moment you land',
    'hero_2_text' => 'From the airport gate to the boardroom, travellers, freelancers and remote teams get instant data in 190+ countries. No roaming bills, no SIM swaps.',
    'hero_3_heading' => 'Set up in minutes, before you even pack',
    'hero_3_text' => 'Buy your data plan, scan the QR code and you are ready to go. No shop queues, no plastic SIM to lose, and your usual number keeps working for calls and messages.',
    'go_to_advert' => 'Go to advert :number',

    // Location tabs and the heading each one gives the grid.
    'tab_popular' => 'Popular',
    'tab_local' => 'Local',
    'tab_regional' => 'Regional',
    'tab_global' => 'Global',
    'tab_unlimited' => 'Unlimited',
    'title_popular' => 'Popular locations',
    'title_local' => 'Local eSIMs',
    'title_regional' => 'Regional eSIMs',
    'title_global' => 'Global eSIMs',
    'title_unlimited' => 'Unlimited data eSIMs',
    'title_all' => 'All eSIMs',
    'subtitle_popular' => 'Explore our most popular eSIMs. Packages start from the shown price.',
    'subtitle_local' => 'Single-country eSIMs. Packages start from the shown price.',
    'subtitle_regional' => 'Multi-country regional eSIMs. Packages start from the shown price.',
    'subtitle_global' => 'Worldwide coverage eSIMs. Packages start from the shown price.',
    'subtitle_unlimited' => 'Destinations that sell an unlimited data plan. Packages start from the shown price.',
    'subtitle_all' => 'Every eSIM we offer. Packages start from the shown price.',

    // Destination search.
    'search_placeholder' => 'Where are you travelling?',
    'search_label' => 'Search eSIM destinations',
    'clear_search' => 'Clear search',
    'show_matching' => 'Show matching destinations',

    // Availability and empty states.
    'unavailable_title' => 'eSIMs are temporarily unavailable',
    'unavailable_browse' => 'You can browse coverage, but purchases are paused right now. Please check back soon.',
    'unavailable_view' => 'You can view the plans, but purchases are paused right now. Please check back soon.',
    'empty_title' => 'No eSIMs available yet',
    'empty_body' => 'We\'re adding coverage. Check back shortly.',
    'browse_all' => 'Browse all :count destinations',

    // Destination card chips. :data is an allowance ("1 GB"), :range a span
    // of days ("7 - 30").
    'from' => 'from',
    'from_data' => 'from :data',
    'days_range' => ':range days',
    'unlimited' => 'Unlimited',
    'chip_data' => 'Data',
    'chip_phone_number' => 'Phone number',
    'chip_data_only' => 'Data only',
    'chip_top_up' => 'Top-up',
    'view_plans' => 'View plans',

    // Purchase check raised by a local or regional destination card. :name
    // is the destination.
    'tip_local_heading' => 'Only buy this eSIM if you are in :name or travelling there',
    'tip_regional_heading' => 'Check :name covers the country you are travelling to',
    'tip_local_body' => 'It connects to networks there, so it is no use anywhere else.',
    'tip_regional_body' => 'It works across the countries this plan lists and nowhere else. The full list is on the plan\'s page.',
    'tip_continue' => 'Continue to :name',
    'tip_not_going' => 'Not going there? One eSIM covers you worldwide.',
    'tip_buy_global' => 'Buy :name',

    // Branded destination card on the home row. :count is the plan's real
    // country count; the article variant exists for names English says with
    // "the" (the United States), and may match the plain one elsewhere.
    'card_label_global' => 'GLOBAL eSIM',
    'card_label_regional' => 'REGIONAL eSIM',
    'card_label_local' => 'LOCAL eSIM',
    'card_network_available' => ':network Available',
    'card_aria_from' => ':name, from :price',
    'card_from_price' => 'From :price',
    'card_instant_qr' => 'Instant QR',
    'card_hotspot' => 'Hotspot',
    'card_unavailable' => 'Unavailable',
    'card_currently_unavailable' => 'Currently unavailable',
    'card_global_description' => 'USA Data + Real USA Number. Keeps you connected everywhere.',
    'card_region_description' => 'Stay connected across :count countries.',
    'card_region_description_generic' => 'Stay connected across the region.',
    'card_local_description' => 'Reliable mobile data for your trip to :country.',
    'card_local_description_article' => 'Reliable mobile data for your trip to the :country.',

    // Destination header.
    'countries_and_networks_count' => ':count Countries and Networks',
    'networks' => 'Networks',
    'check_compatibility' => 'Check compatibility',
    'topup_note' => 'If you\'re running low, you can always :topup',
    'topup_word' => 'top up',
    'starts_on_connect' => 'The package starts when you connect to a supported network',
    'guard_warning' => 'Only buy this if you\'re in :region or travelling there - it only works on :local networks. Not headed there? :link for worldwide coverage.',
    'guard_link' => 'Buy Discover Global instead',
    'change_region' => 'Change country or region',
    'search_region' => 'Search a country or region',
    'choose_region' => 'Choose a country or region',
    'instant_activation' => 'Instant activation',
    'no_id_required' => 'No ID required',
    'covers_countries' => 'Covers :count countries and territories',

    // Plan selector and plan cards. :count is a number of days, minutes or
    // texts.
    'tab_voice' => 'Data / Calls / Texts',
    'tab_data' => 'Data Only',
    'mode_fixed' => 'Fixed data',
    'choose_package' => 'Choose your package',
    'badge_calls' => 'Calls',
    'day_one' => ':count day',
    'days_other' => ':count days',
    'plan_minutes' => ':count mins',
    'plan_sms' => ':count SMS',
    'get_esim' => 'Get eSIM',
    'no_packages' => 'No packages in this category right now.',

    // What every voice plan carries.
    'voice_includes' => 'Every voice plan includes',
    'voice_app_title' => 'App verification',
    'voice_app_desc' => 'WhatsApp, Telegram, TikTok and many other apps that need a number.',
    'voice_imessage_title' => 'Unlimited iMessage',
    'voice_imessage_desc' => 'Unlimited iMessage for the full validity period your plan carries.',
    'voice_topup_title' => 'Renewable & top-up',
    'voice_topup_desc' => 'Top up for local calls any time, with iMessage unlimited.',

    // Broader coverage row.
    'broader_title' => 'Need broader coverage?',
    'broader_subtitle' => 'Explore our regional and global eSIMs. Packages start from the shown price and include coverage for the selected location.',

    // Why choose us. :site is the store name, :count the live destination
    // count, :languages the offered languages joined into a list.
    'why_heading' => 'Why travelers choose :site eSIMs',
    'why_coverage' => 'Local, regional and global coverage for :count destinations',
    'why_flexible' => 'Flexible packages, including unlimited data options',
    'why_pay_languages' => 'Pay in your currency or crypto, with the store in :languages',
    'why_pay' => 'Pay in your currency or crypto',
    'why_install' => 'Easy install and set up to get connected in minutes',

    // How to add your eSIM. The step titles are printed unescaped so the
    // arrow entity renders.
    'how_heading' => 'How to add your eSIM',
    'how_subtitle' => 'Three quick steps and you are roaming on local rates. No SIM tray, no queues at the airport kiosk.',
    'how_step_1_title' => 'Open Settings &rarr; Mobile Service',
    'how_step_1_body' => 'Unlock your phone and tap Mobile Service (or Cellular on older iPhones). This is where every line on your device already lives.',
    'how_step_2_title' => 'Tap Add eSIM and scan your QR code',
    'how_step_2_body' => 'We email the QR code seconds after checkout. Point your camera at it, or tap Enter Details Manually to paste the code instead.',
    'how_step_3_title' => 'Turn it on, switch on Data Roaming',
    'how_step_3_body' => 'Flip Turn On This Line, then enable Data Roaming for the new line. The moment you land, your phone connects at local rates.',
    'how_guide' => 'Still stuck? Read the full guide',

    // Anonymous purchase. :site is the store name.
    'anon_heading' => 'Anonymous eSIM with MOMO, Cards, Crypto and more. No KYC required.',
    'anon_body' => 'Unlike traditional SIM cards that ask for a passport and personal details, your :site eSIM can be bought with mobile money, cards, crypto and more. No identity verification, no personal data collected.',
    'anon_kyc_title' => 'No KYC required',
    'anon_kyc_body' => 'Buy instantly without ID verification or personal information.',
    'anon_pay_title' => 'Pay your way',
    'anon_pay_body' => 'Mobile money, cards, Apple Pay, bank transfer or crypto (Bitcoin, Ethereum, Tether, BNB, Solana, Litecoin). Your call.',
    'anon_delivery_title' => 'Instant delivery',
    'anon_delivery_body' => 'Receive your eSIM QR code in your inbox the moment your payment confirms.',

    // Payment methods strip.
    'payments_heading' => 'Payment methods we accept',
    'payments_subtitle' => 'Pay with crypto or your local card. Settle in the currency you already hold.',
    'payments_label' => 'Accepted payment methods',

    // No plans for this destination.
    'no_plans_title' => 'No data plans available',
    'no_plans_body' => 'This region has no plans in stock right now. Check back later.',
    'browse_other_regions' => 'Browse other regions',

    // Purchase sheet. :countries is the bold "N countries" run, :region the
    // destination name.
    'calls_and_texts' => 'calls & texts',
    'coverage' => 'Coverage',
    'buy_works_across' => 'Works across :countries, connecting to a local network automatically in each.',
    'countries_count' => ':count countries',
    'more_count' => '+:count more',
    'buy_works_in' => 'Works in :region. It connects on arrival, and you can install it before you travel.',
    'pay_in' => 'Pay in',
    'total' => 'Total',
    'package_details' => 'Package details',

    // Networks modal.
    'countries_networks' => 'Countries & Networks',
    'networks_across' => 'This eSIM works across :count countries and connects to a local network automatically in each.',
    'search_country' => 'Search by country',
    'networks_switch' => 'This eSIM may switch between the following networks based on availability and speed. You can adjust this in your device settings.',
    'networks_auto' => 'Your device connects automatically to the best available local network in :region.',

    // Compatibility modal. :models and :brands are counts off the list.
    'compat_intro' => 'To use an eSIM, a device must meet all three of these conditions:',
    'compat_supports' => 'The device supports eSIMs.',
    'compat_unlocked' => 'The device is not carrier or network-locked.',
    'compat_not_jailbroken' => 'The device is not jailbroken (iOS) or rooted (Android).',
    'compat_missing_title' => 'Don\'t see your device?',
    'compat_missing_body' => 'The list is kept up to date but is not complete, and some regional models of a listed device do not carry eSIM. Check with the manufacturer if yours is missing.',
    'compat_find' => 'Find your device',
    'compat_counts' => ':models models across :brands manufacturers',
    'compat_search' => 'Search by manufacturer or model',
    'compat_keep_typing' => 'Keep typing, three characters or more.',
    'compat_models' => ':count model|:count models',
    'compat_no_match' => 'No manufacturer or model matches that search.',

    // Package details modal.
    'details_package' => 'Package',
    'details_texts' => 'Texts',
    'details_validity' => 'Validity',
    'details_flexible' => 'Flexible',
    'details_apple_services' => 'iMessage & Apple services',
    'details_imessage_desc' => 'No limit messaging other Apple devices.',
    'details_imessage_calls' => 'iMessage Calls',
    'details_imessage_calls_desc' => 'Voice calls over iMessage / data.',
    'details_imessage_text' => 'iMessage text',
    'details_imessage_text_desc' => 'Send and receive texts over iMessage.',
    'details_facetime_desc' => 'Video and audio FaceTime over data.',
    'details_topups_title' => 'Top-ups & iMessage',
    'details_topups_body' => 'You can top up this plan any time for local calls, texts and regular data browsing. Using iMessage is always free and unlimited - it never counts against your allowance.',
    'details_data_only_body' => 'This is a data-only plan - it keeps you connected online with mobile data for the full validity period. It has no calls, texts or phone number, just internet access.',
    'details_activation' => 'Activation policy',
    'activation_first_usage' => 'The validity period starts when the eSIM connects to a supported network in its coverage area - not at purchase. If you install it outside the coverage area, it activates when you arrive.',
    'activation_installation' => 'The validity period starts as soon as the eSIM is installed on your device.',
    'activation_automatic' => 'The eSIM activates automatically once installed and connected to a supported network.',
    'details_topup_option' => 'Top-up option',
    'details_rechargeable' => 'You can top up this eSIM when your data or validity runs out - top-ups are available once the eSIM is installed.',
    'details_not_rechargeable' => 'This package is not rechargeable - when it runs out, purchase a new plan for the same device.',
    'details_other_info' => 'Other info',
    'details_after_allowance' => 'After your data, SMS and calls finish, you can still call, text and FaceTime over iMessage, WhatsApp and your WiFi. To keep using normal texts, calls and data, top up before you let your eSIM expire. If you plan to keep using it, a top-up auto-renews your eSIM to the top-up plan you choose. On your Web App orders page you can manage your eSIM, see remaining data, credit and SMS, top up your eSIM (only from there) and install your eSIM from there too. Have fun 😊',
    'details_confirm_device' => 'To complete your order, confirm your device is eSIM-compatible and network-unlocked.',
];
