<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Orders\EsimLowDataMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Airalo Partner webhooks — currently only the eSIM low-data notification.
 *
 * Airalo POSTs us when a customer's eSIM crosses 75 % / 90 % data usage, or
 * when their plan has 3 days / 1 day left before expiry. We map the ICCID
 * back to an order item, email the customer a "Top up your eSIM" CTA, and
 * drop a dashboard notification so they see it the next time they log in.
 *
 * Payload shape (per Airalo docs):
 *   {
 *     "iccid": "...",
 *     "usage_percentage": 75 | 90,           // present for data threshold events
 *     "days_remaining": 3 | 1,               // present for expiry threshold events
 *     "event_type": "DATA_LOW" | "EXPIRY_SOON",
 *     "package_data": "5 GB",
 *     "remaining_data": "1.2 GB",
 *     "expire_at": "2026-06-12T08:30:00Z",
 *     ...
 *   }
 *
 * Signature verification: Airalo HMAC-signs the body with the partner shared
 * secret. We compare in constant time. Falls back to skip-verification only
 * when `services.airalo.webhook_secret` is empty (local dev convenience —
 * production deploys MUST set it).
 */
class AiraloWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->verifySignature($request)) {
            Log::warning('Airalo webhook: signature mismatch', ['ip' => $request->ip()]);

            return response()->json(['error' => 'invalid signature'], 401);
        }

        $payload = $request->json()->all();
        $iccid = (string) ($payload['iccid'] ?? '');
        $eventType = strtoupper((string) ($payload['event_type'] ?? ''));

        if ($iccid === '') {
            return response()->json(['error' => 'missing iccid'], 422);
        }

        // Find the most recent order item that fulfilled this ICCID. Multiple
        // top-ups can share an ICCID; the *latest* item is the canonical
        // reference for "what the customer's eSIM is right now".
        $orderItem = OrderItem::query()
            ->where(function ($q) use ($iccid) {
                $q->whereJsonContains('fulfillment_payload->iccid', $iccid)
                    ->orWhereJsonContains('fulfillment_payload->esim->iccid', $iccid);
            })
            ->with('order.user')
            ->latest()
            ->first();

        if (! $orderItem || ! $orderItem->order?->user) {
            Log::info('Airalo webhook: no matching order item for ICCID', ['iccid' => $iccid]);

            // Return 200 so Airalo doesn't keep retrying — we have nothing
            // actionable, but the webhook itself was well-formed and authed.
            return response()->json(['status' => 'noop'], 200);
        }

        $user = $orderItem->order->user;

        try {
            $title = $this->titleFor($eventType, $payload);
            $message = $this->messageFor($eventType, $payload, $orderItem);

            app(CommunicationManager::class)->send(
                recipient: $user,
                category: CommunicationCategory::EsimLowData,
                title: $title,
                message: $message,
                mailable: new EsimLowDataMail(
                    recipient: $user,
                    orderItem: $orderItem,
                    iccid: $iccid,
                    eventType: $eventType,
                    payload: $payload,
                ),
                correlationId: 'esim_low_data_'.$iccid.'_'.$eventType,
                metadata: [
                    'kind' => 'esim_low_data',
                    'iccid' => $iccid,
                    'order_item_id' => $orderItem->id,
                    'event_type' => $eventType,
                    'usage_percentage' => $payload['usage_percentage'] ?? null,
                    'days_remaining' => $payload['days_remaining'] ?? null,
                ]
            );

        } catch (Throwable $e) {
            Log::error('Airalo webhook handler failed', [
                'iccid' => $iccid,
                'error' => $e->getMessage(),
            ]);

            // 500 → Airalo retries.
            return response()->json(['error' => 'internal'], 500);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Constant-time HMAC verification. Airalo issues partners only a
     * client_id + client_secret pair, so by default webhooks are signed
     * with the client_secret. A dedicated AIRALO_WEBHOOK_SECRET is honoured
     * if set (for partners on a custom signing arrangement), otherwise we
     * fall back to AIRALO_CLIENT_SECRET. The signing payload is the raw
     * request body; the signature can land in either header Airalo uses.
     */
    private function verifySignature(Request $request): bool
    {
        $secret = (string) (config('services.airalo.webhook_secret') ?: config('services.airalo.client_secret', ''));
        if ($secret === '') {
            // Local-dev convenience: with no Airalo creds set we accept
            // unsigned requests so curl tests work. Production refuses.
            return ! app()->isProduction();
        }

        $signature = (string) ($request->header('X-Airalo-Signature') ?: $request->header('Signature') ?: '');
        if ($signature === '') {
            return false;
        }

        // Airalo sends the digest as hex; some integrations send the raw
        // header value with a "sha256=" prefix. Normalise both before
        // constant-time compare.
        $signature = preg_replace('/^sha256=/i', '', $signature);

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, (string) $signature);
    }

    private function titleFor(string $eventType, array $payload): string
    {
        if ($eventType === 'EXPIRY_SOON' || isset($payload['days_remaining'])) {
            $days = (int) ($payload['days_remaining'] ?? 0);

            return $days <= 1 ? 'Your eSIM expires tomorrow' : "Your eSIM expires in {$days} days";
        }

        $pct = (int) ($payload['usage_percentage'] ?? 0);

        return $pct >= 90 ? 'Your eSIM is almost out of data' : 'Your eSIM is running low';
    }

    private function messageFor(string $eventType, array $payload, OrderItem $orderItem): string
    {
        $brand = $orderItem->product?->name ?? 'eSIM';

        if ($eventType === 'EXPIRY_SOON' || isset($payload['days_remaining'])) {
            $days = (int) ($payload['days_remaining'] ?? 0);
            $when = $days <= 1 ? 'tomorrow' : "in {$days} days";

            return "Your {$brand} expires {$when}. Top it up to keep your line active.";
        }

        $remaining = (string) ($payload['remaining_data'] ?? '');
        $tail = $remaining !== '' ? " You have {$remaining} left." : '';

        return "Your {$brand} is running low.{$tail} Top it up so you stay connected.";
    }
}
