<?php

namespace App\Http\Controllers\Api\V2\Catalog;

use App\Http\Controllers\Controller;
use App\Models\CurrencyRate;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/**
 * The settings the app needs before it can render prices or a rewards screen.
 *
 * Fetched once on launch and cached by the app. Everything here is an admin
 * setting rather than a constant, which is the point: turning Rcoin redemption off
 * has to reach the app without shipping a new build.
 */
class ConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                // Every price we hold and settle is USD. Stated explicitly so the
                // app never has to infer it from a response that happens to be
                // showing dollars.
                'settlement_currency' => 'USD',

                // What may be sent in X-Display-Currency. Only currencies we can
                // actually price a conversion for: offering one we cannot would
                // silently fall back to USD and look like a bug.
                'display_currencies' => $this->displayCurrencies(),

                'rcoin' => [
                    'enabled' => (bool) Setting::get('rcoin_enabled', true),
                    'cashback_percentage' => (float) Setting::get('cashback_percentage', 0.5),
                    'usd_rate' => (float) Setting::rcoinUsdRate(),
                    'redemption' => [
                        'enabled' => (bool) Setting::get('redemption_enabled', true),
                        'min_rcoin' => (int) Setting::get('redemption_min_rcoin', 2000),
                        'max_percentage' => (float) Setting::get('redemption_max_percentage', 30.0),
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    private function displayCurrencies(): array
    {
        return CurrencyRate::query()
            ->where('is_active', true)
            ->where('rate_per_usd', '>', 0)
            // Fiat only. The crypto rows in this table are funding rails, not
            // currencies anybody wants a gift card priced in.
            ->where('type', 'fiat')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn (CurrencyRate $rate) => [
                'code' => strtoupper((string) $rate->code),
                'name' => (string) $rate->name,
            ])
            ->values()
            ->all();
    }
}
