<?php

namespace App\Domain\Fulfillment\Console;

use App\Notifications\CriticalSystemAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CheckZenditBalanceCommand extends Command
{
    protected $signature = 'zendit:check-balance {--threshold=250}';

    protected $description = 'Check Zendit API balance and alert if it falls below threshold';

    public function handle()
    {
        $threshold = (float) $this->option('threshold');
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

            if ($balance < $threshold) {
                $msg = "Zendit account balance has dropped below the threshold ({$threshold} {$currency}). Current Balance: {$balance} {$currency}. Fulfillments may begin to fail if funds run out.";
                Log::critical($msg);

                // Alert Admin via CriticalSystemAlert
                $adminEmail = 'dev@roddytechgroup.com';

                Notification::route('mail', $adminEmail)
                    ->notify(new CriticalSystemAlert(
                        title: 'Zendit Balance Low',
                        description: $msg,
                        context: [
                            'Threshold' => $threshold,
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
