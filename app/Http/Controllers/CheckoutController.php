<?php

namespace App\Http\Controllers;

use App\Domain\Cart\Services\CartManager;
use App\Domain\Kyc\Exceptions\KycStepUpRequiredException;
use App\Domain\Kyc\Exceptions\SpendingLimitExceededException;
use App\Domain\Order\Exceptions\InvalidCouponException;
use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\GatewayDisabledException;
use App\Domain\Payment\Exceptions\PaymentCategoryIneligibleException;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Domain\PaymentInsights\Exceptions\PaymentBackoffException;
use App\Domain\Risk\Exceptions\RiskDeniedException;
use App\Domain\Risk\Exceptions\RiskReviewRequiredException;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\MissingTransactionPinException;
use App\Domain\Wallet\Exceptions\RateUnavailableException;
use App\Domain\Wallet\Exceptions\WalletOnHoldException;
use App\Http\Resources\PaymentSessionResource;
use App\Jobs\VerifyPaymentJob;
use App\Models\Order;
use App\Models\PaymentSession;
use App\Models\WalletFunding;
use App\Rules\ChargeableCurrency;
use App\Support\FeatureFlag;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Web checkout. Delegates order placement to the CheckoutService orchestration
 * engine (the same path CheckoutApiController uses): cart validation, atomic
 * Order + OrderItem snapshots, a PaymentAttempt, gateway init, and — for wallet
 * payments — an immediate pessimistic-lock reserve + fulfillment dispatch.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private CartManager $cartManager,
        private CheckoutService $checkoutService,
    ) {}

    /**
     * Resolve a storefront route to its dashboard mirror when the request arrived
     * under /dashboard/shop/* — keeps the cart -> checkout -> order flow inside the
     * dashboard/PWA chrome instead of redirecting back to the public storefront.
     */
    private function shopRoute(Request $request, string $name, mixed $params = []): string
    {
        $prefix = $request->is('dashboard/shop*') ? 'dashboard.shop.' : 'shop.';

        return route($prefix.$name, $params);
    }

    public function process(Request $request)
    {
        // features.checkout_enabled kill-switch. Admin can pause new orders
        // (e.g. during a supplier outage) without taking the storefront down.
        abort_if(! FeatureFlag::on('checkout'), 503, 'Checkout is temporarily unavailable.');

        $data = $request->validate([
            'delivery_email' => ['required', 'email'],
            'payment_method' => ['required', 'in:card,mobile_money,crypto,wallet,bank_transfer,apple_pay,ussd,pay_with_bank,bank_qr,mobile_wallet'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
            // The wallet the customer chose to pay from (wallet method only);
            // absent, the first wallet that covers the order pays.
            'wallet_currency' => ['nullable', 'string', 'size:3'],
        ]);

        $user = $request->user();

        // Guest purchase is removed: checkout is signed-in-only. Guests keep
        // their cart (it merges into the account at sign-in, and the checkout
        // page returns them here to pay); this guard is the server-side
        // backstop for a hand-crafted POST.
        if (! $user) {
            $msg = 'Please sign in to complete checkout.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => $msg], 401);
            }

            return redirect($this->shopRoute($request, 'checkout'))->with('checkout_status', $msg);
        }

        $cart = $this->cartManager->resolveCart($user->id);
        $cart?->load('items.product', 'items.variant');

        if (! $cart || $cart->items->isEmpty()) {
            return redirect($this->shopRoute($request, 'checkout'))
                ->with('checkout_status', 'Your cart is empty.');
        }

        // Display currency comes from the customer's locale (hidden field on the
        // checkout form). Settlement is always USD; this is presentation only.
        $displayCurrency = strtoupper($request->inputString('currency', 'USD'));
        if (strlen($displayCurrency) !== 3) {
            $displayCurrency = 'USD';
        }

        // It must be a currency we hold a live rate for. The rate lookup behind
        // checkout used to answer an unknown or deactivated code with the USD
        // row, so a $5 cart posted as "ZZZ" became an order for 5.00 ZZZ and
        // that figure went to the gateway. The customer picked this currency in
        // the locale modal, so if an admin has since switched it off they are
        // told to pick another rather than being charged in dollars unawares.
        if (! ChargeableCurrency::accepts($displayCurrency)) {
            $msg = 'That currency is not available right now. Please choose another currency and try again.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => $msg], 422);
            }

            return redirect($this->shopRoute($request, 'checkout'))
                ->with('checkout_status', $msg);
        }

        // Verify currency-method compatibility behind the scenes. Mirrors the
        // frontend's `getFilteredMethods` mapping so a tampered request can't
        // route a method through a currency Flutterwave doesn't accept it on.
        // Shared with PaymentSessionController::pay - if the two lists drift,
        // an order gets created for a method the pay step then refuses.
        if (! SupportedPaymentMethods::allows($displayCurrency, $data['payment_method'])) {
            $msg = "The selected payment method is not available for {$displayCurrency}.";
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => $msg], 422);
            }

            return redirect($this->shopRoute($request, 'checkout'))
                ->with('checkout_status', $msg);
        }

        // Map checkout page methods to backend payment providers
        $paymentMethod = match ($data['payment_method']) {
            'wallet' => 'wallet',
            'crypto' => 'crypto',
            default => 'flutterwave',
        };

        try {
            $order = $this->checkoutService->placeOrder(
                user: $user,
                cart: $cart,
                paymentMethod: $paymentMethod,
                displayCurrency: $displayCurrency,
                deliveryEmail: $data['delivery_email'],
                // Three states for Rcoin redemption:
                //   - 'full' -> pay the entire order with Rcoin (rewards-page convert flow)
                //   - true   -> standard partial redemption capped at redemption_max_percentage
                //   - false  -> no Rcoin
                applyRcoin: $request->input('apply_rcoin') === 'full' ? 'full' : $request->boolean('apply_rcoin'),
                couponCode: $data['coupon_code'] ?? null,
                // The tile the customer actually picked. $paymentMethod above
                // collapses eight Flutterwave methods into one gateway name, so
                // this is the only place the real choice is still visible.
                selectedMethod: $data['payment_method'],
                walletCurrency: $data['wallet_currency'] ?? null,
            );

            // Order placed - the cart is converted, so drop the ephemeral
            // "Buy now" tag too (its item ids are now stale).
            $request->session()->forget('cart.buy_now_items');
        } catch (GatewayDisabledException $e) {
            // An admin has switched this gateway off. Nothing was written;
            // surface the same "temporarily unavailable" message the greyed
            // tiles show, and keep the cart so the customer can pick another
            // method.
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message' => $e->customerMessage(),
                    'gateway_disabled' => true,
                ], 503);
            }

            return redirect($this->shopRoute($request, 'checkout'))->with('checkout_status', $e->customerMessage());
        } catch (PaymentCategoryIneligibleException $e) {
            // A product category in the cart is not approved on the resolved
            // gateway. The gate runs before any write, so nothing needs cleanup;
            // surface the customer-safe message and keep the cart so the buyer
            // can remove the item or come back later.
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message' => $e->customerMessage(),
                    'category_ineligible' => true,
                ], 422);
            }

            return redirect($this->shopRoute($request, 'checkout'))->with('checkout_status', $e->customerMessage());
        } catch (PaymentBackoffException $e) {
            // Rate limited before any write happened, so there is no order to
            // clean up. Surface the wait plainly and keep the cart intact.
            // Without this the catch-all below swallowed the exception's own
            // 429 render() and the buyer saw a generic checkout failure with
            // no idea they simply had to wait.
            //
            // The exception renders its own 429 so every surface gets the same
            // payload, including `locked`: without it the checkout page cannot
            // tell a six hour lock from a short pause and never shows the lock
            // notice or the way to contact support.
            if ($request->expectsJson() || $request->ajax()) {
                return $e->render($request);
            }

            return redirect($this->shopRoute($request, 'checkout'))->with('checkout_status', $e->customerMessage());
        } catch (InvalidCouponException $e) {
            // Coupon error - surface the customer-safe message verbatim so the
            // buyer sees "That coupon code is not valid" instead of a generic
            // checkout failure.
            $message = $e->getMessage();

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => $message], 422);
            }

            return redirect($this->shopRoute($request, 'checkout'))->with('checkout_status', $message);
        } catch (WalletOnHoldException|InsufficientBalanceException|MissingTransactionPinException $e) {
            // Customer-facing wallet errors carry their own polished message —
            // surface them verbatim so the user sees the "wallet on hold /
            // contact support" copy instead of a generic checkout failure.
            $message = $e->getMessage();

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => $message], 422);
            }

            return redirect($this->shopRoute($request, 'checkout'))->with('checkout_status', $message);
        } catch (KycStepUpRequiredException $e) {
            // The transaction crossed the identity-verification threshold. Nothing
            // was written; hand the customer the Smile session so the frontend can
            // launch verification, then retry once verified.
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json($e->toResponse(), 422);
            }

            return redirect($this->shopRoute($request, 'checkout'))->with('checkout_status', $e->customerMessage());
        } catch (SpendingLimitExceededException $e) {
            // Rolling spending cap reached. Nothing was written; the message
            // nudges unverified customers toward KYC (which raises the cap).
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json($e->toResponse(), 422);
            }

            return redirect($this->shopRoute($request, 'checkout'))->with('checkout_status', $e->customerMessage());
        } catch (RiskReviewRequiredException $e) {
            // Held for manual review. Nothing was written; the attempt is queued
            // for an admin and the customer sees a neutral "under review" message.
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json($e->toResponse(), 422);
            }

            return redirect($this->shopRoute($request, 'checkout'))->with('checkout_status', $e->customerMessage());
        } catch (RiskDeniedException $e) {
            // Refused by the risk engine. Customer-safe message, no reason codes.
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json($e->toResponse(), 403);
            }

            return redirect($this->shopRoute($request, 'checkout'))->with('checkout_status', $e->customerMessage());
        } catch (RateUnavailableException $e) {
            // No usable rate for the charge currency (too old, or none at all).
            // Nothing was written. One customer sentence, not the pair and age
            // the stale exception carries for the log.
            $msg = 'We cannot price your order in '.$displayCurrency.' right now. Please try again shortly or choose another currency.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => $msg], 422);
            }

            return redirect($this->shopRoute($request, 'checkout'))->with('checkout_status', $msg);
        } catch (Throwable $e) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message' => 'Checkout could not be completed: '.$e->getMessage(),
                ], 422);
            }

            return redirect($this->shopRoute($request, 'checkout'))
                ->with('checkout_status', 'Checkout could not be completed: '.$e->getMessage());
        }

        if ($request->expectsJson() || $request->ajax()) {
            $session = $order->paymentAttempts()->latest()->first()?->paymentSession;

            return response()->json([
                'order_number' => $order->order_number,
                'redirect_url' => $this->shopRoute($request, 'order', $order->order_number),
                'payment_session' => $session ? new PaymentSessionResource($session) : null,
            ]);
        }

        return redirect($this->shopRoute($request, 'order', $order->order_number));
    }

    /**
     * Scope an order lookup to its rightful viewer: the owning, signed-in
     * user. Order numbers can never be enumerated by others. (Legacy guest
     * orders are reached by claiming the account, never by session.)
     */
    private function orderScopedToViewer(Request $request, string $orderNumber)
    {
        abort_unless($request->user(), 404);

        return Order::query()
            ->where('order_number', $orderNumber)
            ->where('user_id', $request->user()->id);
    }

    /**
     * Order confirmation page. Scoped to the owning user so order numbers
     * can't be enumerated by other customers.
     */
    public function order(Request $request, string $orderNumber)
    {
        $order = $this->orderScopedToViewer($request, $orderNumber)
            ->with(['items', 'paymentAttempts.paymentSession'])
            ->firstOrFail();

        return view('shop.order', ['order' => $order]);
    }

    /**
     * Lightweight status probe for the order page's fulfillment watcher: the
     * pending view polls this and swaps itself for the completed view the
     * moment fulfillment lands, so the customer never has to reload by hand.
     */
    public function orderStatus(Request $request, string $orderNumber)
    {
        $order = $this->orderScopedToViewer($request, $orderNumber)->firstOrFail();

        return response()->json([
            'order_status' => $order->order_status?->value,
            'fulfillment_status' => $order->fulfillment_status?->value,
        ]);
    }

    /**
     * Hosted-checkout return URL. Flutterwave redirects the customer here
     * after a USSD / Pay With Bank / Bank QR / Mobile Wallet payment. We
     * verify by tx_ref (the only field we trust from the query string) and
     * push the customer to the order page on success.
     *
     * Even if the customer never lands here (closed tab, network drop), the
     * Flutterwave webhook will reach `/webhooks/flutterwave` and finalise the
     * payment server-side - this handler is the happy-path nudge.
     */
    public function hostedReturn(Request $request, PaymentSession $session)
    {
        $attempt = $session->paymentAttempt;
        if (! $attempt) {
            return redirect($this->shopRoute($request, 'checkout'))->with('checkout_status', 'Payment session has expired.');
        }

        // payment_sessions has no user_id of its own - ownership lives on the
        // underlying PaymentAttempt. Guard there so a customer can't
        // hand-craft a URL pointing at someone else's session. Signed-in
        // only: guest purchase is removed, so an unauthenticated return leg
        // has no business here (the webhook still settles the payment).
        abort_unless($request->user(), 403);
        abort_unless($attempt->user_id === $request->user()->id, 403);

        // The query string is the browser's word, not the gateway's. A USSD or
        // bank payment approved on the phone after the customer came back
        // "cancelled" has still been paid; trusting the string sent them to
        // pay again while the first payment landed behind them. Every return
        // asks the gateway, and an unpaid one simply fails to verify.

        // Verify against the gateway AND run the full settlement chain in
        // one synchronous pass (VerifyPaymentJob: gateway verify, order ->
        // Paid, session confirmed, cart cleared, confirmation email,
        // fulfillment dispatched; for fundings it credits the wallet).
        // The old code called verifyPayment() bare, which stamped the
        // attempt Paid and then relied on the webhook - but the webhook
        // path stands down when it finds the attempt already Paid, so the
        // browser winning the race stranded the order in Pending forever.
        try {
            dispatch_sync(new VerifyPaymentJob($attempt));
        } catch (Throwable $e) {
            // Inline settlement failed (gateway hiccup, lock contention).
            // Queue the same job so its retries finish the chain; the
            // webhook remains a second settlement authority.
            Log::warning('hostedReturn: inline settlement failed, queued for retry', [
                'attempt_id' => $attempt->id,
                'message' => $e->getMessage(),
            ]);
            VerifyPaymentJob::dispatch($attempt);
        }

        $attempt->refresh();
        $verified = $attempt->payment_status === PaymentStatus::Paid;

        if ($verified && $attempt->order) {
            return redirect($this->shopRoute($request, 'order', $attempt->order->order_number));
        }

        // Wallet fundings arrive here too when a backup gateway's hosted
        // page redirects back. VerifyPaymentJob has already credited the
        // balance (idempotent, locked; the webhook doing it again stands
        // down cleanly), so the wallet page loads correct.
        if ($verified && $attempt->payable_type === WalletFunding::class) {
            return redirect()->route('dashboard.wallet')
                ->with('wallet_status', 'Your wallet top-up was received and is being credited.');
        }

        // Anything else (cancelled, failed, or unverified) returns the
        // customer to the checkout page with the cart intact for a retry.
        return redirect($this->shopRoute($request, 'checkout'))
            ->with('checkout_status', 'Payment was not completed. Please try again or pick a different method.');
    }

    /**
     * Download the redeemable codes for this order as a CSV file. One row per
     * unit (so 2 x Apple $5 becomes 2 rows). Owned-by-current-user scope as
     * the order view itself - codes are the most sensitive thing we hand out.
     */
    public function codesCsv(Request $request, string $orderNumber)
    {
        $order = $this->loadOrderForDownload($request, $orderNumber);

        $filename = "order-{$order->order_number}-codes.csv";

        return response()->streamDownload(function () use ($order) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Order', 'Item', 'Region', 'Value', 'Currency', 'Code Type', 'Code', 'Status']);
            foreach ($order->items as $item) {
                $snap = $item->product_snapshot ?? [];
                $name = $snap['name'] ?? 'Item';
                $region = $snap['country_code'] ?? '';
                $payload = (array) ($item->fulfillment_payload ?? []);
                $primaryCode = $payload['pin']
                    ?? $payload['code']
                    ?? $payload['serial']
                    ?? $payload['voucher_code']
                    ?? $payload['activation_code']
                    ?? '';
                $codeType = isset($payload['pin']) ? 'PIN' : (isset($payload['serial']) ? 'Serial' : 'Code');
                fputcsv($out, [
                    $order->order_number,
                    $name,
                    $region,
                    (string) $item->display_amount,
                    $item->display_currency ?: $order->settlement_currency,
                    $codeType,
                    $primaryCode,
                    $item->fulfillment_status?->value ?? 'pending',
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Download a printable PDF receipt with all redemption codes - the "Add
     * to wallet" action on the success view. Generated via DomPDF from a
     * dedicated print-friendly blade template.
     */
    public function codesPdf(Request $request, string $orderNumber)
    {
        $order = $this->loadOrderForDownload($request, $orderNumber);

        $pdf = Pdf::loadView('shop.order-codes-pdf', ['order' => $order]);

        return $pdf->download("order-{$order->order_number}-codes.pdf");
    }

    private function loadOrderForDownload(Request $request, string $orderNumber): Order
    {
        return $this->orderScopedToViewer($request, $orderNumber)
            ->with('items')
            ->firstOrFail();
    }
}
