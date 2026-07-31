<?php

namespace App\Domain\Wallet\Jobs;

use App\Models\CurrencyRate;
use App\Models\ExchangeRate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncExchangeRatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('intelligence');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting scheduled exchange rate sync...');

        try {
            $rates = CurrencyRate::active()->get();

            foreach ($rates as $rate) {
                // Sync USD to Target
                ExchangeRate::updateOrCreate(
                    [
                        'base_currency' => 'USD',
                        'target_currency' => $rate->code,
                        'provider' => 'system_sync',
                    ],
                    [
                        'rate' => $rate->rate_per_usd,
                        'source' => 'currency_rates_db',
                        'is_active' => true,
                        'fetched_at' => now(),
                        'expires_at' => now()->addHours(24),
                    ]
                );

                // Inverse: Target to USD
                if ($rate->code !== 'USD' && $rate->rate_per_usd > 0) {
                    ExchangeRate::updateOrCreate(
                        [
                            'base_currency' => $rate->code,
                            'target_currency' => 'USD',
                            'provider' => 'system_sync',
                        ],
                        [
                            'rate' => round(1.0 / (float) $rate->rate_per_usd, 8),
                            'source' => 'currency_rates_db',
                            'is_active' => true,
                            'fetched_at' => now(),
                            'expires_at' => now()->addHours(24),
                        ]
                    );
                }
            }

            Log::info('Scheduled exchange rate sync completed successfully.', [
                'synced_count' => $rates->count(),
            ]);

        } catch (\Throwable $e) {
            Log::error('Exchange rate sync failed: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
