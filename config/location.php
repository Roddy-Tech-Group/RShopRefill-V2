<?php

use Stevebauman\Location\Drivers\MaxMind;
use Stevebauman\Location\Position;

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | A LOCAL MaxMind GeoLite2 database file, read straight off disk.
    |
    | The package default is IpApi (ip-api.com), and production ran on it from
    | July to September 2026 with no token, which meant three things we did not
    | want: their free endpoint is licensed for non-commercial use only, it is
    | plain HTTP so every customer IP left our server unencrypted to a third
    | party we never declared as a processor, and it is capped at 45 requests a
    | minute per source IP, so a traffic spike would have silently stopped
    | country resolution with a 429. It also sat on the login critical path,
    | one blocking HTTP round trip before the customer got in.
    |
    | A local .mmdb has none of those properties: no outbound request, no rate
    | limit, no third party, and a lookup measured in microseconds. The file is
    | refreshed by `location:update` (see routes/console.php).
    |
    */

    'driver' => MaxMind::class,

    /*
    |--------------------------------------------------------------------------
    | Driver Fallbacks
    |--------------------------------------------------------------------------
    |
    | Deliberately empty. Every fallback the package ships is a third-party
    | HTTP service, so falling back would reintroduce exactly the licensing,
    | privacy and latency problems the local driver exists to remove.
    |
    | A missing or corrupt database file therefore resolves to "unknown", which
    | every caller already treats as "do not guess". IpLocator raises a Sev2
    | when the file is absent so the silence is never silent to us.
    |
    */

    'fallbacks' => [],

    /*
    |--------------------------------------------------------------------------
    | Position
    |--------------------------------------------------------------------------
    */

    'position' => Position::class,

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Options
    |--------------------------------------------------------------------------
    |
    | Unused by the local driver, kept tight so that if a future driver ever
    | does speak HTTP it cannot hold a login open for six seconds.
    |
    */

    'http' => [
        'timeout' => 2,
        'connect_timeout' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Localhost Testing
    |--------------------------------------------------------------------------
    |
    | OFF by default, which inverts the package default of true.
    |
    | When enabled, the package silently swaps any local/private IP for a
    | Google host in the United States, so a request that genuinely has no
    | resolvable location comes back as a confident "United States". On a
    | platform whose login risk rule compares countries, a fabricated country
    | is worse than no country: it reads as a real move between two places.
    | Opt in explicitly with LOCATION_TESTING=true when probing locally.
    |
    */

    'testing' => [
        'ip' => '66.102.0.0',
        'enabled' => env('LOCATION_TESTING', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | MaxMind Configuration
    |--------------------------------------------------------------------------
    |
    | Web service off: we read the local file. The City edition is used rather
    | than Country because the device registry stores city and timezone too,
    | and shows them to customers on their own device list.
    |
    | MAXMIND_LICENSE_KEY is a free GeoLite2 key from maxmind.com. Without it
    | `location:update` cannot fetch the file, but a file already on disk keeps
    | working, so an expired key degrades to a stale database, not an outage.
    |
    */

    'maxmind' => [
        'license_key' => env('MAXMIND_LICENSE_KEY'),

        // MaxMind calls this the Account ID on their site; the package and the
        // older docs call it the user id. Both names are read so the value in
        // .env can match the label the person copying it is looking at.
        'account_id' => env('MAXMIND_ACCOUNT_ID', env('MAXMIND_USER_ID')),

        'web' => [
            'enabled' => false,
            'user_id' => env('MAXMIND_ACCOUNT_ID', env('MAXMIND_USER_ID')),
            'locales' => ['en'],
            'options' => ['host' => 'geoip.maxmind.com'],
        ],

        'local' => [
            'type' => 'city',
            'path' => database_path('maxmind/GeoLite2-City.mmdb'),

            // MAXMIND_DOWNLOAD_URL is an escape hatch: MaxMind has moved this
            // endpoint before and may again, and a changed URL should not need
            // a deploy to fix. All three of their current shapes answer 401 to
            // a bad key, so a 401 here means the key is wrong, not the URL.
            'url' => env('MAXMIND_DOWNLOAD_URL') ?: sprintf(
                'https://download.maxmind.com/app/geoip_download_by_token?edition_id=GeoLite2-City&license_key=%s&suffix=tar.gz',
                env('MAXMIND_LICENSE_KEY'),
            ),
        ],
    ],

];
