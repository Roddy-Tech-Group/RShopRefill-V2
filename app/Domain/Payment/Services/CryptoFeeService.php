<?php

namespace App\Domain\Payment\Services;

use App\Domain\Payment\DTOs\CryptoFeeBreakdown;
use App\Domain\Payment\Providers\NowPaymentsProvider;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Estimates the fee breakdown for crypto payments BEFORE invoice creation.
 *
 * Responsibilities:
 *  - Resolve network fee estimates (admin-configurable per-network)
 *  - Calculate NOWPayments service fee
 *  - Query the NOWPayments /estimate endpoint for crypto conversion rates
 *  - Assemble a CryptoFeeBreakdown DTO for the checkout UI
 *
 * The product price already includes RshopRefills' platform markup (from the
 * PricingRule system). This service only adds blockchain-level costs.
 */
class CryptoFeeService
{
    /**
     * Default network fee estimates in USD. These are conservative defaults
     * used when no admin override exists in the settings table. Admins can
     * fine-tune these via Setting::set('crypto_network_fee_{network}', value).
     *
     * @var array<string, float>
     */
    private const DEFAULT_NETWORK_FEES = [
        'tron' => 1.00,
        'ethereum' => 5.00,
        'bnb' => 0.50,
        'polygon' => 0.10,
        'solana' => 0.05,
        'bitcoin' => 3.00,
        'litecoin' => 0.10,
    ];

    /** Default NOWPayments service fee rate (0.5% for mono-currency). */
    private const DEFAULT_SERVICE_FEE_PCT = 0.5;

    public function __construct(
        private readonly NowPaymentsProvider $nowPaymentsProvider,
    ) {}

    /**
     * Build a full fee breakdown for a crypto payment.
     *
     * @param  float  $productPriceUsd  The order total in USD (markup included).
     * @param  string  $payCurrency  NOWPayments currency code (e.g. 'usdttrc20').
     */
    public function estimateBreakdown(float $productPriceUsd, string $payCurrency): CryptoFeeBreakdown
    {
        $network = $this->resolveNetworkFromCurrency($payCurrency);
        $networkFeeUsd = $this->getNetworkFeeEstimate($network);
        $serviceFeeUsd = $this->getServiceFee($productPriceUsd);
        $totalDueUsd = $productPriceUsd + $networkFeeUsd + $serviceFeeUsd;

        // Get the crypto exchange rate from NOWPayments /estimate endpoint.
        // Cache for 60 seconds to avoid hammering the API on rapid coin switches.
        $cacheKey = "crypto_estimate:{$payCurrency}:".round($totalDueUsd, 2);
        $estimate = Cache::remember($cacheKey, 60, function () use ($totalDueUsd, $payCurrency) {
            return $this->nowPaymentsProvider->estimatePayment($totalDueUsd, 'USD', $payCurrency);
        });

        $exchangeRate = $estimate['rate'] ?? 1.0;
        $estimatedPayAmount = $estimate['estimated_amount'] ?? round($totalDueUsd * $exchangeRate, 8);

        return new CryptoFeeBreakdown(
            productPriceUsd: $productPriceUsd,
            networkFeeEstimateUsd: $networkFeeUsd,
            serviceFeeEstimateUsd: $serviceFeeUsd,
            totalDueUsd: $totalDueUsd,
            payCurrency: strtolower($payCurrency),
            network: $network,
            estimatedPayAmount: $estimatedPayAmount,
            exchangeRate: $exchangeRate,
        );
    }

    /**
     * Estimated network fee for a blockchain network. Reads from the admin
     * settings table first, then falls back to the hardcoded defaults.
     */
    public function getNetworkFeeEstimate(string $network): float
    {
        $network = strtolower($network);
        $settingKey = "crypto_network_fee_{$network}";
        $value = Setting::get($settingKey);

        if ($value !== null && is_numeric($value)) {
            return (float) $value;
        }

        return self::DEFAULT_NETWORK_FEES[$network] ?? 1.00;
    }

    /**
     * NOWPayments service fee as a USD amount. The rate is admin-configurable
     * via `crypto_service_fee_pct` (default 0.5%).
     */
    public function getServiceFee(float $amountUsd): float
    {
        $pct = (float) Setting::get('crypto_service_fee_pct', self::DEFAULT_SERVICE_FEE_PCT);

        return round($amountUsd * ($pct / 100), 2);
    }

    /**
     * Map a NOWPayments pay_currency code to its blockchain network name.
     * Mirrors NowPaymentsProvider::resolveNetwork() but is public and static
     * so it can be used without instantiating the full provider.
     */
    public function resolveNetworkFromCurrency(string $payCurrency): string
    {
        return match (strtolower($payCurrency)) {
            'btc' => 'bitcoin',
            'eth' => 'ethereum',
            'usdt', 'usdttrc20' => 'tron',
            'usdterc20' => 'ethereum',
            'usdtbsc', 'usdtbep20' => 'bnb',
            'usdtmatic', 'usdtpolygon' => 'polygon',
            'usdtsol' => 'solana',
            'ltc' => 'litecoin',
            'bnb', 'bnbbsc' => 'bnb',
            'matic' => 'polygon',
            'sol' => 'solana',
            'trx' => 'tron',
            default => strtolower($payCurrency),
        };
    }

    /**
     * All supported networks with their current fee estimates. Used by the
     * admin panel to display and configure per-network fees.
     *
     * @return array<string, array{network: string, fee_usd: float, source: string}>
     */
    public function allNetworkFees(): array
    {
        $result = [];

        foreach (self::DEFAULT_NETWORK_FEES as $network => $defaultFee) {
            $settingKey = "crypto_network_fee_{$network}";
            $override = Setting::get($settingKey);

            $result[$network] = [
                'network' => $network,
                'fee_usd' => ($override !== null && is_numeric($override)) ? (float) $override : $defaultFee,
                'source' => ($override !== null && is_numeric($override)) ? 'admin' : 'default',
            ];
        }

        return $result;
    }
}
