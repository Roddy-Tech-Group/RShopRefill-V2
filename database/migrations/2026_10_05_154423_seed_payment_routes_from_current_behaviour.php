<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fill the switchboard with exactly what the code did before it existed.
 *
 * Written out literally rather than read from the application's constants, so
 * this migration means the same thing on every machine forever, whatever those
 * constants become. Deploying it changes nothing a customer can see:
 *
 *  - the methods offered per currency are SupportedPaymentMethods as of
 *    2026-10-05, Apple Pay included but switched off with its reason;
 *  - the tile order is the one checkout really rendered, card first, because
 *    its tiles followed its method catalogue, not its per-currency lists;
 *  - every fiat method keeps Flutterwave first and the backups behind it in
 *    FiatGatewayRouter's failover order. A backup listed for a currency it
 *    cannot charge is skipped by the router, as it always was.
 *
 * Moving a rail to Kora or Paystack is done afterwards, on purpose, from
 * Management > Gateway settings.
 */
return new class extends Migration
{
    private const GATEWAYS = [
        'card' => ['flutterwave', 'paystack', 'korapay'],
        'apple_pay' => ['flutterwave', 'paystack'],
        'bank_transfer' => ['flutterwave', 'paystack', 'korapay'],
        'pay_with_bank' => ['flutterwave', 'korapay'],
        'ussd' => ['flutterwave', 'paystack'],
        'bank_qr' => ['flutterwave'],
        'mobile_wallet' => ['flutterwave'],
        'mobile_money' => ['flutterwave', 'korapay'],
        'crypto' => ['nowpayments'],
        'wallet' => ['wallet'],
    ];

    /**
     * Currency => methods in tile order. A method in WITHHELD is listed but not
     * offered.
     *
     * @var array<string, list<string>>
     */
    private const ROWS = [
        'USD' => ['card', 'mobile_money', 'apple_pay', 'crypto', 'wallet'],
        'EUR' => ['card', 'pay_with_bank', 'crypto', 'wallet'],
        'GBP' => ['card', 'pay_with_bank', 'apple_pay', 'crypto', 'wallet'],
        'NGN' => ['card', 'bank_transfer', 'pay_with_bank', 'ussd', 'bank_qr', 'mobile_wallet', 'apple_pay', 'crypto', 'wallet'],
        'GHS' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'XAF' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'XOF' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'KES' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'UGX' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'RWF' => ['card', 'mobile_money', 'crypto', 'wallet'],
        // Currencies a gateway covers but no customer has paid in yet. Card
        // stays on, exactly the fallback these currencies had with no row;
        // the local method is listed switched off, so it can be managed from
        // Gateway settings and switched on after one real test payment.
        'EGP' => ['card', 'mobile_money'],
        'TZS' => ['card', 'mobile_money'],
        'ZMW' => ['card'],
        'ZAR' => ['card'],
    ];

    private const WITHHELD = [
        'apple_pay' => 'Not enabled',
    ];

    /**
     * Currency => methods listed but switched off until a real payment proves
     * them.
     *
     * @var array<string, list<string>>
     */
    private const OFF_UNTIL_TESTED = [
        'EGP' => ['mobile_money'],
        'TZS' => ['mobile_money'],
    ];

    public function up(): void
    {
        $now = now();
        $rows = [];

        foreach (self::ROWS as $currency => $methods) {
            foreach ($methods as $position => $method) {
                $rows[] = [
                    'currency' => $currency,
                    'method' => $method,
                    'is_offered' => ! isset(self::WITHHELD[$method]) && ! in_array($method, self::OFF_UNTIL_TESTED[$currency] ?? [], true),
                    'sort_order' => ($position + 1) * 10,
                    'gateways' => json_encode(self::GATEWAYS[$method]),
                    'note' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('payment_routes')->insertOrIgnore($rows);

        Cache::forget('payment_routes:v1');
    }

    public function down(): void
    {
        DB::table('payment_routes')->truncate();

        Cache::forget('payment_routes:v1');
    }
};
