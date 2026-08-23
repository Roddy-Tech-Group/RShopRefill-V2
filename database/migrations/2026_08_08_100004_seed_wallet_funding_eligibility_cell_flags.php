<?php

use App\Domain\Shared\Enums\ComplianceCategory;
use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Seeds the wallet-funding (stored value) eligibility cells, all OFF: one flag
 * per provider that could fund a wallet, so an admin can approve WHICH provider
 * is allowed to fund. Split from the product-category cells (100003) because
 * those already ran; the `if not exists` guard also makes this safe to re-apply.
 *
 * These are the most sensitive cells in the matrix: once money is in a wallet,
 * the funding provider can no longer see what it ultimately buys. They stay off
 * until stored-value funding is genuinely approved on a provider.
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
        $category = ComplianceCategory::Wallet;
        $gateways = (array) config('payment_eligibility.cells.'.$category->value, []);

        $cells = [];

        foreach ($gateways as $gateway) {
            $key = "features.eligibility_{$gateway}_{$category->value}_enabled";
            $cells[$key] = "Eligibility: allow wallet funding via {$gateway} (stored value). Off = denied. Effective only while payment_eligibility_enforce is on.";
        }

        return $cells;
    }
};
