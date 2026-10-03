<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mobile API (/v2)
    |--------------------------------------------------------------------------
    |
    | The token-authenticated API the Expo app talks to. Entirely separate from
    | the storefront's /api routes, which authenticate a browser session cookie
    | and are not versioned.
    |
    */

    'v2' => [

        /*
        | Reported by GET /v2/meta so the app can show it in a diagnostics
        | screen and so a bug report says which contract it was speaking.
        */
        'version' => '2.0.0',

        /*
        | The oldest app build allowed to talk to /v2. An app cannot be
        | recalled: people run last month's build for months, so when a
        | contract changes or a bad build ships this is the only way to stop
        | it. Raise it and older builds are refused with 426 and a message
        | telling the customer to update.
        |
        | A BUILD NUMBER, not a version string. Expo increments it on every
        | build and integers compare without a parser to get wrong.
        |
        | 0 disables the check.
        */
        'minimum_app_build' => (int) env('API_V2_MINIMUM_APP_BUILD', 0),

        /*
        | Access token lifetime. Short on purpose: this is the token sent on
        | every single request, so it is the one most likely to leak, and a
        | leaked one has to become worthless quickly. The app trades its
        | refresh token for a new one without the customer noticing.
        */
        'access_token_minutes' => (int) env('API_V2_ACCESS_TOKEN_MINUTES', 60),

        /*
        | Refresh token lifetime, and therefore how long a phone can sit
        | unused before its owner has to sign in again. Long, because nobody
        | tolerates an app that logs them out weekly, which is exactly why it
        | is single-use and rotated on every exchange.
        */
        'refresh_token_days' => (int) env('API_V2_REFRESH_TOKEN_DAYS', 30),

        /*
        | How long the app has to finish a device-verification challenge after
        | a login that needed an email code. Comfortably longer than the
        | ten-minute code so the failure the customer sees is "that code has
        | expired", not "your session went away".
        */
        'challenge_minutes' => (int) env('API_V2_CHALLENGE_MINUTES', 20),

        /*
        | Grace period after a refresh token is exchanged, during which
        | presenting that SAME token again returns the same new pair instead
        | of being treated as a replay.
        |
        | This exists because of the networks our customers are on. The app
        | sends its refresh token, we rotate and answer, and the answer is
        | lost in a tunnel. The app retries with the only token it still has,
        | the one we just consumed. Without a grace window that innocent
        | retry looks exactly like a stolen token being replayed, and the
        | customer is signed out of a working app for no reason.
        |
        | Outside the window, a consumed token really is treated as theft and
        | the device's whole family is revoked. Short, because the cost of
        | the window is that a stolen token is briefly still worth something.
        */
        'refresh_replay_seconds' => (int) env('API_V2_REFRESH_REPLAY_SECONDS', 60),

        /*
        | Idempotency, for the endpoints that move money.
        |
        | ttl_hours  how long a remembered answer stays replayable. 24 hours,
        |            the window Stripe made the convention: long enough to
        |            cover a phone that was offline, short enough that the
        |            table stays small.
        | lock_seconds  how long a first attempt is believed to still be
        |            running. A concurrent retry inside this window is told
        |            so; after it, the attempt is presumed dead and the retry
        |            may take over. Must comfortably exceed the slowest
        |            gateway call on the request path, or a legitimate slow
        |            checkout could be taken over while still running.
        | max_body_bytes  a response larger than this is not stored, and the
        |            key is released so a retry still works. A money response
        |            this large is a bug; the cap stops it becoming a
        |            database problem as well.
        */
        'idempotency' => [
            'ttl_hours' => (int) env('API_V2_IDEMPOTENCY_TTL_HOURS', 24),
            'lock_seconds' => (int) env('API_V2_IDEMPOTENCY_LOCK_SECONDS', 120),
            'max_body_bytes' => (int) env('API_V2_IDEMPOTENCY_MAX_BODY_BYTES', 262144),
        ],
    ],

];
