<?php

namespace App\Http\Controllers\Admin;

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
        return response()->json(FulfillmentLog::latest()->paginate(15));
    }

    public function retryFulfillment(string $itemId)
    {
        $item = OrderItem::findOrFail($itemId);

        // Can only retry failed or queued ones
        if ($item->fulfillment_status->value === 'fulfilled') {
            return response()->json(['message' => 'This item is already successfully fulfilled.'], 400);
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

        $order = Order::findOrFail($orderId);
        $amount = (float) $validated['amount'];

        if ($amount > $order->total_amount) {
            return response()->json(['message' => 'Refund amount cannot exceed the order total amount.'], 400);
        }

        // Get successful or active payment attempt
        $attempt = $order->paymentAttempts()
            ->whereIn('payment_status', [PaymentStatus::Paid, PaymentStatus::Reserved])
            ->first();

        if (! $attempt) {
            return response()->json(['message' => 'No successful payment attempt found for this order.'], 400);
        }

        try {
            $factory = app(PaymentGatewayFactory::class);
            $provider = $factory->getProvider($attempt->gateway);

            // Trigger refund in gateway
            $provider->refundPayment($attempt, $amount);

            // Mark order status
            $order->order_status = OrderStatus::Refunded;
            $order->payment_status = PaymentStatus::Refunded;
            $order->save();

            RefundIssued::dispatch($order, $amount, $validated['reason']);

            return response()->json(['message' => 'Refund processed successfully.']);
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
