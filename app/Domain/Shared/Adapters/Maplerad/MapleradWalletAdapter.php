<?php

namespace App\Domain\Shared\Adapters\Maplerad;

use App\Domain\Shared\Contracts\IWalletProvider;
use Illuminate\Support\Facades\Http;
use Exception;

class MapleradWalletAdapter implements IWalletProvider
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

    public function createWallet(array $customerData): array
    {
        $response = $this->client()->post('/customers', $customerData);
        
        if (!$response->successful()) {
            throw new Exception('Maplerad Customer/Wallet Creation Failed: ' . $response->body());
        }

        return $response->json('data');
    }

    public function getBalance(string $currency, ?string $walletId = null): array
    {
        $response = $this->client()->get('/balances');
        
        if (!$response->successful()) {
            throw new Exception('Maplerad Balance Fetch Failed: ' . $response->body());
        }

        $balances = $response->json('data');
        $amount = 0;
        
        foreach ($balances as $bal) {
            if (strtoupper($bal['currency']) === strtoupper($currency)) {
                $amount = $bal['available_balance'];
                break;
            }
        }

        return [
            'currency' => $currency,
            'amount' => $amount,
            'raw_response' => $balances
        ];
    }

    public function withdraw(int $amount, string $currency, array $bankDetails): array
    {
        $response = $this->client()->post('/transfers', [
            'amount' => $amount, // Maplerad typically expects minor units, ensure this matches docs
            'currency' => $currency,
            'account_number' => $bankDetails['account_number'],
            'bank_code' => $bankDetails['bank_code'],
            'reason' => $bankDetails['reason'] ?? 'Withdrawal',
        ]);

        if (!$response->successful()) {
            throw new Exception('Maplerad Withdrawal Failed: ' . $response->body());
        }

        return $response->json('data');
    }
}
