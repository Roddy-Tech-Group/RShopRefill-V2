<?php

namespace App\Domain\Order\Services;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Transaction\Exceptions\DuplicateTransactionException;
use App\Domain\Wallet\Services\WalletService;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Log;

/**
 * Refunds for orders paid through a NON-wallet gateway (card / mobile money /
 * crypto). The customer's money settled to us, so when fulfillment fails there
 * is nothing to "reverse" on a wallet payment - we instead credit the
 * customer's wallet with what they paid, honouring the wallet-first refund
 * policy ("failed delivery -> automatic refund to your wallet"). Wallet-funded
 * orders are handled separately by WalletPaymentProvider.
 */
class GatewayRefundService
{
    public function __construct(private WalletService $walletService) {}

    /**
     * Credit the customer's wallet for a single failed item on a non-wallet
     * paid order. Idempotent per item, so retries and the two failure paths
     * (FulfillOrderItemJob + PollPendingFulfillmentJob) can never double-credit.
     */
    public function refundFailedItemToWallet(OrderItem $item): void
    {
        $order = $item->order;
        if (! $order || ! $order->user) {
            return;
        }

        // Only act on a non-wallet payment that actually settled. Wallet
        // payments are released/refunded by WalletPaymentProvider elsewhere.
        $paid = $order->paymentAttempts()
            ->where('gateway', '!=', 'wallet')
            ->where('payment_status', PaymentStatus::Paid)
            ->latest()
            ->first();

        if (! $paid) {
            return;
        }

        // This item's share of what was actually charged (handles multi-item
        // orders where only some items failed).
        $share = $this->capturedShareForItem($order, $item, (float) $paid->amount);

        if ($share <= 0) {
            return;
        }

        $currency = Currency::tryFrom(strtoupper((string) $paid->currency)) ?? Currency::USD;
        $wallet = $this->walletService->getOrCreateWallet($order->user, $currency);

        try {
            $this->walletService->credit(
                $wallet,
                $share,
                TransactionCategory::Refund,
                "Refund for an undelivered item on order {$order->order_number}",
                "refund-{$item->id}",
                "refund-failed-item-{$item->id}",
            );

            Log::info('Gateway refund credited to wallet', [
                'order' => $order->order_number,
                'item' => $item->id,
                'amount' => $share,
                'currency' => $currency->value,
            ]);
        } catch (DuplicateTransactionException) {
            // Already refunded by an earlier attempt - safe no-op.
        }
    }

    /**
     * This item's slice of the cash that actually settled.
     *
     * The captured amount is the anchor, NOT the item's list price: an order
     * settled with an RCOIN or coupon discount captured less than the items
     * are worth, and refunding the pre-discount price hands the customer more
     * cash than they ever paid. Item subtotals are used only as weights.
     *
     * Shares are cut from a running cumulative total rather than rounded
     * independently, so the slices of a multi-item order telescope back to
     * exactly the captured amount - per-item rounding can never accumulate
     * into a refund that exceeds the charge.
     */
    private function capturedShareForItem(Order $order, OrderItem $item, float $captured): float
    {
        $captured = round($captured, 2);

        if ($captured <= 0) {
            return 0.0;
        }

        $rows = $order->items()->orderBy('id')->get(['id', 'subtotal_amount']);

        if ($rows->isEmpty()) {
            return $captured;
        }

        $weights = $rows->mapWithKeys(fn (OrderItem $row) => [
            $row->id => max(0.0, (float) $row->subtotal_amount),
        ]);
        $totalWeight = (float) $weights->sum();

        // Legacy or zero-priced snapshots carry no usable weights; an even
        // split is the only honest apportionment left.
        if ($totalWeight <= 0) {
            $weights = $weights->map(fn () => 1.0);
            $totalWeight = (float) $weights->count();
        }

        $cumulative = 0.0;

        foreach ($weights as $id => $weight) {
            $before = round($captured * ($cumulative / $totalWeight), 2);
            $cumulative += $weight;
            $upTo = round($captured * ($cumulative / $totalWeight), 2);

            if ($id === $item->id) {
                return max(0.0, round($upTo - $before, 2));
            }
        }

        Log::warning('Gateway refund could not apportion - item is not on its order', [
            'order' => $order->order_number,
            'item' => $item->id,
        ]);

        return 0.0;
    }
}
