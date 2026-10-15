<?php

namespace App\Support;

use Illuminate\Support\Str;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * Builds the country list for the registration phone input: ISO code, display
 * name, international dialling code, and flag emoji, for every country in
 * config/countries.php.
 *
 * The country dataset lives in one place (config/countries.php). Dial codes are
 * derived from libphonenumber so they never drift from the numbers the Phone
 * validation rule accepts, and flag emoji are computed from the ISO code, so
 * this list needs no hand-maintained dial/flag columns.
 */
final class PhoneCountries
{
    /** @var list<array{iso: string, name: string, dial: string, flag: string, example: string}>|null */
    private static ?array $cache = null;

    /**
     * The default country pre-selected in the input when the modal loads;
     * callers can override with a geo-detected code.
     */
    public const DEFAULT_ISO = 'US';

    /**
     * Every dialable country an account may be opened in, alphabetical by name.
     *
     * @return list<array{iso: string, name: string, dial: string, flag: string, example: string}>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $util = PhoneNumberUtil::getInstance();
        $rows = [];

        foreach ((array) config('countries.codes', []) as $name => $iso) {
            $iso = strtoupper((string) $iso);

            // No account is opened under comprehensive sanctions. Leaving them
            // out here leaves them out of every dial picker and every
            // Rule::in(isoCodes()) that validates an account's country.
            if (SanctionedCountries::includes($iso)) {
                continue;
            }

            $dial = $util->getCountryCodeForRegion($iso);

            // A 0 dial code means libphonenumber has no numbering plan for the
            // region (e.g. Antarctica); it cannot hold a valid phone, so drop it.
            if ($dial === 0) {
                continue;
            }

            $rows[$iso] = [
                'iso' => $iso,
                'name' => (string) $name,
                'dial' => (string) $dial,
                'flag' => self::flag($iso),
                // The national significant number of a sample mobile line (no
                // trunk 0), e.g. "8031234567" for NG, used as the input's
                // placeholder so the customer sees the expected length/shape.
                'example' => self::exampleNationalNumber($util, $iso),
            ];
        }

        $rows = array_values($rows);

        // Alphabetical by name. Compare the ASCII-folded, case-insensitive name
        // so accented countries (e.g. "Cote d'Ivoire") sort where a reader
        // expects rather than after the plain-ASCII names.
        usort($rows, static fn (array $a, array $b): int => strcasecmp(
            Str::ascii($a['name']),
            Str::ascii($b['name']),
        ));

        return self::$cache = $rows;
    }

    /**
     * The set of ISO codes that are valid selections, for the "in:" validation
     * rule on the country field.
     *
     * @return list<string>
     */
    public static function isoCodes(): array
    {
        return array_map(static fn (array $row): string => $row['iso'], self::all());
    }

    /**
     * The flag emoji for a 2-letter ISO code, composed from the two regional
     * indicator symbols. Renders as a flag on mobile and modern browsers, and
     * as the two letters (e.g. "NG") on platforms without flag glyphs.
     */
    public static function flag(string $iso): string
    {
        $iso = strtoupper($iso);

        if (! preg_match('/^[A-Z]{2}$/', $iso)) {
            return '';
        }

        return mb_chr(ord($iso[0]) - 65 + 0x1F1E6).mb_chr(ord($iso[1]) - 65 + 0x1F1E6);
    }

    /**
     * A sample national significant number for the country's mobile lines, used
     * as the input placeholder. Empty when libphonenumber has no example.
     */
    private static function exampleNationalNumber(PhoneNumberUtil $util, string $iso): string
    {
        try {
            $example = $util->getExampleNumberForType($iso, PhoneNumberType::MOBILE)
                ?? $util->getExampleNumber($iso);
        } catch (\Throwable) {
            return '';
        }

        return $example ? (string) $example->getNationalNumber() : '';
    }
}
