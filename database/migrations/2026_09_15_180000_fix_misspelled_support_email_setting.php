<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Corrects the support address the settings seed stored with a misspelled
 * domain: support@rshoprefills.com, where the real (and only receiving)
 * mailbox is support@rshoprefill.com. The Contact page renders this setting,
 * so a database still holding the seed value publishes an address that
 * cannot receive mail.
 *
 * Only that exact seed value is replaced. An address an admin has since typed
 * into System Settings is left alone.
 */
return new class extends Migration
{
    private const KEY = 'contact.email_support';

    private const MISSPELLED = 'support@rshoprefills.com';

    private const CORRECT = 'support@rshoprefill.com';

    public function up(): void
    {
        $setting = SiteSetting::query()->where('key', self::KEY)->first();

        if ($setting !== null && trim((string) $setting->value) === self::MISSPELLED) {
            SiteSetting::put(self::KEY, self::CORRECT, 'contact', $setting->description);
        }

        Cache::forget('site_setting:'.self::KEY);
    }

    public function down(): void
    {
        // Deliberately empty: restoring an address that cannot receive mail
        // is never the state anyone wants to roll back to.
    }
};
