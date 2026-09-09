<?php

namespace App\Http\Controllers\Api;

use App\Domain\Order\Events\PaymentConfirmed;
use App\Domain\Order\Services\CouponRedemptionService;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\GatewayDisabledException;
use App\Domain\Payment\Exceptions\PaymentCategoryIneligibleException;
use App\Domain\Payment\Providers\NowPaymentsProvider;
use App\Domain\Payment\Services\PaymentEligibilityService;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Payment\Support\CryptoCoinGate;
use App\Domain\Payment\Support\FiatGatewayRouter;
use App\Domain\Payment\Support\MobileMoneyRail;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Domain\Shared\Enums\ComplianceCategory;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\WalletOnHoldException;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentSessionResource;
use App\Jobs\FulfillOrderItemJob;
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
     * Authorize access to the payment session: signed-in owner only (guest
     * purchase is removed, so an unauthenticated caller has no session to
     * reach).
     */
    private function authorizeSessionAccess(PaymentSession $session): void
    {
        $attempt = $session->paymentAttempt;
        if (! $attempt) {
            abort(403, 'Unauthorized access to payment session.');
        }

        if (! auth()->check() || $attempt->user_id !== auth()->id()) {
            abort(403, 'Unauthorized access to payment session.');
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
                if (! in_array($session->status, ['pending', 'awaiting_method', 'awaiting_payment', 'awaiting_transfer', 'awaiting_redirect', 'awaiting_confirmation', 'awaiting_customer_action'])) {
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

                    // Dispatch fulfillment jobs (staggered per identical offer
                    // so duplicates don't trip supplier velocity protection).
                    FulfillOrderItemJob::dispatchStaggered($attempt->order->items);
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
        //
        // Fiat sessions are NOT refused here: their gateway is re-resolved
        // per method below (FiatGatewayRouter), so a session stamped for
        // Flutterwave can still pay through a live backup.
        $gateway = in_array($session->provider, ['crypto', 'nowpayments'], true) ? 'nowpayments' : $session->provider;
        if (! in_array($gateway, FiatGatewayRouter::FIAT_GATEWAYS, true) && ! FeatureFlag::gatewayEnabled($gateway)) {
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

        // The cart's compliance categories, resolved once: they drive both the
        // category-aware gateway pick below and the authoritative gate before the
        // charge. From the order's snapshot, or Wallet for a funding attempt.
        $eligibilityCategories = $attempt->order
            ? $attempt->order->loadMissing('items')->items->map(fn ($item) => $item->compliance_category)
            : [ComplianceCategory::Wallet];

        $eligibility = app(PaymentEligibilityService::class);

        $gatewayFactory = app(PaymentGatewayFactory::class);

        // Per-method fiat routing. The gateway stamped at order placement was
        // only the router's default; the customer has now committed to a
        // method, which is the first moment the real gateway is knowable
        // (mirrors the crypto re-stamp below). Re-routing is allowed ONLY
        // while the session is pre-charge ('awaiting_method'): once any
        // gateway has minted provider-side state (virtual account, inline
        // popup, redirect link), the payment finishes where it started or
        // not at all, so a late flag flip refuses rather than re-routes and
        // the customer restarts through checkout reuse.
        $fiatGateway = null;
        if (array_key_exists($method, FiatGatewayRouter::METHOD_PRIORITY)) {
            // Category-aware: the first live gateway that both serves this
            // method/currency AND is approved for the cart, so a category one
            // gateway does not approve still routes to a backup that does instead
            // of being denied. Falls back to plain capability order in shadow mode.
            $fiatGateway = $eligibility->routeGateway($method, $currency, $eligibilityCategories);

            if ($fiatGateway === null) {
                // No live gateway both serves this method/currency AND is
                // approved for the cart. Distinguish a plain outage (nothing
                // capable) from a category refusal (capable, but none approved),
                // so the customer sees the right message.
                $candidates = FiatGatewayRouter::candidates($method, $currency);

                if ($candidates === []) {
                    return response()->json([
                        'message' => GatewayDisabledException::customerMessageFor('fiat'),
                        'gateway_disabled' => true,
                    ], 503);
                }

                return response()->json([
                    'message' => PaymentCategoryIneligibleException::messageFor(
                        $eligibility->deniedFor($eligibilityCategories, $candidates[0], $currency),
                    ),
                    'category_ineligible' => true,
                ], 422);
            }

            if ($fiatGateway !== $attempt->gateway) {
                if ($session->status !== 'awaiting_method') {
                    return response()->json([
                        'message' => GatewayDisabledException::customerMessageFor($attempt->gateway),
                        'gateway_disabled' => true,
                    ], 503);
                }

                $attempt->gateway = $fiatGateway;
                $attempt->save();

                $session->provider = $fiatGateway;
                $session->save();

                // Wallet fundings carry their own gateway stamp, which the
                // webhook router and FundingVerificationService both consult;
                // leaving it behind would verify against the wrong provider
                // (the same trap the crypto switch below already guards).
                if ($attempt->payable_type === WalletFunding::class && $attempt->payable) {
                    $attempt->payable->update(['gateway' => $fiatGateway]);
                }
            }
        }

        // Authoritative eligibility gate. The final gateway is now known and
        // this is the last point before money moves, so it is the un-bypassable
        // check: it catches a crafted request to this public endpoint and a
        // matrix change made mid-session, both of which the checkout-time gate
        // cannot see. The effective gateway mirrors the charge routing below
        // (fiat -> resolved backup/primary, crypto -> nowpayments, wallet ->
        // wallet); the categories come from the order's snapshot, or Wallet for
        // a funding attempt. Shadow mode logs the denial and lets it through.
        $effectiveGateway = $fiatGateway
            ?? ($method === 'crypto' ? 'nowpayments' : ($method === 'wallet' ? 'wallet' : $attempt->gateway));

        $eligibilityBlockers = $eligibility->blockers(
            $eligibilityCategories,
            $effectiveGateway,
            $currency,
            ['stage' => 'pay', 'session' => $session->id],
        );

        if ($eligibilityBlockers !== []) {
            return response()->json([
                'message' => PaymentCategoryIneligibleException::messageFor($eligibilityBlockers),
                'category_ineligible' => true,
            ], 422);
        }

        try {
            $result = null;

            if ($fiatGateway !== null && $fiatGateway !== 'flutterwave') {
                // Backup gateways (Paystack today, Kora next) run EVERY fiat
                // method through their hosted checkout: one channel-restricted
                // redirect out, verification on return. No inline card entry,
                // no per-method API surface, so a backup cannot half-support
                // a method the router said it covers.
                $backupProvider = $gatewayFactory->getProvider($fiatGateway);
                $returnUrl = route('shop.checkout.return', ['session' => $session->id]);
                $result = $backupProvider->chargeHosted($attempt, $method, $returnUrl);
            } elseif ($method === 'card') {
                $flwProvider = $gatewayFactory->getProvider($fiatGateway);

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
                $flwProvider = $gatewayFactory->getProvider($fiatGateway);
                $result = $flwProvider->chargeBankTransfer($attempt);
            } elseif ($method === 'apple_pay') {
                $flwProvider = $gatewayFactory->getProvider($fiatGateway);
                $result = $flwProvider->chargeApplePay($attempt, $details);
            } elseif ($method === 'mobile_money') {
                $request->validate([
                    'details.phone_number' => 'required|string',
                    'details.network' => 'nullable|string',
                ]);

                // Checked against the rail here, not at the gateway: a network
                // the market does not have is a stale screen, and answering it
                // with a failed order would turn a display bug into a lost sale.
                if (MobileMoneyRail::requiresNetwork($currency)
                    && MobileMoneyRail::gatewayNetwork($currency, $details['network'] ?? null) === null) {
                    return response()->json([
                        'message' => "Choose a mobile money network available in {$currency}.",
                    ], 422);
                }

                $flwProvider = $gatewayFactory->getProvider($fiatGateway);
                $result = $flwProvider->chargeMobileMoney(
                    $attempt,
                    $details['phone_number'],
                    $details['network'] ?? null,
                    // Rails that answer with their own authorization page send
                    // the customer back through the hosted return leg.
                    route('shop.checkout.return', ['session' => $session->id]),
                );
            } elseif ($method === 'crypto') {
                $request->validate([
                    'details.pay_currency' => 'required|string',
                ]);

                // Kill switch for the crypto rail. The early session-provider
                // check above never sees this case: a checkout/funding session
                // is stamped with a FIAT default and only re-stamps to
                // nowpayments right here, so without this guard a greyed-out
                // crypto tile could still be charged server-side.
                if (! FeatureFlag::gatewayEnabled('nowpayments')) {
                    return response()->json([
                        'message' => GatewayDisabledException::customerMessageFor('nowpayments'),
                        'gateway_disabled' => true,
                    ], 503);
                }

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
                $flwProvider = $gatewayFactory->getProvider($fiatGateway);
                $returnUrl = route('shop.checkout.return', ['session' => $session->id]);
                $result = $flwProvider->chargeHosted($attempt, $method, $returnUrl);
            } elseif ($method === 'wallet') {
                $request->validate([
                    'details.auth_token' => 'required|string',
                ]);

                // Kill switch for the wallet rail, for the same reason as the
                // crypto guard above: an order session stamped with a fiat
                // default reaches this branch without ever passing the early
                // session-provider check.
                if (! FeatureFlag::gatewayEnabled('wallet')) {
                    return response()->json([
                        'message' => GatewayDisabledException::customerMessageFor('wallet'),
                        'gateway_disabled' => true,
                    ], 503);
                }

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

                    // Staggered per identical offer (supplier velocity protection).
                    FulfillOrderItemJob::dispatchStaggered($attempt->order->items);
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

        // Eligibility gate. A top-up is a fresh crypto charge for the shortfall,
        // so it is re-checked like pay(): if the category lost approval on
        // nowpayments between the first payment and now, the balance charge is
        // refused. Shadow mode logs and allows.
        $topUpCategories = $attempt->order
            ? $attempt->order->loadMissing('items')->items->map(fn ($item) => $item->compliance_category)
            : [ComplianceCategory::Wallet];

        $topUpBlockers = app(PaymentEligibilityService::class)->blockers(
            $topUpCategories,
            'nowpayments',
            $attempt->currency,
            ['stage' => 'top_up', 'session' => $session->id],
        );

        if ($topUpBlockers !== []) {
            return response()->json([
                'message' => PaymentCategoryIneligibleException::messageFor($topUpBlockers),
                'category_ineligible' => true,
            ], 422);
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
        // One gate for checkout and wallet funding - see CryptoCoinGate.
        return app(CryptoCoinGate::class)->rejection(
            $payCurrency,
            (float) $attempt->amount,
            (string) $attempt->currency,
        );
    }
}
