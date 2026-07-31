<?php

namespace App\Jobs;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Services\FulfillmentProviderFactory;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\FulfillmentFailed;
use App\Domain\Order\Events\FulfillmentQueued;
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

class FulfillOrderItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(protected OrderItem $item)
    {
        $this->onQueue('fulfillment');
    }

    public function handle(
        FulfillmentProviderFactory $providerFactory,
        OrderService $orderService,
        WalletPaymentProvider $walletProvider
    ): void {
        Log::info("FulfillOrderItemJob: starting fulfillment for order item {$this->item->id}");

        // Bug #3 fix: wrap the entire guard + status-update in a transaction so the
        // lockForUpdate() holds for the duration of the idempotency check. Previously,
        // the lock was released immediately after the first fetch, allowing a concurrent
        // retry to read stale state and dispatch a duplicate fulfillment to Zendit.
        $shouldProceed = DB::transaction(function () {
            $item = OrderItem::where('id', $this->item->id)->lockForUpdate()->first();
            if (! $item) {
                return false;
            }

            // Idempotency: skip if already fulfilled or currently in-flight with a reference
            if ($item->fulfillment_status === FulfillmentStatus::Fulfilled) {
                return false;
            }
            if ($item->fulfillment_status === FulfillmentStatus::Processing && $item->fulfillment_reference) {
                // Already dispatched to Zendit — let PollPendingFulfillmentJob handle it
                return false;
            }

            $item->fulfillment_status = FulfillmentStatus::Processing;
            // Prevent double-spend: Set a temporary reference so concurrent jobs instantly exit on the guard above
            $item->fulfillment_reference = 'PENDING-'.uniqid();
            $item->save();

            return true;
        });

        if (! $shouldProceed) {
            return;
        }

        // Reload a fresh copy after the transaction above released its lock
        $item = OrderItem::find($this->item->id);

        FulfillmentQueued::dispatch($item);

        try {
            $provider = $providerFactory->getProvider($item->provider_name);
            $result = $provider->fulfill($item);

            DB::transaction(function () use ($item, $result, $orderService, $walletProvider, $provider) {
                // Bug #3 fix: re-fetch with a lock inside this transaction so we are
                // operating on the latest DB state, not a stale in-memory object.
                $item = OrderItem::where('id', $item->id)->lockForUpdate()->first();
                if (! $item) {
                    return;
                }

                $status = $result['status'];
                $item->fulfillment_status = $status;
                $item->fulfillment_reference = $result['reference'] ?? $item->fulfillment_reference;

                if ($status === FulfillmentStatus::Fulfilled) {
                    $item->fulfillment_payload = $provider->normalizeResponse($result['payload'] ?? []);
                    $item->delivered_at = now();
                    // A recovered retry must not keep advertising its old failure
                    $item->failed_at = null;
                    $item->save();

                    FulfillmentSucceeded::dispatch($item);

                    $this->checkOrderCompletion($item->order_id, $orderService);
                } elseif ($status === FulfillmentStatus::Processing || $status === FulfillmentStatus::Delayed) {
                    $item->save();
                    // Schedule status polling job
                    PollPendingFulfillmentJob::dispatch($item)->delay(now()->addSeconds(10));
                } else {
                    // Failed
                    $item->failed_at = now();
                    $item->save();

                    FulfillmentFailed::dispatch($item, 'Provider returned failed status');

                    // Handle wallet reversal if wallet checkout and all items failed
                    $this->handleFailureReversal($item, $walletProvider);

                    $this->checkOrderCompletion($item->order_id, $orderService);
                }
            });

        } catch (\Exception $e) {
            Log::error("Fulfillment job crashed for item {$item->id}: ".$e->getMessage());

            DB::transaction(function () use ($item, $e) {
                $freshItem = OrderItem::where('id', $item->id)->lockForUpdate()->first();
                if (! $freshItem || $freshItem->fulfillment_status === FulfillmentStatus::Fulfilled) {
                    return;
                }
                $freshItem->fulfillment_status = FulfillmentStatus::Failed;
                $freshItem->failed_at = now();
                $freshItem->save();

                FulfillmentFailed::dispatch($freshItem, $e->getMessage());
            });

            // The customer's money is deliberately NOT reversed here. This throw
            // schedules another attempt, and a retry that succeeds after a reversal
            // hands over a real voucher the customer was already refunded for.
            // failed() owns the reversal, once no further attempt is coming.
            throw $e; // re-throw so Laravel can schedule retries
        }
    }

    /**
     * Bug #7 fix: Handle permanent job failure after all retries are exhausted.
     * Without this, items stay stuck in 'processing' forever with no refund.
     */
    public function failed(\Throwable $e): void
    {
        Log::critical("FulfillOrderItemJob permanently failed for item {$this->item->id}: ".$e->getMessage());

        $walletProvider = app(WalletPaymentProvider::class);

        DB::transaction(function () use ($e, $walletProvider) {
            $item = OrderItem::where('id', $this->item->id)->lockForUpdate()->first();
            if (! $item || $item->fulfillment_status === FulfillmentStatus::Fulfilled) {
                return;
            }

            $item->fulfillment_status = FulfillmentStatus::Failed;
            $item->failed_at = now();
            $item->save();

            FulfillmentFailed::dispatch($item, 'Job permanently failed: '.$e->getMessage());
            $this->handleFailureReversal($item, $walletProvider);

            $this->checkOrderCompletion($item->order_id, app(OrderService::class));
        });
    }

    private function handleFailureReversal(OrderItem $item, WalletPaymentProvider $walletProvider): void
    {
        $order = $item->order;

        // Find wallet payment (could be Reserved or Paid)
        $walletPayment = $order->paymentAttempts()
            ->where('gateway', 'wallet')
            ->whereIn('payment_status', [PaymentStatus::Reserved, PaymentStatus::Paid])
            ->first();

        if ($walletPayment) {
            // Releasing the whole reservation is only correct when nothing on this
            // order can still be delivered. A sibling that is queued or has not
            // started yet WILL buy a real voucher from its own job, so treating it
            // as a total failure unlocks money for goods the customer then receives
            // free - and leaves the attempt un-Reserved so finalizeDebit never runs.
            //
            // Re-read the siblings under a row lock rather than trusting the
            // already-loaded relation: this decision releases money, and a
            // concurrent sibling job flipping to Fulfilled between the read and
            // the release is precisely the race that would refund an order the
            // customer is about to receive. Both callers run inside a
            // transaction, so the lock is held until the reversal commits.
            $orderItems = $order->items()->lockForUpdate()->get();
            $hasDeliverableItem = $orderItems->contains(function ($i) {
                return in_array($i->fulfillment_status, [
                    FulfillmentStatus::Fulfilled,
                    FulfillmentStatus::Processing,
                    FulfillmentStatus::Delayed,
                    FulfillmentStatus::NotStarted,
                    FulfillmentStatus::Queued,
                ]);
            });

            if (! $hasDeliverableItem) {
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
                // Partial fulfillment: refund only this item's share
                $refundAmount = $item->subtotal_amount;
                if ($walletPayment->payment_status === PaymentStatus::Paid) {
                    $walletProvider->refundPayment($walletPayment, $refundAmount);
                }
                // If Reserved, the full reservation will be settled when remaining items resolve.
            }

            return;
        }

        // Non-wallet (card / mobile money / crypto) paid order: the money
        // settled to us, so honour the wallet-first refund policy by crediting
        // the customer's wallet for this failed item. Idempotent per item.
        app(GatewayRefundService::class)->refundFailedItemToWallet($item);
    }

    private function checkOrderCompletion(string $orderId, OrderService $orderService): void
    {
        $order = Order::where('id', $orderId)->lockForUpdate()->first();
        if (! $order) {
            return;
        }

        $orderItems = $order->items()->lockForUpdate()->get();

        $isOrderFinished = $orderItems->every(fn ($i) => in_array($i->fulfillment_status, [
            FulfillmentStatus::Fulfilled,
            FulfillmentStatus::Failed,
        ]));

        if ($isOrderFinished) {
            $allFailed = $orderItems->every(fn ($i) => $i->fulfillment_status === FulfillmentStatus::Failed);
            $allFulfilled = $orderItems->every(fn ($i) => $i->fulfillment_status === FulfillmentStatus::Fulfilled);

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
