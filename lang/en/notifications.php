<?php

/**
 * Email and in-app notification copy.
 *
 * Rendered in the RECIPIENT language. User::preferredLocale() is what carries
 * that into a queued job, rather than the locale of whoever triggered it.
 */
return [
    'hello' => 'Hello :name,',
    'regards' => 'Thanks,',
    'order_confirmed_subject' => 'Your order is confirmed',
    'order_confirmed_body' => 'We have received your payment and your order is on its way.',
    'order_delivered_subject' => 'Your order is ready',
    'verify_email_subject' => 'Confirm your email address',
    'verify_email_action' => 'Confirm email',
    'reset_password_subject' => 'Reset your password',
    'reset_password_action' => 'Reset password',
    'refund_issued_subject' => 'Your refund has been issued',
    'no_reply' => 'This is an automated message, please do not reply.',
];
