<?php

use App\Domain\Shared\Enums\ComplianceCategory;
use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Seeds one admin-toggleable eligibility flag per (gateway, category) cell,
 * all OFF, from config/payment_eligibility.php. These are what an admin flips
 * to approve a restricted category on a gateway once approval is granted in
 * writing. They only take effect while payment_eligibility_enforce is on.
 *
 * Additive and safe: seeded off, so deploying changes nothing. Restricted
 * categories with no cell here (wallet funding, crypto purchase, ...) stay
 * denied by default and are deliberately NOT exposed as a toggle.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (self::cells() as $key => $description) {
            if (! SiteSetting::query()->where('key', $key)->exists()) {
                SiteSetting::put($key, 'off', 'features', $description);
            }

            Cache::forget('site_setting:'.$key);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::cells()) as $key) {
            SiteSetting::query()->where('key', $key)->delete();
            Cache::forget('site_setting:'.$key);
        }
    }

    /**
     * @return array<string, string> flag key => description
     */
    private static function cells(): array
    {
        $cells = [];

        foreach ((array) config('payment_eligibility.cells', []) as $category => $gateways) {
            $label = ComplianceCategory::from($category)->label();

            foreach ((array) $gateways as $gateway) {
                $key = "features.eligibility_{$gateway}_{$category}_enabled";
                $cells[$key] = "Eligibility: allow {$label} to be charged via {$gateway}. Off = denied. Effective only while payment_eligibility_enforce is on.";
            }
        }

        return $cells;
    }
};
