<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Seeds the master enforcement switch for the payment eligibility gate, OFF.
 *
 * Off means shadow mode: the gate records what it WOULD have blocked but turns
 * no customer away, so deploying this changes nothing customers experience. The
 * gate is switched to enforcing deliberately, once config/payment_eligibility.php
 * reflects real, written gateway approvals. Same shadow -> enforce rollout the
 * progressive-backoff limiter uses.
 */
return new class extends Migration
{
    // Must match the key FeatureFlag::on('payment_eligibility_enforce') resolves
    // to: it prefixes 'features.' and suffixes '_enabled'.
    private const FLAG_KEY = 'features.payment_eligibility_enforce_enabled';

    public function up(): void
    {
        if (! SiteSetting::query()->where('key', self::FLAG_KEY)->exists()) {
            SiteSetting::put(
                self::FLAG_KEY,
                'off',
                'features',
                'Master switch for the payment eligibility gate. On enforces the category-to-gateway matrix (restricted categories default deny). Off is shadow mode: denials are logged, no customer is turned away. Seeded off.',
            );
        }

        Cache::forget('site_setting:'.self::FLAG_KEY);
    }

    public function down(): void
    {
        SiteSetting::query()->where('key', self::FLAG_KEY)->delete();
        Cache::forget('site_setting:'.self::FLAG_KEY);
    }
};
