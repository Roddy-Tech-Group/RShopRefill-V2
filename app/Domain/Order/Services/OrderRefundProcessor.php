<?php

namespace App\Domain\Order\Services;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Order\Events\RefundIssued;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Models\Order;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;

/**
 * Sends money back to a customer through the gateway that took it, and
 * records it: the money path behind an admin's refund on the Orders page.
 * (An eSIM refund credits the customer's wallet instead; see EsimRefundService.)
 *
 * The caller owns the transaction and must pass the order already locked
 * (lockForUpdate): the lock is held across cap check, gateway call and
 * record, so two concurrent refunds serialize and the second sees the first
 * one's total. Without it, both read "0 refunded", both pass, both pay out.
 *
 * The caller writes its own success audit row (admin.order_refunded,
 * admin.esim_refunded); a declined refund is audited here.
 */
class OrderRefundProcessor
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentGatewayFactory $gateways,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $ledgerContext  extra RefundLedger context (order_item_id, refunded_by, supplier_credit_status, ...)
     * @return array{ok: bool, status: int, message: string, gateway: string|null, refunded_total: float}
     */
    public function refund(Order $lockedOrder, float $amount, string $tag, string $reason, array $ledgerContext = []): array
    {
        $order = $lockedOrder;
        $amount = round($amount, 2);

        // Cumulative cap: every refund already issued against this order
        // counts, including the AUTOMATIC fulfillment-failure reversals, so no
        // combination of refunds can hand back more than the customer paid.
        $alreadyRefunded = $this->orders->refundedTotalFor($order);
        $remaining = round((float) $order->total_amount - $alreadyRefunded, 2);

        if ($amount > $remaining) {
            return $this->declined(400, 'Refund amount cannot exceed the amount still refundable ('.number_format($remaining, 2).').', $alreadyRefunded);
        }

        // Get the attempt that carried the money. Refunded stays in the list
        // because the providers stamp the attempt Refunded even on a partial
        // refund; the cumulative cap above is what prevents over-refunding.
        // Reserved is deliberately EXCLUDED: a reserved wallet attempt has the
        // funds locked, not debited, so "refunding" it would credit money that
        // was never taken. Those are cancelled/released, never refunded.
        /** @var PaymentAttempt|null $attempt */
        $attempt = $order->paymentAttempts()
            ->whereIn('payment_status', [PaymentStatus::Paid, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded])
            ->first();

        if (! $attempt) {
            $hasReserved = $order->paymentAttempts()
                ->where('payment_status', PaymentStatus::Reserved)
                ->exists();

            return $this->declined(400, $hasReserved
                ? 'This payment was never captured (funds are only reserved). Cancel the order to release the hold instead of refunding.'
                : 'No successful payment attempt found for this order.', $alreadyRefunded);
        }

        // The gateway's answer decides everything downstream. Stamping
        // Refunded and emailing the customer on a declined (or, for crypto,
        // nonexistent) refund promises money that never moves.
        $refunded = $this->gateways->getProvider($attempt->gateway)->refundPayment($attempt, $amount, $tag);

        if (! $refunded) {
            $this->audit->log(
                action: 'admin.order_refund_failed',
                model: $order,
                metadata: array_filter([
                    'amount' => $amount,
                    'gateway' => $attempt->gateway,
                    'reason' => $reason,
                    'order_item_id' => $ledgerContext['order_item_id'] ?? null,
                ], fn ($value) => $value !== null),
            );

            $hint = $attempt->gateway === 'nowpayments'
                ? ' Crypto payments have no refund API; send the funds back manually, then record it here.'
                : ' The gateway declined the refund; nothing was recorded and the customer was not notified.';

            return $this->declined(400, 'The gateway did not process this refund.'.$hint, $alreadyRefunded, $attempt->gateway);
        }

        $this->orders->applyRefundRecord($order, $amount, $tag, array_merge($ledgerContext, [
            'gateway' => $attempt->gateway,
            'reason' => $reason,
        ]));
        $this->orders->markRefunded($order);
        $refundedTotal = $this->orders->refundedTotalFor($order);

        // The customer email rides the commit: a rollback after the gateway
        // said yes must not un-send "your refund is on its way". The tag keys
        // the email's idempotency, so a second refund of the SAME amount
        // still emails the customer.
        DB::afterCommit(fn () => RefundIssued::dispatch($order, $amount, $reason, $tag));

        return ['ok' => true, 'status' => 200, 'message' => 'Refund processed successfully.', 'gateway' => $attempt->gateway, 'refunded_total' => $refundedTotal];
    }

    /**
     * @return array{ok: bool, status: int, message: string, gateway: string|null, refunded_total: float}
     */
    private function declined(int $status, string $message, float $refundedTotal, ?string $gateway = null): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message, 'gateway' => $gateway, 'refunded_total' => $refundedTotal];
    }
}
