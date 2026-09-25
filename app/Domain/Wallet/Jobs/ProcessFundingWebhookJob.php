<?php

namespace App\Domain\Wallet\Jobs;

use App\Domain\Payment\Services\FlutterwaveService;
use App\Domain\Payment\Support\CustomerFailureCopy;
use App\Domain\Payment\Support\GatewayFailure;
use App\Domain\PaymentInsights\Services\FailureReasonClassifier;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Transaction\Exceptions\DuplicateTransactionException;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhook;
use App\Models\WalletFunding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessFundingWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [30, 60, 120];

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly int $paymentWebhookId
    ) {
        $this->onQueue('webhooks');
    }

    /**
     * Execute the job.
     */
    public function handle(WalletFundingService $fundingService): void
    {
        $webhook = PaymentWebhook::find($this->paymentWebhookId);

        if (! $webhook) {
            Log::error("PaymentWebhook ID {$this->paymentWebhookId} not found during processing job.");

            return;
        }

        $webhook->increment('processing_attempts');

        try {
            $payload = $webhook->payload;
            $eventType = $payload['event'] ?? ($payload['event-type'] ?? 'unknown');
            $data = $payload['data'] ?? [];
            $txRef = $data['tx_ref'] ?? ($payload['txRef'] ?? null);
            $transactionId = $data['id'] ?? ($payload['id'] ?? null);
            $status = $data['status'] ?? ($payload['status'] ?? 'unknown');

            if (strtolower((string) $status) === 'failed' && $txRef && $transactionId) {
                // The gateway says the charge was refused. It arrives as
                // charge.completed like a success does, so it is recognised
                // first. A refused top-up prompt used to fall into the success
                // branch, fail to verify, and leave the funding Pending: the
                // wallet page spun until the hourly reconcile gave up on it,
                // with no reason ever shown.
                $this->failRefusedFunding($fundingService, (string) $txRef, (string) $transactionId);

                $webhook->update([
                    'processed' => true,
                    'processing_status' => 'completed',
                    'processed_at' => now(),
                ]);
            } elseif ($eventType === 'charge.completed' || strtolower($status) === 'successful') {
                if ($txRef && $transactionId) {
                    $fundingService->processSuccessfulFunding($txRef, (string) $transactionId, $payload);

                    $webhook->update([
                        'processed' => true,
                        'processing_status' => 'completed',
                        'processed_at' => now(),
                    ]);

                    Log::info('ProcessFundingWebhookJob completed successfully.', [
                        'webhook_id' => $webhook->id,
                        'reference' => $txRef,
                    ]);
                } else {
                    $webhook->update([
                        'processing_status' => 'failed',
                        'exception_traces' => 'Missing tx_ref or transactionId in payload.',
                    ]);
                }
            } else {
                // Not a payment outcome this job acts on
                $webhook->update([
                    'processing_status' => 'completed',
                    'processed_at' => now(),
                ]);
            }
        } catch (DuplicateTransactionException $e) {
            Log::info('ProcessFundingWebhookJob encountered duplicate transaction, ignoring.', [
                'webhook_id' => $webhook->id,
                'message' => $e->getMessage(),
            ]);

            $webhook->update([
                'processed' => true,
                'processing_status' => 'completed',
                'processed_at' => now(),
                'exception_traces' => 'Ignored: '.$e->getMessage(),
            ]);

            return;
        } catch (\Throwable $e) {
            Log::error('ProcessFundingWebhookJob failed: '.$e->getMessage(), [
                'webhook_id' => $webhook->id,
                'trace' => $e->getTraceAsString(),
            ]);

            $webhook->update([
                'processing_status' => 'failed',
                'exception_traces' => $e->getMessage().PHP_EOL.$e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Fail a pending funding on the gateway's own verdict. The webhook is
     * never trusted for this: the transaction is verified with the gateway
     * first and must carry this reference and a terminal "failed". Only the
     * single-charge flows (a phone prompt, a direct card charge) are ended:
     * a popup or hosted page lets the customer try another card under the
     * same reference, so a refusal there is not the last word.
     */
    private function failRefusedFunding(WalletFundingService $fundingService, string $txRef, string $transactionId): void
    {
        $attempt = PaymentAttempt::query()
            ->where('idempotency_key', $txRef)
            ->where('payable_type', WalletFunding::class)
            ->with(['payable', 'paymentSession'])
            ->first();

        $funding = $attempt?->payable;
        $session = $attempt?->paymentSession;

        if (! $funding instanceof WalletFunding
            || $funding->status !== FundingStatus::Pending
            || $session === null
            || ! in_array($session->status, ['awaiting_confirmation', 'awaiting_customer_action'], true)) {
            return;
        }

        $verified = app(FlutterwaveService::class)->verifyTransaction($transactionId);

        if (! is_array($verified)
            || ($verified['status'] ?? null) !== 'failed'
            || ! hash_equals($txRef, (string) ($verified['tx_ref'] ?? ''))) {
            return;
        }

        $failure = GatewayFailure::fromFlutterwave(['data' => $verified]);
        $classification = app(FailureReasonClassifier::class)->classify(
            'flutterwave',
            (string) ($verified['processor_response'] ?? 'declined'),
            $session->status,
            $failure,
        );

        $fundingService->failFunding(
            $funding,
            CustomerFailureCopy::forVerdict($classification, $session->status === 'awaiting_confirmation'),
            [
                'provider' => 'flutterwave',
                'failure' => $failure,
                'gateway_verdict' => [
                    'status' => 'failed',
                    'processor_response' => $verified['processor_response'] ?? null,
                    'transaction_id' => $transactionId,
                    'payment_type' => $verified['payment_type'] ?? null,
                    'seen_at' => now()->toIso8601String(),
                ],
                'client_reason' => 'gateway_refused',
            ],
        );
    }
}
