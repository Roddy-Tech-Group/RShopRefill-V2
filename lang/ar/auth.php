<?php

/**
 * Sign-in, registration and account security screens.
 *
 * The framework auth keys (failed / password / throttle) live here too, under
 * the names Laravel looks up. Putting them anywhere else means a failed login
 * falls back to English whatever language the customer chose.
 */
return [
    'failed' => 'بيانات الدخول هذه لا تطابق سجلاتنا.',
    'password' => 'كلمة المرور المدخلة غير صحيحة.',
    'throttle' => 'محاولات تسجيل دخول كثيرة. يرجى المحاولة مجددًا بعد :seconds ثانية.',
    'sign_in' => 'تسجيل الدخول',
    'sign_up' => 'التسجيل',
    'create_account' => 'إنشاء حساب',
    'email' => 'البريد الإلكتروني',
    'password_label' => 'كلمة المرور',
    'confirm_password' => 'تأكيد كلمة المرور',
    'full_name' => 'الاسم الكامل',
    'first_name' => 'الاسم الأول',
    'last_name' => 'اسم العائلة',
    'referral_code' => 'رمز الإحالة',
    'forgot_password' => 'نسيت كلمة المرور؟',
    'remember_me' => 'تذكرني',
    'continue_with_google' => 'المتابعة عبر Google',
    'or' => 'أو',
    'agree_to_terms' => 'أوافق على :terms و :privacy.',
    'agree_required' => 'يرجى قبول شروط الخدمة وسياسة الخصوصية للمتابعة.',
    'privacy_note' => 'معلوماتك محمية وتُعالج وفقًا لـ :privacy',
    'password_requirements' => '8 أحرف على الأقل، بما في ذلك رقم واحد.',
    'password_min_length' => '8 أحرف على الأقل',
    'password_has_number' => 'تتضمن رقمًا',
    'brand_tagline' => 'عالمك الرقمي كله في مكان واحد.',
    'brand_subtitle' => 'بطاقات هدايا، وشرائح eSIM، وشحن رصيد، ودفع فواتير.',

    // The sign-in / register modal (livewire/auth/auth-modal). :site is the
    // site name and :email the address just registered - neither is translated.
    'modal_log_in' => 'تسجيل الدخول',
    'modal_register' => 'التسجيل',
    'modal_welcome_aboard' => 'مرحبًا بك معنا',
    'modal_join' => 'انضم إلى :site وابدأ التسوق فورًا',
    'modal_welcome_back' => 'مرحبًا بعودتك',
    'modal_sign_in_to' => 'سجّل الدخول إلى حسابك في :site',
    'modal_email' => 'البريد الإلكتروني',
    'modal_show_password' => 'إظهار كلمة المرور',
    'modal_hide_password' => 'إخفاء كلمة المرور',
    'modal_signing_in' => 'جارٍ تسجيل الدخول...',
    'modal_no_account' => 'ليس لديك حساب؟',
    'modal_create_one' => 'أنشئ حسابًا',
    'modal_step_of' => 'الخطوة :step من :total',
    'modal_step_details' => 'بياناتك',
    'modal_step_security' => 'الأمان',
    'modal_step_finish' => 'الإنهاء',
    'modal_full_name' => 'الاسم الكامل',
    'modal_name_placeholder' => 'اسمك',
    'modal_phone' => 'رقم الهاتف',
    'modal_dial_code' => 'اختر رمز الاتصال الدولي',
    'modal_search_country' => 'ابحث عن دولة أو رمز',
    'modal_no_match' => 'لا توجد نتائج مطابقة',
    'modal_password_hint' => 'أكثر من 8 أحرف تتضمن أحرفًا كبيرة وصغيرة وأرقامًا ورمزًا خاصًا واحدًا على الأقل',
    'modal_confirm_password' => 'تأكيد كلمة المرور',
    'modal_gender' => 'الجنس',
    'modal_gender_male' => 'ذكر',
    'modal_gender_female' => 'أنثى',
    'modal_gender_other' => 'آخر',
    'modal_optional' => '(اختياري)',
    'modal_referral_placeholder' => 'أدخل رمز الإحالة الخاص بصديقك',
    'modal_terms_consent' => '* أقر بأنني قرأت :terms و:privacy وأوافق عليهما',
    'modal_terms_link' => 'شروط الاستخدام',
    'modal_privacy_link' => 'إشعار الخصوصية',
    'modal_newsletter' => 'لا تفوّت إصدارنا القادم من القسائم! اشترك لتصلك خصومات حصرية ورموز ترويجية',
    'modal_create_account' => 'إنشاء حساب',
    'modal_creating_account' => 'جارٍ إنشاء الحساب...',
    'modal_have_account' => 'لديك حساب بالفعل؟',
    'modal_account_created' => 'تم إنشاء الحساب',
    'modal_verification_sent' => 'أرسلنا رابط تحقق إلى :email. أكّد بريدك الإلكتروني لتبدأ الطلب. يمكنك التصفح في هذه الأثناء.',
    'modal_no_email' => 'لم يصلك البريد؟',
    'modal_resend' => 'أعد إرساله',
    'modal_sending' => 'جارٍ الإرسال...',
    'modal_resent' => 'رابط تحقق جديد في الطريق إليك.',
    'modal_resend_wait' => 'يرجى الانتظار قليلًا قبل طلب رابط آخر.',
    'modal_continue_account' => 'المتابعة إلى حسابي',

    // Errors the modal's server side raises.
    'registrations_closed' => 'التسجيلات الجديدة مغلقة مؤقتًا.',
    'phone_taken' => 'رقم الهاتف هذا مسجل بالفعل. جرّب تسجيل الدخول بدلًا من ذلك.',
    'security_unavailable' => 'التحقق الأمني غير متاح مؤقتًا. يرجى المحاولة مرة أخرى.',
    'security_failed' => 'فشل التحقق الأمني. حدّث الصفحة وحاول مرة أخرى.',

    // Field names for the modal's validation messages, so an error names the
    // field rather than the Livewire property behind it.
    'modal_attributes' => [
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'country' => 'الدولة',
        'phone' => 'رقم الهاتف',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'gender' => 'الجنس',
        'terms' => 'الشروط',
        'referral' => 'رمز الإحالة',
    ],
];
