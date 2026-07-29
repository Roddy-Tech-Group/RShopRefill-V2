<?php

namespace App\Domain\Wallet\Jobs;

use App\Domain\Transaction\Exceptions\DuplicateTransactionException;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Models\PaymentWebhook;
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

            if ($eventType === 'charge.completed' || strtolower($status) === 'successful') {
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
                // Not a successful payment event
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
}
