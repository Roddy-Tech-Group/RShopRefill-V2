<?php

namespace App\Jobs;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Models\PaymentAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefundPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(protected PaymentAttempt $attempt)
    {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('payments');
    }

    public function handle(PaymentGatewayFactory $gatewayFactory): void
    {
        Log::info("RefundPaymentJob: Dispatching refund for duplicate/excess payment attempt {$this->attempt->id}");

        $provider = $gatewayFactory->getProvider($this->attempt->gateway);

        try {
            // Stable reference: a retried job replays the same idempotent
            // refund instead of throwing DuplicateTransaction and burning its
            // remaining attempts without ever reaching a terminal state.
            $success = $provider->refundPayment($this->attempt, (float) $this->attempt->amount, 'duplicate-payment');

            if ($success) {
                Log::info("RefundPaymentJob: Successfully refunded attempt {$this->attempt->id}");
                $this->attempt->payment_status = PaymentStatus::Refunded;
                $this->attempt->save();
            } else {
                Log::error("RefundPaymentJob: Provider returned false for refunding attempt {$this->attempt->id}");
                $this->fail(new \Exception('Provider returned false for refund'));
            }
        } catch (\Exception $e) {
            Log::error("RefundPaymentJob: Exception while refunding attempt {$this->attempt->id}: ".$e->getMessage());
            throw $e;
        }
    }
}
