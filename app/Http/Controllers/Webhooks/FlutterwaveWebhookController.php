<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\InvalidWebhookException;
use App\Domain\Payment\Services\FlutterwaveService;
use App\Domain\Wallet\Jobs\ProcessFundingWebhookJob;
use App\Http\Controllers\Controller;
use App\Jobs\VerifyPaymentJob;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhook;
use App\Models\WalletFunding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FlutterwaveWebhookController extends Controller
{
    public function __construct(
        private readonly FlutterwaveService $flutterwaveService
    ) {}

    /**
     * Hardened signature-verified asynchronous webhook entry point.
     */
    public function handle(Request $request)
    {
        $signature = $request->header('verif-hash');
        $payload = $request->all();
        $headers = $request->headers->all();

        $eventType = $payload['event'] ?? ($payload['event-type'] ?? 'unknown');
        $data = $payload['data'] ?? [];
        $txRef = $data['tx_ref'] ?? ($payload['txRef'] ?? null);

        // 1. Persist the raw webhook immediately for absolute auditability
        $webhook = PaymentWebhook::create([
            'gateway' => 'flutterwave',
            'event_type' => $eventType,
            'reference' => $txRef,
            'payload' => $payload,
            'headers' => $headers,
            'signature' => $signature,
            'processed' => false,
            'processing_status' => 'pending',
            'processing_attempts' => 0,
        ]);

        try {
            // 2. Perform signature checking
            $this->flutterwaveService->verifyWebhookSignature($signature);

        } catch (InvalidWebhookException $e) {
            Log::warning('Flutterwave webhook signature check failed.', [
                'webhook_id' => $webhook->id,
                'signature' => $signature,
            ]);

            $webhook->update([
                'processing_status' => 'failed',
                'exception_traces' => 'Signature verification failed: '.$e->getMessage(),
            ]);

            return response()->json(['message' => 'Unauthorized signature'], 401);
        }

        // 2b. Transfer (payout) webhooks belonged to gift-card trading, which is
        // removed (it never processed a trade in production, so no payout can
        // legitimately reference one). Acknowledge with a 200 so Flutterwave
        // stops retrying, and log in case one ever arrives unexpectedly.
        $transferReference = $data['reference'] ?? null;
        if ($eventType === 'transfer.completed'
            || (is_string($transferReference) && str_starts_with($transferReference, 'FW-PAYOUT-'))) {
            Log::info('Ignoring transfer webhook: gift-card trading payouts are removed.', [
                'webhook_id' => $webhook->id,
                'reference' => $transferReference,
            ]);

            $webhook->update([
                'processed' => true,
                'processing_status' => 'ignored',
                'reference' => $transferReference ?? $txRef,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Transfer webhook acknowledged',
                'webhook_id' => $webhook->id,
            ], 200);
        }

        // 3. Dispatch the processing job asynchronously based on payable type
        $attempt = DB::transaction(function () use ($txRef, $payload, $data) {
            $att = PaymentAttempt::where('idempotency_key', $txRef)->lockForUpdate()->first();

            if ($att && ($att->payable_type === Order::class || ! empty($att->order_id))) {
                $att->webhook_payload = $payload;

                // Only a successful charge's id is worth keeping, and never
                // over an attempt already paid: one popup can see card A
                // declined and card B pay under the same reference, and the
                // declined id arriving last made every later lookup read
                // "failed" and strand card B's money.
                $status = strtolower((string) ($data['status'] ?? ($payload['status'] ?? '')));
                $chargeId = $data['id'] ?? ($payload['id'] ?? null);

                if ($chargeId !== null && $status === 'successful' && $att->payment_status !== PaymentStatus::Paid) {
                    $att->gateway_reference = $chargeId;
                }

                $att->save();
            }

            return $att;
        });

        if ($attempt) {
            if ($attempt->payable_type === Order::class || ! empty($attempt->order_id)) {
                VerifyPaymentJob::dispatch($attempt);
            } elseif ($attempt->payable_type === WalletFunding::class) {
                ProcessFundingWebhookJob::dispatch($webhook->id);
            } else {
                $webhook->update([
                    'processing_status' => 'ignored',
                    'exception_traces' => 'Unhandled payable_type: '.$attempt->payable_type,
                ]);
            }
        } else {
            $webhook->update([
                'processing_status' => 'ignored',
                'exception_traces' => 'No matching PaymentAttempt found for tx_ref: '.$txRef,
            ]);
        }

        Log::info('Flutterwave webhook queued successfully.', [
            'webhook_id' => $webhook->id,
            'reference' => $txRef,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook queued for processing',
            'webhook_id' => $webhook->id,
        ], 200);
    }
}
