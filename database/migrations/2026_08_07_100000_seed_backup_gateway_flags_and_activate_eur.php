<?php

use App\Models\CurrencyRate;
use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Fiat backup gateways (Paystack, Kora Pay) get their kill-switch flags,
 * seeded OFF: deploying this changes nothing. When Flutterwave's flag is off,
 * FiatGatewayRouter fails each checkout method over to the first enabled
 * backup that covers it in the customer's currency; while Flutterwave is on,
 * the backups are unreachable regardless of these flags.
 *
 * Also activates EUR as a checkout display currency. The currency_rates row
 * already exists (rate maintained by the rates sync) but was inactive, and
 * the Currency enum gained an EUR case in the same change.
 */
return new class extends Migration
{
    private const FLAGS = [
        [
            'key' => 'features.gateway_paystack_enabled',
            'description' => 'Fiat BACKUP gateway, only used while Flutterwave is off. On lets card, Apple Pay, USSD, NGN bank transfer and GHS/KES/XOF mobile money fail over to Paystack. Seeded off; in-flight payments and webhooks are never gated.',
        ],
        [
            'key' => 'features.gateway_korapay_enabled',
            'description' => 'Fiat BACKUP gateway, only used while Flutterwave is off. On lets XAF/XOF/GHS/KES/EGP/TZS mobile money and NGN bank rails fail over to Kora. Seeded off; in-flight payments and webhooks are never gated.',
        ],
    ];

    public function up(): void
    {
        foreach (self::FLAGS as $flag) {
            if (! SiteSetting::query()->where('key', $flag['key'])->exists()) {
                SiteSetting::put($flag['key'], 'off', 'features', $flag['description']);
            }

            Cache::forget('site_setting:'.$flag['key']);
        }

        $eur = CurrencyRate::query()->where('code', 'EUR')->first();

        if ($eur) {
            $eur->update(['name' => 'Euro', 'type' => 'fiat', 'is_active' => true]);
        } else {
            // Fresh installs: seed with a plausible rate; the rates sync
            // refreshes it on its next run.
            CurrencyRate::query()->create([
                'code' => 'EUR',
                'name' => 'Euro',
                'type' => 'fiat',
                'rate_per_usd' => 0.87,
                'is_active' => true,
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::FLAGS as $flag) {
            SiteSetting::query()->where('key', $flag['key'])->delete();
            Cache::forget('site_setting:'.$flag['key']);
        }

        CurrencyRate::query()->where('code', 'EUR')->update(['is_active' => false]);
    }
};
