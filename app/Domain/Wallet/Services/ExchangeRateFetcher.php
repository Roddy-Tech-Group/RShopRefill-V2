<?php

namespace App\Domain\Wallet\Services;

use Illuminate\Support\Facades\Http;

/**
 * Pulls a fresh USD-base rate snapshot from a free public FX provider so the
 * admin doesn't have to maintain ~160 currencies by hand. Crypto codes are
 * left alone - those are still admin-managed because public FX providers
 * don't cover them with the same quality.
 *
 * Provider: open.er-api.com (free, no API key, ~161 fiat currencies, ECB +
 * bank-feed sourced, daily updates). Swap to a different provider by editing
 * `fetchUsdRates()` - the rest of the system only cares that this method
 * returns a [code => rate_per_usd] array.
 */
class ExchangeRateFetcher
{
    private const ENDPOINTS = [
        'https://open.er-api.com/v6/latest/USD',
        'https://api.exchangerate-api.com/v4/latest/USD',
    ];

    /**
     * Fetch the latest USD-base FX rates from the provider.
     *
     * @return array<string, float> keyed by uppercase ISO code, e.g. ['NGN' => 1492.5, ...]
     *
     * @throws \RuntimeException when all providers fail to return a valid payload.
     */
    public function fetchUsdRates(): array
    {
        $body = null;
        $lastException = null;

        foreach (self::ENDPOINTS as $endpoint) {
            try {
                $response = Http::timeout(30)->retry(2, 1000)->get($endpoint);

                if ($response->ok()) {
                    $json = $response->json();
                    if (is_array($json['rates'] ?? null)) {
                        $body = $json;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $lastException = $e;

                continue;
            }
        }

        if (! $body) {
            throw new \RuntimeException('All FX providers failed. Last error: '.($lastException ? $lastException->getMessage() : 'Unknown error'));
        }

        // Normalise keys to uppercase + values to float. Force USD to 1.0
        // even if the provider returns something else (some endpoints round).
        $rates = [];
        foreach ($body['rates'] as $code => $value) {
            $rates[strtoupper((string) $code)] = (float) $value;
        }
        $rates['USD'] = 1.0;

        return $rates;
    }
}
