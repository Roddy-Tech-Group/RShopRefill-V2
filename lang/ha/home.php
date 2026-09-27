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
    'hero_heading_line_one' => 'Katunan Kyauta, eSIM, Cajin Waya',
    'hero_heading_line_two' => '& Biyan Bili, Duk a Wuri Ɗaya',
    'hero_subtitle' => 'Ka biya cikin sauƙi da Mobile Money, Orange Money, Kati, Canja Kuɗi ta Banki, ko Crypto don duk sayayyar dijital, cajin biyan kuɗin rajista, da biyan bili.',
    'hero_cta_gift_cards' => 'Sayi katunan kyauta',
    'hero_cta_esims' => 'Duba eSIM',

    // The feature chips under the hero. :count is the rounded catalogue figure,
    // so the number stays live while the sentence around it is translatable.
    'chip_products_title' => 'Kayan dijital :count+',
    'chip_products_subtitle' => 'Katunan kyauta, eSIM, caji da biyan bili a duk duniya',
    'chip_countries_title' => 'Ƙasashe :count+ da ake samu',
    'chip_countries_subtitle' => 'eSIM da cajin gida a wurare sama da :count',
    'chip_reviews_title' => 'Ra\'ayoyin abokan ciniki da aka tabbatar',
    'chip_reviews_subtitle' => 'Ra\'ayi na gaskiya daga abokan ciniki da suka saya a nan',
    'chip_devices_title' => 'Yana aiki a kowace na\'ura',
    'chip_devices_subtitle' => 'Ya dace da kwamfuta, iPad da waya',
    'chip_search_title' => 'Nemo abin da kake so da sauri',
    'chip_search_subtitle' => 'Nemi katunan kyauta, eSIM da caji cikin daƙiƙu',

    // Assurance strip under the category cards: three promises and the
    // payment methods that back them.
    'assurance_label' => 'Abin da kuke samu da yadda ake biya',
    'assurance_instant_title' => 'Isar da kaya nan take',
    'panel_title' => 'Isar da kaya nan take, babu wahala.',
    'assurance_privacy_title' => 'Amintacce ta tsarin sa',
    'assurance_support_title' => 'Tallafin mutane awa 24',
    'assurance_pay_with' => 'Biya da MOMO, OM, katuna da kripto',
    'assurance_more_methods' => '+:count ƙari',

    // Homepage FAQ. Questions themselves live in the faqs table.
    'faq_heading' => 'Tambayoyin da ake yawan yi',
    'faq_subheading' => 'Duba shafin :link don ƙarin tambayoyi',
    'faq_link_label' => 'FAQ',

    // Live counts panel. The numbers come from the database; these are
    // only the labels beside them.
    'counts_customers' => 'Abokan ciniki masu rajista',
    'counts_orders' => 'Oda da aka kai',
    'counts_countries' => 'Ƙasashen da muke hidima',
    'counts_rank_value' => 'Abin dogaro',
    'counts_rank_label' => 'Dandalin cinikin dijital',

    // Home rows, the how-it-works steps and the services panel. The
    // offering_* words are joined into sentences with the list glue keys,
    // so a language can set its own separators.
    'esim_row_heading' => 'Ci gaba da haɗuwa a ƙasashe :count+ da eSIM',
    'esim_row_prev' => 'eSIM na baya',
    'esim_row_next' => 'Ƙarin eSIM',
    'teaser_flights_and_stays' => 'Jiragen sama da masauki',
    'teaser_flights_body' => 'Yi tafiya cikin wayo da ajiyar wuri mai tsaro, tabbatarwa cikin sauri, da samun dama a duk inda kake a duniya.',
    'teaser_stays_body' => 'Mayar da kuɗin dijital zuwa tafiye-tafiye na gaske, jin daɗi da abubuwan da ba za a manta da su ba.',
    'row_featured_gift_cards' => 'Katunan kyauta na musamman',
    'row_popular_gift_cards' => 'Shahararrun katunan kyauta',
    'row_instant_topups' => 'Cajin waya nan take',
    'alt_gift_card' => 'Katin kyauta na :name',
    'alt_top_up' => 'Cajin :name',
    'how_heading' => 'Yadda yake aiki',
    'how_step1_title' => '1. Zaɓi samfuri ko sabis',
    'how_step1_body' => 'Zaɓi daga cikin :count+ :offerings da aka shirya don sauƙaƙa maka siyayya.',
    'how_step2_title' => '2. Biya da kati, kirifto, Mobile Money, tura kuɗi ta banki da sauransu',
    'how_step2_body' => 'Samu samfuran dijital :count+ ciki har da :offerings da ƙari, duka a dandali ɗaya mai sauƙi.',
    'how_step3_title' => '3. Karɓa nan take',
    'how_step3_body' => 'Samfurinka yana isowa cikin daƙiƙoƙi, a shirye don amfani, zuwa adireshin imel ɗinka da dashboard ɗinka idan kana da asusu tare da mu.',
    'offering_gift_cards' => 'katunan kyauta',
    'offering_esims' => 'eSIM',
    'offering_flights' => 'tikitin jirgin sama',
    'offering_stays' => 'masauki',
    'offering_global_stays' => 'masauki a duk duniya',
    'offering_topups' => 'cajin waya',
    'offering_bills' => 'biyan kuɗin bil',
    'list_glue' => ', ',
    'list_last_glue' => ' da ',
    'list_last_glue_serial' => ' da ',
    'promo_heading' => 'Abin da muke kawowa',
    'promo_aria' => 'Ayyukanmu',
    'promo_title' => 'Abin da Rshop ke ba ka',
    'promo_services_count' => 'ayyukan dijital 23+',
    'promo_access' => 'Samu :services a cikin tsari ɗaya mai ƙarfi, ciki har da :offerings.',
    'promo_together' => ':site yana haɗa komai wuri ɗaya, yana sa siyayya ta ƙasashen waje, haɗin intanet, tafiye-tafiye da biyan kuɗi na dijital na yau da kullum su zama masu sauri, sauƙi kuma ba tare da damuwa ba.',
    'promo_tagline_one' => 'Dandali ɗaya. Damammaki marasa iyaka.',
    'promo_tagline_two' => 'An gina shi don sauƙaƙa maka rayuwa.',
    'promo_copy_manually' => 'Kwafi wannan hanyar gayyata da hannu:',
    'promo_copy_link_aria' => 'Kwafi hanyar gayyatarka',
    'promo_share_link' => 'Raba hanyar gayyatarka don samun RCoin',
    'promo_set_code' => 'Saita lambar gayyatarka don fara samu',
    'promo_open_rewards' => 'Buɗe lada',
    'promo_guest_title' => 'Buɗe asusunka don fara samun RCoin',
    'promo_guest_cta' => 'Buɗe asusu kyauta',
];
