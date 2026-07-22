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
];
