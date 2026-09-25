<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Fulfillment\Enums\EsimStatus;
use App\Domain\Fulfillment\Providers\AiraloFulfillmentProvider;
use App\Domain\Order\Events\RefundIssued;
use App\Domain\Order\Services\GatewayRefundService;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Wallet\Services\WalletService;
use App\Models\Admin;
use App\Models\Esim;
use App\Models\Order;
use App\Models\OrderRefund;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Refunds one unused eSIM in two steps, so we only ever give back money the
 * supplier has already given back to us.
 *
 *   1. Request. The eSIM must be from Airalo, bought within the refund window,
 *      and unused, confirmed live with Airalo at that moment. A claim goes to
 *      Airalo and the eSIM shows Refund pending. No money moves.
 *   2. Complete, once Airalo's credit is on our account (they email us; there
 *      is no endpoint to check). The customer's wallet in the currency they
 *      paid in is credited with exactly what they paid for that eSIM, whatever
 *      method they paid with, and the wallet is created if they have none in
 *      that currency. The Finances page takes the revenue and Airalo's cost off
 *      together, in that month.
 *   Or Refused, when Airalo says no. Nothing moves and the eSIM goes back to
 *   its real status.
 */
class EsimRefundService
{
    public function __construct(
        private readonly EsimUsageSync $usage,
        private readonly GatewayRefundService $shares,
        private readonly AiraloFulfillmentProvider $airalo,
        private readonly WalletService $wallets,
        private readonly OrderService $orders,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Why a refund cannot be requested for this eSIM, from what we already
     * know, or null when it can. The live check with Airalo still runs when
     * the refund is requested.
     */
    public function blockedReason(Esim $esim): ?string
    {
        // Refunded here, or refunded at the provider (its dashboard says
        // Refunded; the API shows an eSIM with no package left).
        if ($esim->isRefunded() || $esim->status === EsimStatus::Refunded) {
            return 'This eSIM was already refunded.';
        }

        if ($esim->isRefundPending()) {
            return 'A refund is already pending with the provider.';
        }

        if ($esim->refund_status === Esim::REFUND_REJECTED) {
            return 'The provider already declined a refund for this eSIM.';
        }

        // Money already went back on this order from the Orders page, and that
        // refund does not say which eSIM it covered. A second refund here could
        // pay the same eSIM twice, so the Orders page stays the place for it.
        if ($esim->order && self::hasOrdersPageRefund($esim->order)) {
            return 'This order already has a refund from the Orders page.';
        }

        if (! $this->usage->supports($esim)) {
            return 'Refunds here are only for eSIMs from a provider with a refund process.';
        }

        if ($esim->purchased_at->lt(now()->subDays($this->windowDays()))) {
            return "Past the {$this->windowDays()}-day refund window.";
        }

        if ($esim->status !== EsimStatus::Inactive) {
            return 'Only an eSIM that was never activated can be refunded.';
        }

        return null;
    }

    /**
     * Whether an admin refunded money on this order from the Orders page
     * (refund tags admin-{uuid}). The automatic reversals of undelivered items
     * and eSIM refunds made here do not count.
     */
    public static function hasOrdersPageRefund(Order $order): bool
    {
        foreach (array_keys((array) data_get($order->metadata, 'refund_records', [])) as $tag) {
            if (str_starts_with((string) $tag, 'admin-')) {
                return true;
            }
        }

        return false;
    }

    public function windowDays(): int
    {
        return max(1, (int) config('services.airalo.refund_window_days', 14));
    }

    /**
     * What the customer gets back for this one eSIM: what they actually paid
     * for it (after any coupon or Rcoin discount), in the currency they paid
     * in, never more than is still refundable on the order.
     *
     * @return array{amount: float, currency: Currency|null}
     */
    public function refundFor(Esim $esim): array
    {
        $order = $esim->order;
        $item = $esim->orderItem;

        $attempt = $order->paymentAttempts()
            ->whereIn('payment_status', [PaymentStatus::Paid, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded])
            ->first();

        $currency = Currency::tryFrom(strtoupper((string) $attempt?->currency))
            ?? Currency::tryFrom(strtoupper((string) $order->display_currency));

        if (! $attempt || ! $item || $currency === null || $currency === Currency::RCOIN) {
            return ['amount' => 0.0, 'currency' => $currency];
        }

        $share = $this->shares->capturedShareForItem($order, $item, (float) $attempt->amount);
        $perUnit = round($share / max(1, (int) $item->quantity), 2);
        $remaining = round((float) $order->total_amount - $this->orders->refundedTotalFor($order), 2);

        return ['amount' => max(0.0, min($perUnit, $remaining)), 'currency' => $currency];
    }

    /**
     * Step 1: check with Airalo, file the claim, mark the eSIM Refund pending.
     *
     * @return array{ok: bool, message: string}
     */
    public function request(Esim $esim, Admin $admin, string $reason, string $note): array
    {
        $note = trim($note);

        if (! array_key_exists($reason, AiraloFulfillmentProvider::REFUND_REASONS)) {
            return ['ok' => false, 'message' => 'Choose a refund reason.'];
        }

        if ($reason === 'OTHERS' && mb_strlen($note) < 10) {
            return ['ok' => false, 'message' => 'Explain what happened when the reason is Other.'];
        }

        return $this->withLock($esim, function () use ($esim, $admin, $reason, $note) {
            // The live check runs outside the database lock: it is a network
            // call, and the cache lock already keeps a second refund out.
            if (! $this->usage->sync($esim)) {
                return ['ok' => false, 'message' => 'Could not confirm with the provider that this eSIM is unused. Nothing was requested; try again in a minute.'];
            }

            if ($blocked = $this->blockedReason($esim->refresh())) {
                return ['ok' => false, 'message' => $blocked];
            }

            $refund = $this->refundFor($esim);

            if ($refund['amount'] <= 0 || $refund['currency'] === null) {
                return ['ok' => false, 'message' => 'Nothing was paid for this eSIM that could go back to a wallet.'];
            }

            $esim->forceFill([
                'status' => EsimStatus::RefundPending,
                'refund_status' => Esim::REFUND_PENDING,
                'refund_amount' => $refund['amount'],
                'refund_currency' => $refund['currency']->value,
                'refund_reason' => $reason,
                'refund_note' => $note !== '' ? $note : null,
                'refund_requested_by' => $admin->getKey(),
                'refund_requested_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'admin.esim_refund_requested',
                model: $esim->order,
                metadata: [
                    'esim_id' => $esim->getKey(),
                    'iccid' => $esim->iccid,
                    'amount' => $refund['amount'],
                    'currency' => $refund['currency']->value,
                    'reason' => $reason,
                    'note' => $note,
                ],
            );

            return $this->fileSupplierClaim($esim)
                ? ['ok' => true, 'message' => 'Refund requested.']
                : ['ok' => true, 'message' => 'Refund marked pending, but the request did not reach the provider. Send it again from the eSIM page.'];
        });
    }

    /**
     * Send (or resend) the claim to Airalo. Safe to repeat: a claim already on
     * file is not sent twice. Returns true when a claim is on file.
     */
    public function fileSupplierClaim(Esim $esim): bool
    {
        if (! $esim->isRefundPending()) {
            return false;
        }

        if ($esim->refund_claim_reference !== null) {
            return true;
        }

        $claim = $this->airalo->requestRefund([$esim->iccid], (string) $esim->refund_reason, self::reasonText($esim));

        $esim->forceFill([
            'refund_claim_reference' => $claim['refund_id'] ?? null,
            'metadata' => array_merge((array) $esim->metadata, $claim
                ? ['refund_claim_filed_at' => now()->toIso8601String(), 'refund_claim_error' => null]
                : ['refund_claim_error' => 'The provider did not accept the request at '.now()->format('M j, Y g:i A')]),
        ])->save();

        return $claim !== null;
    }

    /**
     * Step 2: the provider confirmed the refund. Credit the customer's wallet,
     * record the refund and the provider's credit, and mark the order.
     *
     * The provider credits back exactly what it charged us for the eSIM, the
     * cost stored on its order item, so that is the credit recorded.
     *
     * @return array{ok: bool, message: string}
     */
    public function complete(Esim $esim, Admin $admin): array
    {
        return $this->withLock($esim, fn () => DB::transaction(function () use ($esim, $admin) {
            $order = Order::whereKey($esim->order_id)->lockForUpdate()->firstOrFail();
            $locked = Esim::whereKey($esim->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isRefundPending()) {
                return ['ok' => false, 'message' => 'This eSIM has no refund pending.'];
            }

            // Someone refunded the order from the Orders page while this
            // request was pending; crediting now could pay the eSIM twice.
            if (self::hasOrdersPageRefund($order)) {
                return ['ok' => false, 'message' => 'This order was refunded from the Orders page since this request. Mark this refund as declined instead.'];
            }

            $providerCreditUsd = round((float) $locked->orderItem?->provider_cost_usd, 4);

            $currency = Currency::tryFrom((string) $locked->refund_currency);
            $remaining = round((float) $order->total_amount - $this->orders->refundedTotalFor($order), 2);
            $amount = round(min((float) $locked->refund_amount, $remaining), 2);

            if ($currency === null || $amount <= 0) {
                return ['ok' => false, 'message' => 'Nothing is left to refund on this order.'];
            }

            $tag = 'esim-'.$locked->getKey();

            $this->wallets->credit(
                wallet: $this->wallets->getOrCreateWallet($order->user, $currency),
                amount: $amount,
                category: TransactionCategory::Refund,
                description: "eSIM refund credited to your wallet (order {$order->order_number})",
                reference: $tag,
                idempotencyKey: 'esim-refund-'.$locked->getKey(),
                metadata: [
                    'esim_id' => $locked->getKey(),
                    'iccid' => $locked->iccid,
                    'order_id' => $order->getKey(),
                    'admin_id' => $admin->getKey(),
                ],
            );

            $this->orders->applyRefundRecord($order, $amount, $tag, [
                'order_item_id' => $locked->order_item_id,
                'gateway' => 'wallet',
                'reason' => self::reasonText($locked),
                'refunded_by' => $admin->getKey(),
                'supplier_credit_status' => OrderRefund::CREDIT_CREDITED,
                'supplier_credit_usd' => $providerCreditUsd,
                'supplier_credited_at' => now(),
                'supplier_credit_reference' => $locked->refund_claim_reference,
                'supplier_credit_reason' => $locked->refund_reason,
                'metadata' => ['esim_id' => $locked->getKey(), 'iccid' => $locked->iccid],
            ]);
            $this->orders->markRefunded($order);

            $ledgerRow = OrderRefund::query()->where('order_id', $order->getKey())->where('tag', $tag)->first();

            $locked->forceFill([
                'status' => EsimStatus::Refunded,
                'refund_status' => Esim::REFUND_COMPLETED,
                'refund_amount' => $amount,
                'refund_resolved_by' => $admin->getKey(),
                'refund_resolved_at' => now(),
                'refunded_at' => now(),
                'order_refund_id' => $ledgerRow?->getKey(),
            ])->save();

            $this->audit->log(
                action: 'admin.esim_refunded',
                model: $order,
                metadata: [
                    'esim_id' => $locked->getKey(),
                    'iccid' => $locked->iccid,
                    'order_item_id' => $locked->order_item_id,
                    'amount' => $amount,
                    'currency' => $currency->value,
                    'credited_to' => 'wallet',
                    'reason' => self::reasonText($locked),
                    'supplier_reason' => $locked->refund_reason,
                    'provider_credit_usd' => $providerCreditUsd,
                    'provider_reference' => $locked->refund_claim_reference,
                    'order_refund_id' => $ledgerRow?->getKey(),
                ],
            );

            // The customer hears once the credit is durable.
            DB::afterCommit(fn () => RefundIssued::dispatch($order, $amount, self::reasonText($locked), $tag));

            return ['ok' => true, 'message' => "Credited {$currency->value} ".number_format($amount, 2)." to the customer's {$currency->value} wallet."];
        }));
    }

    /**
     * Airalo refused the claim. Nothing moves; the eSIM goes back to the
     * status Airalo reports.
     *
     * @return array{ok: bool, message: string}
     */
    public function reject(Esim $esim, Admin $admin, string $note): array
    {
        return $this->withLock($esim, function () use ($esim, $admin, $note) {
            $esim->refresh();

            if (! $esim->isRefundPending()) {
                return ['ok' => false, 'message' => 'This eSIM has no refund pending.'];
            }

            $esim->forceFill([
                'status' => EsimStatus::fromProvider($esim->provider_status, $esim->data_remaining_mb, $esim->data_total_mb, $esim->is_unlimited),
                'refund_status' => Esim::REFUND_REJECTED,
                'refund_resolved_by' => $admin->getKey(),
                'refund_resolved_at' => now(),
                'refund_resolution_note' => trim($note),
            ])->save();

            $this->audit->log(
                action: 'admin.esim_refund_rejected',
                model: $esim->order,
                metadata: ['esim_id' => $esim->getKey(), 'iccid' => $esim->iccid, 'note' => trim($note)],
            );

            return ['ok' => true, 'message' => 'Refund marked as declined. Nothing was credited.'];
        });
    }

    /**
     * The words that explain the refund to Airalo, the customer and the audit
     * trail: the note when one was written ("Other"), else the reason itself.
     */
    public static function reasonText(Esim $esim): string
    {
        return filled($esim->refund_note)
            ? (string) $esim->refund_note
            : (AiraloFulfillmentProvider::REFUND_REASONS[$esim->refund_reason] ?? 'eSIM refund');
    }

    /**
     * One refund action per eSIM at a time, across every admin.
     *
     * @param  callable(): array{ok: bool, message: string}  $work
     * @return array{ok: bool, message: string}
     */
    private function withLock(Esim $esim, callable $work): array
    {
        $lock = Cache::lock("esim-refund:{$esim->getKey()}", 120);

        if (! $lock->get()) {
            return ['ok' => false, 'message' => 'Another refund action on this eSIM is in progress.'];
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
