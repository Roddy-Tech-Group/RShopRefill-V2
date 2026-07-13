<?php

namespace App\Jobs;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Services\FulfillmentProviderFactory;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\FulfillmentFailed;
use App\Domain\Order\Events\FulfillmentSucceeded;
use App\Domain\Order\Services\GatewayRefundService;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\WalletPaymentProvider;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PollPendingFulfillmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 120; // Allow polling up to 120 times (20 minutes total)

    public function __construct(protected OrderItem $item)
    {
        $this->onQueue('fulfillment');
    }

    public function handle(
        FulfillmentProviderFactory $providerFactory,
        OrderService $orderService,
        WalletPaymentProvider $walletProvider
    ): void {
        $item = OrderItem::find($this->item->id);
        if (! $item || ! in_array($item->fulfillment_status, [FulfillmentStatus::Processing, FulfillmentStatus::Delayed])) {
            return;
        }

        try {
            $provider = $providerFactory->getProvider($item->provider_name);
            $result = $provider->verifyStatus($item);

            $status = $result['status'];

            // Bug #5 fix: capture whether a release is needed BEFORE entering the transaction,
            // then call $this->release() AFTER the transaction closes. Calling release() inside
            // a DB::transaction closure is a Laravel antipattern — the queue-level operation
            // can silently fail or race against an uncommitted transaction.
            $needsRelease = false;

            DB::transaction(function () use ($item, $status, $result, $provider, $orderService, $walletProvider, &$needsRelease) {
                // Re-fetch with lock inside the transaction for fresh state
                $item = OrderItem::where('id', $item->id)->lockForUpdate()->first();
                if (! $item) {
                    return;
                }

                if ($status === FulfillmentStatus::Fulfilled) {
                    $item->fulfillment_status = FulfillmentStatus::Fulfilled;
                    $item->fulfillment_payload = $provider->normalizeResponse($result['payload'] ?? []);
                    $item->delivered_at = now();
                    // A recovered retry must not keep advertising its old failure
                    $item->failed_at = null;
                    $item->save();

                    FulfillmentSucceeded::dispatch($item);

                    $this->checkOrderCompletion($item->order_id, $orderService);
                } elseif ($status === FulfillmentStatus::Failed) {
                    $item->fulfillment_status = FulfillmentStatus::Failed;
                    $item->failed_at = now();
                    $item->save();

                    FulfillmentFailed::dispatch($item, 'Provider verification returned failed status');

                    $this->handleFailureReversal($item, $walletProvider);
                    $this->checkOrderCompletion($item->order_id, $orderService);
                } else {
                    // Still pending/processing — signal that we need to re-queue after the transaction
                    $needsRelease = true;
                }
            });

            // Bug #6 fix: gracefully handle max attempts instead of throwing MaxAttemptsExceededException
            if ($this->attempts() >= $this->tries - 1) {
                $this->failed(new \Exception("Polling exhausted after {$this->tries} attempts."));
                return;
            }

            if ($needsRelease) {
                $this->release(10); // Release back to queue in 10 seconds
            }

        } catch (\Exception $e) {
            Log::error("Fulfillment status polling exception for item {$item->id}: ".$e->getMessage());
            $this->release(10);
        }
    }

    /**
     * Bug #6 fix: Handle permanent job failure after all 120 polling attempts are exhausted.
     * Without this, items stay stuck in 'processing' with no refund and no alert.
     */
    public function failed(\Throwable $e): void
    {
        Log::critical("PollPendingFulfillmentJob permanently failed for item {$this->item->id} after all retries. Manual intervention required.");

        $walletProvider = app(WalletPaymentProvider::class);

        DB::transaction(function () use ($e, $walletProvider) {
            $item = OrderItem::where('id', $this->item->id)->lockForUpdate()->first();
            if (! $item || $item->fulfillment_status === FulfillmentStatus::Fulfilled) {
                return;
            }

            $item->fulfillment_status = FulfillmentStatus::Failed;
            $item->failed_at = now();
            $item->save();

            FulfillmentFailed::dispatch($item, 'Polling exhausted after 120 attempts: '.$e->getMessage());

            $this->handleFailureReversal($item, $walletProvider);
            $this->checkOrderCompletion($item->order_id, app(OrderService::class));
        });
    }

    private function handleFailureReversal(OrderItem $item, WalletPaymentProvider $walletProvider): void
    {
        $order = $item->order;

        $walletPayment = $order->paymentAttempts()
            ->where('gateway', 'wallet')
            ->whereIn('payment_status', [PaymentStatus::Reserved, PaymentStatus::Paid])
            ->first();

        if ($walletPayment) {
            $hasSuccessfulItem = $order->items->contains(function ($i) {
                return in_array($i->fulfillment_status, [
                    FulfillmentStatus::Fulfilled,
                    FulfillmentStatus::Processing,
                    FulfillmentStatus::Delayed,
                ]);
            });

            if (! $hasSuccessfulItem) {
                if ($walletPayment->payment_status === PaymentStatus::Reserved) {
                    $walletProvider->releaseFunds($walletPayment);
                } else {
                    $walletProvider->refundPayment($walletPayment, $order->total_amount);
                }

                $order->payment_status = PaymentStatus::Failed;
                $order->order_status = OrderStatus::Failed;
                $order->failed_at = now();
                $order->save();
            } else {
                $refundAmount = $item->subtotal_amount;
                if ($walletPayment->payment_status === PaymentStatus::Paid) {
                    $walletProvider->refundPayment($walletPayment, $refundAmount);
                } elseif ($walletPayment->payment_status === PaymentStatus::Reserved) {
                    $walletProvider->releasePartialFunds($walletPayment, $refundAmount);
                }
            }

            return;
        }

        // Non-wallet (card / mobile money / crypto) paid order: refund the
        // failed item to the customer's wallet per the wallet-first policy.
        app(GatewayRefundService::class)->refundFailedItemToWallet($item);
    }

    private function checkOrderCompletion(string $orderId, OrderService $orderService): void
    {
        $order = Order::where('id', $orderId)->lockForUpdate()->first();
        if (! $order) {
            return;
        }

        $isOrderFinished = $order->items->every(fn ($i) => in_array($i->fulfillment_status, [
            FulfillmentStatus::Fulfilled,
            FulfillmentStatus::Failed,
        ]));

        if ($isOrderFinished) {
            $allFailed = $order->items->every(fn ($i) => $i->fulfillment_status === FulfillmentStatus::Failed);
            $allFulfilled = $order->items->every(fn ($i) => $i->fulfillment_status === FulfillmentStatus::Fulfilled);

            if ($allFailed) {
                $orderService->transitionFulfillmentStatus($order, FulfillmentStatus::Failed);
            } elseif ($allFulfilled) {
                $orderService->transitionFulfillmentStatus($order, FulfillmentStatus::Fulfilled);
            } else {
                $orderService->transitionFulfillmentStatus($order, FulfillmentStatus::PartiallyFulfilled);
            }
        }
    }
}
