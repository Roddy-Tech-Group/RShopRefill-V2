<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * The login country check, seeded ON.
 *
 * Enforcement flags on this platform seed OFF as a rule, and this one is a
 * deliberate exception Maxwell signed off on 2026-09-26. The usual reason to
 * seed off is that flipping on adds a new way to refuse a customer. Here the
 * flag replaces a check that already forces codes and does it badly, so OFF
 * would mean no travel check at all rather than the safe status quo. Nothing
 * new can refuse anyone either way: the worst this rule can do is email a
 * six-digit code, and the old rule could already do that.
 *
 * Off is still a real setting, and the honest one to reach for if the GeoIP
 * database is ever unavailable for a long stretch: it stops the check running
 * rather than leaving it half blind.
 *
 * Descriptions stay under 255 characters; the column is VARCHAR(255) on
 * MariaDB and sqlite does not enforce it.
 */
return new class extends Migration
{
    private const KEY = 'features.login_country_check_enabled';

    private const DESCRIPTION = 'Ask for an email code when an account signs in from a different country than its last login within a few hours. Same country and unknown locations are ignored, and each country is only ever asked about once.';

    public function up(): void
    {
        if (SiteSetting::query()->where('key', self::KEY)->exists()) {
            return;
        }

        SiteSetting::put(self::KEY, 'on', 'features', self::DESCRIPTION);
    }

    public function down(): void
    {
        SiteSetting::query()->where('key', self::KEY)->delete();
    }
};
