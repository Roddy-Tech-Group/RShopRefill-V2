<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrects two rate faults and moves the crypto catalogue to the launch ten.
 *
 * CurrencyRateSeeder uses firstOrCreate, so it never rewrites a row that already
 * exists — which means neither fix can reach an existing environment from the
 * seeder alone. This migration carries them.
 */
return new class extends Migration
{
    /** Codes retired from checkout. Deactivated, never deleted — historical orders reference them. */
    private const RETIRED = ['BNB', 'USDT', 'USDC'];

    /**
     * @var array<int, array{code: string, name: string, rate: float, icon: string, sort: int}>
     */
    private const LAUNCH_COINS = [
        ['code' => 'USDTBSC',   'name' => 'Tether (BNB Chain)',   'rate' => 1.0,        'icon' => 'USDT.svg',   'sort' => 100],
        ['code' => 'USDTTRC20', 'name' => 'Tether (Tron)',        'rate' => 1.0,        'icon' => 'USDT.svg',   'sort' => 102],
        ['code' => 'USDTSOL',   'name' => 'Tether (Solana)',      'rate' => 1.0,        'icon' => 'USDT.svg',   'sort' => 104],
        ['code' => 'USDCBSC',   'name' => 'USD Coin (BNB Chain)', 'rate' => 1.0,        'icon' => 'USDC.svg',   'sort' => 106],
        ['code' => 'USDCBASE',  'name' => 'USD Coin (Base)',      'rate' => 1.0,        'icon' => 'USDC.svg',   'sort' => 108],
        ['code' => 'USDCSOL',   'name' => 'USD Coin (Solana)',    'rate' => 1.0,        'icon' => 'USDC.svg',   'sort' => 110],
        ['code' => 'BTC',       'name' => 'Bitcoin',              'rate' => 0.00001733, 'icon' => 'BTC.svg',    'sort' => 120],
        ['code' => 'ETH',       'name' => 'Ethereum',             'rate' => 0.0003467,  'icon' => 'ETH.svg',    'sort' => 122],
        ['code' => 'SOL',       'name' => 'Solana',               'rate' => 0.00693,    'icon' => 'SOLANA.svg', 'sort' => 124],
        ['code' => 'LTC',       'name' => 'Litecoin',             'rate' => 0.01156,    'icon' => 'LTC.webp',   'sort' => 126],
    ];

    public function up(): void
    {
        // 1. USD must be exactly 1.0. It is the anchor CurrencyRateService divides
        //    by when falling back, so a value of 1.04 skewed every pair routed
        //    through USD by ~4% in whichever direction the conversion ran.
        DB::table('currency_rates')->where('code', 'USD')->update(['rate_per_usd' => 1.0]);

        // 2. Retire the coins not in the launch ten. Deactivated rather than
        //    deleted so existing orders that reference them still resolve.
        DB::table('currency_rates')
            ->where('type', 'crypto')
            ->whereIn('code', self::RETIRED)
            ->update(['is_active' => false]);

        // 3. Upsert the launch ten. Stablecoins land at a true 1.0 — the previous
        //    1.03 was an intended platform spread that only ever altered an on-screen
        //    estimate and was never collected, so it misquoted customers for nothing.
        foreach (self::LAUNCH_COINS as $coin) {
            DB::table('currency_rates')->updateOrInsert(
                ['code' => $coin['code']],
                [
                    'name' => $coin['name'],
                    'type' => 'crypto',
                    'rate_per_usd' => $coin['rate'],
                    'icon_path' => $coin['icon'],
                    'sort_order' => $coin['sort'],
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('currency_rates')->where('code', 'USD')->update(['rate_per_usd' => 1.04]);

        DB::table('currency_rates')
            ->where('type', 'crypto')
            ->whereIn('code', self::RETIRED)
            ->update(['is_active' => true]);

        DB::table('currency_rates')
            ->whereIn('code', ['USDTBSC', 'USDTTRC20', 'USDTSOL', 'USDCBSC'])
            ->update(['is_active' => false]);
    }
};
