<?php

namespace App\Domain\Fulfillment\Providers;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Interfaces\FulfillmentProviderInterface;
use App\Models\FulfillmentLog;
use App\Models\OrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiraloFulfillmentProvider implements FulfillmentProviderInterface
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
                throw new \Exception('Failed to authenticate with Airalo API');
            }

            return $response->json('data.access_token') ?? $response->json('access_token');
        });
    }

    /**
     * Available top-up packages for an existing eSIM (by ICCID). Airalo
     * scopes the catalog to whatever country / operator the original eSIM is
     * provisioned on, so the caller doesn't have to filter. Each row is the
     * raw Airalo package dict; the customer-facing page picks the fields it
     * needs (id, data, day, price, net_price, voice, text).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTopupsForIccid(string $iccid): array
    {
        $iccid = trim($iccid);
        if ($iccid === '') {
            return [];
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(12)
                ->withToken($this->getAccessToken())
                ->acceptJson()
                ->get("{$this->baseUrl}/sims/{$iccid}/topups");

            if ($response->failed()) {
                Log::warning('Airalo listTopups failed', [
                    'iccid' => $iccid,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return [];
            }

            // Airalo wraps lists in `data` (collection). Some endpoints use
            // `data.packages`; handle both shapes so we don't break if the
            // partner-API response evolves.
            $body = $response->json() ?? [];
            $list = $body['data']['packages']
                ?? $body['data']
                ?? $body['packages']
                ?? [];

            return is_array($list) ? array_values($list) : [];
        } catch (\Exception $e) {
            Log::error('Airalo listTopups exception: '.$e->getMessage(), ['iccid' => $iccid]);

            return [];
        }
    }

    /**
     * eSIMs Cloud sharing link + access code for an installed eSIM. Airalo
     * does NOT include the sharing block in the submit-order response — it is
     * only returned by the Get eSIM endpoint (GET /sims/{iccid}), per their
     * "How to get the eSIMs Cloud sharing link through API" guide. Returns
     * ['link' => ?string, 'access_code' => ?string]; both null on any failure
     * so fulfilment never breaks over a missing portal link.
     *
     * @return array{link: ?string, access_code: ?string}
     */
    public function fetchSharing(string $iccid): array
    {
        $none = ['link' => null, 'access_code' => null];

        $iccid = trim($iccid);
        if ($iccid === '') {
            return $none;
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(10)
                ->withToken($this->getAccessToken())
                ->acceptJson()
                ->get("{$this->baseUrl}/sims/{$iccid}");

            if ($response->failed()) {
                Log::warning('Airalo fetchSharing failed', [
                    'iccid' => $iccid,
                    'status' => $response->status(),
                ]);

                return $none;
            }

            $data = $response->json('data') ?? [];

            return [
                'link' => $data['sharing']['link'] ?? $data['sharing_link'] ?? null,
                'access_code' => $data['sharing']['access_code'] ?? $data['sharing_access_code'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::warning('Airalo fetchSharing exception: '.$e->getMessage(), ['iccid' => $iccid]);

            return $none;
        }
    }

    /**
     * Live status and data for one eSIM (GET /sims/{iccid}/usage). Airalo
     * caches the answer for 20 minutes and allows 10 calls a minute per ICCID.
     *
     * Returns null on any failure, so a sync never overwrites a known state
     * with a guess. Data is in MB. Airalo's times are UTC; they come back in
     * the app's timezone.
     *
     * @return array{status: string, remaining_mb: int|null, total_mb: int|null, is_unlimited: bool, expires_at: Carbon|null, raw: array<string, mixed>}|null
     */
    public function fetchUsage(string $iccid): ?array
    {
        $iccid = trim($iccid);
        if ($iccid === '') {
            return null;
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(10)
                ->withToken($this->getAccessToken())
                ->acceptJson()
                ->get("{$this->baseUrl}/sims/{$iccid}/usage");

            if ($response->failed()) {
                Log::warning('Airalo fetchUsage failed', ['iccid' => $iccid, 'status' => $response->status()]);

                return null;
            }

            $data = (array) ($response->json('data') ?? []);

            if (! isset($data['status'])) {
                return null;
            }

            $expiresAt = filled($data['expired_at'] ?? null)
                ? Carbon::parse((string) $data['expired_at'], 'UTC')->setTimezone(config('app.timezone'))
                : null;

            return [
                'status' => strtoupper((string) $data['status']),
                'remaining_mb' => isset($data['remaining']) ? max(0, (int) $data['remaining']) : null,
                'total_mb' => isset($data['total']) ? max(0, (int) $data['total']) : null,
                'is_unlimited' => (bool) ($data['is_unlimited'] ?? false),
                'expires_at' => $expiresAt,
                'raw' => $data,
            ];
        } catch (\Exception $e) {
            Log::warning('Airalo fetchUsage exception: '.$e->getMessage(), ['iccid' => $iccid]);

            return null;
        }
    }

    /**
     * Reasons Airalo's refund endpoint accepts. OTHERS needs a note.
     *
     * @var array<string, string>
     */
    public const REFUND_REASONS = [
        'TRIP_CANCELLATION' => 'Trip cancelled',
        'WRONG_PURCHASE' => 'Wrong eSIM bought',
        'CHANGE_OF_PLAN' => 'Customer changed plan',
        'INCOMPATIBLE_DEVICE' => 'Device not compatible',
        'NO_COVERAGE' => 'No coverage',
        'INSTALLATION_FAILURE' => 'Could not install',
        'QR_ISSUE_PARTNERS' => 'QR code problem',
        'OTHERS' => 'Other',
    ];

    /**
     * File a refund claim for unused eSIMs (POST /refund). Airalo reviews it by
     * hand and, when granted, credits our account; there is no status endpoint,
     * so the credit is confirmed from the account statement.
     *
     * Returns the claim id and time, or null when Airalo refused or could not
     * be reached; the caller keeps the claim open to retry.
     *
     * @param  array<int, string>  $iccids  up to five
     * @return array{refund_id: string, created_at: string|null}|null
     */
    public function requestRefund(array $iccids, string $reason, string $notes): ?array
    {
        $iccids = array_slice(array_values(array_filter(array_map('trim', $iccids))), 0, 5);

        if ($iccids === [] || ! array_key_exists($reason, self::REFUND_REASONS)) {
            return null;
        }

        $parts = array_map(fn (string $iccid) => ['name' => 'iccids[]', 'contents' => $iccid], $iccids);
        $parts[] = ['name' => 'reason', 'contents' => $reason];
        $parts[] = ['name' => 'notes', 'contents' => mb_substr($notes, 0, 255)];

        try {
            $response = Http::withoutVerifying()
                ->timeout(15)
                ->withToken($this->getAccessToken())
                ->acceptJson()
                ->asMultipart()
                ->post("{$this->baseUrl}/refund", $parts);

            if ($response->failed()) {
                Log::warning('Airalo requestRefund failed', ['iccids' => $iccids, 'status' => $response->status(), 'body' => $response->json()]);

                return null;
            }

            $refundId = $response->json('data.refund_id') ?? $response->json('refund_id');

            return $refundId !== null
                ? ['refund_id' => (string) $refundId, 'created_at' => $response->json('data.created_at') ?? $response->json('created_at')]
                : null;
        } catch (\Exception $e) {
            Log::warning('Airalo requestRefund exception: '.$e->getMessage(), ['iccids' => $iccids]);

            return null;
        }
    }

    public function fulfill(OrderItem $item): array
    {
        // provider_offer_id was saved as "airalo_{id}" by the normalizer, so strip prefix
        $packageId = str_replace('airalo_', '', $item->provider_offer_id);

        // Top-up branch: item metadata carries the parent ICCID we're refilling.
        // We route to /orders/topups with the parent ICCID + the chosen package;
        // Airalo credits the existing eSIM and we keep the same fulfilment shape
        // for everything downstream (poll job, normalizer, orders dashboard).
        $parentIccid = (string) ($item->metadata['parent_iccid'] ?? '');
        $isTopup = $parentIccid !== '';

        $endpoint = $isTopup ? '/orders/topups' : '/orders';
        $requestPayload = $isTopup
            ? [
                'quantity' => $item->quantity,
                'package_id' => $packageId,
                'iccid' => $parentIccid,
                'description' => "Top-up for ICCID {$parentIccid} (order {$item->order->order_number})",
            ]
            : [
                'quantity' => $item->quantity,
                'package_id' => $packageId,
                'description' => "Order {$item->order->order_number} - Item {$item->id}",
            ];

        try {
            $response = Http::withoutVerifying()
                ->timeout(15)
                ->withToken($this->getAccessToken())
                ->acceptJson()
                ->post("{$this->baseUrl}{$endpoint}", $requestPayload);

            $responseBody = $response->json() ?? [];

            FulfillmentLog::create([
                'order_item_id' => $item->id,
                'provider_name' => 'airalo',
                'request_payload' => $requestPayload,
                'response_payload' => $responseBody,
                'status' => $response->successful() ? 'SUCCESS' : 'FAILED',
                'error_message' => $response->successful() ? null : $response->body(),
            ]);

            if ($response->failed()) {
                Log::error("Airalo transaction failed: {$item->id}", ['body' => $responseBody]);

                return [
                    'status' => FulfillmentStatus::Failed,
                    'reference' => null,
                    'payload' => $responseBody,
                ];
            }

            // Airalo v2 usually returns success and a generated order ID in 'data'
            $orderData = $responseBody['data'] ?? [];

            return [
                'status' => FulfillmentStatus::Fulfilled, // Assuming synchronous response for eSIM payload
                'reference' => $orderData['id'] ?? null,
                'payload' => $responseBody,
            ];

        } catch (\Exception $e) {
            Log::error('Airalo fulfillment exception: '.$e->getMessage());

            FulfillmentLog::create([
                'order_item_id' => $item->id,
                'provider_name' => 'airalo',
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

    public function verifyStatus(OrderItem $item): array
    {
        try {
            if (! $item->fulfillment_reference) {
                return ['status' => FulfillmentStatus::Failed, 'payload' => []];
            }

            $response = Http::withoutVerifying()
                ->timeout(10)
                ->withToken($this->getAccessToken())
                ->acceptJson()
                ->get("{$this->baseUrl}/orders/{$item->fulfillment_reference}");

            $responseBody = $response->json() ?? [];

            if ($response->failed()) {
                return ['status' => FulfillmentStatus::Processing, 'payload' => $responseBody];
            }

            return ['status' => FulfillmentStatus::Fulfilled, 'payload' => $responseBody];
        } catch (\Exception $e) {
            return ['status' => FulfillmentStatus::Processing, 'payload' => []];
        }
    }

    public function refund(OrderItem $item): bool
    {
        return false; // Not supported by Airalo generically
    }

    public function normalizeResponse(array $rawPayload): array
    {
        // Extract QR, ICCID, LPA, and Phone Number from Airalo payload
        $data = $rawPayload['data'] ?? [];

        // Airalo returns a list of sims generated
        $sims = $data['sims'] ?? [];
        $firstSim = $sims[0] ?? [];

        $lpa = $firstSim['lpa'] ?? null;
        // Airalo's actual field name is `qrcode_url` (one word). The earlier
        // `qr_code_url` lookup silently nulled the QR on every fulfilment.
        $qrCodeUrl = $firstSim['qrcode_url'] ?? null;
        $manualActivationCode = $firstSim['matching_id'] ?? null; // For Airalo, matching_id is often the manual code
        // iOS tap-to-install link Airalo provides — opens the eSIM setup flow
        // directly on iPhone, no QR scan needed.
        $directInstallUrl = $firstSim['direct_apple_installation_url'] ?? null;

        // Branded eSIMs Cloud sharing link — Airalo's white-labelled portal
        // where the customer manages the eSIM (install, monitor usage, top up).
        // Comes back under `sharing.link` once the partner has uploaded a brand
        // in the Airalo Partners dashboard; on partners without branding it is
        // still returned but un-branded. Access code lets the customer re-claim
        // the eSIM on another device.
        // The sharing block has appeared both per-sim and at the order level
        // across Airalo payload revisions, so check every known location.
        $sharingLink = $firstSim['sharing']['link']
            ?? $firstSim['sharing_link']
            ?? $data['sharing']['link']
            ?? $data['sharing_link']
            ?? null;
        $sharingAccessCode = $firstSim['sharing']['access_code']
            ?? $firstSim['sharing_access_code']
            ?? $data['sharing']['access_code']
            ?? $data['sharing_access_code']
            ?? null;

        // The submit-order response never carries the sharing block — Airalo
        // only returns it from Get eSIM. One extra call per fulfilment buys
        // the branded portal link + access code for the email and dashboard.
        if ($sharingLink === null && ! empty($firstSim['iccid'])) {
            $sharing = $this->fetchSharing((string) $firstSim['iccid']);
            $sharingLink = $sharing['link'];
            $sharingAccessCode = $sharingAccessCode ?? $sharing['access_code'];
        }

        // Compile manual text if applicable
        $manualText = null;
        if ($lpa && $manualActivationCode) {
            $manualText = "SM-DP+ Address: {$lpa} | Activation Code: {$manualActivationCode}";
        }

        // Specifically extract the phone number (Airalo often provides this inside 'sims' -> 'iccid' or metadata depending on the plan)
        // If the plan has voice, 'phone_number' or 'msisdn' might be present
        $phoneNumber = $firstSim['phone_number'] ?? $firstSim['msisdn'] ?? null;

        return [
            'qrcode_url' => $qrCodeUrl,
            'qr_manual_code' => $manualText,
            'iccid' => $firstSim['iccid'] ?? null,
            'lpa' => $lpa,
            'phone_number' => $phoneNumber,
            'provider_reference' => $data['id'] ?? null,
            'network' => $data['operator'] ?? null,
            'direct_install_url' => $directInstallUrl,
            'sharing_link' => $sharingLink,
            'sharing_access_code' => $sharingAccessCode,
            'raw_response' => $rawPayload,

            // Format to generic pins/esim array for UI backwards compatibility
            'pins' => [],
            'esim' => [
                'iccid' => $firstSim['iccid'] ?? null,
                'lpaUrl' => $lpa,
                'manualActivationCode' => $manualActivationCode,
                'directInstallUrl' => $directInstallUrl,
                'sharingLink' => $sharingLink,
                'sharingAccessCode' => $sharingAccessCode,
            ],
        ];
    }
}
