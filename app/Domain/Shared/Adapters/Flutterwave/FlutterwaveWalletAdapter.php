<?php

namespace App\Domain\Shared\Adapters\Flutterwave;

use App\Domain\Shared\Contracts\IWalletProvider;
use Illuminate\Support\Facades\Http;
use Exception;

class FlutterwaveWalletAdapter implements IWalletProvider
{
    private string $baseUrl;
    private string $secretKey;

    public function __construct()
    {
        $this->baseUrl = config('services.flutterwave.url', 'https://api.flutterwave.com/v3');
        $this->secretKey = config('services.flutterwave.secret_key');
    }

    private function client()
    {
        return Http::withToken($this->secretKey)->baseUrl($this->baseUrl)->timeout(15);
    }

    public function createWallet(array $customerData): array
    {
        // Flutterwave Virtual Accounts creation
        $response = $this->client()->post('/virtual-account-numbers', [
            'email' => $customerData['email'],
            'is_permanent' => true,
            'bvn' => $customerData['bvn'] ?? null,
            'tx_ref' => $customerData['reference']
        ]);
        
        if (!$response->successful()) {
            throw new Exception('Flutterwave Wallet Creation Failed: ' . $response->body());
        }

        return $response->json('data');
    }

    public function getBalance(string $currency, ?string $walletId = null): array
    {
        $response = $this->client()->get("/balances/{$currency}");
        
        if (!$response->successful()) {
            throw new Exception('Flutterwave Balance Fetch Failed: ' . $response->body());
        }

        $data = $response->json('data');

        return [
            'currency' => $currency,
            'amount' => $data['available_balance'],
            'raw_response' => $data
        ];
    }

    public function withdraw(int $amount, string $currency, array $bankDetails): array
    {
        $response = $this->client()->post('/transfers', [
            'account_bank' => $bankDetails['bank_code'],
            'account_number' => $bankDetails['account_number'],
            'amount' => $amount,
            'currency' => $currency,
            'narration' => $bankDetails['reason'] ?? 'Withdrawal',
            'reference' => $bankDetails['reference'] ?? uniqid('tx_')
        ]);

        if (!$response->successful()) {
            throw new Exception('Flutterwave Withdrawal Failed: ' . $response->body());
        }

        return $response->json('data');
    }
}
