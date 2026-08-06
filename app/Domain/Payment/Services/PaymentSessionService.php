<?php

namespace App\Domain\Payment\Services;

use App\Domain\Order\Services\CouponRedemptionService;
use App\Domain\Payment\Events\PaymentSessionConfirmed;
use App\Domain\Payment\Events\PaymentSessionCreated;
use App\Domain\Payment\Events\PaymentSessionExpired;
use App\Domain\Payment\Events\PaymentSessionFailed;
use App\Domain\PaymentInsights\Exceptions\PaymentBackoffException;
use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Domain\Shared\Enums\Currency;
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
        DB::transaction(function () use ($session, $payload) {
            $session = PaymentSession::where('id', $session->id)->lockForUpdate()->firstOrFail();

            if ($session->status === 'confirmed') {
                return;
            }

            if (in_array($session->status, ['awaiting_payment', 'awaiting_method', 'awaiting_transfer', 'awaiting_confirmation', 'awaiting_customer_action'])) {
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
            }

            event(new PaymentSessionConfirmed($session));
        });
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

            if (in_array($session->status, ['awaiting_payment', 'awaiting_method', 'awaiting_transfer', 'awaiting_confirmation', 'awaiting_customer_action'])) {
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
            $session->payment_payload = array_merge($session->payment_payload ?? [], [
                'failure_reason' => $reason,
            ]);

            $session->save();

            // Unlock RCOIN if it was locked during checkout. The release is
            // recorded on the order so a late confirm (failed -> confirmed)
            // knows the lock is already gone.
            $order = $session->paymentAttempt?->order;
            if ($order) {
                $rcoinApplied = $order->metadata['rcoin_applied'] ?? 0;
                if ($rcoinApplied > 0 && ! ($order->metadata['rcoin_lock_released'] ?? false)) {
                    $walletService = app(WalletService::class);
                    $wallet = $walletService->getOrCreateWallet($order->user, Currency::RCOIN);
                    $walletService->unlockFunds($wallet, $rcoinApplied);
                    $order->metadata = array_merge($order->metadata ?? [], ['rcoin_lock_released' => true]);
                    $order->save();
                }
            }

            // Hand the coupon slot back so the next customer can use it. A late
            // confirm on this same session reclaims it (see redeemForOrder).
            if ($order) {
                app(CouponRedemptionService::class)->releaseForOrder($order, $reason);
            }

            event(new PaymentSessionFailed($session, $reason));
        });
    }

    /**
     * Transition payment session to expired.
     */
    public function expireSession(PaymentSession $session): void
    {
        DB::transaction(function () use ($session) {
            $session = PaymentSession::where('id', $session->id)->lockForUpdate()->firstOrFail();

            if (! in_array($session->status, ['awaiting_payment', 'awaiting_method', 'awaiting_transfer', 'awaiting_confirmation', 'awaiting_customer_action'])) {
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
            if ($order) {
                $rcoinApplied = $order->metadata['rcoin_applied'] ?? 0;
                if ($rcoinApplied > 0 && ! ($order->metadata['rcoin_lock_released'] ?? false)) {
                    $walletService = app(WalletService::class);
                    $wallet = $walletService->getOrCreateWallet($order->user, Currency::RCOIN);
                    $walletService->unlockFunds($wallet, $rcoinApplied);
                    $order->metadata = array_merge($order->metadata ?? [], ['rcoin_lock_released' => true]);
                    $order->save();
                }
            }

            if ($order) {
                app(CouponRedemptionService::class)->releaseForOrder($order, 'session_expired');
            }

            event(new PaymentSessionExpired($session));
        });
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

        /** @var PaymentBackoffManager $backoffManager */
        $backoffManager = app(PaymentBackoffManager::class);
        $status = $backoffManager->getBackoffStatus(
            $user,
            $productLine,
            request()->ip(),
            request()->cookie('device_uuid') ?? request()->header('X-Installation-ID')
        );

        if ($status['is_rate_limited']) {
            throw new PaymentBackoffException($status['wait_seconds']);
        }
    }
}
