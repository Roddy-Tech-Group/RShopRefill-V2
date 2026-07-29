<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Settles the final crypto lineup: swaps USDC-Base for Toncoin, and relabels the
 * chains using the names customers actually see in their own wallets.
 *
 * USDC on Base is dropped despite being the cheapest rail measured — Base has very
 * little traction among our customers, and Toncoin has both a lower minimum and a
 * real distribution channel through Telegram. Verified as needing no memo or
 * destination tag, so it can be accepted safely.
 *
 * Naming: "BEP20" and "TRC20" rather than "BSC" and "Tron". Those are the labels
 * Binance shows on its withdrawal screen, which is where most of our customers
 * will be sending from — matching their screen removes a wrong-network mistake
 * that would otherwise cost them the funds. The checkout derives the network chip
 * from the text in brackets, so the name IS the label.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{code: string, name: string, network: string, rate: float, icon: string, sort: int}>
     */
    private const LINEUP = [
        // Ticker, not brand name: customers recognise "USDT" and "USDC" — those are
        // what their wallet and exchange show them. "Tether" and "USD Coin" are the
        // issuing companies, and asking someone to match a company name against a
        // ticker on their withdrawal screen is an avoidable chance to send the
        // wrong asset.
        ['code' => 'USDTBSC',   'name' => 'USDT (BEP20)',        'network' => 'bnb',      'rate' => 1.0,        'icon' => 'USDT.svg',   'sort' => 100],
        ['code' => 'USDTTRC20', 'name' => 'USDT (TRC20)',        'network' => 'tron',     'rate' => 1.0,        'icon' => 'USDT.svg',   'sort' => 102],
        ['code' => 'USDTSOL',   'name' => 'USDT (Solana)',       'network' => 'solana',   'rate' => 1.0,        'icon' => 'USDT.svg',   'sort' => 104],
        ['code' => 'USDCBSC',   'name' => 'USDC (BEP20)',        'network' => 'bnb',      'rate' => 1.0,        'icon' => 'USDC.svg',   'sort' => 106],
        ['code' => 'USDCSOL',   'name' => 'USDC (Solana)',       'network' => 'solana',   'rate' => 1.0,        'icon' => 'USDC.svg',   'sort' => 108],
        ['code' => 'BTC',       'name' => 'Bitcoin',             'network' => 'bitcoin',  'rate' => 0.00001733, 'icon' => 'BTC.svg',    'sort' => 120],
        ['code' => 'ETH',       'name' => 'Ethereum',            'network' => 'ethereum', 'rate' => 0.0003467,  'icon' => 'ETH.svg',    'sort' => 122],
        ['code' => 'SOL',       'name' => 'Solana',              'network' => 'solana',   'rate' => 0.00693,    'icon' => 'SOLANA.svg', 'sort' => 124],
        ['code' => 'TON',       'name' => 'Toncoin',             'network' => 'ton',      'rate' => 0.63,       'icon' => 'TON.svg',    'sort' => 126],
        ['code' => 'LTC',       'name' => 'Litecoin',            'network' => 'litecoin', 'rate' => 0.01156,    'icon' => 'LTC.webp',   'sort' => 128],
    ];

    public function up(): void
    {
        $keep = array_column(self::LINEUP, 'code');

        // Retire anything outside the lineup — currently USDCBASE, plus the bare
        // USDT/USDC/BNB tickers retired earlier. Deactivated rather than deleted:
        // historical orders reference these codes and must still resolve.
        DB::table('currency_rates')
            ->where('type', 'crypto')
            ->whereNotIn('code', $keep)
            ->update(['is_active' => false]);

        foreach (self::LINEUP as $coin) {
            DB::table('currency_rates')->updateOrInsert(
                ['code' => $coin['code']],
                [
                    'name' => $coin['name'],
                    'type' => 'crypto',
                    'network' => $coin['network'],
                    'rate_per_usd' => $coin['rate'],
                    'icon_path' => $coin['icon'],
                    'sort_order' => $coin['sort'],
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        // Deliberately NOT touching is_payable or min_amount_usd. Those are the
        // gateway's answers, not ours — crypto:sync-currencies owns them, and
        // guessing here would either offer a coin the account cannot accept or
        // gate one it can.
    }

    public function down(): void
    {
        DB::table('currency_rates')->where('code', 'TON')->update(['is_active' => false]);
        DB::table('currency_rates')->whereIn('code', ['USDCBASE'])->update(['is_active' => true]);
        DB::table('currency_rates')->where('code', 'USDTBSC')->update(['name' => 'Tether (BNB Chain)']);
        DB::table('currency_rates')->where('code', 'USDCBSC')->update(['name' => 'USD Coin (BNB Chain)']);
        DB::table('currency_rates')->where('code', 'USDTTRC20')->update(['name' => 'Tether (Tron)']);
        DB::table('currency_rates')->where('code', 'USDTSOL')->update(['name' => 'Tether (Solana)']);
        DB::table('currency_rates')->where('code', 'USDCSOL')->update(['name' => 'USD Coin (Solana)']);
    }
};
