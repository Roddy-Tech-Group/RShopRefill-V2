<?php

namespace App\Domain\Shared\Adapters\Maplerad;

use App\Domain\Shared\Contracts\ICardProvider;
use Illuminate\Support\Facades\Http;
use Exception;

class MapleradCardAdapter implements ICardProvider
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
        return Http::withToken($this->secretKey)->baseUrl($this->baseUrl)->timeout(15);
    }

    public function createVirtualCard(array $data): array
    {
        $response = $this->client()->post('/issuing', [
            'customer_id' => $data['customer_id'],
            'currency' => $data['currency'],
            'type' => 'VIRTUAL',
            'amount' => $data['amount'],
            'auto_approve' => true
        ]);
        
        if (!$response->successful()) {
            throw new Exception('Maplerad Card Creation Failed: ' . $response->body());
        }

        return $response->json('data');
    }

    public function fundCard(string $cardId, int $amount, string $currency): array
    {
        $response = $this->client()->post("/issuing/{$cardId}/fund", [
            'amount' => $amount
        ]);
        
        if (!$response->successful()) {
            throw new Exception('Maplerad Card Funding Failed: ' . $response->body());
        }

        return $response->json('data');
    }

    public function freezeCard(string $cardId): bool
    {
        $response = $this->client()->patch("/issuing/{$cardId}/freeze");
        return $response->successful();
    }

    public function unfreezeCard(string $cardId): bool
    {
        $response = $this->client()->patch("/issuing/{$cardId}/unfreeze");
        return $response->successful();
    }

    public function terminateCard(string $cardId): bool
    {
        $response = $this->client()->patch("/issuing/{$cardId}/terminate");
        return $response->successful();
    }

    public function getCardTransactions(string $cardId, array $filters = []): array
    {
        $response = $this->client()->get("/issuing/{$cardId}/transactions", $filters);
        
        if (!$response->successful()) {
            throw new Exception('Maplerad Card Transactions Fetch Failed: ' . $response->body());
        }

        return $response->json('data');
    }
}
