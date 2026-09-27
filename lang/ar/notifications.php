<?php

/**
 * Email and in-app notification copy.
 *
 * Rendered in the RECIPIENT language. User::preferredLocale() is what carries
 * that into a queued job, rather than the locale of whoever triggered it.
 */
return [
    'hello' => 'مرحبًا :name،',
    'regards' => 'شكرًا لك،',
    'order_confirmed_subject' => 'تم تأكيد طلبك',
    'order_confirmed_body' => 'لقد استلمنا دفعتك وطلبك في طريقه إليك.',
    'order_delivered_subject' => 'طلبك جاهز',
    'verify_email_subject' => 'أكد بريدك الإلكتروني',
    'verify_email_action' => 'تأكيد البريد',
    'reset_password_subject' => 'إعادة تعيين كلمة المرور',
    'reset_password_action' => 'إعادة تعيين كلمة المرور',
    'refund_issued_subject' => 'تم تنفيذ استرداد مبلغك',
    'no_reply' => 'هذه رسالة آلية، يرجى عدم الرد عليها.',
];
