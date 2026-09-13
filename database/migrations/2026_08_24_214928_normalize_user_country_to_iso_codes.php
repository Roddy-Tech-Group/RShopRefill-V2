<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One format for users.country: the ISO 3166-1 alpha-2 code.
 *
 * The column was being written in two different shapes. Registration validates
 * against PhoneCountries::isoCodes() and stored "NG"; the profile settings page
 * validated against the keys of config/countries.php and stored "Nigeria". The
 * same column therefore held both, which breaks any consumer that compares it
 * to an ISO list - Smile ID's per-country product routing being the one that
 * matters, since a name never matches its biometric-coverage list and silently
 * falls back to the weaker global document check.
 *
 * This rewrites the display-name rows to their ISO code. Rows already holding a
 * code, and rows holding neither, are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ((array) config('countries.codes', []) as $name => $iso) {
            DB::table('users')
                ->where('country', $name)
                ->update(['country' => strtoupper((string) $iso)]);
        }
    }

    /**
     * Restore the display names. Only codes that map back to exactly one
     * country name are reversed, which is every entry in the config.
     */
    public function down(): void
    {
        foreach ((array) config('countries.codes', []) as $name => $iso) {
            DB::table('users')
                ->where('country', strtoupper((string) $iso))
                ->update(['country' => $name]);
        }
    }
};
