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

];
