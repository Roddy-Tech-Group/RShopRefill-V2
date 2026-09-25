<?php

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Exceptions\InvalidWebhookException;
use App\Domain\Payment\Support\FlutterwaveHttp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Handles interactions with the Flutterwave API.
 */
class FlutterwaveService
{
    private string $secretKey;

    private string $webhookHash;

    private string $baseUrl;

    public function __construct()
    {
        $this->secretKey = config('services.flutterwave.secret_key', '');
        $this->webhookHash = config('services.flutterwave.webhook_hash', '');
        $this->baseUrl = 'https://api.flutterwave.com/v3';
    }

    /**
     * Verify the incoming webhook signature against our configured hash.
     *
     * @throws InvalidWebhookException
     */
    public function verifyWebhookSignature(?string $signature): void
    {
        if (! $signature || $signature !== $this->webhookHash) {
            Log::warning('Flutterwave webhook signature mismatch.', ['provided' => $signature]);
            throw InvalidWebhookException::signatureMismatch();
        }
    }

    /**
     * Verify a transaction directly with the Flutterwave API.
     * We NEVER trust the webhook payload alone for crediting wallets.
     */
    public function verifyTransaction(string $transactionId): ?array
    {
        $response = FlutterwaveHttp::readClient($this->secretKey)
            ->get("{$this->baseUrl}/transactions/{$transactionId}/verify");

        if ($response->successful()) {
            return $response->json('data');
        }

        Log::error('Flutterwave transaction verification failed.', [
            'transaction_id' => $transactionId,
            'status' => $response->status(),
            'response' => $response->json(),
        ]);

        return null;
    }

    /**
     * Initialize a new payment request to get the payment link.
     */
    public function initializePayment(array $payload): ?array
    {
        $response = FlutterwaveHttp::client($this->secretKey)
            ->post("{$this->baseUrl}/payments", $payload);

        if ($response->successful()) {
            return $response->json('data');
        }

        Log::error('Flutterwave payment initialization failed.', [
            'payload' => $payload,
            'status' => $response->status(),
            'response' => $response->json(),
        ]);

        return null;
    }

    /**
     * Fetch the list of banks for a given country. The list changes rarely
     * and every read counts against the same gateway budget as a charge, so
     * a non-empty answer is kept for a day.
     */
    public function getBanks(string $country = 'NG'): array
    {
        $country = strtoupper($country);
        $cacheKey = 'flutterwave:banks:'.$country;
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $response = FlutterwaveHttp::readClient($this->secretKey)
            ->get("{$this->baseUrl}/banks/{$country}");

        if ($response->successful()) {
            $banks = $response->json('data') ?? [];

            if ($banks !== []) {
                Cache::put($cacheKey, $banks, now()->addDay());
            }

            return $banks;
        }

        Log::error('Flutterwave get banks failed.', [
            'country' => $country,
            'status' => $response->status(),
            'response' => $response->json(),
        ]);

        return [];
    }

    /**
     * Resolve a bank account number to get the account name.
     */
    public function resolveBankAccount(string $accountNumber, string $bankCode): ?array
    {
        $response = FlutterwaveHttp::client($this->secretKey)
            ->post("{$this->baseUrl}/accounts/resolve", [
                'account_number' => $accountNumber,
                'account_bank' => $bankCode,
            ]);

        if ($response->successful()) {
            return $response->json('data');
        }

        return null;
    }
}
