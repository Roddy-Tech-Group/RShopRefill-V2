<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Should we offer the visitor a different product region than the one in force?
 *
 * The catalogue is region-locked: ResolveRegion decides which country's
 * products are shown, from ?country= then the `region` cookie then US. A
 * visitor browsing from Cameroon therefore lands on the US catalogue by
 * default and has no reason to suspect another one exists.
 *
 * This class answers one question - is there a better region to offer - and
 * deliberately does nothing about it. Applying a region stays with the locale
 * modal, which owns country, language and currency together.
 *
 * Detection is Cloudflare's CF-IPCountry header. It is trusted only as far as
 * it can be validated: the header is set by the edge and cannot be forged by
 * the client on a Cloudflare-fronted origin, but it carries sentinel values
 * for cases where Cloudflare itself does not know, and any of those must be
 * treated as "no answer" rather than as a country.
 */
class RegionSuggestion
{
    /**
     * Values Cloudflare sends that are not countries.
     *
     * XX  country could not be determined
     * T1  the request came through Tor
     * EU / AP  regional groupings, not countries, sent for some IP ranges
     */
    private const NON_COUNTRIES = ['XX', 'T1', 'EU', 'AP'];

    /**
     * The visitor's country, or null when it cannot be established.
     *
     * Null covers every failure mode on purpose - header absent (not behind
     * Cloudflare, or local development), a sentinel value, a malformed code,
     * or a real code the storefront has no catalogue for. The caller shows
     * nothing in all of those cases rather than guessing.
     */
    public static function detect(?Request $request = null): ?string
    {
        $request ??= request();

        $code = strtoupper(trim((string) $request->header('CF-IPCountry', '')));

        if ($code === '' || in_array($code, self::NON_COUNTRIES, true)) {
            return null;
        }

        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            return null;
        }

        return self::isSupported($code) ? $code : null;
    }

    /** Is this ISO code one the storefront actually has a region for? */
    public static function isSupported(string $iso): bool
    {
        return in_array(strtoupper($iso), self::supportedCodes(), true);
    }

    /**
     * The display name for an ISO code, from the same config the locale modal
     * and the gift-card filters read, so a country is never named two ways.
     */
    public static function nameFor(string $iso): ?string
    {
        $iso = strtoupper($iso);

        foreach ((array) config('countries.codes', []) as $name => $code) {
            if (strtoupper((string) $code) === $iso) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The suggestion to make, or null for none.
     *
     * Returns null when detection failed, when the visitor is already on their
     * own region, or when the active region is not one we recognise. The last
     * case matters: suggesting a switch away from a region we cannot name
     * would produce a sentence with a blank in it.
     *
     * @return array{iso: string, name: string, current: string}|null
     */
    public static function forRegion(string $activeRegion, ?Request $request = null): ?array
    {
        $detected = self::detect($request);

        if ($detected === null) {
            return null;
        }

        $active = strtoupper(trim($activeRegion));

        if ($detected === $active) {
            return null;
        }

        $detectedName = self::nameFor($detected);
        $activeName = self::nameFor($active);

        if ($detectedName === null || $activeName === null) {
            return null;
        }

        return [
            'iso' => $detected,
            'name' => $detectedName,
            'current' => $activeName,
        ];
    }

    /** @return list<string> */
    private static function supportedCodes(): array
    {
        return array_values(array_map(
            fn ($c) => strtoupper((string) $c),
            (array) config('countries.codes', [])
        ));
    }
}
