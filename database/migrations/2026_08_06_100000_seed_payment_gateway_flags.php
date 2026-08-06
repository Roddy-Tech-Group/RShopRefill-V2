<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * One kill switch per payment gateway, so a failing provider can be pulled out
 * of checkout from the admin settings screen in seconds, without a deploy.
 *
 * Off means: tiles for that gateway grey out on checkout with a "temporarily
 * unavailable" notice, and the server refuses NEW payment attempts on it
 * before anything is written. Money already in flight always lands: webhooks,
 * verification, the sweeper and refunds are never gated by these flags.
 *
 * All seeded ON, so deploying this changes nothing until a switch is thrown.
 * Future backup gateways (Kora Pay, Cryptomus) seed their own rows OFF.
 */
return new class extends Migration
{
    private const FLAGS = [
        [
            'key' => 'features.gateway_flutterwave_enabled',
            'description' => 'Off greys out ALL fiat methods on checkout (card, bank transfer, USSD, mobile money, Apple Pay) and refuses new Flutterwave payments server-side. In-flight payments, webhooks and refunds still process.',
        ],
        [
            'key' => 'features.gateway_nowpayments_enabled',
            'description' => 'Off greys out the Crypto tile on checkout and refuses new NOWPayments sessions server-side. In-flight crypto payments, webhooks and confirmations still process.',
        ],
        [
            'key' => 'features.gateway_wallet_enabled',
            'description' => 'Off greys out the Wallet tile on checkout and refuses new wallet payments server-side. Wallet balances, funding webhooks and refunds are unaffected.',
        ],
    ];

    public function up(): void
    {
        foreach (self::FLAGS as $flag) {
            if (! SiteSetting::query()->where('key', $flag['key'])->exists()) {
                SiteSetting::put($flag['key'], 'on', 'features', $flag['description']);
            }

            Cache::forget('site_setting:'.$flag['key']);
        }
    }

    public function down(): void
    {
        foreach (self::FLAGS as $flag) {
            SiteSetting::query()->where('key', $flag['key'])->delete();
            Cache::forget('site_setting:'.$flag['key']);
        }
    }
};
