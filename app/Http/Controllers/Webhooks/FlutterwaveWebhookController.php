<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\GiftCardTrading\Services\PayoutOrchestrator;
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

        // 2b. Transfer (payout) webhooks carry no PaymentAttempt - they reconcile a
        // gift-card trade Payout by its reference. Route them to the payout
        // orchestrator (which is idempotent on already-terminal payouts) and stop.
        $transferReference = $data['reference'] ?? null;
        if ($eventType === 'transfer.completed'
            || (is_string($transferReference) && str_starts_with($transferReference, 'FW-PAYOUT-'))) {
            app(PayoutOrchestrator::class)->handleWebhook($payload);

            $webhook->update([
                'processed' => true,
                'processing_status' => 'processed',
                'reference' => $transferReference ?? $txRef,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Transfer webhook processed',
                'webhook_id' => $webhook->id,
            ], 200);
        }

        // 3. Dispatch the processing job asynchronously based on payable type
        $attempt = DB::transaction(function () use ($txRef, $payload, $data) {
            $att = PaymentAttempt::where('idempotency_key', $txRef)->lockForUpdate()->first();

            if ($att && ($att->payable_type === Order::class || ! empty($att->order_id))) {
                $att->webhook_payload = $payload;
                $att->gateway_reference = $data['id'] ?? ($payload['id'] ?? $att->gateway_reference);
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
