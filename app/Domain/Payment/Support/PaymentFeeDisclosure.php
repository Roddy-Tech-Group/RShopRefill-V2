<?php

namespace App\Domain\Payment\Support;

use Illuminate\Support\Facades\DB;

/**
 * What the checkout page needs to disclose a processing fee honestly: the
 * fee tables from config/payment_fees.php, plus which gateway the switchboard
 * routes each fiat method to in each checkout currency. The page reads the
 * rates for THAT gateway, so a method Kora takes discloses Kora's customer fee
 * (none today) and the page and Kora's payment window name one figure.
 */
final class PaymentFeeDisclosure
{
    /**
     * @return array{
     *     fee_free: list<string>,
     *     methods: array<string, array{transaction: float, international: float}>,
     *     default: array{transaction: float, international: float},
     *     gateways: array<string, array{methods: array<string, array{transaction: float, international: float}>, default: array{transaction: float, international: float}}>,
     *     gateway_by_currency: array<string, array<string, string|null>>
     * }
     */
    public static function forCheckout(): array
    {
        return [
            'fee_free' => array_values((array) config('payment_fees.fee_free_methods', [])),
            'methods' => (array) config('payment_fees.methods', []),
            'default' => (array) config('payment_fees.default', ['transaction' => 0.0, 'international' => 0.0]),
            'gateways' => (array) config('payment_fees.gateways', []),
            'gateway_by_currency' => self::gatewayByCurrency(),
        ];
    }

    /**
     * The gateway that takes each fiat method in each currency the route table
     * knows, or null where none is live: the router's answer, nothing else.
     *
     * @return array<string, array<string, string|null>>
     */
    public static function gatewayByCurrency(): array
    {
        $map = [];

        $currencies = DB::table('payment_routes')->distinct()->pluck('currency')
            ->map(fn ($currency) => strtoupper((string) $currency))
            ->unique()
            ->values();

        foreach ($currencies as $currency) {
            foreach (array_keys(FiatGatewayRouter::METHOD_PRIORITY) as $method) {
                $map[$currency][$method] = FiatGatewayRouter::resolve($method, $currency);
            }
        }

        return $map;
    }
}
