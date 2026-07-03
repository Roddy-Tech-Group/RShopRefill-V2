<?php

namespace Database\Seeders;

use App\Models\CurrencyRate;
use Illuminate\Database\Seeder;

class CurrencyRateSeeder extends Seeder
{
    /*
     * Seeds currency_rates with every currency the catalog actually uses (39 fiat
     * spanning the gift-card / eSIM countries, plus 6 crypto).
     *
     * `rate_per_usd` = "how many of this currency = 1 USD". The fiat values are
     * SENSIBLE STARTING POINTS — approximate market rates. The CTO is expected to
     * tune them (and bake in the platform spread) via Rate Management.
     *
     * Idempotent and non-destructive: firstOrCreate keyed on `code`, so a re-run
     * only adds missing currencies and never clobbers a rate the admin adjusted.
     */
    public function run(): void
    {
        $rows = [
            // Fiat — original launch set.
            ['code' => 'GHS', 'name' => 'Ghanaian Cedi',             'type' => 'fiat', 'rate_per_usd' => 12.71,    'icon_path' => 'GH.svg',  'sort_order' => 10],
            ['code' => 'NGN', 'name' => 'Nigerian Naira',            'type' => 'fiat', 'rate_per_usd' => 1400.00,  'icon_path' => 'NGN.svg', 'sort_order' => 20],
            ['code' => 'USD', 'name' => 'United States Dollar',      'type' => 'fiat', 'rate_per_usd' => 1.04,     'icon_path' => 'USD.svg', 'sort_order' => 30],
            ['code' => 'XAF', 'name' => 'Central African CFA Franc', 'type' => 'fiat', 'rate_per_usd' => 788.00,   'icon_path' => 'XAF.svg', 'sort_order' => 40],

            // Fiat — every other currency present across the catalog (gift cards + eSIMs).
            ['code' => 'AED', 'name' => 'UAE Dirham',             'type' => 'fiat', 'rate_per_usd' => 3.6725,   'icon_path' => null, 'sort_order' => 50],
            ['code' => 'AUD', 'name' => 'Australian Dollar',      'type' => 'fiat', 'rate_per_usd' => 1.52,     'icon_path' => null, 'sort_order' => 50],
            ['code' => 'BGN', 'name' => 'Bulgarian Lev',          'type' => 'fiat', 'rate_per_usd' => 1.80,     'icon_path' => null, 'sort_order' => 50],
            ['code' => 'BHD', 'name' => 'Bahraini Dinar',         'type' => 'fiat', 'rate_per_usd' => 0.376,    'icon_path' => null, 'sort_order' => 50],
            ['code' => 'BRL', 'name' => 'Brazilian Real',         'type' => 'fiat', 'rate_per_usd' => 5.50,     'icon_path' => null, 'sort_order' => 50],
            ['code' => 'CAD', 'name' => 'Canadian Dollar',        'type' => 'fiat', 'rate_per_usd' => 1.37,     'icon_path' => null, 'sort_order' => 50],
            ['code' => 'CHF', 'name' => 'Swiss Franc',            'type' => 'fiat', 'rate_per_usd' => 0.88,     'icon_path' => null, 'sort_order' => 50],
            ['code' => 'CNY', 'name' => 'Chinese Yuan',           'type' => 'fiat', 'rate_per_usd' => 7.25,     'icon_path' => null, 'sort_order' => 50],
            ['code' => 'COP', 'name' => 'Colombian Peso',         'type' => 'fiat', 'rate_per_usd' => 4000.00,  'icon_path' => null, 'sort_order' => 50],
            ['code' => 'CUP', 'name' => 'Cuban Peso',             'type' => 'fiat', 'rate_per_usd' => 24.00,    'icon_path' => null, 'sort_order' => 50],
            ['code' => 'CZK', 'name' => 'Czech Koruna',           'type' => 'fiat', 'rate_per_usd' => 23.00,    'icon_path' => null, 'sort_order' => 50],
            ['code' => 'DKK', 'name' => 'Danish Krone',           'type' => 'fiat', 'rate_per_usd' => 6.90,     'icon_path' => null, 'sort_order' => 50],
            ['code' => 'DOP', 'name' => 'Dominican Peso',         'type' => 'fiat', 'rate_per_usd' => 59.00,    'icon_path' => null, 'sort_order' => 50],
            ['code' => 'EUR', 'name' => 'Euro',                   'type' => 'fiat', 'rate_per_usd' => 0.92,     'icon_path' => null, 'sort_order' => 50],
            ['code' => 'GBP', 'name' => 'British Pound',          'type' => 'fiat', 'rate_per_usd' => 0.79,     'icon_path' => null, 'sort_order' => 50],
            ['code' => 'GMD', 'name' => 'Gambian Dalasi',         'type' => 'fiat', 'rate_per_usd' => 68.00,    'icon_path' => null, 'sort_order' => 50],
            ['code' => 'GNF', 'name' => 'Guinean Franc',          'type' => 'fiat', 'rate_per_usd' => 8600.00,  'icon_path' => null, 'sort_order' => 50],
            ['code' => 'GTQ', 'name' => 'Guatemalan Quetzal',     'type' => 'fiat', 'rate_per_usd' => 7.80,     'icon_path' => null, 'sort_order' => 50],
            ['code' => 'HRK', 'name' => 'Croatian Kuna',          'type' => 'fiat', 'rate_per_usd' => 6.95,     'icon_path' => null, 'sort_order' => 50],
            ['code' => 'HUF', 'name' => 'Hungarian Forint',       'type' => 'fiat', 'rate_per_usd' => 360.00,   'icon_path' => null, 'sort_order' => 50],
            ['code' => 'INR', 'name' => 'Indian Rupee',           'type' => 'fiat', 'rate_per_usd' => 83.00,    'icon_path' => null, 'sort_order' => 50],
            ['code' => 'KES', 'name' => 'Kenyan Shilling',        'type' => 'fiat', 'rate_per_usd' => 130.00,   'icon_path' => null, 'sort_order' => 50],
            ['code' => 'MXN', 'name' => 'Mexican Peso',           'type' => 'fiat', 'rate_per_usd' => 17.00,    'icon_path' => null, 'sort_order' => 50],
            ['code' => 'PEN', 'name' => 'Peruvian Sol',           'type' => 'fiat', 'rate_per_usd' => 3.75,     'icon_path' => null, 'sort_order' => 50],
            ['code' => 'PHP', 'name' => 'Philippine Peso',        'type' => 'fiat', 'rate_per_usd' => 57.00,    'icon_path' => null, 'sort_order' => 50],
            ['code' => 'QAR', 'name' => 'Qatari Riyal',           'type' => 'fiat', 'rate_per_usd' => 3.64,     'icon_path' => null, 'sort_order' => 50],
            ['code' => 'SAR', 'name' => 'Saudi Riyal',            'type' => 'fiat', 'rate_per_usd' => 3.75,     'icon_path' => null, 'sort_order' => 50],
            ['code' => 'SEK', 'name' => 'Swedish Krona',          'type' => 'fiat', 'rate_per_usd' => 10.50,    'icon_path' => null, 'sort_order' => 50],
            ['code' => 'THB', 'name' => 'Thai Baht',              'type' => 'fiat', 'rate_per_usd' => 36.00,    'icon_path' => null, 'sort_order' => 50],
            ['code' => 'TND', 'name' => 'Tunisian Dinar',         'type' => 'fiat', 'rate_per_usd' => 3.10,     'icon_path' => null, 'sort_order' => 50],
            ['code' => 'TRY', 'name' => 'Turkish Lira',           'type' => 'fiat', 'rate_per_usd' => 34.00,    'icon_path' => null, 'sort_order' => 50],
            ['code' => 'TWD', 'name' => 'New Taiwan Dollar',      'type' => 'fiat', 'rate_per_usd' => 32.00,    'icon_path' => null, 'sort_order' => 50],
            ['code' => 'VND', 'name' => 'Vietnamese Dong',        'type' => 'fiat', 'rate_per_usd' => 25000.00, 'icon_path' => null, 'sort_order' => 50],
            ['code' => 'XOF', 'name' => 'West African CFA Franc', 'type' => 'fiat', 'rate_per_usd' => 600.00,   'icon_path' => null, 'sort_order' => 50],
            ['code' => 'ZAR', 'name' => 'South African Rand',     'type' => 'fiat', 'rate_per_usd' => 18.50,    'icon_path' => null, 'sort_order' => 50],

            // Crypto.
            ['code' => 'BNB',  'name' => 'BNB',      'type' => 'crypto', 'rate_per_usd' => 0.001733,   'icon_path' => 'BNB.webp',    'sort_order' => 110],
            ['code' => 'BTC',  'name' => 'Bitcoin',  'type' => 'crypto', 'rate_per_usd' => 0.00001733, 'icon_path' => 'BTC.svg',    'sort_order' => 120],
            ['code' => 'ETH',  'name' => 'Ethereum', 'type' => 'crypto', 'rate_per_usd' => 0.0003467,  'icon_path' => 'ETH.svg',    'sort_order' => 130],
            ['code' => 'LTC',  'name' => 'Litecoin', 'type' => 'crypto', 'rate_per_usd' => 0.01156,    'icon_path' => 'LTC.webp',    'sort_order' => 140],
            ['code' => 'SOL',  'name' => 'Solana',   'type' => 'crypto', 'rate_per_usd' => 0.00693,    'icon_path' => 'SOLANA.svg', 'sort_order' => 150],
            ['code' => 'USDT', 'name' => 'Tether',   'type' => 'crypto', 'rate_per_usd' => 1.03,       'icon_path' => 'USDT.svg',   'sort_order' => 160],
        ];

        foreach ($rows as $row) {
            CurrencyRate::firstOrCreate(
                ['code' => $row['code']],
                array_merge($row, ['is_active' => true])
            );
        }
    }
}
