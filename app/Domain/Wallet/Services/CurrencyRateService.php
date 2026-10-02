<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Exceptions\StaleRateException;
use App\Domain\Wallet\Exceptions\UnknownCurrencyException;
use App\Models\CurrencyRate;
use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Log;

class CurrencyRateService
{
    /**
     * Resolve the active exchange rate between two currencies.
     * Uses the exchange_rates table first, then falls back to the currency_rates table.
     *
     * This is the TRANSACTIONAL resolver: checkout, wallet funding and the wallet
     * payment provider price charges with it. It fails closed. A pair whose live
     * rate is too old throws StaleRateException; a currency with no active row at
     * all throws UnknownCurrencyException rather than quietly converting at 1.0.
     * Read-only displays use resolveRateForDisplay(), which keeps the old
     * leniency.
     *
     * @throws StaleRateException
     * @throws UnknownCurrencyException
     */
    public function resolveRate(string $baseCurrency, string $targetCurrency): float
    {
        $base = strtoupper(trim($baseCurrency));
        $target = strtoupper(trim($targetCurrency));

        if ($base === $target) {
            return 1.0;
        }

        // 1. Attempt to resolve from exchange_rates table
        $rateRow = ExchangeRate::active()
            ->where('base_currency', $base)
            ->where('target_currency', $target)
            ->orderBy('fetched_at', 'desc')
            ->first();

        if ($rateRow) {
            $this->validateRateFreshness($rateRow);

            return (float) $rateRow->rate;
        }

        // 2. Try the inverse rate
        $inverseRateRow = ExchangeRate::active()
            ->where('base_currency', $target)
            ->where('target_currency', $base)
            ->orderBy('fetched_at', 'desc')
            ->first();

        if ($inverseRateRow) {
            $this->validateRateFreshness($inverseRateRow);
            $rate = (float) $inverseRateRow->rate;

            return $rate > 0 ? (1.0 / $rate) : 0.0;
        }

        // 3. Fallback: Resolve using the currency_rates table. Strict: a
        //    currency the table does not know cannot be charged in.
        return $this->resolveFallbackRate($base, $target, strict: true);
    }

    /**
     * Convert an amount between two currencies.
     *
     * @throws StaleRateException
     * @throws UnknownCurrencyException
     */
    public function convert(float $amount, string $from, string $to): float
    {
        $rate = $this->resolveRate($from, $to);

        return round($amount * $rate, 4);
    }

    /**
     * Same live exchange_rates resolution chain as resolveRate(), but stale
     * rows never throw - an old rate is better than a $0 headline on a
     * read-only admin display. Transactional paths (checkout, funding) must
     * keep using resolveRate()/convert() so the staleness guard still bites
     * where money moves.
     */
    public function resolveRateForDisplay(string $baseCurrency, string $targetCurrency): float
    {
        $base = strtoupper(trim($baseCurrency));
        $target = strtoupper(trim($targetCurrency));

        if ($base === $target) {
            return 1.0;
        }

        $rateRow = ExchangeRate::active()
            ->where('base_currency', $base)
            ->where('target_currency', $target)
            ->orderBy('fetched_at', 'desc')
            ->first();

        if ($rateRow) {
            return (float) $rateRow->rate;
        }

        $inverseRateRow = ExchangeRate::active()
            ->where('base_currency', $target)
            ->where('target_currency', $base)
            ->orderBy('fetched_at', 'desc')
            ->first();

        if ($inverseRateRow && (float) $inverseRateRow->rate > 0) {
            return 1.0 / (float) $inverseRateRow->rate;
        }

        return $this->resolveFallbackRate($base, $target);
    }

    /**
     * Display-safe conversion for read-only views. See resolveRateForDisplay().
     */
    public function convertForDisplay(float $amount, string $from, string $to): float
    {
        return round($amount * $this->resolveRateForDisplay($from, $to), 4);
    }

    /**
     * Generate an audit snapshot array for a currency pair.
     */
    public function generateSnapshot(string $base, string $target): array
    {
        $base = strtoupper(trim($base));
        $target = strtoupper(trim($target));

        $rateRow = ExchangeRate::active()
            ->where('base_currency', $base)
            ->where('target_currency', $target)
            ->orderBy('fetched_at', 'desc')
            ->first();

        if ($rateRow) {
            return [
                'base' => $base,
                'target' => $target,
                'rate' => (float) $rateRow->rate,
                'provider' => $rateRow->provider,
                'source' => $rateRow->source,
                'fetched_at' => $rateRow->fetched_at->toIso8601String(),
                'type' => 'registry',
            ];
        }

        // Fallback
        $rate = $this->resolveFallbackRate($base, $target);

        return [
            'base' => $base,
            'target' => $target,
            'rate' => $rate,
            'provider' => 'currency_rates_fallback',
            'source' => 'system_fallback',
            'fetched_at' => now()->toIso8601String(),
            'type' => 'fallback',
        ];
    }

    /**
     * Validate rate freshness and raise alerts if stale.
     *
     * @throws StaleRateException
     */
    protected function validateRateFreshness(ExchangeRate $rateRow): void
    {
        $ageHours = $rateRow->fetched_at->diffInHours(now());

        // Stale rate threshold: 24 hours
        if ($ageHours > 24) {
            Log::warning('Stale exchange rate detected in registry.', [
                'id' => $rateRow->id,
                'base' => $rateRow->base_currency,
                'target' => $rateRow->target_currency,
                'fetched_at' => $rateRow->fetched_at,
                'age_hours' => $ageHours,
            ]);

            if ($ageHours > 48) {
                throw new StaleRateException("Exchange rate is critically stale. Age: {$ageHours} hours. Base: {$rateRow->base_currency}, Target: {$rateRow->target_currency}");
            }
        }
    }

    /**
     * Resolves a synthetic rate using the legacy currency_rates table.
     *
     * Strict mode is for money. CurrencyRate::resolve() answers an unknown or
     * deactivated code with the USD row, which is the right thing for a price
     * label (show dollars rather than nothing) and the wrong thing for a charge:
     * it turned "5.00 ZZZ" into a real order for 5.00 of a currency we do not
     * hold a rate for. In strict mode each code must have its own active row.
     *
     * @throws UnknownCurrencyException
     */
    protected function resolveFallbackRate(string $base, string $target, bool $strict = false): float
    {
        $baseRateObj = $strict ? $this->activeRateRow($base) : CurrencyRate::resolve($base);
        $targetRateObj = $strict ? $this->activeRateRow($target) : CurrencyRate::resolve($target);

        $basePerUsd = (float) $baseRateObj->rate_per_usd;
        $targetPerUsd = (float) $targetRateObj->rate_per_usd;

        if ($basePerUsd <= 0 || $targetPerUsd <= 0) {
            return 0.0;
        }

        // If converting USD -> NGN: targetPerUsd / basePerUsd (USD is base, NGN is target)
        // targetPerUsd (1400 NGN) / basePerUsd (1.0 USD) = 1400 NGN per USD
        return $targetPerUsd / $basePerUsd;
    }

    /**
     * The active rate row for exactly this code, or a refusal. No substitution,
     * with one exception that is not a substitution: USD is the settlement
     * currency every rate in the table is expressed against, so when it has no
     * row of its own it is the anchor at 1.0, exactly as CurrencyRate::resolve()
     * treats it. Any other code must have its own active row.
     *
     * @throws UnknownCurrencyException
     */
    private function activeRateRow(string $code): CurrencyRate
    {
        $row = CurrencyRate::query()
            ->where('is_active', true)
            ->where('code', $code)
            ->first();

        if ($row !== null) {
            return $row;
        }

        if ($code === 'USD') {
            return new CurrencyRate([
                'code' => 'USD',
                'name' => 'United States Dollar',
                'type' => 'fiat',
                'rate_per_usd' => 1.0,
            ]);
        }

        throw new UnknownCurrencyException($code);
    }
}
