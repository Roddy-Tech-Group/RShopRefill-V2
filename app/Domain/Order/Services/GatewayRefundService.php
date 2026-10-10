<?php

namespace App\Domain\Order\Services;

use App\Domain\Order\Events\RefundIssued;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Domain\Transaction\Exceptions\DuplicateTransactionException;
use App\Domain\Wallet\Services\WalletService;
use App\Enums\AlertSeverity;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;
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

        $credit = $this->walletCreditFor($paid, $share);

        if ($credit === null) {
            // No wallet in the paid currency and no rate to value it by: never
            // guess. Nothing moves, nothing is recorded as refunded, and a
            // person is told, so the customer is made whole by hand.
            app(OperationalAlerter::class)->send(
                AlertSeverity::Sev2,
                "gateway-refund-unvalued:{$item->id}",
                'Automatic refund needs a person',
                "Item {$item->id} on order {$order->order_number} could not be delivered, but its "
                    .number_format($share, 2).' '.strtoupper((string) $paid->currency)
                    .' could not be valued for a wallet refund (no rate recorded or on file). Refund it by hand.',
                ['order' => $order->order_number, 'item' => $item->id, 'amount' => $share, 'currency' => $paid->currency],
            );

            return;
        }

        [$currency, $creditAmount] = $credit;
        $converted = $currency->value !== strtoupper((string) $paid->currency);
        $wallet = $this->walletService->getOrCreateWallet($order->user, $currency);

        try {
            $this->walletService->credit(
                $wallet,
                $creditAmount,
                TransactionCategory::Refund,
                "Refund for an undelivered item on order {$order->order_number}",
                "refund-{$item->id}",
                "refund-failed-item-{$item->id}",
            );

            Log::info('Gateway refund credited to wallet', [
                'order' => $order->order_number,
                'item' => $item->id,
                'amount' => $share,
                'paid_currency' => strtoupper((string) $paid->currency),
                'credited' => $creditAmount,
                'currency' => $currency->value,
            ]);

            $reason = $converted
                ? 'An item on your order could not be delivered, so we refunded it to your wallet as '
                    .$currency->value.' '.number_format($creditAmount, 2).'.'
                : 'An item on your order could not be delivered, so we refunded it to your wallet.';

            // The customer hears about money that moved, once the move is
            // durable. The duplicate branch below deliberately does NOT
            // dispatch: that refund's email already went out the first time.
            DB::afterCommit(fn () => RefundIssued::dispatch(
                $order,
                $share,
                $reason,
                "item-{$item->id}",
            ));
        } catch (DuplicateTransactionException) {
            // Already refunded by an earlier attempt - safe no-op.
        }

        // On the order's shared refund ledger either way (the record is
        // tag-idempotent, so the duplicate branch above records nothing new):
        // the admin refund cap must see automatic reversals or it will
        // happily pay the same money back a second time.
        app(OrderService::class)->recordRefundedAmount($order, $share, "item-{$item->id}");
    }

    /**
     * The wallet a refund of $amount (in the paid currency) lands in, and how
     * much it credits there, or null when it cannot be valued.
     *
     * A currency a wallet is held in is refunded into that wallet, figure for
     * figure. Any other (XOF, KES, UGX, RWF, a card-only currency) has no
     * wallet of its own, so its value goes to the USD wallet at the rate frozen
     * when the customer paid: what they get back is what they paid, whatever
     * the rate has done since. The figure itself is never carried across: 5,000
     * XOF is about $8, and crediting $5,000 hands out money nobody paid.
     *
     * @return array{0: Currency, 1: float}|null
     */
    private function walletCreditFor(PaymentAttempt $paid, float $amount): ?array
    {
        $paidCurrency = strtoupper((string) $paid->currency);
        $own = Currency::tryFrom($paidCurrency);

        if ($own !== null && $own !== Currency::RCOIN) {
            return [$own, $amount];
        }

        // Local currency units per US dollar, frozen at checkout. The column
        // defaults to 1, which for these currencies means "never recorded"
        // (an attempt made outside checkout), not parity with the dollar, so
        // only then is today's rate used; with none on file, nothing is guessed.
        $perUsd = (float) $paid->exchange_rate_snapshot;

        if ($perUsd <= 0 || $perUsd === 1.0) {
            $perUsd = (float) (CurrencyRate::query()
                ->where('code', $paidCurrency)
                ->where('is_active', true)
                ->value('rate_per_usd') ?? 0);
        }

        if ($perUsd <= 0) {
            return null;
        }

        $usd = round($amount / $perUsd, 2);

        return $usd > 0 ? [Currency::USD, $usd] : null;
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
     *
     * Public because the WALLET reversal paths (FulfillOrderItemJob,
     * PollPendingFulfillmentJob, OrderService's partial settle) need the same
     * discount-aware apportioning; they used to refund raw item subtotals and
     * over-refunded every RCOIN/coupon-discounted order.
     */
    public function capturedShareForItem(Order $order, OrderItem $item, float $captured): float
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
