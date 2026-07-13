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
use Illuminate\Support\Facades\Notification;
use App\Notifications\CriticalSystemAlert;

class PollPendingFulfillmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1000; // Allow polling up to 1000 times to support 12-hour backoff

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
            $attempt = $this->attempts();

            DB::transaction(function () use ($item, $status, $result, $provider, $orderService, $walletProvider, &$needsRelease, $attempt) {
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

                    if ($attempt === 60 && $item->fulfillment_status !== FulfillmentStatus::Delayed) {
                        $item->fulfillment_status = FulfillmentStatus::Delayed;
                        $item->save();

                        Notification::route('mail', config('mail.admin_address') ?? 'dev@roddytechgroup.com')
                            ->notify(new CriticalSystemAlert(
                                'Order Delayed by Provider',
                                "Fulfillment for Order Item {$item->id} has been stuck in IN_PROGRESS for over 10 minutes. The system will continue monitoring it automatically.",
                                ['Order Item ID' => $item->id, 'Provider' => $item->provider_name]
                            ));
                    }
                }
            });

            // Bug #6 fix: gracefully handle max attempts instead of throwing MaxAttemptsExceededException
            if ($attempt >= 108) { // 60 attempts at 10s + 48 attempts at 15m = ~12 hours
                Log::critical("PollPendingFulfillmentJob stopping after 12 hours for item {$item->id}");

                DB::transaction(function () use ($item) {
                    $lockedItem = OrderItem::where('id', $item->id)->lockForUpdate()->first();
                    if (! $lockedItem || in_array($lockedItem->fulfillment_status, [FulfillmentStatus::Fulfilled, FulfillmentStatus::Failed])) {
                        return;
                    }

                    $lockedItem->fulfillment_status = FulfillmentStatus::Delayed;
                    $lockedItem->save();

                    Notification::route('mail', config('mail.admin_address') ?? 'dev@roddytechgroup.com')
                        ->notify(new CriticalSystemAlert(
                            'Action Required: Order Permanently Stuck',
                            "Order Item {$lockedItem->id} has been polling for 12 hours with no resolution. It has been frozen in the 'Delayed' state. Manual review required.",
                            ['Order Item ID' => $lockedItem->id]
                        ));
                });
                return;
            }

            if ($needsRelease) {
                if ($attempt <= 60) {
                    $this->release(10); // First 10 mins: poll every 10 seconds
                } else {
                    $this->release(900); // Next 12 hours: poll every 15 minutes
                }
            }

        } catch (\Exception $e) {
            Log::error("Fulfillment status polling exception for item {$item->id}: ".$e->getMessage());
            $this->release(10);
        }
    }

    /**
     * Handle permanent job failure (e.g., job crashed via exception).
     * We freeze the item as Delayed and alert the admin instead of auto-refunding.
     */
    public function failed(\Throwable $e): void
    {
        Log::critical("PollPendingFulfillmentJob permanently failed for item {$this->item->id} after all retries. Manual intervention required.");

        DB::transaction(function () use ($e) {
            $item = OrderItem::where('id', $this->item->id)->lockForUpdate()->first();
            if (! $item || in_array($item->fulfillment_status, [FulfillmentStatus::Fulfilled, FulfillmentStatus::Failed])) {
                return;
            }

            $item->fulfillment_status = FulfillmentStatus::Delayed;
            $item->save();

            Notification::route('mail', config('mail.admin_address') ?? 'dev@roddytechgroup.com')
                ->notify(new CriticalSystemAlert(
                    'Action Required: Polling Job Failed Permanently',
                    "Order Item {$item->id} polling job failed permanently. It has been frozen in the 'Delayed' state. Exception: " . $e->getMessage(),
                    ['Order Item ID' => $item->id]
                ));
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
