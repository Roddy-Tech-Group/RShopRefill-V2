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
    'chip_countries_subtitle' => 'eSIM na tafiya da cajin gida a wurare sama da :count',
    'chip_reviews_title' => 'Ra\'ayoyin abokan ciniki da aka tabbatar',
    'chip_reviews_subtitle' => 'Ra\'ayi na gaskiya daga abokan ciniki da suka saya a nan',
    'chip_devices_title' => 'Yana aiki a kowace na\'ura',
    'chip_devices_subtitle' => 'Ya dace da kwamfuta, iPad da waya',
    'chip_search_title' => 'Nemo abin da kake so da sauri',
    'chip_search_subtitle' => 'Nemi katunan kyauta, eSIM da caji cikin daƙiƙu',
];
