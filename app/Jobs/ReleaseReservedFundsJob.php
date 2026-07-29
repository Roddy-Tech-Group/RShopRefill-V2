<?php

namespace App\Jobs;

use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\WalletPaymentProvider;
use App\Models\Order;
use App\Models\PaymentAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReleaseReservedFundsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected PaymentAttempt $attempt)
    {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('payments');
    }

    public function handle(
        WalletPaymentProvider $walletProvider,
        OrderService $orderService
    ): void {
        $attempt = PaymentAttempt::find($this->attempt->id);
        if (! $attempt || $attempt->payment_status !== PaymentStatus::Reserved) {
            return;
        }

        try {
            Log::info("ReleaseReservedFundsJob: releasing reserved funds for attempt {$attempt->id}");

            // Release locked wallet balance
            $walletProvider->releaseFunds($attempt);

            // Set Order status to Failed
            $order = $attempt->order;
            $orderService->transitionPaymentStatus($order, PaymentStatus::Failed, ['reason' => 'Reserved funds released manually/automatically on timeout']);
        } catch (\Exception $e) {
            Log::error('ReleaseReservedFundsJob failed: '.$e->getMessage());
        }
    }
}
