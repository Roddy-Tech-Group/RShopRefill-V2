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

    /**
     * Convert a supplier cost expressed in `$currency` into USD. Returns the
     * amount unchanged for USD/blank/zero, or when the currency has no active
     * rate (logged, so a missing rate surfaces instead of silently mispricing).
     */
    protected function costToUsd(?float $amount, ?string $currency): float
    {
        $amount = (float) $amount;
        $currency = strtoupper(trim((string) $currency));

        if ($amount <= 0.0 || $currency === '' || $currency === 'USD') {
            return $amount;
        }

        $rate = $this->usdRateMemo[$currency] ??= (float) (CurrencyRate::where('code', $currency)->value('rate_per_usd') ?: 0.0);

        if ($rate <= 0.0) {
            Log::warning("ConvertsCostToUsd: no USD rate for {$currency}; cost left unconverted", ['amount' => $amount]);

            return $amount;
        }

        return round($amount / $rate, 4);
    }
}
