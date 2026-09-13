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
    'hero_heading_line_one' => 'بطاقات هدايا، وشرائح eSIM، وشحن رصيد',
    'hero_heading_line_two' => 'ودفع فواتير، في مكان واحد',
    'hero_subtitle' => 'ادفع بسهولة عبر Mobile Money أو Orange Money أو البطاقات أو التحويل البنكي أو العملات الرقمية لكل مشترياتك الرقمية وشحن اشتراكاتك ودفع فواتيرك.',
    'hero_cta_gift_cards' => 'تسوق بطاقات الهدايا',
    'hero_cta_esims' => 'استكشف شرائح eSIM',

    // The feature chips under the hero. :count is the rounded catalogue figure,
    // so the number stays live while the sentence around it is translatable.
    'chip_products_title' => 'أكثر من :count منتج رقمي',
    'chip_products_subtitle' => 'بطاقات هدايا وشرائح eSIM وشحن رصيد ودفع فواتير حول العالم',
    'chip_countries_title' => 'تغطية أكثر من :count دولة',
    'chip_countries_subtitle' => 'شرائح eSIM للسفر وشحن محلي في أكثر من :count وجهة',
    'chip_reviews_title' => 'تقييمات عملاء موثقة',
    'chip_reviews_subtitle' => 'آراء حقيقية من عملاء اشتروا من هنا',
    'chip_devices_title' => 'يعمل على كل الأجهزة',
    'chip_devices_subtitle' => 'متوافق مع الحاسوب وiPad والهاتف',
    'chip_search_title' => 'اعثر على ما تحتاجه بسرعة',
    'chip_search_subtitle' => 'ابحث في بطاقات الهدايا وeSIM وشحن الرصيد في ثوانٍ',
];
