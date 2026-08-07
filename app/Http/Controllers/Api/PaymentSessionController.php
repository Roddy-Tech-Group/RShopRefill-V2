<?php

namespace App\Http\Controllers\Api;

use App\Domain\Order\Events\PaymentConfirmed;
use App\Domain\Order\Services\CouponRedemptionService;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\GatewayDisabledException;
use App\Domain\Payment\Providers\NowPaymentsProvider;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\WalletOnHoldException;
use App\Domain\Wallet\Services\CurrencyRateService;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentSessionResource;
use App\Jobs\FulfillOrderItemJob;
use App\Models\CurrencyRate;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\WalletFunding;
use App\Support\FeatureFlag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentSessionController extends Controller
{
    /**
     * Authorize access to the payment session for the current user or guest.
     */
    private function authorizeSessionAccess(PaymentSession $session): void
    {
        $attempt = $session->paymentAttempt;
        if (! $attempt) {
            abort(403, 'Unauthorized access to payment session.');
        }

        if (auth()->check()) {
            $userId = auth()->id();
            if ($attempt->user_id !== $userId) {
                abort(403, 'Unauthorized access to payment session.');
            }
        } else {
            $order = $attempt->order;
            if (! $order) {
                abort(403, 'Unauthorized access to payment session.');
            }

            $guestOrders = session('guest_order_numbers', []);
            if (! in_array($order->order_number, $guestOrders)) {
                abort(403, 'Unauthorized access to payment session.');
            }
        }
    }

    /**
     * Display the specified payment session details.
     */
    public function show(string $id, Request $request)
    {
        $session = PaymentSession::with('paymentAttempt.order')->findOrFail($id);

        $this->authorizeSessionAccess($session);

        return new PaymentSessionResource($session);
    }

    /**
     * Retrieve the current raw status of the payment session (optimized for fast polling).
     */
    public function status(string $id, Request $request)
    {
        // The crypto settlement columns are selected because the poll is the only
        // channel that can tell a waiting customer their payment came up short —
        // without it they sit on a spinner awaiting a confirmation that will never
        // come, since a short payment is deliberately never auto-fulfilled.
        $session = PaymentSession::with([
            'paymentAttempt:id,user_id,order_id,payment_status,pay_currency,pay_amount,actually_paid,settlement_delta,purchase_id',
            'paymentAttempt.order',
        ])
            ->select('id', 'status', 'expires_at', 'confirmed_at', 'failed_at', 'payment_attempt_id')
            ->findOrFail($id);

        $this->authorizeSessionAccess($session);

        $attempt = $session->paymentAttempt;

        return response()->json([
            'id' => $session->id,
            'status' => $session->status,
            'is_expired' => $session->expires_at ? $session->expires_at->isPast() : false,
            'confirmed_at' => $session->confirmed_at?->toIso8601String(),
            'failed_at' => $session->failed_at?->toIso8601String(),
            'underpayment' => $attempt && $attempt->isUnderpaid() ? [
                'expected' => (string) $attempt->pay_amount,
                'received' => (string) $attempt->actually_paid,
                'shortfall' => (string) abs((float) $attempt->settlement_delta),
                'currency' => strtoupper((string) $attempt->pay_currency),
                'can_top_up' => $attempt->purchase_id !== null,
            ] : null,
        ]);
    }

    /**
     * Cancel the specified active payment session.
     */
    public function cancel(string $id, Request $request)
    {
        $session = PaymentSession::with('paymentAttempt.order')->findOrFail($id);

        $this->authorizeSessionAccess($session);

        try {
            DB::transaction(function () use ($session) {
                // Check if session can be cancelled
                if (! in_array($session->status, ['pending', 'awaiting_method', 'awaiting_payment', 'awaiting_transfer', 'awaiting_confirmation', 'awaiting_customer_action'])) {
                    throw new \DomainException("Cannot cancel payment session that is already {$session->status}.");
                }

                // Capture how far the customer got before the transition wipes
                // it, so the outcome records the real stage rather than the
                // terminal one.
                $session->metadata = array_merge($session->metadata ?? [], [
                    'status_at_failure' => $session->status,
                ]);
                $session->save();

                $session->transitionTo('cancelled');

                // Cancel polymorphic payment attempt
                $attempt = $session->paymentAttempt;
                if ($attempt && $attempt->payment_status !== PaymentStatus::Paid) {
                    $attempt->update([
                        'payment_status' => PaymentStatus::Failed,
                        'failed_at' => now(),
                    ]);
                }

                // Give the coupon slot back. This path bypasses failSession()
                // and expireSession(), so without an explicit release a customer
                // who cancels deliberately would hold the slot until it lapsed.
                if ($order = $attempt?->order) {
                    app(CouponRedemptionService::class)->releaseForOrder($order, 'customer_cancelled');
                }
            });

            return response()->json([
                'message' => 'Payment session cancelled successfully.',
                'status' => 'cancelled',
                'is_expired' => $session->expires_at ? $session->expires_at->isPast() : false,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Trigger explicit verify/check of the payment session with the gateway.
     */
    public function verify(string $id, Request $request)
    {
        $session = PaymentSession::with('paymentAttempt.order')->findOrFail($id);

        $this->authorizeSessionAccess($session);

        if ($session->status === 'confirmed') {
            return response()->json([
                'status' => 'confirmed',
                'message' => 'Payment session already confirmed.',
            ]);
        }

        $attempt = $session->paymentAttempt;
        if (! $attempt) {
            return response()->json(['message' => 'Associated payment attempt not found.'], 404);
        }

        // We deliberately ignore any client-supplied transaction_id. Verification
        // resolves the authentic gateway transaction from OUR own reference:
        // while gateway_reference is still our (non-numeric) tx_ref,
        // verifyPayment() falls back to a tx_ref lookup that captures the real
        // numeric id and cross-binds it to this attempt. This removes the client
        // trust boundary entirely — a caller can never influence the
        // reconciliation reference used downstream.

        try {
            $gatewayFactory = app(PaymentGatewayFactory::class);
            $provider = $gatewayFactory->getProvider($attempt->gateway);

            // Call verifyPayment directly
            $isPaid = $provider->verifyPayment($attempt);

            if ($isPaid) {
                // Confirm the payment session
                $sessionService = app(PaymentSessionService::class);
                $sessionService->confirmSession($session, [
                    'transaction_id' => $attempt->gateway_reference,
                    'payload' => $attempt->verification_payload,
                ]);

                // If it is an order checkout payment, fulfill it
                if ($attempt->order) {
                    $orderService = app(OrderService::class);
                    $orderService->transitionPaymentStatus($attempt->order, PaymentStatus::Paid, $attempt->verification_payload);

                    PaymentConfirmed::dispatch($attempt->order, $attempt);

                    // Dispatch fulfillment jobs
                    foreach ($attempt->order->items as $item) {
                        FulfillOrderItemJob::dispatch($item);
                    }
                }
                // If it is wallet funding
                elseif ($attempt->payable_type === WalletFunding::class) {
                    $funding = $attempt->payable;
                    if ($funding && $funding->status !== FundingStatus::Completed) {
                        $fundingService = app(WalletFundingService::class);
                        // Process funding safely
                        $fundingService->processSuccessfulFunding(
                            $funding->reference,
                            $attempt->gateway_reference ?: 'DIRECT-'.uniqid(),
                            $attempt->verification_payload ?: []
                        );
                    }
                }

                return response()->json([
                    'status' => 'confirmed',
                    'message' => 'Payment verified and session confirmed.',
                ]);
            }

            return response()->json([
                'status' => $session->fresh()->status,
                'message' => 'Payment could not be verified yet. Please try again.',
            ]);
        } catch (\Exception $e) {
            Log::error('Explicit payment session verify failed: '.$e->getMessage());

            return response()->json(['message' => 'Verification could not be completed. Please try again or contact support.'], 500);
        }
    }

    /**
     * Pay/authorize the specified active payment session.
     */
    public function pay(string $id, Request $request)
    {
        $session = PaymentSession::with('paymentAttempt.order')->findOrFail($id);

        $this->authorizeSessionAccess($session);

        if (in_array($session->status, ['confirmed', 'failed', 'expired', 'cancelled'])) {
            return response()->json([
                'message' => 'Payment session is in a terminal state: '.$session->status,
                'status' => $session->status,
            ], 400);
        }

        $attempt = $session->paymentAttempt;
        if (! $attempt) {
            return response()->json(['message' => 'Associated payment attempt not found.'], 404);
        }

        // Gateway kill switch: refuse a FRESH charge on a disabled gateway.
        // This catches the customer whose payment modal was already open when
        // the switch flipped, since their tiles never got greyed out. Only
        // new money is gated here; webhooks, verification and the sweeper
        // still confirm anything already submitted.
        $gateway = in_array($session->provider, ['crypto', 'nowpayments'], true) ? 'nowpayments' : $session->provider;
        if (! FeatureFlag::gatewayEnabled($gateway)) {
            return response()->json([
                'message' => GatewayDisabledException::customerMessageFor($gateway),
                'gateway_disabled' => true,
            ], 503);
        }

        $method = $request->input('method');
        $details = $request->input('details', []);

        // Validate that the payment method is supported in the session's currency.
        // The list is shared with CheckoutController: when this copy was shorter,
        // a method offered at checkout produced an order that could never be paid.
        $currency = strtoupper($attempt->currency);

        if ($session->session_type === 'wallet') {
            $allowedMethods = ['wallet'];
        } elseif ($session->session_type === 'crypto') {
            $allowedMethods = ['crypto'];
        } else {
            $allowedMethods = SupportedPaymentMethods::forCurrency($currency);
        }

        if (! in_array($method, $allowedMethods)) {
            return response()->json([
                'message' => "The payment method {$method} is not available for currency {$currency}.",
            ], 422);
        }

        // The customer has now committed to a method, and this is the only point
        // where wallet funding ever learns which one: the funding sheet mints the
        // attempt first and picks the method afterwards. Recorded after the
        // allow-list check so a rejected method is never persisted, and before
        // the gateway call so an attempt that fails at the provider still tells
        // us what was being attempted — which is exactly the case we most need
        // to see when deciding whether a method is broken.
        $attempt->recordPaymentMethod($method);

        $gatewayFactory = app(PaymentGatewayFactory::class);

        try {
            $result = null;

            if ($method === 'card') {
                $flwProvider = $gatewayFactory->getProvider('flutterwave');

                if (config('services.flutterwave.direct_charge_enabled', false)) {
                    if ($otp = $request->input('otp')) {
                        $flwRef = $request->input('flw_ref') ?: ($session->payment_payload['flw_ref'] ?? '');
                        $result = $flwProvider->validateOTP($attempt, $otp, $flwRef);
                    } elseif ($pin = $request->input('pin')) {
                        $result = $flwProvider->chargeCard($attempt, $details, ['pin' => $pin]);
                    } else {
                        $request->validate([
                            'details.card_number' => 'required|string',
                            'details.cvv' => 'required|string',
                            'details.expiry_month' => 'required|string',
                            'details.expiry_year' => 'required|string',
                        ]);
                        $result = $flwProvider->chargeCard($attempt, $details);
                    }
                } else {
                    // Flutterwave Inline: return initialization data for the frontend
                    // to open Flutterwave's secure popup. Card details are entered
                    // directly into Flutterwave's UI — our server never touches them.
                    $inlineData = $flwProvider->initializePayment($attempt);

                    $session->transitionTo('awaiting_payment');
                    $session->payment_payload = array_merge(
                        $session->payment_payload ?? [],
                        ['inline' => $inlineData]
                    );
                    $session->save();

                    return new PaymentSessionResource($session->fresh());
                }
            } elseif ($method === 'bank_transfer') {
                $flwProvider = $gatewayFactory->getProvider('flutterwave');
                $result = $flwProvider->chargeBankTransfer($attempt);
            } elseif ($method === 'apple_pay') {
                $flwProvider = $gatewayFactory->getProvider('flutterwave');
                $result = $flwProvider->chargeApplePay($attempt, $details);
            } elseif ($method === 'mobile_money') {
                $request->validate([
                    'details.phone_number' => 'required|string',
                    'details.network' => 'required|string',
                ]);
                $flwProvider = $gatewayFactory->getProvider('flutterwave');
                $result = $flwProvider->chargeMobileMoney($attempt, $details['phone_number'], $details['network']);
            } elseif ($method === 'crypto') {
                $request->validate([
                    'details.pay_currency' => 'required|string',
                ]);

                // Reject a coin the gateway cannot accept for this order value BEFORE
                // creating anything. Minimums differ by more than two orders of
                // magnitude across our coins and float with network conditions, so
                // without this the customer commits to a payment method and only then
                // meets a raw gateway rejection. The UI hides these coins already;
                // this is the authoritative check, since the UI list can be stale.
                if ($rejection = $this->rejectUnusableCoin($attempt, (string) $details['pay_currency'])) {
                    return response()->json(['message' => $rejection], 422);
                }

                // --- FIX: Switch gateway to nowpayments for both Attempt and Funding ---
                // By default, wallet funding initiates with flutterwave. When switching
                // to crypto in the checkout/funding wizard, we must update the gateway
                // so that webhooks and verify jobs use the correct provider.
                $attempt->gateway = 'nowpayments';
                $attempt->save();

                $session->provider = 'nowpayments';
                $session->session_type = 'crypto';
                $session->save();

                if ($attempt->payable_type === WalletFunding::class) {
                    $funding = $attempt->payable;
                    if ($funding) {
                        $funding->gateway = 'nowpayments';
                        $funding->save();
                    }
                }

                $npProvider = $gatewayFactory->getProvider('nowpayments');
                $result = $npProvider->chargeCrypto($attempt, $details['pay_currency']);
            } elseif (in_array($method, ['ussd', 'pay_with_bank', 'bank_qr', 'mobile_wallet'], true)) {
                // Hosted-redirect family: USSD, Pay With Bank, Bank QR (NQR),
                // and Nigerian e-wallets (OPay/eNaira) all share the same flow.
                // We call Flutterwave's /payments endpoint with a method-specific
                // `payment_options` value so the hosted page renders only that
                // method, then redirect the customer there. They return to
                // /checkout/return where we verify and finalise.
                $flwProvider = $gatewayFactory->getProvider('flutterwave');
                $returnUrl = route('shop.checkout.return', ['session' => $session->id]);
                $result = $flwProvider->chargeHosted($attempt, $method, $returnUrl);
            } elseif ($method === 'wallet') {
                $request->validate([
                    'details.auth_token' => 'required|string',
                ]);

                $walletProvider = $gatewayFactory->getProvider('wallet');

                try {
                    // Authorization (PIN auth token) reserves the funds. The debit
                    // settles through the order's fulfillment lifecycle, matching the
                    // CTO's wallet-PIN design (funds remain locked until then).
                    $walletProvider->authorizeTransaction($attempt, $details['auth_token']);
                    $result = [
                        'status' => 'confirmed',
                        'transaction_id' => $attempt->gateway_reference,
                    ];
                } catch (\Exception $e) {
                    $result = [
                        'status' => 'failed',
                        'message' => $e->getMessage(),
                    ];
                }
            } else {
                return response()->json(['message' => 'Unsupported payment method.'], 400);
            }

            if (! $result || ! isset($result['status'])) {
                throw new \RuntimeException('Gateway returned empty response.');
            }

            if ($result['status'] === 'confirmed') {
                $sessionService = app(PaymentSessionService::class);
                $sessionService->confirmSession($session, [
                    'transaction_id' => $result['transaction_id'] ?? $attempt->gateway_reference,
                    'payload' => $result,
                ]);

                if ($attempt->order) {
                    $orderService = app(OrderService::class);
                    $orderService->transitionPaymentStatus($attempt->order, PaymentStatus::Paid, $result);

                    PaymentConfirmed::dispatch($attempt->order, $attempt);

                    foreach ($attempt->order->items as $item) {
                        FulfillOrderItemJob::dispatch($item);
                    }
                } elseif ($attempt->payable_type === WalletFunding::class) {
                    $funding = $attempt->payable;
                    if ($funding && $funding->status !== FundingStatus::Completed) {
                        $fundingService = app(WalletFundingService::class);
                        $fundingService->processSuccessfulFunding(
                            $funding->reference,
                            $result['transaction_id'] ?? 'DIRECT-'.uniqid(),
                            $result
                        );
                    }
                }
            } elseif ($result['status'] === 'awaiting_customer_action') {
                $session->transitionTo('awaiting_customer_action');
                $session->payment_payload = array_merge($session->payment_payload ?? [], $result);
                $session->save();
            } elseif ($result['status'] === 'awaiting_transfer') {
                $session->transitionTo('awaiting_transfer');
                $session->payment_payload = array_merge($session->payment_payload ?? [], $result);

                // Re-anchor the session to the gateway's own expiry now that a real
                // payment exists. Until a coin is chosen there is no quote to expire,
                // so the session carries a provisional window; keeping that window
                // would let the sweeper cancel an order while the customer's coins
                // are still confirming. The gateway's value is authoritative and is
                // the same one the countdown must run on.
                $attempt->refresh();
                if ($attempt->expires_at) {
                    $session->expires_at = $attempt->expires_at;
                }

                $session->save();
            } elseif ($result['status'] === 'awaiting_confirmation') {
                $session->transitionTo('awaiting_confirmation');
                $session->payment_payload = array_merge($session->payment_payload ?? [], $result);
                $session->save();
            } elseif ($result['status'] === 'awaiting_redirect') {
                // Hosted-checkout (USSD / Pay With Bank / Bank QR / Mobile Wallet).
                // Stash the Flutterwave hosted link on the session payload so the
                // checkout JS can read `payment_payload.redirect_url` and forward
                // the customer there.
                $session->transitionTo('awaiting_redirect');
                $session->payment_payload = array_merge($session->payment_payload ?? [], $result);
                $session->save();
            } elseif ($result['status'] === 'failed') {
                $sessionService = app(PaymentSessionService::class);
                $sessionService->failSession($session, $result['message'] ?? 'Payment failed', $result);

                if ($attempt->order) {
                    $orderService = app(OrderService::class);
                    $orderService->transitionPaymentStatus($attempt->order, PaymentStatus::Failed, $result);
                } elseif ($attempt->payable_type === WalletFunding::class) {
                    $funding = $attempt->payable;
                    if ($funding) {
                        $funding->update(['status' => FundingStatus::Failed]);
                    }
                }
            }

            return new PaymentSessionResource($session->fresh());

        } catch (WalletOnHoldException|InsufficientBalanceException $e) {
            // Customer-facing wallet errors carry their own polished message —
            // render them verbatim instead of wrapping in "Payment processing
            // failed: …" which reads like an internal stack trace.
            Log::info('Payment session pay rejected: '.$e->getMessage());

            return response()->json(['message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('Payment session pay failed: '.$e->getMessage());

            return response()->json(['message' => 'Payment processing failed: '.$e->getMessage()], 400);
        }
    }

    /**
     * Issue a follow-up payment for the outstanding balance on an underpaid session.
     *
     * Zero tolerance means a short payment is never fulfilled, so without this the
     * customer's money has arrived, their order has not, and they have no way to
     * make up the difference. Most shortfalls are not their fault — exchanges
     * commonly deduct a withdrawal fee from the amount sent — so the recovery path
     * has to be self-service rather than a support ticket.
     */
    public function topUp(string $id)
    {
        $session = PaymentSession::where('id', $id)->orWhere('client_reference', $id)->firstOrFail();
        $this->authorizeSessionAccess($session);

        $attempt = $session->paymentAttempt;

        if (! $attempt || $attempt->payment_status !== PaymentStatus::PartiallyPaid) {
            return response()->json(['message' => 'This payment has no outstanding balance.'], 422);
        }

        $provider = app(PaymentGatewayFactory::class)->getProvider($attempt->gateway);

        if (! $provider instanceof NowPaymentsProvider) {
            return response()->json(['message' => 'Top-up is not available for this payment method.'], 422);
        }

        $result = $provider->createTopUpPayment($attempt);

        if (($result['status'] ?? 'failed') === 'failed') {
            return response()->json(['message' => $result['message'] ?? 'Could not create a top-up payment.'], 422);
        }

        $attempt->refresh();

        // Back to awaiting_transfer: there is a live quote again, for the balance.
        $session->payment_payload = array_merge($session->payment_payload ?? [], $result, ['is_top_up' => true]);
        if ($attempt->expires_at) {
            $session->expires_at = $attempt->expires_at;
        }
        $session->save();
        $session->transitionTo('awaiting_transfer');

        // Pending, not PartiallyPaid: the outstanding balance is now unpaid rather
        // than short, and leaving it PartiallyPaid would keep it out of the
        // reconciler's sweep.
        $attempt->payment_status = PaymentStatus::Pending;
        $attempt->save();

        return new PaymentSessionResource($session->fresh());
    }

    /**
     * Why this coin cannot be used for this order, or null if it can.
     *
     * Returns customer-facing copy: it names the coin, states the minimum in the
     * money they understand, and tells them what to do instead. A raw gateway
     * rejection here reads as a failure of the store rather than a limit of the
     * chain they picked.
     */
    private function rejectUnusableCoin(PaymentAttempt $attempt, string $payCurrency): ?string
    {
        $coin = CurrencyRate::query()
            ->crypto()
            ->where('code', strtoupper(trim($payCurrency)))
            ->first();

        if (! $coin) {
            return 'That cryptocurrency is not available. Please choose another coin.';
        }

        if (! $coin->is_active || ! $coin->is_payable) {
            return "{$coin->name} is temporarily unavailable. Please choose another coin.";
        }

        // Fails open on an unsynced minimum — see CurrencyRate::acceptsOrderValue.
        $usd = $this->attemptValueInUsd($attempt);

        if ($usd !== null && ! $coin->acceptsOrderValue($usd)) {
            $minimum = '$'.number_format((float) $coin->min_amount_usd, 2);

            return "Orders paid with {$coin->name} must be at least {$minimum}. Please choose another coin or add more to your order.";
        }

        return null;
    }

    /**
     * The attempt's value in USD, or null when it cannot be established.
     *
     * Null means "do not gate" rather than "reject": a rate lookup failure must not
     * block a payment the gateway would have accepted.
     */
    private function attemptValueInUsd(PaymentAttempt $attempt): ?float
    {
        $currency = strtoupper((string) $attempt->currency);
        $amount = (float) $attempt->amount;

        if ($amount <= 0) {
            return null;
        }

        if ($currency === 'USD') {
            return $amount;
        }

        try {
            return $amount * app(CurrencyRateService::class)->resolveRate($currency, 'USD');
        } catch (\Throwable $e) {
            Log::warning('Could not resolve order value in USD for crypto minimum check', [
                'attempt_id' => $attempt->id,
                'currency' => $currency,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
