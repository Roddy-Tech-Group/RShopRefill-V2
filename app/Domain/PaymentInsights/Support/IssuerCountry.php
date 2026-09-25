<?php

namespace App\Domain\PaymentInsights\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns whatever the gateway calls a card's country into an ISO 3166-1
 * alpha-2 code, or nothing at all.
 *
 * Flutterwave does not promise a code. The `card.country` field on a listed
 * charge has arrived as a code ("US"), as a full name ("UNITED STATES"), and
 * as "UNKNOWN". We used to store `substr($raw, 0, 2)` to fit the two-character
 * column, which silently filed a name under its first two letters: IRELAND
 * became "IR" (Iran), SWEDEN became "SW" (nothing at all), EL SALVADOR became
 * "EL" (nothing at all). Production declines on 2026-09-16 carried "UN", "SW"
 * and "EL", none of which are assigned codes, and one of them nearly went to
 * the CEO as an Iranian card.
 *
 * A wrong country is worse than no country: it is the field we would route
 * global cards on, and the one a fraud report is read from. So anything that
 * cannot be resolved with certainty is stored as null and logged, rather than
 * guessed.
 */
final class IssuerCountry
{
    /**
     * Values that explicitly mean "the gateway does not know".
     *
     * @var list<string>
     */
    private const UNKNOWN_MARKERS = ['unknown', 'unspecified', 'n/a', 'na', 'none', 'null', 'nil', '-', '--'];

    /**
     * Names the country table does not carry under this spelling.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'usa' => 'US',
        'u.s.a.' => 'US',
        'u.s.' => 'US',
        'united states of america' => 'US',
        'uk' => 'GB',
        'u.k.' => 'GB',
        'great britain' => 'GB',
        'england' => 'GB',
        'scotland' => 'GB',
        'wales' => 'GB',
        'northern ireland' => 'GB',
        'republic of ireland' => 'IE',
        'south korea' => 'KR',
        'republic of korea' => 'KR',
        'north korea' => 'KP',
        'ivory coast' => 'CI',
        "cote d'ivoire" => 'CI',
        'cote divoire' => 'CI',
        'drc' => 'CD',
        'democratic republic of the congo' => 'CD',
        'uae' => 'AE',
        'czechia' => 'CZ',
        'holland' => 'NL',
        'turkiye' => 'TR',
        'swaziland' => 'SZ',
        'burma' => 'MM',
        'macedonia' => 'MK',
        'cabo verde' => 'CV',
    ];

    /**
     * The gateway's answer as an alpha-2 code, or null when it cannot be
     * resolved. Never returns a code the ISO list does not assign.
     */
    public static function normalise(?string $raw): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $raw) ?? '');

        if ($value === '') {
            return null;
        }

        $key = mb_strtolower($value);

        if (in_array($key, self::UNKNOWN_MARKERS, true)) {
            return null;
        }

        // Already a code, but only when ISO actually assigns it. "SW" and "UN"
        // look like codes and are not, which is exactly how the truncation bug
        // stayed invisible.
        if (preg_match('/^[a-z]{2}$/', $key) === 1 && in_array(strtoupper($key), self::assignedCodes(), true)) {
            return strtoupper($key);
        }

        $code = self::namesToCodes()[$key] ?? self::ALIASES[$key] ?? null;

        if ($code !== null) {
            return $code;
        }

        // Logged rather than guessed: the gateway's vocabulary is the only way
        // to learn what else it sends, and a wrong code is worse than none.
        Log::info('payment.issuer_country_unrecognised', ['raw' => Str::limit($value, 60, '')]);

        return null;
    }

    /**
     * Every alpha-2 code ISO assigns, from the country table.
     *
     * @return list<string>
     */
    private static function assignedCodes(): array
    {
        return array_values(config('countries.codes', []));
    }

    /**
     * Lowercased country name to alpha-2 code.
     *
     * @return array<string, string>
     */
    private static function namesToCodes(): array
    {
        $map = [];

        foreach (config('countries.codes', []) as $name => $code) {
            $map[mb_strtolower((string) $name)] = $code;
        }

        return $map;
    }
}
