<?php

namespace App\Domain\Payment\Support;

use App\Domain\Wallet\Services\CurrencyRateService;
use App\Models\CurrencyRate;
use Illuminate\Support\Facades\Log;

/**
 * The one answer to "can this coin take a payment of this value?".
 *
 * Coin minimums differ by more than two orders of magnitude and float with
 * network conditions, so the check has to run BEFORE anything is created, or
 * the customer commits to a coin and only then meets a raw gateway rejection.
 * The storefront hides unusable coins already; this is the authoritative
 * check, since the list it renders from can be stale.
 *
 * Shared by the checkout pay step and the wallet-funding commit so the two
 * surfaces can never disagree about which coins are usable.
 */
final class CryptoCoinGate
{
    public function __construct(
        private readonly CurrencyRateService $rates,
    ) {}

    /**
     * Why the coin cannot be used for this value, as a customer-safe sentence,
     * or null when it can.
     */
    public function rejection(string $payCurrency, float $amount, string $currency): ?string
    {
        $coin = CurrencyRate::query()
            ->crypto()
            ->where('code', strtoupper(trim($payCurrency)))
            ->first();

        if (! $coin) {
            return 'That cryptocurrency is not available. Please choose another coin.';
        }

        if (! $coin->is_active || ! $coin->is_payable) {
            return "{$coin->name} is temporarily unavailable. Please choose another coin.";
        }

        // Fails open on an unsynced minimum - see CurrencyRate::acceptsOrderValue.
        $usd = $this->valueInUsd($amount, $currency);

        if ($usd !== null && ! $coin->acceptsOrderValue($usd)) {
            $minimum = '$'.number_format((float) $coin->min_amount_usd, 2);

            return "Payments with {$coin->name} must be at least {$minimum}. Please choose another coin or increase the amount.";
        }

        return null;
    }

    /**
     * The value in USD, or null when it cannot be established.
     *
     * Null means "do not gate" rather than "reject": a rate lookup failure must
     * not block a payment the gateway would have accepted.
     */
    public function valueInUsd(float $amount, string $currency): ?float
    {
        $currency = strtoupper(trim($currency));

        if ($amount <= 0) {
            return null;
        }

        if ($currency === 'USD') {
            return $amount;
        }

        try {
            return $amount * $this->rates->resolveRate($currency, 'USD');
        } catch (\Throwable $e) {
            Log::warning('Could not resolve value in USD for crypto minimum check', [
                'currency' => $currency,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
