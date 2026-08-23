<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Admin Notification Target
    |--------------------------------------------------------------------------
    |
    | The email address that receives system alerts, order notifications,
    | and fraud detection warnings. Must match a user in the database.
    |
    */
    'admin_email' => env('ADMIN_NOTIFICATION_EMAIL', 'dev@roddytechgroup.com'),

    /*
    |--------------------------------------------------------------------------
    | Undeliverable Email Domains
    |--------------------------------------------------------------------------
    |
    | RFC 2606 reserved domains and other well-known undeliverable suffixes.
    | Resend rejects these with a 422 which, unguarded, retries three times,
    | fills the failed_jobs table, and fires a Sentry alert for an address
    | that can never receive mail. EmailChannel suppresses sends to them and
    | NewsletterService refuses subscriptions from them.
    |
    | Comma-separated so an env override can extend or (as phpunit.xml does,
    | because the whole suite fakes with @example.com addresses) blank it.
    |
    */
    'undeliverable_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'MAIL_UNDELIVERABLE_DOMAINS',
            'example.com,example.org,example.net,test.com,test.org,test.net,invalid,localhost'
        ))
    ))),
];
