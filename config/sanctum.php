<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Sanctum
|--------------------------------------------------------------------------
|
| Sanctum is here for ONE reason: the mobile app. It authenticates the /v2
| API with bearer tokens, because a phone has no browser cookie jar to carry
| a session for it the way every existing /api route relies on.
|
| It does NOT touch how the website signs anyone in. The 'web' and 'admin'
| session guards are untouched, and the existing /api group keeps
| authenticating the same session cookie it always has.
|
| Sanctum also ships a browser-SPA mode (cookie based, CSRF, stateful
| domains). We do not use it. The settings that belong to it are left below
| because the package reads them, and are marked as unused so nobody wires
| them up by accident.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains (UNUSED)
    |--------------------------------------------------------------------------
    |
    | Part of the browser-SPA mode. Only consulted by
    | EnsureFrontendRequestsAreStateful, which is not in any middleware group.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Fallback Guards
    |--------------------------------------------------------------------------
    |
    | Guards Sanctum tries BEFORE it looks at the bearer token. Deliberately
    | empty: the token is the only thing that authenticates /v2.
    |
    | Sanctum's default is ['web'], which would let a browser session cookie
    | authenticate a /v2 request and return a user with no token behind it.
    | Every ability check, every per-device revocation and every token expiry
    | would be silently skipped for that request. /v2 is stateless by design,
    | so today there is no session for it to find, but the default would make
    | adding session middleware later a security change disguised as a tidy-up.
    |
    */

    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | MUST stay null. Read Guard::isValidAccessToken before changing it: this
    | value is a CEILING applied to every token, ANDed with the token's own
    | expires_at, not a default for tokens that have none.
    |
    | Setting it to the access-token lifetime would therefore also expire the
    | refresh tokens, and the app would sign customers out on the same clock
    | it was supposed to be protected from. Lifetimes are set per token at
    | creation instead: a short access token, a long refresh token.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Every token we issue starts with this, so a token pasted into a public
    | repository, an issue or a log is recognisable at a glance and by the
    | secret-scanning services that watch for known prefixes.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'rsr_'),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware (UNUSED)
    |--------------------------------------------------------------------------
    |
    | Part of the browser-SPA mode, as above.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sanctum's Own Routes
    |--------------------------------------------------------------------------
    |
    | Off. The only route Sanctum registers for itself is
    | GET /sanctum/csrf-cookie, which exists for the browser-SPA mode. A
    | phone sending a bearer token never calls it, and an endpoint nobody
    | uses is still an endpoint somebody can probe.
    |
    */

    'routes' => false,

];
