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
    'chip_countries_subtitle' => 'شرائح eSIM وشحن محلي في أكثر من :count وجهة',
    'chip_reviews_title' => 'تقييمات عملاء موثقة',
    'chip_reviews_subtitle' => 'آراء حقيقية من عملاء اشتروا من هنا',
    'chip_devices_title' => 'يعمل على كل الأجهزة',
    'chip_devices_subtitle' => 'متوافق مع الحاسوب وiPad والهاتف',
    'chip_search_title' => 'اعثر على ما تحتاجه بسرعة',
    'chip_search_subtitle' => 'ابحث في بطاقات الهدايا وeSIM وشحن الرصيد في ثوانٍ',

    // Assurance strip under the category cards: three promises and the
    // payment methods that back them.
    'assurance_label' => 'ما تحصل عليه وكيف تدفع',
    'assurance_instant_title' => 'تسليم فوري',
    'panel_title' => 'تسليم فوري، بلا عناء.',
    'assurance_privacy_title' => 'آمن بالتصميم',
    'assurance_support_title' => 'دعم بشري على مدار الساعة',
    'assurance_pay_with' => 'ادفع عبر MOMO وOM والبطاقات والعملات الرقمية',
    'assurance_more_methods' => '+:count أخرى',

    // Homepage FAQ. Questions themselves live in the faqs table.
    'faq_heading' => 'الأسئلة الشائعة',
    'faq_subheading' => 'اطّلع على صفحة :link لمزيد من الأسئلة',
    'faq_link_label' => 'FAQ',

    // Live counts panel. The numbers come from the database; these are
    // only the labels beside them.
    'counts_customers' => 'عملاء مسجلون',
    'counts_orders' => 'طلبات تم تسليمها',
    'counts_countries' => 'دولة نخدمها',
    'counts_rank_value' => 'موثوق',
    'counts_rank_label' => 'منصة تجارة رقمية',

    // Home rows, the how-it-works steps and the services panel. The
    // offering_* words are joined into sentences with the list glue keys,
    // so a language can set its own separators.
    'esim_row_heading' => 'ابقَ متصلًا في أكثر من :count دولة مع شرائح eSIM',
    'esim_row_prev' => 'شرائح eSIM السابقة',
    'esim_row_next' => 'المزيد من شرائح eSIM',
    'teaser_flights_and_stays' => 'الرحلات والإقامات',
    'teaser_flights_body' => 'سافر بذكاء أكبر مع حجوزات آمنة وتأكيدات سريعة ووصول عالمي من أي مكان في العالم.',
    'teaser_stays_body' => 'حوّل عملتك الرقمية إلى سفر حقيقي وراحة وتجارب لا تُنسى.',
    'row_featured_gift_cards' => 'بطاقات هدايا مميزة',
    'row_popular_gift_cards' => 'بطاقات الهدايا الأكثر رواجًا',
    'row_instant_topups' => 'شحن رصيد الجوال فورًا',
    'alt_gift_card' => 'بطاقة هدايا :name',
    'alt_top_up' => 'شحن :name',
    'how_heading' => 'كيف يعمل',
    'how_step1_title' => '1. اختر منتجًا أو خدمة',
    'how_step1_body' => 'اختر من بين أكثر من :count من :offerings الجاهزة لتسهيل تجربة التسوق.',
    'how_step2_title' => '2. ادفع بالبطاقة أو العملات المشفرة أو Mobile Money أو التحويل البنكي وغيرها',
    'how_step2_body' => 'احصل على أكثر من :count منتجًا رقميًا، منها :offerings وغيرها، كلها في منصة واحدة سلسة.',
    'how_step3_title' => '3. استلم فورًا',
    'how_step3_body' => 'يصلك منتجك خلال ثوانٍ وجاهزًا للاستخدام، إلى بريدك الإلكتروني ولوحة حسابك إذا كنت مسجلًا لدينا.',
    'offering_gift_cards' => 'بطاقات الهدايا',
    'offering_esims' => 'شرائح eSIM',
    'offering_flights' => 'رحلات الطيران',
    'offering_stays' => 'الإقامات',
    'offering_global_stays' => 'إقامات حول العالم',
    'offering_topups' => 'شحن رصيد الجوال',
    'offering_bills' => 'دفع الفواتير',
    'list_glue' => '، ',
    'list_last_glue' => ' و',
    'list_last_glue_serial' => ' و',
    'promo_heading' => 'ما نقدمه',
    'promo_aria' => 'خدماتنا',
    'promo_title' => 'ما تقدمه لك Rshop',
    'promo_services_count' => 'أكثر من 23 خدمة رقمية',
    'promo_access' => 'احصل على :services في منظومة واحدة قوية، منها :offerings.',
    'promo_together' => 'يجمع :site كل شيء في مكان واحد، ليجعل التسوق الدولي والاتصال والسفر والمدفوعات الرقمية اليومية سريعة وسلسة وخالية من التوتر.',
    'promo_tagline_one' => 'منصة واحدة. إمكانيات لا حدود لها.',
    'promo_tagline_two' => 'صُممت لتبسيط حياتك.',
    'promo_copy_manually' => 'انسخ رابط الإحالة هذا يدويًا:',
    'promo_copy_link_aria' => 'انسخ رابط الإحالة الخاص بك',
    'promo_share_link' => 'شارك رابط الإحالة لتربح RCoin',
    'promo_set_code' => 'عيّن رمز الإحالة لتبدأ في الربح',
    'promo_open_rewards' => 'افتح المكافآت',
    'promo_guest_title' => 'أنشئ حسابك لتبدأ في ربح RCoin',
    'promo_guest_cta' => 'أنشئ حسابًا مجانيًا',
];
