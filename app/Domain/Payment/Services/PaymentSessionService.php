<?php

namespace App\Domain\Payment\Services;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\OrderConfirmed;
use App\Domain\Order\Services\CouponRedemptionService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Events\PaymentSessionConfirmed;
use App\Domain\Payment\Events\PaymentSessionCreated;
use App\Domain\Payment\Events\PaymentSessionExpired;
use App\Domain\Payment\Events\PaymentSessionFailed;
use App\Domain\Payment\Providers\WalletPaymentProvider;
use App\Domain\Payment\Support\CustomerFailureCopy;
use App\Domain\PaymentInsights\Exceptions\PaymentBackoffException;
use App\Domain\PaymentInsights\Jobs\ReconcileRefusedCardsJob;
use App\Domain\PaymentInsights\Services\FailureReasonClassifier;
use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Services\WalletService;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\User;
use App\Models\WalletFunding;
use App\Support\FeatureFlag;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentSessionService
{
    /**
     * Session states a customer can still act on. A session in one of these can
     * be cancelled by the customer or closed when its checkout is replaced.
     *
     * @var list<string>
     */
    private const LIVE_STATUSES = [
        'pending',
        'awaiting_method',
        'awaiting_payment',
        'awaiting_transfer',
        'awaiting_redirect',
        'awaiting_confirmation',
        'awaiting_customer_action',
    ];

    /**
     * Create a payment session for an Order checkout attempt.
     */
    public function createForOrder(Order $order, PaymentAttempt $attempt, array $providerInitData): PaymentSession
    {
        // NO backoff check here. CheckoutService commits the order, its items,
        // the attempt, the coupon reservation and the OrderPlaced event long
        // before it reaches this call, so throwing at this point stranded the
        // order at Pending with no session at all - and the stale-session
        // sweeper keys off payment_sessions, so nothing could ever clean it up.
        // The guard now runs pre-flight in CheckoutService::placeOrder, before
        // the first durable write. See assertNotRateLimited().
        return DB::transaction(function () use ($order, $attempt, $providerInitData) {
            $expiresAt = $attempt->expires_at ?? now()->addMinutes(15);

            $session = PaymentSession::create([
                'payment_attempt_id' => $attempt->id,
                'provider' => $attempt->gateway,
                'session_type' => $this->resolveSessionType($attempt->gateway),
                'status' => 'pending',
                'client_reference' => 'SESS_'.Str::random(40),
                'provider_reference' => $providerInitData['gateway_reference'] ?? $attempt->gateway_reference,
                'provider_transaction_id' => null,
                'amount' => $attempt->amount,
                'currency' => $attempt->currency,
                'display_currency' => $order->display_currency,
                'exchange_rate_snapshot' => $attempt->exchange_rate_snapshot ?? 1.0000,
                'payment_payload' => $providerInitData,
                'checkout_context' => [
                    'order_number' => $order->order_number,
                    'items_count' => $order->items()->count(),
                ],
                'customer_email' => $order->user->email,
                'customer_ip' => request()->ip(),
                'correlation_id' => Context::get('correlation_id'),
                'device_uuid' => request()->cookie('device_uuid') ?? request()->header('X-Installation-ID'),

                'device_metadata' => [
                    'user_agent' => request()->userAgent(),
                ],
                'expires_at' => $expiresAt,
            ]);

            $initialStatus = match ($session->session_type) {
                'wallet' => $order->user->hasTransactionPin() ? 'awaiting_customer_action' : 'awaiting_payment',
                // Crypto no longer opens on 'awaiting_transfer'. No payment exists
                // until the customer picks a coin, so there is no address and no
                // amount to transfer yet — claiming otherwise is what let the old
                // pre-created BTC invoice masquerade as a live quote. The session
                // moves to 'awaiting_transfer' in PaymentSessionController once
                // chargeCrypto() returns a real address and amount.
                'crypto' => 'awaiting_method',
                default => 'awaiting_method',
            };
            $session->transitionTo($initialStatus);

            event(new PaymentSessionCreated($session));

            return $session;
        });
    }

    /**
     * Create a payment session for a Wallet Deposit funding attempt.
     */
    public function createForWalletFunding(WalletFunding $funding, PaymentAttempt $attempt, array $providerInitData): PaymentSession
    {
        // Guard runs pre-flight in WalletFundingService::initializeFunding, for
        // the same reason as createForOrder above.
        return DB::transaction(function () use ($funding, $attempt, $providerInitData) {
            $expiresAt = $attempt->expires_at ?? now()->addMinutes(15);

            $session = PaymentSession::create([
                'payment_attempt_id' => $attempt->id,
                'provider' => $attempt->gateway,
                'session_type' => $this->resolveSessionType($attempt->gateway),
                'status' => 'pending',
                'client_reference' => 'SESS_'.Str::random(40),
                'provider_reference' => $providerInitData['gateway_reference'] ?? $attempt->gateway_reference,
                'provider_transaction_id' => null,
                'amount' => $attempt->amount,
                'currency' => $attempt->currency,
                'display_currency' => $funding->currency->value,
                'exchange_rate_snapshot' => $attempt->exchange_rate_snapshot ?? 1.0000,
                'payment_payload' => $providerInitData,
                'checkout_context' => [
                    'wallet_funding_reference' => $funding->reference,
                ],
                'customer_email' => $funding->user->email,
                'customer_ip' => request()->ip(),
                'correlation_id' => Context::get('correlation_id'),
                'device_uuid' => request()->cookie('device_uuid') ?? request()->header('X-Installation-ID'),

                'device_metadata' => [
                    'user_agent' => request()->userAgent(),
                ],
                'expires_at' => $expiresAt,
            ]);

            $initialStatus = match ($session->session_type) {
                'wallet' => 'awaiting_payment',
                'crypto' => 'awaiting_transfer',
                default => 'awaiting_method',
            };
            $session->transitionTo($initialStatus);

            event(new PaymentSessionCreated($session));

            return $session;
        });
    }

    /**
     * Transition payment session to confirmed.
     */
    public function confirmSession(PaymentSession $session, ?array $payload = null): void
    {
        // Set inside the transaction, dispatched after it commits: an event
        // fired mid-transaction can email a customer about a confirmation
        // that then rolls back.
        $confirmedOrder = null;

        DB::transaction(function () use ($session, $payload, &$confirmedOrder) {
            $session = PaymentSession::where('id', $session->id)->lockForUpdate()->firstOrFail();

            if ($session->status === 'confirmed') {
                return;
            }

            if (in_array($session->status, ['awaiting_payment', 'awaiting_method', 'awaiting_transfer', 'awaiting_redirect', 'awaiting_confirmation', 'awaiting_customer_action'])) {
                $session->transitionTo('processing');
            }

            $session->transitionTo('confirmed');

            if ($payload) {
                $session->metadata = array_merge($session->metadata ?? [], ['confirmation_payload' => $payload]);
                if (isset($payload['transaction_id'])) {
                    $session->provider_transaction_id = $payload['transaction_id'];
                }
            }

            $session->save();

            // Clear the customer's cart only now that payment is confirmed. This
            // is deferred from checkout init so a failed/abandoned card or crypto
            // payment keeps the cart intact for retry. (No-op for wallet funding,
            // which has no order/cart.)
            $cartId = $session->paymentAttempt?->order?->cart_id;
            if ($cartId) {
                $cart = Cart::find($cartId);
                if ($cart) {
                    $cart->items()->delete();
                    $cart->update(['status' => 'abandoned']);
                }
            }

            // Deduct RCOIN if it was locked during checkout
            $order = $session->paymentAttempt?->order;
            if ($order) {
                $rcoinApplied = $order->metadata['rcoin_applied'] ?? 0;
                if ($rcoinApplied > 0) {
                    $walletService = app(WalletService::class);
                    $wallet = $walletService->getOrCreateWallet($order->user, Currency::RCOIN);

                    // A failed/expired session already returned the lock (this
                    // confirm is a late webhook rescuing it) - re-unlocking
                    // would throw and roll back the confirm, or eat a lock
                    // belonging to a concurrent checkout.
                    if (! ($order->metadata['rcoin_lock_released'] ?? false)) {
                        $walletService->unlockFunds($wallet, $rcoinApplied);
                    }

                    try {
                        $walletService->debit(
                            wallet: $wallet,
                            amount: $rcoinApplied,
                            category: TransactionCategory::RewardRedemption,
                            description: "RCOIN redeemed on Order #{$order->order_number}",
                            metadata: [
                                'order_id' => $order->id,
                                'rcoin_discount_usd' => $order->metadata['rcoin_discount_usd'] ?? 0,
                            ]
                        );
                    } catch (InsufficientBalanceException) {
                        // Late confirm after the customer already spent the
                        // returned RCOIN. Money for the order has arrived, so
                        // never block confirmation over reward points - flag
                        // the shortfall for admin reconciliation instead.
                        $order->metadata = array_merge($order->metadata ?? [], ['rcoin_debt' => $rcoinApplied]);
                        $order->save();
                        Log::warning('RCOIN redemption could not be debited on late confirm; flagged as rcoin_debt.', [
                            'order_id' => $order->id,
                            'rcoin_applied' => $rcoinApplied,
                        ]);
                    }
                }
            }

            // The coupon slot is only truly consumed now that the money has
            // arrived. Safe to call for orders with no coupon at all.
            if ($order) {
                app(CouponRedemptionService::class)->redeemForOrder($order);
                $confirmedOrder = $order;
            }

            event(new PaymentSessionConfirmed($session));
        });

        // This is the single choke point every payment route funnels through
        // (card webhook, bank transfer, crypto, wallet, the sweeper's late
        // rescue), which is what guarantees the customer-facing confirmation
        // fires for every path and no path can fire it twice: the early
        // return above leaves $confirmedOrder null on replay, and the
        // communication layer's idempotency key dedupes a second session
        // confirming the same order. Wallet funding has no order and sends
        // its own receipt.
        if ($confirmedOrder) {
            OrderConfirmed::dispatch($confirmedOrder);
        }

        // A Flutterwave popup may have refused other cards under this same
        // reference before one went through. The gateway never pushes those
        // refusals, so they are pulled on a queue once the money is safe.
        // afterCommit: a caller wrapping this in its own transaction that
        // then rolls back must not leave a job alerting about a payment that
        // never happened.
        if ($session->provider === 'flutterwave') {
            ReconcileRefusedCardsJob::dispatch($session->id)->afterCommit();
        }
    }

    /**
     * Transition payment session to failed.
     */
    public function failSession(PaymentSession $session, string $reason, ?array $payload = null): void
    {
        DB::transaction(function () use ($session, $reason, $payload) {
            $session = PaymentSession::where('id', $session->id)->lockForUpdate()->firstOrFail();

            if (in_array($session->status, ['confirmed', 'failed', 'expired', 'cancelled'])) {
                return;
            }

            // How far the customer actually got, captured BEFORE the state
            // machine moves. Everything below routes through 'processing' on
            // its way to 'failed', so by the time the outcome is recorded the
            // session can no longer say whether the customer picked a payment
            // method or abandoned at the very first screen. Every terminal
            // outcome was consequently filed as a 'processing' failure, which
            // made the stage column, and the dashboard panel built on it,
            // report fiction.
            $statusAtFailure = $session->status;

            if (in_array($session->status, ['awaiting_payment', 'awaiting_method', 'awaiting_transfer', 'awaiting_redirect', 'awaiting_confirmation', 'awaiting_customer_action'])) {
                $session->transitionTo('processing');
            }

            $session->transitionTo('failed');

            // Full audit trail goes to metadata — never exposed to the client.
            // `failure_details` is the structured gateway verdict (GatewayFailure
            // block): provider_code + verbatim provider_message. It is what the
            // outcome classifier reads, so telemetry grades the gateway's truth
            // rather than the polite customer copy in $reason.
            $session->metadata = array_merge($session->metadata ?? [], [
                'failure_reason' => $reason,
                'failure_payload' => $payload,
                'failure_details' => $payload['failure'] ?? null,
                'status_at_failure' => $statusAtFailure,
            ]);

            // Customer-facing reason ALSO goes on payment_payload — that's the
            // field PaymentSessionResource exposes and the checkout wizard reads
            // (`payment_payload?.failure_reason`). Without this, the customer
            // always sees the generic "Transaction could not be completed."
            // fallback regardless of the actual gateway error.
            //
            // Beside it, the classifier's verdict as a customer-safe code. The
            // reason is a sentence in whatever language the code path that
            // failed the session was running in; the code lets every page
            // show the customer why in their own language, and never names
            // what a fraud screen detected (CustomerFailureCopy).
            $classification = app(FailureReasonClassifier::class)->classify(
                $session->provider ?? 'unknown',
                $reason,
                $statusAtFailure,
                is_array($payload['failure'] ?? null) ? $payload['failure'] : null,
            );

            $session->payment_payload = array_merge($session->payment_payload ?? [], [
                'failure_reason' => $reason,
                'failure_code' => CustomerFailureCopy::customerCode($classification['code']),
            ]);

            $session->save();

            // Unlock RCOIN if it was locked during checkout. The release is
            // recorded on the order so a late confirm (failed -> confirmed)
            // knows the lock is already gone.
            $order = $session->paymentAttempt?->order;
            $this->releaseRcoinLock($order);

            // Hand the coupon slot back so the next customer can use it. A late
            // confirm on this same session reclaims it (see redeemForOrder).
            if ($order) {
                app(CouponRedemptionService::class)->releaseForOrder($order, $reason);
            }

            event(new PaymentSessionFailed($session, $reason));
        });
    }

    /**
     * The customer walked away from a payment they had not made.
     *
     * One routine for every surface that cancels a session, so the pieces hanging
     * off it are always put back: the attempt is closed, a wallet reservation is
     * released, locked Rcoin is returned, the coupon slot is handed back, and a
     * wallet top-up is marked cancelled rather than left Pending for the hourly
     * reconciler to ask the gateway about and then fail two hours later with a
     * "your top-up failed" email for something the customer chose to stop.
     *
     * Cancelled is final, as it always has been for sessions: a card decline the
     * gateway recorded is caught by the controller's reconcile step before this
     * runs, and money that genuinely lands later is an operator's case.
     *
     * @throws \DomainException when the session is already past cancelling
     */
    public function cancelSession(PaymentSession $session, string $reason = 'customer_cancelled'): void
    {
        DB::transaction(function () use ($session, $reason) {
            $session = PaymentSession::where('id', $session->id)->lockForUpdate()->firstOrFail();

            if (! in_array($session->status, self::LIVE_STATUSES, true)) {
                throw new \DomainException("Cannot cancel payment session that is already {$session->status}.");
            }

            // How far the customer got, captured before the transition wipes it,
            // so the outcome records the real stage rather than the terminal one.
            $session->metadata = array_merge($session->metadata ?? [], [
                'status_at_failure' => $session->status,
            ]);

            $session->transitionTo('cancelled');

            $attempt = $session->paymentAttempt;
            $order = $attempt?->order;

            if ($attempt && ! $this->releaseReservedWalletFunds($attempt) && $attempt->payment_status !== PaymentStatus::Paid) {
                $attempt->update([
                    'payment_status' => PaymentStatus::Failed,
                    'failed_at' => now(),
                ]);
            }

            $this->releaseRcoinLock($order);

            if ($order) {
                app(CouponRedemptionService::class)->releaseForOrder($order, $reason);

                // The order goes with its payment. It used to stay Pending for
                // good: the sweeper looks for orders whose attempt is still open,
                // and this one's attempt was just closed, so nothing ever came
                // back for it. Only an order still waiting for money is touched;
                // anything paid or in flight is left to the verify chain.
                if ($order->order_status === OrderStatus::Pending
                    && in_array($order->payment_status, [PaymentStatus::Unpaid, PaymentStatus::Pending], true)) {
                    $order->update(['order_status' => OrderStatus::Cancelled]);
                }
            }

            if ($attempt && $attempt->payable_type === WalletFunding::class) {
                $funding = $attempt->payable;

                if ($funding instanceof WalletFunding
                    && in_array($funding->status, [FundingStatus::Pending, FundingStatus::Processing], true)) {
                    $funding->update([
                        'status' => FundingStatus::Cancelled,
                        'processed_at' => now(),
                    ]);
                }
            }
        });
    }

    /**
     * Close a session whose order has been replaced by a newer checkout on the
     * same cart.
     *
     * The order is cancelled by the checkout that replaces it; this closes the
     * payment behind it. Left live, that payment could still be made, and
     * paying it moved a cancelled order back to processing and fulfilled it
     * beside the new one. Its Rcoin lock also stayed held, so the very retry
     * that replaced it could not re-apply the customer's points.
     *
     * The session ends as EXPIRED, not cancelled, and deliberately so: a hosted
     * page or crypto address already issued for it cannot be withdrawn, and
     * expired is the one terminal state a late payment can still confirm from.
     * Money that lands then follows the existing late-arrival path (settled,
     * ops told). No outcome event fires: a replaced checkout is housekeeping,
     * not a payment that failed, and it must not count against the customer in
     * the payment rate limiter or the insights dashboard. The marker written to
     * metadata is what the limiter reads to leave it out.
     */
    public function supersedeSession(PaymentSession $session, string $replacementOrderNumber): void
    {
        DB::transaction(function () use ($session, $replacementOrderNumber) {
            $session = PaymentSession::where('id', $session->id)->lockForUpdate()->firstOrFail();

            if (! in_array($session->status, self::LIVE_STATUSES, true)) {
                return;
            }

            $session->metadata = array_merge($session->metadata ?? [], [
                'status_at_failure' => $session->status,
                'superseded_by' => $replacementOrderNumber,
                'superseded_at' => now()->toIso8601String(),
            ]);

            $session->transitionTo('expired');

            $attempt = $session->paymentAttempt;
            $order = $attempt?->order;

            // The sweeper's own rule for an abandoned attempt: Unpaid or Pending
            // becomes Expired, anything further along (a charge in flight, coins
            // partly received) is left for the verify chain to finish.
            if ($attempt && ! $this->releaseReservedWalletFunds($attempt)
                && in_array($attempt->payment_status, [PaymentStatus::Unpaid, PaymentStatus::Pending], true)) {
                $attempt->update(['payment_status' => PaymentStatus::Expired]);
            }

            $this->releaseRcoinLock($order);

            if ($order) {
                app(CouponRedemptionService::class)->releaseForOrder($order, 'superseded_checkout');
            }

            Log::info('payment_session.superseded', [
                'session_id' => $session->id,
                'order_number' => $order?->order_number,
                'replaced_by' => $replacementOrderNumber,
                'status_at_supersede' => $session->metadata['status_at_failure'],
            ]);
        });
    }

    /**
     * Transition payment session to expired.
     */
    public function expireSession(PaymentSession $session): void
    {
        DB::transaction(function () use ($session) {
            $session = PaymentSession::where('id', $session->id)->lockForUpdate()->firstOrFail();

            if (! in_array($session->status, ['awaiting_payment', 'awaiting_method', 'awaiting_transfer', 'awaiting_redirect', 'awaiting_confirmation', 'awaiting_customer_action'])) {
                return;
            }

            // Same reason as failSession: record how far the customer got
            // before the state machine erases it.
            $session->metadata = array_merge($session->metadata ?? [], [
                'status_at_failure' => $session->status,
            ]);

            $session->transitionTo('expired');

            // Unlock RCOIN if it was locked during checkout. Recorded on the
            // order so a late confirm (expired -> confirmed) skips re-unlock.
            $order = $session->paymentAttempt?->order;
            $this->releaseRcoinLock($order);

            if ($order) {
                app(CouponRedemptionService::class)->releaseForOrder($order, 'session_expired');
            }

            event(new PaymentSessionExpired($session));
        });
    }

    /**
     * Return the Rcoin a checkout locked against this order, once. The release
     * is recorded on the order so a late confirm knows the lock is already gone
     * and neither re-unlocks nor eats a lock belonging to a newer checkout.
     */
    private function releaseRcoinLock(?Order $order): void
    {
        if ($order === null) {
            return;
        }

        $rcoinApplied = $order->metadata['rcoin_applied'] ?? 0;

        if ($rcoinApplied > 0 && ! ($order->metadata['rcoin_lock_released'] ?? false)) {
            $walletService = app(WalletService::class);
            $wallet = $walletService->getOrCreateWallet($order->user, Currency::RCOIN);
            $walletService->unlockFunds($wallet, $rcoinApplied);
            $order->metadata = array_merge($order->metadata ?? [], ['rcoin_lock_released' => true]);
            $order->save();
        }
    }

    /**
     * Give back wallet money an attempt is still holding.
     *
     * A wallet payment reserves the amount when the PIN token is accepted and
     * only debits it when the order is fulfilled. If the session is closed in
     * between (the confirm step failed and the customer cancelled, or a newer
     * checkout replaced the order) the hold would otherwise outlive it. The
     * provider's release also closes the attempt, so the caller skips its own
     * terminal stamp when this returns true.
     */
    private function releaseReservedWalletFunds(PaymentAttempt $attempt): bool
    {
        if ($attempt->gateway !== 'wallet' || $attempt->payment_status !== PaymentStatus::Reserved) {
            return false;
        }

        app(WalletPaymentProvider::class)->releaseFunds($attempt);

        return true;
    }

    /**
     * Resolve type of session from gateway name.
     */
    private function resolveSessionType(string $gateway): string
    {
        return match ($gateway) {
            'wallet' => 'wallet',
            'nowpayments', 'crypto' => 'crypto',
            'flutterwave' => 'card',
            default => 'card',
        };
    }

    /**
     * Refuse a payment attempt from a customer who is currently rate limited.
     *
     * MUST be called pre-flight, before the calling flow makes its first
     * durable write. A backoff check placed after a commit cannot undo what it
     * has already created, so it leaves an unpayable order (or funding) behind
     * and manufactures exactly the orphan records the sweeper cannot see. That
     * is not a tuning problem, it is a defect by construction, so the guard
     * lives at the entry point of each flow and nowhere else.
     *
     * @throws PaymentBackoffException
     */
    public function assertNotRateLimited(?User $user, string $productLine): void
    {
        // Kill switch: disabling progressive backoff removes the rate limit
        // without touching outcome recording or recovery. Note the manager is
        // still consulted when only enforcement is off, because shadow mode's
        // whole purpose is to keep measuring while letting customers through.
        if (FeatureFlag::off('progressive_backoff')) {
            return;
        }

        // Fail open. This guard sits in front of every checkout and every
        // top-up, so a fault inside the limiter itself (a query the database
        // rejects, an unreachable cache) must never become a payment outage.
        // The fault is reported so it is fixed; the customer pays, and the
        // gateway's own fraud screen still stands in front of the card.
        try {
            /** @var PaymentBackoffManager $backoffManager */
            $backoffManager = app(PaymentBackoffManager::class);
            $status = $backoffManager->getBackoffStatus(
                $user,
                $productLine,
                request()->ip(),
                $this->requestDeviceUuid()
            );
        } catch (\Throwable $e) {
            report($e);
            Log::error('payment_backoff.guard_failed_open', [
                'user_id' => $user?->getKey(),
                'product_line' => $productLine,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($status['is_rate_limited']) {
            throw new PaymentBackoffException($status['wait_seconds'], locked: (bool) ($status['locked'] ?? false));
        }
    }

    /**
     * The pause or lock this customer is already serving, for a page that is
     * about to render a pay button.
     *
     * Lets checkout and the wallet top-up start the countdown the moment the
     * page loads, instead of offering Continue to payment after a refresh and
     * only then being refused. Read-only: it records nothing, so rendering a
     * page never counts as an attempt or writes an event.
     *
     * Null when nothing is being served, when the limiter is off or only
     * observing, for guests, and on any fault: a page must always render, and
     * the pre-flight guard still stands behind the button either way.
     *
     * @return array{wait_seconds: int, locked: bool, message: string}|null
     */
    public function currentHold(?User $user, string $productLine): ?array
    {
        if ($user === null || FeatureFlag::off('progressive_backoff')) {
            return null;
        }

        // The dashboard renders the wallet sheet more than once; ask once.
        $memoKey = 'payment_backoff.current_hold.'.$user->getKey().'.'.$productLine;
        if (request()->attributes->has($memoKey)) {
            return request()->attributes->get($memoKey);
        }

        try {
            $status = app(PaymentBackoffManager::class)->peek(
                $user,
                $productLine,
                request()->ip(),
                $this->requestDeviceUuid(),
            );

            $hold = $status['is_rate_limited']
                ? [
                    'wait_seconds' => (int) $status['wait_seconds'],
                    'locked' => (bool) $status['locked'],
                    'message' => (new PaymentBackoffException((int) $status['wait_seconds'], locked: (bool) $status['locked']))->customerMessage(),
                ]
                : null;
        } catch (\Throwable $e) {
            report($e);
            $hold = null;
        }

        request()->attributes->set($memoKey, $hold);

        return $hold;
    }

    /**
     * The device fingerprint this request carries: the browser's persistent
     * cookie, or the installation id a native app sends.
     */
    private function requestDeviceUuid(): ?string
    {
        return request()->cookie('device_uuid') ?? request()->header('X-Installation-ID');
    }
}
