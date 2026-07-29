<?php

namespace App\Domain\Payment\Jobs;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Shared\Enums\FundingStatus;
use App\Jobs\VerifyPaymentJob;
use App\Models\PaymentSession;
use App\Models\WalletFunding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sweep PaymentSessions past their expires_at and still pre-terminal, transitioning
 * them to 'expired' and cascading the cancellation to the linked Order (-> Cancelled)
 * or WalletFunding (-> Failed).
 *
 * Without this, a customer who closes the tab mid-payment (or fails a card charge in
 * a way that doesn't fail-the-session synchronously) leaves an "RSR-..." order sitting
 * in Pending on their dashboard forever, polluting Recent Orders and the order count.
 *
 * Scheduled in routes/console.php — runs every 5 minutes. Can also be triggered on
 * demand with `php artisan payments:expire-stale` (see the same file).
 */
class ExpireStalePaymentSessionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Non-terminal session statuses we'll consider for expiry. Matches the set
     * `PaymentSessionService::expireSession()` itself will act on — anything
     * outside this list is already terminal (confirmed / failed / cancelled).
     */
    private const ACTIVE_STATUSES = [
        'awaiting_payment',
        'awaiting_method',
        'awaiting_transfer',
        'awaiting_confirmation',
        'awaiting_customer_action',
    ];

    /**
     * Floor on how long a gateway-backed session lives before this sweep may
     * touch it. Sessions are minted with a 15-minute TTL, but the bank-transfer
     * screens promise "this account expires in 30 minutes" and the virtual
     * account really does stay open that long. Sweeping on the shorter TTL marks
     * a transfer that lands at minute 20 as Failed while the customer's money is
     * already on its way to us. Wallet-gateway sessions move no external money,
     * so they keep their own TTL.
     */
    private const MINIMUM_GATEWAY_LIFETIME_MINUTES = 30;

    public function handle(
        PaymentSessionService $paymentSessionService,
        OrderService $orderService
    ): void {
        $stale = PaymentSession::query()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->with('paymentAttempt.order', 'paymentAttempt.payable')
            ->get();

        if ($stale->isEmpty()) {
            return;
        }

        $expired = 0;

        foreach ($stale as $session) {
            try {
                $attempt = $session->paymentAttempt;

                // 0. PROMISED-WINDOW FLOOR. Never terminate a gateway-backed
                //    session before the window the customer was told they had -
                //    a transfer sent at minute 20 against a 30-minute virtual
                //    account must not find its funding already marked Failed.
                if ($attempt
                    && $attempt->gateway !== 'wallet'
                    && $session->created_at
                    && $session->created_at->gt(now()->subMinutes(self::MINIMUM_GATEWAY_LIFETIME_MINUTES))) {
                    continue;
                }

                // 1. LAST-CHANCE GATEWAY VERIFICATION. A customer can confirm a
                //    payment (card / mobile money / bank transfer) whose webhook
                //    + return both fail to land - the order stays Unpaid, or the
                //    funding stays Pending, and either would be wrongly killed
                //    here while the gateway holds their money. Before treating
                //    the session as abandoned, ask the gateway one more time; if
                //    it's actually paid, VerifyPaymentJob confirms the order and
                //    dispatches fulfillment (or credits the wallet funding), and
                //    we skip the expiry.
                if ($attempt
                    && ($attempt->order || $attempt->payable_type === WalletFunding::class)
                    && $attempt->gateway !== 'wallet'
                    && ! in_array($attempt->payment_status, [PaymentStatus::Paid, PaymentStatus::Failed], true)) {
                    try {
                        dispatch_sync(new VerifyPaymentJob($attempt));
                    } catch (\Throwable $e) {
                        Log::warning('ExpireStalePaymentSessionsJob: gateway verify failed for attempt '.$attempt->id, [
                            'message' => $e->getMessage(),
                        ]);
                    }

                    $attempt->refresh();
                    if ($attempt->payment_status === PaymentStatus::Paid) {
                        Log::info('ExpireStalePaymentSessionsJob: recovered a real payment on session '.$session->id.' - confirmed instead of cancelled.');

                        continue; // payment was genuine: confirmed + fulfilling
                    }
                }

                // 2. Expire the session itself - the existing state-machine method,
                //    idempotent if status changed between query and lock.
                $paymentSessionService->expireSession($session);

                // 3. Cascade to the linked Order or WalletFunding so the customer's
                //    dashboard reflects the dead checkout instead of showing Pending
                //    in perpetuity.
                if (! $attempt) {
                    $expired++;

                    continue;
                }

                // The admin Transactions page lists PaymentAttempt rows, so the
                // attempt must reach a terminal state too - otherwise an
                // abandoned checkout shows "pending" in admin forever even
                // though the order was cancelled and the session expired.
                if (in_array($attempt->payment_status, [PaymentStatus::Unpaid, PaymentStatus::Pending, PaymentStatus::Processing], true)) {
                    $attempt->update(['payment_status' => PaymentStatus::Expired]);
                }

                if ($order = $attempt->order) {
                    // Skip if a late webhook already moved the order past Unpaid.
                    if ($order->payment_status === PaymentStatus::Unpaid) {
                        $orderService->transitionPaymentStatus($order, PaymentStatus::Failed, [
                            'reason' => 'Payment session expired',
                            'expired_at' => $session->expires_at?->toIso8601String(),
                        ]);
                        $order->update(['order_status' => OrderStatus::Cancelled]);
                    }
                } elseif ($attempt->payable_type === WalletFunding::class) {
                    $funding = $attempt->payable;
                    if ($funding && $funding->status !== FundingStatus::Completed) {
                        $funding->update(['status' => FundingStatus::Failed]);
                    }
                }

                $expired++;
            } catch (\Throwable $e) {
                // Don't let one bad session abort the whole sweep.
                Log::error('ExpireStalePaymentSessionsJob: failed for session '.$session->id, [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($expired > 0) {
            Log::info("ExpireStalePaymentSessionsJob: expired {$expired} stale session(s).");
        }
    }
}
