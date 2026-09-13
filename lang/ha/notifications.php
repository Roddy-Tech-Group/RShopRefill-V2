<?php

/**
 * Email and in-app notification copy.
 *
 * Rendered in the RECIPIENT language. User::preferredLocale() is what carries
 * that into a queued job, rather than the locale of whoever triggered it.
 */
return [
    'hello' => 'Sannu :name,',
    'regards' => 'Na gode,',
    'order_confirmed_subject' => 'An tabbatar da odarka',
    'order_confirmed_body' => 'Mun karɓi kuɗinka kuma odarka na kan hanya.',
    'order_delivered_subject' => 'Odarka ta shirya',
    'verify_email_subject' => 'Tabbatar da adireshin imel ɗinka',
    'verify_email_action' => 'Tabbatar da imel',
    'reset_password_subject' => 'Sake saita kalmar sirrinka',
    'reset_password_action' => 'Sake saita kalmar sirri',
    'refund_issued_subject' => 'An maido maka da kuɗinka',
    'no_reply' => 'Wannan saƙo ne na atomatik, kada ka amsa shi.',
];
