<?php

return [

    /*
    |--------------------------------------------------------------------------
    | VAPID Keys
    |--------------------------------------------------------------------------
    |
    | The VAPID protocol allows push services to identify your application
    | without requiring a Firebase or third-party account. You can generate
    | these keys using: php artisan webpush:generate-keys
    |
    */

    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:support@rshoprefill.com'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE', 'storage/app/vapid.pem'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Appearance
    |--------------------------------------------------------------------------
    |
    | What a push notification looks like when the sender does not say. The
    | icon is the mark the browser shows beside the message; the badge is the
    | small monochrome silhouette Android puts in the status bar.
    |
    | The badge is null on purpose. We have no monochrome mark drawn, and
    | pointing at a file that does not exist is worse than omitting it: left
    | out, the browser chooses its own. Set it once a badge asset exists.
    |
    */

    'notification' => [
        'icon' => env('WEBPUSH_ICON', '/assets/icon-192.png'),
        'badge' => env('WEBPUSH_BADGE'),
    ],

];
