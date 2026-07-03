<?php

namespace App\Domain\Fulfillment\Providers;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Interfaces\FulfillmentProviderInterface;
use App\Domain\Shared\Services\CircuitBreaker;
use App\Models\FulfillmentLog;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZenditFulfillmentProvider implements FulfillmentProviderInterface
{
    private string $apiKey;

    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.zendit.api_key') ?: 'ZENDIT_API_KEY_MOCK';

        // Explicit base URL from config always wins.
        $explicitUrl = config('services.zendit.base_url');
        if ($explicitUrl) {
            $this->baseUrl = $explicitUrl;
        } else {
            $this->baseUrl = 'https://api.zendit.io/v1';
        }
    }

    public function fulfill(OrderItem $item): array
    {
        $offerId = $item->provider_offer_id;

        $title = strtolower($item->product_snapshot['name'] ?? '');
        // The product_snapshot didn't carry the nested category for historical
        // orders, so fall back to the FK relation. Without this, every top-up
        // order was being routed to /vouchers/purchases (gift-card endpoint).
        $categorySlug = (string) ($item->product_snapshot['category']['slug'] ?? $item->category?->slug ?? '');
        $isEsim = $categorySlug === 'esims' || strpos($title, 'esim') !== false;
        $isTopup = $categorySlug === 'mobile-airtime' || $categorySlug === 'topups' || strpos($title, 'top up') !== false;
        $isBill = $categorySlug === 'bill-payments' || strpos($title, 'bill') !== false;

        $email = 'dev@roddytechgroup.com';
        $firstName = 'Rshop';
        $lastName = 'Refills';

        // Zendit treats transactionId as unique forever - a failed transaction
        // burns its ID ("transaction_duplicate_id" on reuse). Manual retries
        // bump `fulfillment_retry` in the item metadata (RetryFulfillmentCommand),
        // producing a fresh ID per attempt, while automatic queue retries within
        // the same attempt reuse the ID so a timed-out request can't double-buy.
        $retrySequence = (int) data_get($item->metadata, 'fulfillment_retry', 0);
        $customTransactionId = 'RSR-'.str_replace('-', '', (string) $item->id)
            .($retrySequence > 0 ? '-R'.$retrySequence : '');

        if ($isEsim) {
            $requestPayload = [
                'offerId' => $offerId,
                'transactionId' => $customTransactionId,
            ];
            $endpoint = '/esim/purchases';
        } elseif ($isTopup) {
            // Mobile top-up: requires the recipient phone the buyer entered on
            // the product page; CheckoutService persists it to order_items.metadata.
            // E.164 format is the safest interchange (e.g. "+17575551234").
            $recipientPhone = (string) ($item->metadata['recipient_phone'] ?? '');

            if ($recipientPhone === '') {
                Log::error("Zendit top-up: missing recipient phone on order item {$item->id}");

                FulfillmentLog::create([
                    'order_item_id' => $item->id,
                    'provider_name' => 'zendit',
                    'request_payload' => ['offerId' => $offerId, 'transactionId' => $customTransactionId],
                    'response_payload' => null,
                    'status' => 'FAILED',
                    'error_message' => 'No recipient_phone in order item metadata',
                ]);

                return [
                    'status' => FulfillmentStatus::Failed,
                    'reference' => null,
                    'payload' => ['error' => 'No recipient phone number on file for this top-up.'],
                ];
            }

            $requestPayload = [
                'offerId' => $offerId,
                'transactionId' => $customTransactionId,
                // Zendit's TopupPurchaseMakeInput uses `recipientPhoneNumber`
                // (the Go struct's RecipientPhoneNumber field). Sending
                // `recipientPhone` triggers a "required" validation error.
                'recipientPhoneNumber' => $recipientPhone,
            ];

            // Variable-value top-ups: Zendit takes the amount in the operator's
            // minor units (e.g. cents). Same convention as gift cards.
            if ($variableValue = $this->variablePriceValue($item)) {
                $requestPayload['value'] = $variableValue;
            }
            $endpoint = '/topups/purchases';
        } elseif ($isBill) {
            // Bill payment: Zendit's /bills/purchases (or /vouchers with the
            // billing.accountId field, depending on operator) expects the
            // customer's biller account / meter number. Buyer types it on the
            // product page; CheckoutService persists it to order_items.metadata.
            $accountId = (string) ($item->metadata['account_id'] ?? '');

            if ($accountId === '') {
                Log::error("Zendit bill: missing account_id on order item {$item->id}");

                FulfillmentLog::create([
                    'order_item_id' => $item->id,
                    'provider_name' => 'zendit',
                    'request_payload' => ['offerId' => $offerId, 'transactionId' => $customTransactionId],
                    'response_payload' => null,
                    'status' => 'FAILED',
                    'error_message' => 'No account_id in order item metadata',
                ]);

                return [
                    'status' => FulfillmentStatus::Failed,
                    'reference' => null,
                    'payload' => ['error' => 'No account / meter number on file for this bill.'],
                ];
            }

            $requestPayload = [
                'offerId' => $offerId,
                'transactionId' => $customTransactionId,
                'fields' => [
                    ['key' => 'billing.accountId', 'value' => $accountId],
                    ['key' => 'recipient.email', 'value' => $email],
                ],
            ];

            // Variable-amount bills (post-paid, water, etc.) use the same minor-
            // units convention as vouchers + top-ups.
            if ($variableValue = $this->variablePriceValue($item)) {
                $requestPayload['value'] = $variableValue;
            }
            $endpoint = '/vouchers/purchases'; // Zendit routes bills through the same vouchers endpoint
        } else {
            $requestPayload = [
                'offerId' => $offerId,
                'transactionId' => $customTransactionId,
                'fields' => [
                    ['key' => 'recipient.email', 'value' => $email],
                    ['key' => 'recipient.firstName', 'value' => $firstName],
                    ['key' => 'recipient.lastName', 'value' => $lastName],
                ],
            ];

            // For variable price items, Zendit requires value in minor units
            if ($variableValue = $this->variablePriceValue($item)) {
                $requestPayload['value'] = $variableValue;
            }
            $endpoint = '/vouchers/purchases';
        }

        // Mock mode
        if (str_contains($this->apiKey, 'MOCK')) {
            $responsePayload = $this->getMockResponse($item, $requestPayload['transactionId']);

            FulfillmentLog::create([
                'order_item_id' => $item->id,
                'provider_name' => 'zendit',
                'request_payload' => $requestPayload,
                'response_payload' => $responsePayload,
                'status' => 'SUCCESS',
            ]);

            return [
                'status' => FulfillmentStatus::Fulfilled,
                'reference' => $responsePayload['transactionId'] ?? 'MOCK-TX-'.uniqid(),
                'payload' => $responsePayload,
            ];
        }

        $breaker = new CircuitBreaker('zendit_api', 10, 5);
        if ($breaker->isOpen()) {
            Log::warning("Zendit API circuit breaker is OPEN. Failing gracefully for item {$item->id}");

            return [
                'status' => FulfillmentStatus::Failed,
                'reference' => null,
                'payload' => ['error' => 'Zendit API circuit breaker is open due to recent failures. Please try again later.'],
            ];
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(15)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->post("{$this->baseUrl}{$endpoint}", $requestPayload);

            $responseBody = $response->json() ?? [];

            FulfillmentLog::create([
                'order_item_id' => $item->id,
                'provider_name' => 'zendit',
                'request_payload' => $requestPayload,
                'response_payload' => $responseBody,
                'status' => $response->successful() ? 'SUCCESS' : 'FAILED',
                'error_message' => $response->successful() ? null : $response->body(),
            ]);

            if ($response->failed() || ! isset($responseBody['transactionId'])) {
                // We resent a transaction id Zendit already holds (typically an auto-retry
                // after a timed-out response). Treating this as a failure would refund an
                // item Zendit may have actually delivered - instead look up the existing
                // transaction's real status and act on that.
                if (($responseBody['errorCode'] ?? null) === 'transaction_duplicate_id') {
                    $breaker->recordSuccess();

                    return $this->recoverDuplicateTransaction($item, $customTransactionId, $isEsim, $isTopup);
                }

                $breaker->recordFailure();
                Log::error("Zendit transaction failed: {$item->id}", [
                    'status' => $response->status(),
                    'body' => $responseBody,
                ]);

                return [
                    'status' => FulfillmentStatus::Failed,
                    'reference' => null,
                    'payload' => $responseBody,
                ];
            }

            $txStatus = $responseBody['status'] ?? 'PENDING';
            $statusEnum = match (strtoupper($txStatus)) {
                'SUCCESS', 'COMPLETED', 'DONE' => FulfillmentStatus::Fulfilled,
                'PENDING', 'PROCESSING', 'AUTHORIZED', 'IN_PROGRESS', 'ACCEPTED' => FulfillmentStatus::Processing,
                default => FulfillmentStatus::Failed,
            };

            $breaker->recordSuccess();

            return [
                'status' => $statusEnum,
                // In v1, they poll using Zendit's returned transactionId!
                'reference' => $responseBody['transactionId'],
                'payload' => $responseBody,
            ];
        } catch (\Exception $e) {
            $breaker->recordFailure();

            Log::error('Zendit fulfillment exception: '.$e->getMessage());

            FulfillmentLog::create([
                'order_item_id' => $item->id,
                'provider_name' => 'zendit',
                'request_payload' => $requestPayload,
                'response_payload' => null,
                'status' => 'EXCEPTION',
                'error_message' => $e->getMessage(),
            ]);

            return [
                'status' => FulfillmentStatus::Failed,
                'reference' => null,
                'payload' => [],
            ];
        }
    }

    /**
     * Build Zendit's `value` object for a variable/custom-amount item, or null
     * for a fixed-denomination item (whose offerId already encodes the value).
     *
     * Zendit wants the amount in the OFFER's currency, in minor units. We send
     * `provider_face_value` (the chosen face value captured at checkout in the
     * offer currency, e.g. USD for a Visa card) - NOT `display_amount`, which is
     * the display-currency figure. Sending the converted display_amount made
     * Zendit read a USD card's XAF amount as USD ("value is out of range").
     *
     * Older items predate the provider_face_value column; back the offer amount
     * out of display_amount via the order's stored exchange rate so an in-flight
     * order never sends a converted figure.
     *
     * @return array{type: string, value: int}|null
     */
    private function variablePriceValue(OrderItem $item): ?array
    {
        if (empty($item->variant_snapshot['is_variable'])) {
            return null;
        }

        $divisor = (float) ($item->variant_snapshot['metadata']['send']['currencyDivisor'] ?? 100);

        $faceValue = $item->provider_face_value;
        if ($faceValue === null) {
            $rate = (float) (data_get($item->order, 'metadata.exchange_rate') ?: 1);
            $faceValue = $rate > 0 ? ((float) $item->display_amount / $rate) : (float) $item->display_amount;
        }

        return [
            'type' => 'PRICE',
            'value' => (int) round((float) $faceValue * $divisor),
        ];
    }

    /**
     * Resolve a "transaction_duplicate_id" rejection by fetching the existing
     * transaction's real status (Zendit keys transactions by the id we supply).
     *
     * A delivered transaction comes back Fulfilled (no false refund); an
     * unconfirmed or errored lookup stays Processing so polling retries rather
     * than refunding something that may have gone through; a genuinely declined
     * transaction comes back Failed so the normal refund path runs.
     *
     * @return array{status: FulfillmentStatus, reference: string, payload: array<string, mixed>}
     */
    private function recoverDuplicateTransaction(OrderItem $item, string $transactionId, bool $isEsim, bool $isTopup): array
    {
        $endpoint = match (true) {
            $isEsim => "/esim/purchases/{$transactionId}",
            $isTopup => "/topups/purchases/{$transactionId}",
            default => "/vouchers/purchases/{$transactionId}",
        };

        try {
            $response = Http::withoutVerifying()
                ->timeout(10)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->get("{$this->baseUrl}{$endpoint}");

            $body = $response->json() ?? [];

            if ($response->failed()) {
                Log::warning("Zendit duplicate-id lookup unconfirmed for {$transactionId} (item {$item->id})", [
                    'http_status' => $response->status(),
                    'body' => $body,
                ]);

                // Can't confirm - keep it in flight rather than refund a possible delivery.
                return ['status' => FulfillmentStatus::Processing, 'reference' => $transactionId, 'payload' => $body];
            }

            $statusEnum = match (strtoupper($body['status'] ?? 'PENDING')) {
                'SUCCESS', 'COMPLETED', 'DONE' => FulfillmentStatus::Fulfilled,
                'PENDING', 'PROCESSING', 'AUTHORIZED', 'IN_PROGRESS', 'ACCEPTED' => FulfillmentStatus::Processing,
                default => FulfillmentStatus::Failed,
            };

            return ['status' => $statusEnum, 'reference' => $transactionId, 'payload' => $body];
        } catch (\Exception $e) {
            Log::error("Zendit duplicate-id lookup threw for {$transactionId}: ".$e->getMessage());

            return ['status' => FulfillmentStatus::Processing, 'reference' => $transactionId, 'payload' => []];
        }
    }

    public function verifyStatus(OrderItem $item): array
    {
        if (str_contains($this->apiKey, 'MOCK')) {
            return [
                'status' => FulfillmentStatus::Fulfilled,
                'payload' => $item->fulfillment_payload ?? [],
            ];
        }

        try {
            $zenditTxId = $item->fulfillment_reference;
            if (! $zenditTxId) {
                Log::warning("Zendit verifyStatus: no fulfillment_reference on item {$item->id}");

                return ['status' => FulfillmentStatus::Failed, 'payload' => []];
            }

            $title = strtolower($item->product_snapshot['name'] ?? '');
            $categorySlug = (string) ($item->product_snapshot['category']['slug'] ?? $item->category?->slug ?? '');
            $isEsim = $categorySlug === 'esims' || strpos($title, 'esim') !== false;
            $isTopup = $categorySlug === 'mobile-airtime' || $categorySlug === 'topups' || strpos($title, 'top up') !== false;

            // Status endpoint mirrors the purchase endpoint. Without the
            // top-up branch we'd GET /vouchers/purchases/{txId} for airtime
            // and Zendit would always come back blank → item stuck Processing.
            $endpoint = match (true) {
                $isEsim => "/esim/purchases/{$zenditTxId}",
                $isTopup => "/topups/purchases/{$zenditTxId}",
                default => "/vouchers/purchases/{$zenditTxId}",
            };

            $response = Http::withoutVerifying()
                ->timeout(10)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->get("{$this->baseUrl}{$endpoint}");

            $responseBody = $response->json() ?? [];

            if ($response->failed()) {
                Log::error("Zendit verifyStatus failed for {$zenditTxId}", [
                    'http_status' => $response->status(),
                    'body' => $responseBody,
                ]);

                return [
                    'status' => FulfillmentStatus::Processing,
                    'payload' => $responseBody,
                ];
            }

            $txStatus = $responseBody['status'] ?? 'PENDING';
            $statusEnum = match (strtoupper($txStatus)) {
                'SUCCESS', 'COMPLETED', 'DONE' => FulfillmentStatus::Fulfilled,
                'PENDING', 'PROCESSING', 'AUTHORIZED', 'IN_PROGRESS', 'ACCEPTED' => FulfillmentStatus::Processing,
                default => FulfillmentStatus::Failed,
            };

            return [
                'status' => $statusEnum,
                'payload' => $responseBody,
            ];
        } catch (\Exception $e) {
            Log::error('Zendit verifyStatus exception: '.$e->getMessage());

            return ['status' => FulfillmentStatus::Processing, 'payload' => []];
        }
    }

    public function refund(OrderItem $item): bool
    {
        // Zendit handles transactions instantly. Reversals are usually manual or automated by Zendit upon transaction timeouts.
        return true;
    }

    public function normalizeResponse(array $rawPayload): array
    {
        // Normalize response to extract code/pin/eSIM QR
        $items = $rawPayload['items'] ?? [];
        $pins = [];
        $flat = [];

        foreach ($items as $v) {
            $pins[] = [
                'code' => $v['code'] ?? null,
                'pin' => $v['pin'] ?? null,
                'serialNumber' => $v['serialNumber'] ?? null,
                'instructions' => $v['redemptionInstructions'] ?? null,
                'redemption_url' => $v['redemptionUrl'] ?? null,
            ];
        }

        $receipt = $rawPayload['receipt'] ?? null;
        if ($receipt) {
            if (! empty($receipt['voucherId']) && empty($pins)) {
                $pins[] = ['pin' => $receipt['voucherId']];
            }
            if (! empty($receipt['redemptionUrl'])) {
                $flat['redemption_url'] = $receipt['redemptionUrl'];
            }
            if (! empty($receipt['epin'])) {
                $flat['epin'] = $receipt['epin'];
            }
            if (! empty($receipt['instructions'])) {
                $flat['instructions'] = $receipt['instructions'];
            }
        }

        if (! empty($pins)) {
            $firstPin = $pins[0];
            if (! empty($firstPin['code'])) {
                $flat['code'] = $firstPin['code'];
            }
            if (! empty($firstPin['pin'])) {
                $flat['pin'] = $firstPin['pin'];
            }
            if (! empty($firstPin['serialNumber'])) {
                $flat['serial_number'] = $firstPin['serialNumber'];
            }
            if (! empty($firstPin['instructions'])) {
                $flat['instructions'] = $flat['instructions'] ?? $firstPin['instructions'];
            }
            if (! empty($firstPin['redemption_url'])) {
                $flat['redemption_url'] = $flat['redemption_url'] ?? $firstPin['redemption_url'];
            }
        }

        $esimData = null;
        $confirmation = $rawPayload['confirmation'] ?? null;
        if ($confirmation && ! empty($confirmation['iccid'])) {
            $esimData = [
                'iccid' => $confirmation['iccid'] ?? null,
                'lpaUrl' => $confirmation['smdpAddress'] ?? null,
                'manualActivationCode' => $confirmation['activationCode'] ?? null,
            ];

            $flat['esim_iccid'] = $esimData['iccid'];
            $flat['esim_lpa'] = $esimData['lpaUrl'];
            $flat['esim_activation_code'] = $esimData['manualActivationCode'];

            // Mirror the Airalo normalizer's flat contract (qrcode_url / lpa /
            // iccid) so every eSIM surface - dashboard orders, order page,
            // delivery email - renders Zendit eSIMs through the same template
            // branch instead of falling back to the gift-card code picker.
            $activation = (string) ($esimData['manualActivationCode'] ?? '');
            $lpaString = match (true) {
                str_starts_with($activation, 'LPA:') => $activation,
                $activation !== '' && ! empty($esimData['lpaUrl']) => 'LPA:1$'.$esimData['lpaUrl'].'$'.$activation,
                default => null,
            };

            $flat['iccid'] = $esimData['iccid'];
            $flat['lpa'] = $esimData['lpaUrl'];
            if ($lpaString !== null) {
                $flat['qr_manual_code'] = $lpaString;
                $flat['qrcode_url'] = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data='.urlencode($lpaString);
            }
        }

        // Top-up receipt: Zendit returns receipt.topupId + operator + recipientPhone
        // + value*. There's no code to copy — confirmation just proves the airtime
        // landed on the number. The orders view renders these as a "Credited to …"
        // tile so the customer sees what happened.
        $topup = null;
        if ($receipt && (! empty($receipt['topupId']) || ! empty($receipt['recipientPhone']))) {
            $topup = [
                'topup_id' => $receipt['topupId'] ?? null,
                'recipient_phone' => $receipt['recipientPhone'] ?? null,
                'operator' => $receipt['operator'] ?? null,
                'value_requested' => $receipt['valueRequested'] ?? null,
                'value_delivered' => $receipt['valueDelivered'] ?? null,
                'currency' => $receipt['currency'] ?? null,
            ];
            $flat['topup_recipient_phone'] = $topup['recipient_phone'];
            $flat['topup_id'] = $topup['topup_id'];
        }

        return array_merge($flat, [
            'pins' => $pins,
            'esim' => $esimData,
            'topup' => $topup,
        ]);
    }

    private function getMockResponse(OrderItem $item, string $customIdentifier): array
    {
        $title = strtolower($item->product_snapshot['name'] ?? '');
        $categorySlug = (string) ($item->product_snapshot['category']['slug'] ?? $item->category?->slug ?? '');
        $isEsim = $categorySlug === 'esims' || strpos($title, 'esim') !== false;
        $isTopup = $categorySlug === 'mobile-airtime' || $categorySlug === 'topups' || strpos($title, 'top up') !== false;

        $response = [
            'transactionId' => 'ZND-MOCK-'.uniqid(),
            'customIdentifier' => $customIdentifier,
            'status' => 'SUCCESS',
            'offerId' => $item->provider_offer_id,
            'created' => now()->toIso8601String(),
        ];

        if ($isEsim) {
            $response['esim'] = [
                'iccid' => '89852'.str_pad((string) rand(1, 9999999999999), 13, '0', STR_PAD_LEFT),
                'lpaUrl' => 'rsp.zendit.io',
                'qrCodeUrl' => 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=LPA:1$rsp.zendit.io$MOCK-ACTIVATION-CODE',
                'manualActivationCode' => 'LPA:1$rsp.zendit.io$MOCK-ACTIVATION-CODE',
            ];
        } elseif ($isTopup) {
            $recipientPhone = (string) ($item->metadata['recipient_phone'] ?? '+15551234567');
            $operator = $item->variant_snapshot['metadata']['provider'] ?? $item->product_snapshot['provider_name'] ?? 'Mock Telecom';
            $response['receipt'] = [
                'topupId' => 'MOCK-TOPUP-'.uniqid(),
                'recipientPhone' => $recipientPhone,
                'operator' => $operator,
                'valueRequested' => (float) $item->display_amount,
                'valueDelivered' => (float) $item->display_amount,
                'currency' => $item->display_currency,
            ];
        } else {
            $response['vouchers'] = [
                [
                    'code' => strtoupper(uniqid('MOCK-CODE-')),
                    'pin' => str_pad((string) rand(0, 999999), 6, '0', STR_PAD_LEFT),
                    'serialNumber' => 'SN-'.rand(100000, 999999),
                    'redemptionInstructions' => 'Go to brand website and enter mock code during check out.',
                ],
            ];
        }

        return $response;
    }
}
