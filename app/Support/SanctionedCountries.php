<?php

namespace App\Support;

/**
 * Jurisdictions under comprehensive sanctions, where no account is opened.
 *
 * RShopRefill takes payments through regulated providers and is listed on the
 * App Store and Google Play, all of which bar these places outright. The list
 * is the comprehensive programmes only (Cuba, Iran, North Korea, Syria); a
 * country under sectoral sanctions is a business decision, not this list's.
 * Regions without a country code of their own (occupied Crimea, Donetsk and
 * Luhansk) cannot be refused by country and are not here.
 *
 * The country config keeps every country, so a card issuer or a location is
 * still named correctly; only where an account's country is chosen (the phone
 * field's dial code, PhoneCountries) leaves these out. The mobile app mirrors
 * this list (rshoprefill-mobileapp, src/lib/phone/countries.ts).
 */
final class SanctionedCountries
{
    /** ISO 3166-1 alpha-2. */
    public const CODES = ['CU', 'IR', 'KP', 'SY'];

    public static function includes(string $iso): bool
    {
        return in_array(strtoupper($iso), self::CODES, true);
    }
}
