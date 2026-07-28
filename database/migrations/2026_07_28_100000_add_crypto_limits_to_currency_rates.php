<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives each crypto currency the operational facts the checkout needs to decide
 * whether a customer can actually pay with it.
 *
 * Minimums are the reason this matters: they differ by more than two orders of
 * magnitude across our ten coins (USDC on Base clears at about two cents, USDT on
 * Tron needs roughly twelve dollars) and they float with network conditions.
 * Without them the picker happily offers a coin the gateway will reject, and the
 * customer discovers it only after committing to pay.
 *
 * Synced from the gateway rather than hardcoded — see crypto:sync-currencies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currency_rates', function (Blueprint $table) {
            $table->string('network', 40)->nullable()->after('type');

            // Cached fiat equivalent of the gateway's minimum, in USD. Nullable
            // because "not yet synced" must be distinguishable from "no minimum" —
            // we fail open on unknown rather than blocking a payable coin.
            $table->decimal('min_amount_usd', 12, 4)->nullable()->after('network');

            // Coins on tag-based chains cannot be credited automatically without a
            // memo/destination tag, so checkout has to render one when true.
            $table->boolean('requires_extra_id')->default(false)->after('min_amount_usd');

            // Whether the gateway currently accepts this coin on OUR merchant
            // account. Distinct from is_active, which is our own merchandising
            // switch — a coin can be enabled by us but unavailable at the gateway.
            $table->boolean('is_payable')->default(true)->after('requires_extra_id');

            // dateTime, not timestamp: MySQL silently rewrites the first NOT NULL
            // timestamp column to CURRENT_TIMESTAMP on every row update.
            $table->dateTime('limits_synced_at')->nullable()->after('is_payable');
        });

        // Seed the networks we already know so the picker is correct before the
        // first sync runs. Minimums stay null until the gateway is asked.
        $networks = [
            'USDTBSC' => 'bnb', 'USDCBSC' => 'bnb',
            'USDTTRC20' => 'tron', 'TRX' => 'tron',
            'USDTSOL' => 'solana', 'USDCSOL' => 'solana', 'SOL' => 'solana',
            'USDCBASE' => 'base',
            'BTC' => 'bitcoin', 'ETH' => 'ethereum', 'LTC' => 'litecoin',
            'USDC' => 'ethereum', 'USDT' => 'tron', 'BNB' => 'bnb',
        ];

        foreach ($networks as $code => $network) {
            DB::table('currency_rates')->where('code', $code)->update(['network' => $network]);
        }
    }

    public function down(): void
    {
        Schema::table('currency_rates', function (Blueprint $table) {
            $table->dropColumn([
                'network',
                'min_amount_usd',
                'requires_extra_id',
                'is_payable',
                'limits_synced_at',
            ]);
        });
    }
};
