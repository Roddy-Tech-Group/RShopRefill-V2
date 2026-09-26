<?php

/**
 * Nav bars, the category rail and the footer. Category labels live here rather
 * than in products.php because these are the NAVIGATION labels - the catalogue's
 * own category names come from the database.
 */
return [
    'popular_in' => 'الأكثر رواجًا في :country',
    'categories' => 'الفئات',
    'featured' => 'مميز',
    'gift_cards' => 'بطاقات الهدايا',
    'esims' => 'شرائح eSIM',
    'mobile_refill' => 'شحن الرصيد',
    'bill_payments' => 'الفواتير',
    'flights' => 'رحلات الطيران',
    'stays' => 'أماكن الإقامة',
    'search_placeholder' => 'ابحث عن علامة تجارية أو فئة أو بلد',
    'sign_in' => 'تسجيل الدخول',
    'cart' => 'السلة',
    'account' => 'الحساب',
    'my_orders' => 'طلباتي',
    'rcoin_rewards' => 'مكافآت Rcoin',
    'settings' => 'الإعدادات',
    'limits' => 'الحدود',
    'logout' => 'تسجيل الخروج',
    'shop' => 'المتجر',
    'company' => 'الشركة',
    'legal' => 'الشؤون القانونية',
    'get_the_app' => 'حمّل التطبيق',
    'follow_us' => 'تابعنا',
    'help_center' => 'المساعدة',
    'faqs' => 'الأسئلة الشائعة',
    'contact_us' => 'اتصل بنا',
    'how_it_works' => 'كيف يعمل',
    'earn_points' => 'اكسب نقاطًا',
    'terms_of_service' => 'شروط الخدمة',
    'privacy_policy' => 'سياسة الخصوصية',
    'cookie_policy' => 'سياسة ملفات تعريف الارتباط',
    'compliance' => 'الامتثال',
    'refund_policy' => 'سياسة الاسترداد',
    'accessibility' => 'إمكانية الوصول',
    'sitemap' => 'خريطة الموقع',

    // Region suggestion popup under the header's region pill.
    'region_suggest_body' => 'أنت ترى منتجات قابلة للاستخدام في :country. اعرض منتجات:',
    'region_suggest_switch' => 'تغيير',
    'region_suggest_dismiss' => 'تجاهل اقتراح المنطقة',

    // Header category rail and the phone menu's Mobile Refill page.
    'product_categories' => 'فئات المنتجات',

    // Region pill. :language is filled on the server; :country and :currency
    // in the browser, where the live values are.
    'region_pill_label' => 'المنطقة والعملة: :country، :language، :currency. تغيير',

    // Search panel. The type labels say what a result row IS.
    'search_no_results' => 'لا توجد نتائج لـ ":query"',
    'search_group_bill_payments' => 'دفع الفواتير',
    'search_group_suggestions' => 'اقتراحات',
    'search_type_gift_card' => 'بطاقة هدايا',
    'search_type_topup' => 'شحن رصيد الجوال',
    'search_type_bill' => 'دفع فاتورة',
    'search_type_esim' => 'eSIM',
    'search_type_page' => 'صفحة',

    // Account chip and the cart popup. :amount is a formatted price.
    'your_account' => 'حسابك',
    'cart_decrease' => 'إنقاص',
    'cart_increase' => 'زيادة',
    'cart_unit_card' => 'بطاقة بقيمة :amount',
    'cart_unit_esim' => 'eSIM بقيمة :amount',
    'cart_unit_topup' => 'شحن بقيمة :amount',
    'cart_unit_bill' => 'فاتورة بقيمة :amount',

    // Referral panel (the nav's Invite a Friend pill and the dashboard top bar).
    'referral_title' => 'ادعُ أصدقاءك واربح Rcoin',
    'referral_title_up_to' => 'ادعُ أصدقاءك واربح ما يصل إلى :amount من Rcoin',
    'referral_body_any_order' => 'شارك رابطك. تربح Rcoin عندما يُكمل شخص دعوته طلبًا. التسجيل وحده لا يمنحك شيئًا.',
    'referral_body_first_order' => 'شارك رابطك. تربح Rcoin عندما يُكمل شخص دعوته طلبه الأول. التسجيل وحده لا يمنحك شيئًا.',
    'referral_see_rewards' => 'اعرض مكافآت Rcoin الخاصة بك',
    'referral_sign_in' => 'سجّل الدخول للحصول على رابطك',
    'referral_new_here' => 'جديد هنا؟ :link وسيكون رابطك جاهزًا فورًا.',
    'referral_create_account' => 'أنشئ حسابًا',

    // Cookie banner. :site is the site name, :policy the Cookie Policy link.
    'cookie_consent' => 'الموافقة على ملفات تعريف الارتباط',
    'cookie_body' => 'نستخدم ملفات تعريف الارتباط لإبقائك مسجلًا للدخول وتأمين مدفوعاتك وتحسين :site. اطّلع على :policy.',
    'cookie_decline' => 'رفض',
    'cookie_accept' => 'قبول ملفات تعريف الارتباط',

    // Feedback widget.
    'feedback_region' => 'ملاحظات حول الموقع',
    'feedback_tab' => 'ملاحظات',
    'feedback_tab_label' => 'أرسل لنا ملاحظاتك. اسحب للتحريك أو استخدم مفاتيح الأسهم.',
    'feedback_drag' => 'اسحب للتحريك',
    'feedback_title' => 'إرسال ملاحظات',
    'feedback_email_placeholder' => 'بريدك الإلكتروني',
    'feedback_message_placeholder' => 'ملاحظاتك...',
    'feedback_send' => 'إرسال',
    'feedback_sending' => 'جارٍ الإرسال...',
    'feedback_thanks' => 'شكرًا على ملاحظاتك',
    'feedback_ticket_opened' => 'تم فتح تذكرة لدى الفريق.',
    'feedback_send_another' => 'إرسال ملاحظة أخرى',
    'feedback_security_failed' => 'فشل التحقق الأمني. يرجى المحاولة مرة أخرى.',

    // Currency names in the preferences sheet. The codes beside them are not copy.
    'currency_names' => [
        'USD' => 'الدولار الأمريكي',
        'EUR' => 'اليورو',
        'GBP' => 'الجنيه الإسترليني',
        'NGN' => 'النيرة النيجيرية',
        'XAF' => 'فرنك وسط أفريقيا',
        'ZAR' => 'الراند الجنوب أفريقي',
        'KES' => 'الشلن الكيني',
        'GHS' => 'السيدي الغاني',
        'EGP' => 'الجنيه المصري',
        'MAD' => 'الدرهم المغربي',
        'CAD' => 'الدولار الكندي',
        'AUD' => 'الدولار الأسترالي',
        'JPY' => 'الين الياباني',
        'CNY' => 'اليوان الصيني',
        'INR' => 'الروبية الهندية',
        'BRL' => 'الريال البرازيلي',
        'AED' => 'الدرهم الإماراتي',
        'SAR' => 'الريال السعودي',
        'TRY' => 'الليرة التركية',
        'CHF' => 'الفرنك السويسري',
    ],
];
