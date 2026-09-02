<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\FulfillmentSucceeded;
use App\Domain\Order\Events\RefundIssued;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Http\Controllers\Controller;
use App\Jobs\FulfillOrderItemJob;
use App\Models\FulfillmentLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminCommerceController extends Controller
{
    /**
     * Never-paid checkouts (abandoned / failed) are noise in the orders ledger;
     * the orders page hides them and the export mirrors that by default.
     *
     * @var list<string>
     */
    private const HIDDEN_ORDER_STATUSES = ['pending', 'cancelled', 'failed'];

    public function listOrders(Request $request)
    {
        $query = Order::with(['user', 'items']);

        if ($request->filled('status')) {
            $query->where('order_status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(fn ($q) => $q->where('order_number', 'like', "%{$search}%")->orWhereRelation('user', 'email', 'like', "%{$search}%"));
        }

        return response()->json($query->latest()->paginate(15));
    }

    /**
     * Stream the full order history as a CSV for record-keeping. Honours the
     * same status/search filters as listOrders and, like the orders page,
     * excludes never-paid checkouts unless a specific status is requested.
     * Streamed row-by-row via a cursor so large histories don't exhaust memory.
     */
    public function exportOrders(Request $request): StreamedResponse
    {
        // Bulk order + customer data leaving the system is always attributable.
        app(AuditLogService::class)->log(
            action: 'report.orders_exported',
            metadata: ['status' => $request->input('status', 'all')],
        );

        $query = Order::with('user')->withCount('items');

        if ($request->filled('status')) {
            $query->where('order_status', $request->input('status'));
        } else {
            $query->whereNotIn('order_status', self::HIDDEN_ORDER_STATUSES);
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(fn ($q) => $q->where('order_number', 'like', "%{$search}%")->orWhereRelation('user', 'email', 'like', "%{$search}%"));
        }

        $query->latest();

        $filename = 'orders-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Order Number', 'Customer Name', 'Customer Email', 'Items',
                'Subtotal (USD)', 'Total (USD)', 'Display Total', 'Display Currency',
                'Order Status', 'Payment Status', 'Fulfillment Status', 'Created At',
            ]);

            foreach ($query->cursor() as $order) {
                fputcsv($handle, [
                    $order->order_number,
                    $order->user?->name,
                    $order->user?->email,
                    $order->items_count,
                    number_format($order->usdSubtotal(), 2, '.', ''),
                    number_format($order->usdTotal(), 2, '.', ''),
                    number_format((float) $order->total_amount, 2, '.', ''),
                    $order->display_currency,
                    $order->order_status?->value,
                    $order->payment_status?->value,
                    $order->fulfillment_status?->value,
                    $order->created_at?->toDateTimeString(),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function listPayments(Request $request)
    {
        $query = PaymentAttempt::with(['user', 'order']);

        if ($request->filled('gateway')) {
            $query->where('gateway', $request->input('gateway'));
        }

        return response()->json($query->latest()->paginate(15));
    }

    public function listFulfillmentLogs(Request $request)
    {
        return response()->json(FulfillmentLog::latest('processed_at')->paginate(15));
    }

    public function retryFulfillment(string $itemId)
    {
        $item = OrderItem::findOrFail($itemId);

        // Can only retry failed or queued ones
        if ($item->fulfillment_status->value === 'fulfilled') {
            return response()->json(['message' => 'This item is already successfully fulfilled.'], 400);
        }

        // A failed item that was already refunded must never be retried: the
        // customer got their money back, so a successful retry would hand
        // them goods that were already paid back. Wallet credit + re-order is
        // the correct path for a customer who still wants the item.
        $order = $item->order;
        $orderService = app(OrderService::class);
        if ($order && ($orderService->hasRefundRecord($order, "item-{$item->id}") || $orderService->hasRefundRecord($order, 'final-'.$order->id))) {
            return response()->json([
                'message' => 'This item was already refunded to the customer. Retrying would deliver goods that were paid back; the customer can re-order using their wallet credit instead.',
            ], 409);
        }

        FulfillOrderItemJob::dispatch($item);

        return response()->json(['message' => 'Fulfillment job successfully re-queued.']);
    }

    public function refundOrder(Request $request, string $orderId)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        Order::findOrFail($orderId);
        $amount = round((float) $validated['amount'], 2);

        try {
            [$status, $message] = DB::transaction(function () use ($orderId, $amount, $validated) {
                // The order row lock is held across cap-check -> gateway call
                // -> record, so two concurrent refunds serialize and the
                // second one sees the first one's total. Without it, both
                // read "0 refunded", both pass the cap, both pay out.
                $order = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();
                $orderService = app(OrderService::class);

                // Cumulative cap: every refund already issued against this
                // order counts, including the AUTOMATIC fulfillment-failure
                // reversals, so no combination of refunds can hand back more
                // than the customer ever paid.
                $alreadyRefunded = $orderService->refundedTotalFor($order);
                $remaining = round((float) $order->total_amount - $alreadyRefunded, 2);

                if ($amount > $remaining) {
                    return [400, 'Refund amount cannot exceed the amount still refundable ('.number_format($remaining, 2).').'];
                }

                // Get the attempt that carried the money. Refunded stays in
                // the list because the providers stamp the attempt Refunded
                // even on a partial refund; the cumulative cap above is what
                // prevents over-refunding. Reserved is deliberately EXCLUDED:
                // a reserved wallet attempt has the funds locked, not
                // debited, so "refunding" it would credit money that was
                // never taken. Those are cancelled/released, never refunded.
                $attempt = $order->paymentAttempts()
                    ->whereIn('payment_status', [PaymentStatus::Paid, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded])
                    ->first();

                if (! $attempt) {
                    $hasReserved = $order->paymentAttempts()
                        ->where('payment_status', PaymentStatus::Reserved)
                        ->exists();

                    return [400, $hasReserved
                        ? 'This payment was never captured (funds are only reserved). Cancel the order to release the hold instead of refunding.'
                        : 'No successful payment attempt found for this order.'];
                }

                $provider = app(PaymentGatewayFactory::class)->getProvider($attempt->gateway);

                // The gateway's answer decides everything downstream.
                // Stamping Refunded and emailing the customer on a declined
                // (or, for crypto, nonexistent) refund promises money that
                // never moves.
                $tag = 'admin-'.Str::uuid();
                $refunded = $provider->refundPayment($attempt, $amount, $tag);

                if (! $refunded) {
                    app(AuditLogService::class)->log(
                        action: 'admin.order_refund_failed',
                        model: $order,
                        metadata: [
                            'amount' => $amount,
                            'gateway' => $attempt->gateway,
                            'reason' => $validated['reason'],
                        ],
                    );

                    $hint = $attempt->gateway === 'nowpayments'
                        ? ' Crypto payments have no refund API; send the funds back manually, then record it here.'
                        : ' The gateway declined the refund; nothing was recorded and the customer was not notified.';

                    return [400, 'The gateway did not process this refund.'.$hint];
                }

                $orderService->applyRefundRecord($order, $amount, $tag);
                $refundedTotal = $orderService->refundedTotalFor($order);
                $isFullyRefunded = $refundedTotal >= round((float) $order->total_amount, 2);

                $order->payment_status = $isFullyRefunded ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;
                if ($isFullyRefunded) {
                    $order->order_status = OrderStatus::Refunded;
                }
                $order->save();

                // Money returned by an admin's hand is always audited: who,
                // how much, through which gateway, and the stated reason.
                app(AuditLogService::class)->log(
                    action: 'admin.order_refunded',
                    model: $order,
                    metadata: [
                        'amount' => $amount,
                        'refunded_total' => $refundedTotal,
                        'gateway' => $attempt->gateway,
                        'reason' => $validated['reason'],
                    ],
                );

                // The customer email rides the commit: a rollback after the
                // gateway said yes must not un-send "your refund is on its
                // way". The tag keys the email's idempotency, so a second
                // admin refund of the SAME amount still emails the customer.
                DB::afterCommit(fn () => RefundIssued::dispatch($order, $amount, $validated['reason'], $tag));

                return [200, 'Refund processed successfully.'];
            });

            return response()->json(['message' => $message], $status);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Refund operation failed: '.$e->getMessage()], 400);
        }
    }

    public function markItemManuallyFulfilled(Request $request, string $itemId, OrderService $orderService)
    {
        $item = OrderItem::findOrFail($itemId);

        if ($item->fulfillment_status->value === 'fulfilled') {
            return response()->json(['message' => 'This item is already successfully fulfilled.'], 400);
        }

        DB::transaction(function () use ($item, $orderService, $request) {
            $item = OrderItem::where('id', $item->id)->lockForUpdate()->first();

            if ($item->fulfillment_status->value === 'fulfilled') {
                return;
            }

            $item->fulfillment_status = FulfillmentStatus::Fulfilled;
            $item->delivered_at = now();
            $item->failed_at = null;
            $item->fulfillment_reference = 'MANUAL-'.uniqid();

            $payload = (array) $item->fulfillment_payload;

            if ($request->filled('pin')) {
                $payload['pin'] = $request->input('pin');
            }

            $item->fulfillment_payload = $payload;

            $item->save();

            FulfillmentLog::create([
                'order_item_id' => $item->id,
                'provider_name' => 'manual',
                'request_payload' => ['action' => 'manual_fulfillment', 'admin_id' => auth('admin')->id()],
                'response_payload' => ['status' => 'success'],
                'status' => 'fulfilled',
                'processed_at' => now(),
            ]);

            FulfillmentSucceeded::dispatch($item);

            // Replicate FulfillOrderItemJob's checkOrderCompletion
            $order = Order::where('id', $item->order_id)->lockForUpdate()->first();
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
        });

        session()->flash('success', 'Item successfully marked as manually fulfilled.');

        return response()->json(['message' => 'Item successfully marked as manually fulfilled.']);
    }
}
