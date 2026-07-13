<?php

namespace App\Jobs;

use App\Domain\Order\Events\PaymentConfirmed;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\WalletFunding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VerifyPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 10;

    public function __construct(protected PaymentAttempt $attempt) {}

    public function handle(
        PaymentGatewayFactory $gatewayFactory,
        OrderService $orderService
    ): void {
        Log::info("VerifyPaymentJob: checking payment status for attempt {$this->attempt->id}");

        // Prevent webhook race conditions by wrapping in a transaction with pessimistic lock
        DB::transaction(function () use ($gatewayFactory, $orderService) {
            $attempt = PaymentAttempt::where('id', $this->attempt->id)->lockForUpdate()->first();

            if (! $attempt || in_array($attempt->payment_status, [PaymentStatus::Paid, PaymentStatus::Failed])) {
                return;
            }

            $provider = $gatewayFactory->getProvider($attempt->gateway);
            $isPaid = $provider->verifyPayment($attempt);

            if ($isPaid) {
                // If this is a WalletFunding attempt, process it via WalletFundingService
                if ($attempt->payable_type === WalletFunding::class) {
                    Log::info("VerifyPaymentJob: payment confirmed for WalletFunding attempt {$attempt->id}");
                    $funding = $attempt->payable;
                    if ($funding && $funding->status !== FundingStatus::Completed) {
                        $fundingService = app(WalletFundingService::class);
                        $fundingService->processSuccessfulFunding(
                            $funding->reference,
                            $attempt->gateway_reference ?: 'VERIFY-'.uniqid(),
                            $attempt->verification_payload ?: []
                        );
                    }

                    return;
                }

                // Check if the order is already Paid by a different attempt
                $order = Order::where('id', $attempt->order_id)->lockForUpdate()->first();
                if ($order && $order->payment_status === PaymentStatus::Paid) {
                    Log::warning("VerifyPaymentJob: Duplicate payment detected. Order {$order->id} is already Paid. Dispatching refund for attempt {$attempt->id}");
                    $attempt->payment_status = PaymentStatus::Paid;
                    $attempt->save();

                    RefundPaymentJob::dispatch($attempt);

                    return;
                }

                Log::info("VerifyPaymentJob: payment confirmed for order attempt {$attempt->id}");

                // 1. Transition the order's payment status to Paid
                if ($attempt->order) {
                    $orderService->transitionPaymentStatus($attempt->order, PaymentStatus::Paid, $attempt->verification_payload);
                }

                // 2. Synchronize active PaymentSession model if exists
                $attempt->load('paymentSession');
                if ($attempt->paymentSession) {
                    $sessionService = app(PaymentSessionService::class);
                    $sessionService->confirmSession($attempt->paymentSession, [
                        'transaction_id' => $attempt->gateway_reference,
                        'payload' => $attempt->verification_payload,
                    ]);
                }

                // 3. Refresh Order to verify paid status
                $order = Order::find($attempt->order_id);
                if ($order) {
                    PaymentConfirmed::dispatch($order, $attempt);

                    // 4. Dispatch fulfillment jobs for each order item
                    foreach ($order->items as $item) {
                        FulfillOrderItemJob::dispatch($item);
                    }
                }

                // 5. Update any pending webhooks related to this attempt
                if ($attempt->idempotency_key) {
                    \App\Models\PaymentWebhook::where('reference', $attempt->idempotency_key)
                        ->where('processing_status', 'pending')
                        ->update([
                            'processed' => true,
                            'processing_status' => 'processed',
                            'processed_at' => now(),
                        ]);
                }
            } else {
                Log::warning("VerifyPaymentJob: payment verification returned unpaid/pending for attempt {$attempt->id}");
            }
        });
    }
}
