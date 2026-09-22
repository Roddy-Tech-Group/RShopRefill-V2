<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Whether customers can start identity verification at all.
 *
 * Until the identity provider is proven live in production, a customer who
 * pressed Verify started a real provider session that could never end in a
 * verified account. This flag closes the door instead: off, tapping Verify
 * says verification is currently unavailable, and an enforced step-up is
 * logged and let through rather than handing the customer a session nobody
 * can finish. Seeded OFF.
 */
return new class extends Migration
{
    private const KEY = 'features.kyc_verification_enabled';

    public function up(): void
    {
        if (! SiteSetting::query()->where('key', self::KEY)->exists()) {
            SiteSetting::put(
                self::KEY,
                'off',
                'features',
                'On lets customers verify their identity. Off answers a Verify tap with "Verification is currently unavailable, try again later" and lets step-ups through. Turn on only once the provider runs on production credentials with a live callback URL.',
            );
        }

        Cache::forget('site_setting:'.self::KEY);
        Cache::forget('site_setting_'.self::KEY);
    }

    public function down(): void
    {
        SiteSetting::query()->where('key', self::KEY)->delete();
        Cache::forget('site_setting:'.self::KEY);
        Cache::forget('site_setting_'.self::KEY);
    }
};
