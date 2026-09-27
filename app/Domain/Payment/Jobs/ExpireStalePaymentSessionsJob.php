<?php

namespace App\Domain\Payment\Jobs;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Payment\Support\GatewayRateLimit;
use App\Domain\Payment\Support\NowPaymentsStatus;
use App\Domain\PaymentInsights\Services\RefusedCardRecorder;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Jobs\VerifyPaymentJob;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\WalletFunding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Sweep PaymentSessions past their expires_at whose Order / WalletFunding never
 * reached a terminal state, transitioning the session to 'expired' where needed
 * and cascading to the linked Order (-> Cancelled) or WalletFunding (-> Failed).
 *
 * Two populations are swept, and the second one is the subtle one:
 *
 *  1. Sessions still pre-terminal past their TTL — the customer closed the tab
 *     and nothing else ever spoke for them.
 *
 *  2. Sessions ALREADY terminal (failed / cancelled) whose order or attempt is
 *     still open. `PaymentSessionService::failSession()` deliberately does not
 *     cascade, so every caller that fails a session without cascading itself
 *     (the browser telemetry endpoint on popup-close, and
 *     `reconcile:orphaned-sessions`) used to strand the parent order in Pending
 *     forever: the session was no longer in ACTIVE_STATUSES, so this sweep
 *     skipped it, and nothing else was watching. Confirmed against production
 *     orders sitting Pending for hours behind a session that failed in seconds.
 *
 * Handling both here rather than cascading inside failSession() is deliberate.
 * A customer can close the popup *after* authorising, so an immediate cascade
 * would cancel orders whose money is already in flight. Routing every case
 * through this sweep keeps the two guards below in play — the promised-window
 * floor and the last-chance gateway verification — which exist precisely to
 * stop a paid order being cancelled.
 *
 * Scheduled in routes/console.php — runs every 5 minutes. Can also be triggered on
 * demand with `php artisan payments:expire-stale` (see the same file).
 */
class ExpireStalePaymentSessionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('payments');
    }

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
        // The backup gateways' hosted-page state. Missing from this list, a
        // customer who never came back from Paystack/Kora's page left a
        // session no sweep could see: RCOIN stayed locked and the coupon slot
        // stayed held forever.
        'awaiting_redirect',
    ];

    /**
     * Terminal session states that still leave work to do upstream. A session
     * here is finished, but its order / attempt may never have been told.
     * 'confirmed' is deliberately absent: money arrived, nothing to cancel.
     */
    private const ORPHANING_STATUSES = [
        'failed',
        'cancelled',
        'expired',
    ];

    /**
     * Order / attempt states that mean "still waiting for money". Anything
     * outside this set has already been resolved and must not be touched.
     */
    private const OPEN_PAYMENT_STATUSES = [
        'unpaid',
        'pending',
        'processing',
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

    /**
     * How far a session is pushed out each time the gateway reports the
     * customer's coins still confirming. Re-checked every pass, so a payment
     * that finally fails at the gateway is caught within one interval.
     */
    private const IN_FLIGHT_EXTENSION_MINUTES = 30;

    /** The longest an in-flight crypto session is kept open by extension. */
    private const IN_FLIGHT_MAX_HOURS = 24;

    /**
     * Gateway calls this sweep may make in one run. Each last-chance
     * verification is one or two calls, made back to back; a backlog of a
     * few hundred stale sessions used to become a burst the gateway answers
     * with 429s, and every customer paying at that moment shared the
     * penalty. Sessions past the budget wait for the next run, five minutes
     * on, oldest first.
     */
    public const MAX_GATEWAY_CHECKS_PER_RUN = 40;

    /** A breath between gateway checks, so the run is a trickle rather than a burst. */
    private const GATEWAY_CHECK_SPACING_MS = 150;

    public function handle(
        PaymentSessionService $paymentSessionService,
        OrderService $orderService
    ): void {
        $stale = PaymentSession::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->where(function ($query) {
                $query
                    // Population 1: still live, nobody spoke for them.
                    ->whereIn('status', self::ACTIVE_STATUSES)
                    // Population 2: already dead, but their order never heard.
                    ->orWhere(function ($orphaned) {
                        $orphaned
                            ->whereIn('status', self::ORPHANING_STATUSES)
                            ->whereHas('paymentAttempt', function ($attempt) {
                                $attempt->whereIn('payment_status', self::OPEN_PAYMENT_STATUSES);
                            });
                    });
            })
            ->with('paymentAttempt.order', 'paymentAttempt.payable')
            ->orderBy('expires_at')
            ->get();

        if ($stale->isEmpty()) {
            return;
        }

        $expired = 0;
        $gatewayChecks = 0;
        $deferred = 0;

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
                    // Paid attempts go through too: an attempt stamped Paid by
                    // the hosted return leg whose order never confirmed is
                    // exactly the stranded settlement VerifyPaymentJob now
                    // finishes. Only Failed is final here.
                    && $attempt->payment_status !== PaymentStatus::Failed) {
                    // The gateway budget. A gateway that has asked us to slow
                    // down, or a run that has spent its calls, leaves the
                    // session exactly as it is for the next pass: a session
                    // must never be expired without its last-chance check.
                    if (GatewayRateLimit::isCoolingDown($attempt->gateway) || $gatewayChecks >= self::MAX_GATEWAY_CHECKS_PER_RUN) {
                        $deferred++;

                        continue;
                    }

                    if ($gatewayChecks > 0) {
                        Sleep::usleep(self::GATEWAY_CHECK_SPACING_MS * 1000);
                    }
                    $gatewayChecks++;

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

                    // 1b. MONEY IN FLIGHT. A crypto payment the gateway reports as
                    //     `confirming` (coins seen on-chain, confirmations still
                    //     accruing), or that already has coins against it, or that
                    //     settled short, is NOT abandoned. Bitcoin routinely needs
                    //     longer than this sweep's floor to confirm; failing the
                    //     funding here told the customer their top-up died while
                    //     their coins were on the way, and then every recovery path
                    //     refused the money when it landed. Push the window out and
                    //     look again next pass; the gateway decides, not the clock.
                    if ($this->cryptoMoneyInFlight($attempt, $session)) {
                        $session->expires_at = now()->addMinutes(self::IN_FLIGHT_EXTENSION_MINUTES);
                        $session->save();

                        Log::info('ExpireStalePaymentSessionsJob: crypto payment still in flight on session '.$session->id.' - window extended, not expired.', [
                            'attempt_id' => $attempt->id,
                            'gateway_status' => data_get($attempt->metadata, 'crypto.status'),
                            'actually_paid' => $attempt->actually_paid,
                        ]);

                        continue;
                    }
                }

                // 2. Expire the session itself - the existing state-machine method,
                //    idempotent if status changed between query and lock. Skipped
                //    for sessions that are already terminal: those are here only
                //    because their order never followed them, and re-expiring a
                //    failed session would rewrite the reason the customer was
                //    given for a failure that already has one.
                if (in_array($session->status, self::ACTIVE_STATUSES, true)) {
                    // 2a. A popup the customer simply left open after a refused
                    //     card is a decline, not an abandonment. Ask the gateway
                    //     before filing it as expired; a refused card fails the
                    //     session with the verdict instead.
                    $declines = $attempt ? app(RefusedCardRecorder::class)->reconcile($session) : collect();

                    if ($declines->isNotEmpty()) {
                        $failure = app(RefusedCardRecorder::class)->failureFor($session, $declines);
                        $paymentSessionService->failSession($session, $failure['reason'], $failure['payload'] + ['client_reason' => 'session_expired']);

                        // The attempt was refused, not abandoned: the admin
                        // Transactions page should say Failed, not Expired.
                        if ($attempt && in_array($attempt->payment_status, [PaymentStatus::Unpaid, PaymentStatus::Pending, PaymentStatus::Processing], true)) {
                            $attempt->update(['payment_status' => PaymentStatus::Failed, 'failed_at' => now()]);
                        }
                    } else {
                        $paymentSessionService->expireSession($session);
                    }
                }

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
                    // Skip if a late webhook already resolved the order. Pending
                    // counts as open alongside Unpaid: orders stranded behind a
                    // telemetry-failed session sit in either state, and leaving
                    // Pending out was half the reason they never got cleaned up.
                    if (in_array($order->payment_status, [PaymentStatus::Unpaid, PaymentStatus::Pending], true)) {
                        $orderService->transitionPaymentStatus($order, PaymentStatus::Failed, [
                            'reason' => 'Payment session expired',
                            'expired_at' => $session->expires_at?->toIso8601String(),
                        ]);
                        $order->update(['order_status' => OrderStatus::Cancelled]);
                    }
                } elseif ($attempt->payable_type === WalletFunding::class) {
                    $funding = $attempt->payable;

                    // Coins arrived (fully, still settling, or short): the
                    // window may close but the funding is NOT failed. Failing it
                    // told the customer their deposit died with their money at
                    // the gateway, and made the later credit fight the Failed
                    // state. It stays Pending for the reconcile / top-up path.
                    if ($funding && $this->cryptoMoneyHeld($attempt)) {
                        Log::info('ExpireStalePaymentSessionsJob: session '.$session->id.' closed but funding '.$funding->reference.' kept open - coins are held at the gateway.', [
                            'attempt_id' => $attempt->id,
                            'payment_status' => $attempt->payment_status?->value,
                            'actually_paid' => $attempt->actually_paid,
                        ]);
                    } elseif ($funding && ! in_array($funding->status, [FundingStatus::Completed, FundingStatus::Failed, FundingStatus::Cancelled], true)) {
                        // Through the service, not a bare update: the direct
                        // status write skipped the FundingFailed event, so the
                        // customer never heard their top-up died, and the
                        // attempt kept no failure payload. failFunding's own
                        // session-fail no-ops safely on the session this sweep
                        // already expired.
                        app(WalletFundingService::class)->failFunding($funding, 'session_expired');
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

        if ($deferred > 0) {
            Log::info("ExpireStalePaymentSessionsJob: left {$deferred} session(s) for the next run (gateway budget or cool-down).");
        }
    }

    /**
     * Whether a crypto attempt has customer money against it that the clock
     * must not overrule. Read straight after the last-chance verification,
     * which refreshes the gateway status onto the attempt.
     *
     * `waiting` with nothing paid is the one state that IS abandonment: no
     * deposit has been seen, so the window may close.
     */
    private function cryptoMoneyInFlight(PaymentAttempt $attempt, PaymentSession $session): bool
    {
        if (! $this->cryptoMoneyHeld($attempt)
            && NowPaymentsStatus::normalize(data_get($attempt->metadata, 'crypto.status')) !== 'confirming') {
            return false;
        }

        // Bounded: no chain takes a day to confirm. Past this the session is
        // allowed to close (the customer's screen goes stale), but see the
        // cascade guard - a funding with coins against it is still never
        // failed, and the reopened verify path credits it if the gateway
        // finishes later.
        return $session->created_at !== null
            && $session->created_at->gt(now()->subHours(self::IN_FLIGHT_MAX_HOURS));
    }

    /**
     * Coins have physically arrived against this crypto attempt, in full or in
     * part. Such a funding must never be marked Failed by the clock: the money
     * is real, and it is either credited by the reopened verify path or resolved
     * by an operator, never written off as abandoned.
     */
    private function cryptoMoneyHeld(PaymentAttempt $attempt): bool
    {
        if (! in_array($attempt->gateway, ['nowpayments', 'crypto'], true)) {
            return false;
        }

        return $attempt->payment_status === PaymentStatus::PartiallyPaid
            || (float) $attempt->actually_paid > 0;
    }
}
