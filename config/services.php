<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'zendit' => [
        'base_url' => env('ZENDIT_BASE_URL'),
        'api_key' => env('ZENDIT_API_KEY'),
        'secret' => env('ZENDIT_SECRET'),
    ],

    'airalo' => [
        'base_url' => env('AIRALO_BASE_URL', 'https://sandbox-partners-api.airalo.com/v2'),
        'client_id' => env('AIRALO_CLIENT_ID'),
        'client_secret' => env('AIRALO_CLIENT_SECRET'),
        // Optional HMAC secret for webhook verification. Airalo only issues
        // partners a client_id + client_secret, so leaving this null is the
        // common case — the controller falls back to AIRALO_CLIENT_SECRET.
        // Only set this if you're on a custom Airalo signing arrangement.
        'webhook_secret' => env('AIRALO_WEBHOOK_SECRET'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
        'from_address' => env('MAIL_FROM_ADDRESS', 'noreply@rshoprefills.com'),
        'from_name' => env('MAIL_FROM_NAME', 'RshopRefill'),
        // Resend allows 10 requests/second per account; the throttle in front
        // of every send shares this ceiling across all queue workers.
        'max_requests_per_second' => (int) env('RESEND_MAX_REQUESTS_PER_SECOND', 8),
        'throttle_wait_ms' => (int) env('RESEND_THROTTLE_WAIT_MS', 5000),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Chatway live-chat widget (customer-facing pages only). The widget stays
    // off until the ID is set, so non-production environments render clean.
    'chatway' => [
        'widget_id' => env('CHATWAY_WIDGET_ID'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
        // Local-only escape hatch that skips the OAuth state check (never
        // honoured in production). Prefer fixing the redirect URI host.
        'stateless' => env('GOOGLE_OAUTH_STATELESS', false),
        // Google Analytics 4 measurement ID (gtag.js). Empty = analytics off,
        // so local/dev and any deploy without the key render no tag.
        'analytics_id' => env('GOOGLE_ANALYTICS_ID'),
    ],

    // Master switch allowing the payment providers to fall back to mock
    // credentials OUTSIDE local/testing. Defaults to false so production hard
    // fails on a missing real key instead of silently going mock. See
    // App\Domain\Payment\Support\MockMode.
    'payment_mock' => env('PAYMENT_MOCK', false),

    'flutterwave' => [
        'public_key' => env('FLW_PUBLIC_KEY'),
        'secret_key' => env('FLW_SECRET_KEY'),
        'encryption_key' => env('FLW_ENCRYPTION_KEY'),
        'webhook_hash' => env('FLW_WEBHOOK_HASH'),
    ],

    'korapay' => [
        'public_key' => env('KORAPAY_PUBLIC_KEY'),
        // Also the webhook signing key: Kora signs json_encode of the
        // webhook's `data` object (NOT the raw body) with HMAC-SHA256.
        'secret_key' => env('KORAPAY_SECRET_KEY'),
        'encryption_key' => env('KORAPAY_ENCRYPTION_KEY'),
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        // Also the webhook signing key: Paystack signs the raw body with
        // HMAC-SHA512 using the secret key, there is no separate webhook hash.
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        // Currencies the merchant account can charge for card / Apple Pay.
        // NGN by default; add USD here once Paystack approves international
        // USD collection (requires the domiciliary settlement account).
        'charge_currencies' => array_filter(array_map('trim', explode(',', (string) env('PAYSTACK_CHARGE_CURRENCIES', 'NGN')))),
    ],

    'nowpayments' => [
        'api_key' => env('NOWPAYMENTS_API_KEY'),
        'ipn_secret' => env('NOWPAYMENTS_IPN_SECRET'),
        'base_url' => env('NOWPAYMENTS_BASE_URL', 'https://api.nowpayments.io/v1'),

        // Sandbox uses a SEPARATE account, API key and IPN secret from production
        // (account-sandbox.nowpayments.io). Flipping this flag alone is not enough —
        // the credentials above must be the sandbox pair too.
        'sandbox' => (bool) env('NOWPAYMENTS_SANDBOX', false),
        'sandbox_base_url' => env('NOWPAYMENTS_SANDBOX_BASE_URL', 'https://api-sandbox.nowpayments.io/v1'),

        // Sandbox-only outcome simulator, passed as `case` on payment creation.
        // One of: success, common, failed, partially_paid. Ignored in production.
        'sandbox_case' => env('NOWPAYMENTS_SANDBOX_CASE'),

        // ZERO underpayment tolerance is a deliberate business rule, not a default
        // we inherited: RshopRefill margins are often cents, so absorbing even a
        // 1% shortfall can erase the profit on an order. A payment is settled only
        // when actually_paid >= pay_amount, compared in CRYPTO terms. Anything less
        // is held as PartiallyPaid for the top-up flow and is never auto-fulfilled.
        // The single exception is dust: a shortfall under half a cent in the price
        // currency, which the top-up flow itself rounds to 0.00 and cannot charge.
        // See NowPaymentsProvider::DUST_CEILING; it is a code constant, not config,
        // and there is no percentage tolerance anywhere.
        //
        // Compare in crypto, never fiat: NOWPayments derives partially_paid from the
        // fiat equivalent at settlement, so a customer who sends exactly the right
        // crypto can still be flagged short if the market moved mid-confirmation.
        // Requesting a fixed rate plus comparing crypto-to-crypto removes that class
        // of false shortfall entirely.
        'fixed_rate' => (bool) env('NOWPAYMENTS_FIXED_RATE', true),
        'fee_paid_by_user' => (bool) env('NOWPAYMENTS_FEE_PAID_BY_USER', true),
    ],

    // Smile ID: identity (KYC) verification for the Tier 2 step-up. Every
    // customer gets Global Document Verification (worldwide, full searchable
    // country list in the hosted widget); per-country Biometric KYC routing is
    // available but currently disabled (see biometric_countries below).
    // Prepaid pay-as-you-go, one account.
    'smileid' => [
        'partner_id' => env('SMILE_PARTNER_ID'),
        'api_key' => env('SMILE_API_KEY'),
        // Smile's sid_server convention: 0 = sandbox, 1 = production.
        'server' => (int) env('SMILE_SERVER', 0),
        'callback_url' => env('SMILE_CALLBACK_URL'),
        // Smile ResultCodes accepted as "identity verified". Default is the KYC
        // exact-match code; confirm/extend against Smile's result-code docs and
        // sandbox before switching KYC enforcement on.
        'approved_result_codes' => ['1012'],
        // Countries routed to Biometric KYC (government ID + selfie match)
        // instead of Global Document Verification. EMPTY by CTO decision
        // (2026-08-16): every customer gets the worldwide Document Verification
        // flow, whose hosted widget lists ALL countries with search - one
        // consistent experience, $0.80 flat. Smile's biometric coverage per the
        // fee schedule is NG, GH, KE, ZA (NIN $0.50 / GH KE $0.75 / ZA $1.30);
        // repopulate this list to re-enable per-country biometric routing once
        // NIN/BVN are approved on the Smile account and the stronger/cheaper
        // check is preferred over the uniform UX.
        'biometric_countries' => [],
        // Optional: pin the hosted widget's country -> ID-type list (also
        // de-duplicates its country menu). Only list ID types ENABLED on the
        // Smile partner account, or their jobs will fail. Example once NIN/BVN
        // are enabled: ['NG' => ['NIN_V2', 'BVN', 'VOTER_ID']]. Null = let the
        // widget offer everything the account supports.
        'id_selection' => null,
    ],

    'trustpilot' => [
        // Public IDs from the Trustpilot widget snippet (Share & promote -> Widgets).
        // Safe to commit defaults since the same values would render in HTML anyway.
        'business_unit_id' => env('TRUSTPILOT_BUSINESS_UNIT_ID', '6a09a40f1ccabec3e9c1b491'),
        'review_collector_template_id' => env('TRUSTPILOT_REVIEW_COLLECTOR_TEMPLATE_ID', '56278e9abfbbba0bdcd568bc'),
        'review_collector_token' => env('TRUSTPILOT_REVIEW_COLLECTOR_TOKEN', 'cfe1b67e-5fc4-4d20-9666-cc4ca9b37b87'),
        'profile_url' => env('TRUSTPILOT_PROFILE_URL', 'https://www.trustpilot.com/review/rshoprefill.com'),
        'short_link' => env('TRUSTPILOT_SHORT_LINK', 'https://trstp.lt/WMNWs7ZKQx'),
        'locale' => env('TRUSTPILOT_LOCALE', 'en-US'),
    ],

    'google_reviews' => [
        // Direct review URL from Google Business Profile -> "Get more reviews".
        // Used for the "Review us on Google" CTA button on /reviews.
        'review_url' => env('GOOGLE_REVIEW_URL', 'https://g.page/r/CZeYrL5rBXvuEAE/review'),
        // QR code rendered next to the CTA so desktop visitors can scan with
        // their phone. File lives in public/assets.
        'qr_asset' => env('GOOGLE_REVIEW_QR_ASSET', 'google reviews qr cord.webp'),
        // Optional: Place ID + Places API key for fetching live Google reviews
        // (paths 2/3 in the integration plan). Leave null until set up.
        'place_id' => env('GOOGLE_PLACE_ID'),
        'places_api_key' => env('GOOGLE_PLACES_API_KEY'),

        // Google Customer Reviews program (Merchant Center -> Merchant programs
        // -> Customer Reviews -> Set up integration). The merchant ID drives both
        // the order-confirmation opt-in survey and the floating seller-rating
        // badge; empty = both stay off, so dev/staging render nothing. Safe to
        // commit the default since the same ID renders in the page JS anyway
        // (same rationale as the Trustpilot IDs above).
        'merchant_id' => env('GOOGLE_REVIEWS_MERCHANT_ID', '5808056900'),
        'badge_enabled' => env('GOOGLE_REVIEWS_BADGE_ENABLED', true),
        // BOTTOM_LEFT keeps the badge clear of the bottom-right live-chat widget.
        'badge_position' => env('GOOGLE_REVIEWS_BADGE_POSITION', 'BOTTOM_LEFT'),
    ],

    'turnstile' => [
        'enabled' => env('TURNSTILE_ENABLED', false),
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'enforce_checkout' => env('TURNSTILE_ENFORCE_CHECKOUT', true),
        'enforce_auth' => env('TURNSTILE_ENFORCE_AUTH', true),
        'enforce_contact' => env('TURNSTILE_ENFORCE_CONTACT', true),
        'bypass_local' => env('TURNSTILE_BYPASS_LOCAL', true),
    ],

];
