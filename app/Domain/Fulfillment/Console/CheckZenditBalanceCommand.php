<?php

namespace App\Domain\Fulfillment\Console;

use App\Notifications\CriticalSystemAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class CheckZenditBalanceCommand extends Command
{
    protected $signature = 'zendit:check-balance {--threshold=250}';

    protected $description = 'Check Zendit API balance and alert if it falls below threshold';

    public function handle()
    {
        $apiKey = config('services.zendit.api_key');
        $baseUrl = config('services.zendit.base_url', 'https://api.zendit.io/v1');

        if (empty($apiKey) || str_contains($apiKey, 'MOCK')) {
            $this->info('Zendit API Key is missing or in MOCK mode. Skipping balance check.');

            return;
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(10)
                ->withToken($apiKey)
                ->acceptJson()
                ->get("{$baseUrl}/balance");

            if ($response->failed()) {
                Log::error('Failed to check Zendit balance', ['status' => $response->status(), 'body' => $response->body()]);
                $this->error('Failed to check Zendit balance. Status: '.$response->status());

                return;
            }

            $data = $response->json();
            $divisor = (float) ($data['currencyDivisor'] ?? 100);
            if ($divisor <= 0) $divisor = 100;
            $balance = ((float) ($data['availableBalance'] ?? 0)) / $divisor;
            $currency = $data['currency'] ?? 'USD';

            $this->info("Current Zendit Balance: {$balance} {$currency}");

            if ($balance > 250) {
                // If balance is healthy, reset all alert states
                Cache::forget('zendit_last_alert_level');
                Cache::forget('zendit_low_balance_alerted_at');
                return;
            }

            $shouldAlert = false;
            $msg = "";

            if ($balance <= 100) {
                // Below $100: Alert every 2 hours
                $lastAlerted = Cache::get('zendit_low_balance_alerted_at');
                
                if (! $lastAlerted || now()->diffInHours($lastAlerted) >= 2) {
                    $shouldAlert = true;
                    $msg = "CRITICAL: Zendit balance is severely low ({$balance} {$currency}). Fulfillments may fail very soon.";
                    
                    Cache::put('zendit_low_balance_alerted_at', now());
                    Cache::put('zendit_last_alert_level', 100);
                }
            } else {
                // Between $100 and $250: Tiered alerts
                $triggerLevel = null;
                if ($balance <= 150) {
                    $triggerLevel = 150;
                } elseif ($balance <= 200) {
                    $triggerLevel = 200;
                } elseif ($balance <= 250) {
                    $triggerLevel = 250;
                }

                $lastAlertedLevel = Cache::get('zendit_last_alert_level');

                if (is_null($lastAlertedLevel) || $triggerLevel < $lastAlertedLevel) {
                    $shouldAlert = true;
                    $msg = "Zendit account balance has dropped below {$triggerLevel} {$currency}. Current Balance: {$balance} {$currency}.";
                    
                    Cache::put('zendit_last_alert_level', $triggerLevel);
                }
            }

            if ($shouldAlert) {
                Log::critical($msg);

                // Alert Admin via CriticalSystemAlert
                $adminEmail = 'dev@roddytechgroup.com';

                Notification::route('mail', $adminEmail)
                    ->notify(new CriticalSystemAlert(
                        title: 'Zendit Balance Low',
                        description: $msg,
                        context: [
                            'Current Balance' => $balance,
                            'Currency' => $currency,
                        ]
                    ));

                $this->warn('Critical Alert Sent!');
            }
        } catch (\Exception $e) {
            Log::error('Exception checking Zendit balance: '.$e->getMessage());
            $this->error('Exception: '.$e->getMessage());
        }
    }
}
