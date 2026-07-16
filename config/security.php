<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Device Trust Protection Window
    |--------------------------------------------------------------------------
    |
    | The number of hours a newly trusted device remains restricted from
    | performing sensitive financial operations (withdrawals, card creation,
    | disabling 2FA, changing PIN/password).
    |
    */
    'device_restriction_hours' => env('SECURITY_DEVICE_RESTRICTION_HOURS', 24),
    
    /*
    |--------------------------------------------------------------------------
    | Security Score Configuration
    |--------------------------------------------------------------------------
    |
    | Defines the maximum score and the points awarded for various security
    | measures taken by the user.
    |
    */
    'score' => [
        'base' => 20,
        'email_verified' => 10,
        'phone_verified' => 10,
        'has_transaction_pin' => 10,
        'two_factor_enabled' => 30,
        'email_otp_always' => 20,
        'email_otp_new_device' => 10,
        'no_old_devices' => 10,
    ],
];
