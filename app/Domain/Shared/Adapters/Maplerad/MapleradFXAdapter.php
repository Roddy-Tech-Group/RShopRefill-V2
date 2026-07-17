<?php

namespace App\Domain\Shared\Adapters\Maplerad;

use App\Domain\Shared\Contracts\IFXProvider;
use Illuminate\Support\Facades\Http;
use Exception;

class MapleradFXAdapter implements IFXProvider
{
    private string $baseUrl;
    private string $secretKey;

    public function __construct()
    {
        $this->baseUrl = config('services.maplerad.url', 'https://api.maplerad.com/v1');
        $this->secretKey = config('services.maplerad.secret');
    }

    private function client()
    {
        return Http::withToken($this->secretKey)->baseUrl($this->baseUrl)->timeout(10);
    }

    public function getQuote(string $sourceCurrency, string $destinationCurrency, int $amount): array
    {
        $response = $this->client()->post('/fx/quote', [
            'source_currency' => $sourceCurrency,
            'target_currency' => $destinationCurrency,
            'amount' => $amount
        ]);
        
        if (!$response->successful()) {
            throw new Exception('Maplerad FX Quote Failed: ' . $response->body());
        }

        return $response->json('data');
    }

    public function executeExchange(string $quoteId): array
    {
        $response = $this->client()->post("/fx/exchange", [
            'quote_reference' => $quoteId
        ]);
        
        if (!$response->successful()) {
            throw new Exception('Maplerad FX Exchange Failed: ' . $response->body());
        }

        return $response->json('data');
    }
}
