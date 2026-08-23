<?php

namespace App\Domain\Catalog\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiraloEsimProvider implements ProviderInterface
{
    private string $baseUrl;

    private string $clientId;

    private string $clientSecret;

    public function __construct()
    {
        $this->baseUrl = (string) config('services.airalo.base_url', 'https://partners-api.airalo.com/v2');
        $this->clientId = (string) config('services.airalo.client_id', '');
        $this->clientSecret = (string) config('services.airalo.client_secret', '');
    }

    public function getProviderName(): string
    {
        return 'airalo';
    }

    private function getAccessToken(): string
    {
        return Cache::remember('airalo_access_token', now()->addMinutes(45), function () {
            $response = Http::withoutVerifying()
                ->timeout(10)
                ->asForm()
                ->post("{$this->baseUrl}/token", [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'client_credentials',
                ]);

            if ($response->failed()) {
                Log::error('Airalo OAuth token request failed', ['body' => $response->body()]);
                throw new \Exception('Failed to authenticate with Airalo API');
            }

            return $response->json('data.access_token') ?? $response->json('access_token');
        });
    }

    public function fetchCatalog(int $page = 1, int $limit = 100): array
    {
        $token = $this->getAccessToken();

        $response = Http::withoutVerifying()
            ->withToken($token)
            ->acceptJson()
            ->timeout(20)
            ->retry(3, 5000, function ($exception, $request) {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException ||
                       ($exception instanceof \Illuminate\Http\Client\RequestException && $exception->response->status() === 429);
            })
            ->get("{$this->baseUrl}/packages", [
                'limit' => $limit,
                'page' => $page,
            ]);

        if ($response->failed()) {
            Log::error("Airalo fetchCatalog failed on page {$page}", ['body' => $response->body()]);
            throw new \Exception('Failed to fetch Airalo catalog');
        }

        // Airalo returns data inside 'data', and pagination metadata in 'meta'
        return $response->json();
    }

    public function fetchOfferDetails(string $providerReference): array
    {
        // Not widely used in the generic sync, but required by interface
        // Airalo packages are usually fully detailed in the list response.
        return [];
    }
}
