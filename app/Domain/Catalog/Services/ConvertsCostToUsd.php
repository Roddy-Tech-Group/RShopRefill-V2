<?php

namespace App\Domain\Catalog\Services;

use App\Models\CurrencyRate;
use Illuminate\Support\Facades\Log;

/**
 * Zendit's `cost` block reports its `fixed` amount in the PRODUCT's own currency
 * (the send/face currency) even though the block is stamped `currency: "USD"`.
 * An AED 100 card comes through as cost 97.89 - that's 97.89 AED, not dollars.
 * The whole pricing pipeline treats `cost_price` as USD (the settlement currency),
 * so a non-USD cost must be converted here or every non-USD card is overpriced by
 * its FX rate. USD cards convert by rate 1.0 - a no-op - so they're never touched.
 */
trait ConvertsCostToUsd
{
    /** @var array<string, float> Per-instance memo so a full sync hits the rates table once per currency. */
    private array $usdRateMemo = [];

    protected function costToUsd(?float $amount, ?string $currency): float
    {
        $currency = strtoupper(trim((string) $currency));

        if (!$amount || !$currency || $currency === 'USD') {
            return (float) $amount;
        }

        if (!array_key_exists($currency, $this->usdRateMemo)) {
            $rate = \App\Models\CurrencyRate::where('code', $currency)->value('rate_per_usd');
            // If rate is missing or 0, fallback to 1.0 to avoid division by zero
            $this->usdRateMemo[$currency] = (float) ($rate ?: 1.0);
        }

        return (float) $amount / $this->usdRateMemo[$currency];
    }
}
