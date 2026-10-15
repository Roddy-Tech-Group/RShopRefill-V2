<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Risk\Services\DisputeNoticeParser;
use App\Domain\Risk\Services\DisputeRecorder;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhooks\Concerns\RecordsDisputes;
use App\Jobs\VerifyPaymentJob;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhook;
use App\Models\WalletFunding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Paystack webhook entry point. Signature (HMAC-SHA512 of the raw body in
 * x-paystack-signature) and replay protection are enforced upstream by the
 * VerifyWebhookSignature middleware, so this controller's job is the same as
 * its Flutterwave sibling: persist the raw event for audit, bind it to the
 * PaymentAttempt by our reference, and hand settlement to VerifyPaymentJob,
 * which asks Paystack's verify endpoint for the truth rather than trusting
 * the webhook body.
 *
 * charge.failed events are recorded but not acted on: the session lifecycle
 * (customer return, sweeper, expiry) already owns failure transitions, and a
 * failed charge can be retried on the same hosted page.
 */
class PaystackWebhookController extends Controller
{
    use RecordsDisputes;

    public function __construct(
        private readonly DisputeNoticeParser $disputeParser,
        private readonly DisputeRecorder $disputes,
    ) {}

    public function handle(Request $request)
    {
        $payload = $request->all();
        $eventType = $payload['event'] ?? 'unknown';
        $data = $payload['data'] ?? [];
        $reference = $data['reference'] ?? null;

        $webhook = PaymentWebhook::create([
            'gateway' => 'paystack',
            'event_type' => $eventType,
            'reference' => $reference,
            'payload' => $payload,
            'headers' => $request->headers->all(),
            'signature' => $request->header('x-paystack-signature'),
            'processed' => false,
            'processing_status' => 'pending',
            'processing_attempts' => 0,
        ]);

        if (is_string($eventType) && str_starts_with($eventType, 'charge.dispute.')) {
            return $this->recordDispute($webhook, $this->disputeParser->paystack(is_array($data) ? $data : []));
        }

        if ($eventType !== 'charge.success') {
            $webhook->update([
                'processed' => true,
                'processing_status' => 'ignored',
                'exception_traces' => "Unhandled event type: {$eventType}",
            ]);

            return response()->json(['status' => 'success', 'message' => 'Event acknowledged'], 200);
        }

        $attempt = DB::transaction(function () use ($reference, $payload, $data) {
            $att = PaymentAttempt::where('idempotency_key', $reference)->lockForUpdate()->first();

            if ($att) {
                $att->webhook_payload = $payload;
                $att->gateway_reference = (string) ($data['id'] ?? $att->gateway_reference);
                $att->save();
            }

            return $att;
        });

        // Orders AND wallet fundings both settle through VerifyPaymentJob: it
        // verifies against the gateway and routes fundings into
        // WalletFundingService::processSuccessfulFunding (idempotent, locked).
        if ($attempt && ($attempt->payable_type === Order::class || ! empty($attempt->order_id) || $attempt->payable_type === WalletFunding::class)) {
            VerifyPaymentJob::dispatch($attempt);
        } else {
            $webhook->update([
                'processing_status' => 'ignored',
                'exception_traces' => $attempt
                    ? 'Unhandled payable_type: '.$attempt->payable_type
                    : 'No matching PaymentAttempt found for reference: '.$reference,
            ]);
        }

        Log::info('Paystack webhook queued successfully.', [
            'webhook_id' => $webhook->id,
            'reference' => $reference,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook queued for processing',
            'webhook_id' => $webhook->id,
        ], 200);
    }
}
