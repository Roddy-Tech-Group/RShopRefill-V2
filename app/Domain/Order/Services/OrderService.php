<?php

namespace App\Domain\Order\Services;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\RefundIssued;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\WalletPaymentProvider;
use App\Jobs\ProcessOrderRewardsJob;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /**
     * Transition payment status and check for subsequent order lifecycle transitions.
     */
    public function transitionPaymentStatus(Order $order, PaymentStatus $newStatus, ?array $payload = null): void
    {
        DB::transaction(function () use ($order, $newStatus, $payload) {
            $order = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            $order->payment_status = $newStatus;

            if ($newStatus === PaymentStatus::Paid) {
                if ($order->order_status !== OrderStatus::Completed) {
                    $order->order_status = OrderStatus::Processing;
                }
                $order->placed_at = $order->placed_at ?? now();
            } elseif ($newStatus === PaymentStatus::Failed) {
                $order->order_status = OrderStatus::Failed;
                $order->failed_at = now();
            }

            if ($payload) {
                $order->metadata = array_merge($order->metadata ?? [], ['payment_transition_payload' => $payload]);
            }

            $order->save();
        });
    }

    /**
     * Transition fulfillment status and check for overall order completion.
     */
    public function transitionFulfillmentStatus(Order $order, FulfillmentStatus $newStatus): void
    {
        DB::transaction(function () use ($order, $newStatus) {
            $order = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            $order->fulfillment_status = $newStatus;

            if (in_array($newStatus, [FulfillmentStatus::Fulfilled, FulfillmentStatus::PartiallyFulfilled])) {
                $order->order_status = OrderStatus::Completed;
                $order->completed_at = now();
                // An order recovered by a retry must not keep its failure stamp
                $order->failed_at = null;

                // Settle reserved wallet payments (PIN flow)
                $walletPayment = $order->paymentAttempts()
                    ->where('gateway', 'wallet')
                    ->where('payment_status', PaymentStatus::Reserved)
                    ->first();

                if ($walletPayment) {
                    $walletProvider = app(WalletPaymentProvider::class);

                    // What is still reserved is exactly what finalizeDebit will take.
                    // Read it before the debit: a failure path that already unlocked a
                    // failed item's share (releasePartialFunds) shrank this reservation.
                    $stillReserved = (float) $walletPayment->amount;

                    $walletProvider->finalizeDebit($walletPayment);
                    $order->payment_status = PaymentStatus::Paid;

                    if ($newStatus === FulfillmentStatus::PartiallyFulfilled) {
                        // Each failed item's share of what was actually CAPTURED,
                        // not its list price: a discounted (RCOIN/coupon) order
                        // captured less than the item subtotals, and refunding
                        // subtotals hands back more than the customer ever paid.
                        $refundService = app(GatewayRefundService::class);
                        $failedTotal = round(
                            (float) $order->items
                                ->where('fulfillment_status', FulfillmentStatus::Failed)
                                ->sum(fn ($item) => $refundService->capturedShareForItem($order, $item, (float) $order->total_amount)),
                            2,
                        );

                        // Reverse each failed item exactly once: anything already
                        // unlocked at failure time was never debited, so refunding the
                        // full failed total on top of it pays the customer back twice.
                        $alreadyReleased = max(0.0, round((float) $order->total_amount - $stillReserved, 2));
                        $refundable = round($failedTotal - $alreadyReleased, 2);

                        if ($refundable > 0 && $walletProvider->refundPayment($walletPayment, $refundable, 'settle-'.$order->id)) {
                            $this->applyRefundRecord($order, $refundable, 'settle-'.$order->id);
                            DB::afterCommit(fn () => RefundIssued::dispatch(
                                $order,
                                $refundable,
                                'Part of your order could not be delivered, so we refunded it to your wallet.',
                                'settle-'.$order->id,
                            ));
                        }
                    }
                }
            } elseif ($newStatus === FulfillmentStatus::Failed) {
                $order->order_status = OrderStatus::Failed;
                $order->failed_at = now();
            }

            $order->save();

            // Dispatch rewards job if the order just transitioned to Completed.
            // Fraud hold: when `fraud_hold_enabled` is on, delay the credit by
            // `fraud_hold_days` so suspicious orders have time to be refunded
            // before the Rcoin lands in the customer's wallet. The job itself
            // re-checks order status before crediting, so a refund in the
            // interim is safe.
            // afterCommit: this runs inside an open transaction, so a worker picking
            // the job up before the commit lands would read a stale order (or none at
            // all) and silently drop the cashback / referral bonus.
            if ($order->wasChanged('order_status') && $order->order_status === OrderStatus::Completed) {
                $job = ProcessOrderRewardsJob::dispatch($order)->onQueue('rewards')->afterCommit();

                if (Setting::get('fraud_hold_enabled', false)) {
                    $days = max(0, (int) Setting::get('fraud_hold_days', 0));
                    if ($days > 0) {
                        $job->delay(now()->addDays($days));
                    }
                }
            }
        });
    }

    /**
     * Everything ever given back on this order, across BOTH refund families:
     * automatic reversals (failed fulfillment) and admin refunds. The admin
     * cap reads this, so an order that was auto-refunded cannot be refunded a
     * second time by hand - the two families used to keep separate books.
     */
    /**
     * Whether a refund has already been recorded on this order under the given
     * idempotency tag (e.g. "item-{id}" for a per-item reversal, "final-{order}"
     * for a whole-order one). Read by the retry-fulfillment guards: an item
     * whose refund tag exists must never be re-fulfilled.
     */
    public function hasRefundRecord(Order $order, string $tag): bool
    {
        return array_key_exists($tag, (array) (($order->metadata ?? [])['refund_records'] ?? []));
    }

    public function refundedTotalFor(Order $order): float
    {
        return round((float) ($order->metadata['refunded_total'] ?? 0), 2);
    }

    /**
     * Record money given back on this order under an idempotency tag. The
     * ledger is a tag => amount map; replaying a tag (job retry, double-run
     * failure path) records nothing, and refunded_total is always the sum of
     * the map, so the admin cap can never drift from what actually moved.
     * Takes its own lock; callers holding the order open must not save their
     * stale instance afterwards (record last, or use applyRefundRecord).
     */
    public function recordRefundedAmount(Order $order, float $amount, string $tag): float
    {
        return DB::transaction(function () use ($order, $amount, $tag) {
            $fresh = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            $this->applyRefundRecord($fresh, $amount, $tag);
            $fresh->save();

            return $this->refundedTotalFor($fresh);
        });
    }

    /**
     * The in-place variant for callers already holding the order row lock:
     * mutates the given instance and leaves saving to the caller's flow.
     */
    public function applyRefundRecord(Order $order, float $amount, string $tag): void
    {
        $metadata = $order->metadata ?? [];
        $records = (array) ($metadata['refund_records'] ?? []);

        if (array_key_exists($tag, $records)) {
            return;
        }

        $records[$tag] = round($amount, 2);
        $metadata['refund_records'] = $records;
        $metadata['refunded_total'] = round(array_sum($records), 2);

        $order->metadata = $metadata;
    }
}
