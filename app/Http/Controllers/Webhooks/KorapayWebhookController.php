<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\VerifyPaymentJob;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhook;
use App\Models\WalletFunding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Kora Pay webhook entry point. Signature (HMAC-SHA256 of the json-encoded
 * `data` object in x-korapay-signature) and replay protection are enforced
 * upstream by VerifyWebhookSignature. Same shape as the Paystack sibling:
 * persist the raw event, bind to the PaymentAttempt by our reference, and
 * let VerifyPaymentJob settle the truth against GET /charges/:reference.
 *
 * Kora's payloads name references two ways: `payment_reference` is the
 * merchant reference on checkout events, while some events carry only
 * `reference` (which may be Kora's own KPY-* id). We match our attempt on
 * whichever equals an idempotency_key we issued.
 */
class KorapayWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();
        $eventType = $payload['event'] ?? 'unknown';
        $data = $payload['data'] ?? [];

        $candidates = array_values(array_filter([
            $data['payment_reference'] ?? null,
            $data['reference'] ?? null,
        ]));

        $webhook = PaymentWebhook::create([
            'gateway' => 'korapay',
            'event_type' => $eventType,
            'reference' => $candidates[0] ?? null,
            'payload' => $payload,
            'headers' => $request->headers->all(),
            'signature' => $request->header('x-korapay-signature'),
            'processed' => false,
            'processing_status' => 'pending',
            'processing_attempts' => 0,
        ]);

        if ($eventType !== 'charge.success') {
            $webhook->update([
                'processed' => true,
                'processing_status' => 'ignored',
                'exception_traces' => "Unhandled event type: {$eventType}",
            ]);

            return response()->json(['status' => 'success', 'message' => 'Event acknowledged'], 200);
        }

        $attempt = DB::transaction(function () use ($candidates, $payload) {
            $att = PaymentAttempt::whereIn('idempotency_key', $candidates)->lockForUpdate()->first();

            if ($att) {
                $att->webhook_payload = $payload;
                $att->save();
            }

            return $att;
        });

        // Orders AND wallet fundings both settle through VerifyPaymentJob: it
        // verifies against the gateway and routes fundings into
        // WalletFundingService::processSuccessfulFunding (idempotent, locked).
        if ($attempt && ($attempt->payable_type === Order::class || ! empty($attempt->order_id) || $attempt->payable_type === WalletFunding::class)) {
            $webhook->update(['reference' => $attempt->idempotency_key]);
            VerifyPaymentJob::dispatch($attempt);
        } else {
            $webhook->update([
                'processing_status' => 'ignored',
                'exception_traces' => $attempt
                    ? 'Unhandled payable_type: '.$attempt->payable_type
                    : 'No matching PaymentAttempt for references: '.implode(', ', $candidates),
            ]);
        }

        Log::info('Korapay webhook queued successfully.', [
            'webhook_id' => $webhook->id,
            'references' => $candidates,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook queued for processing',
            'webhook_id' => $webhook->id,
        ], 200);
    }
}
