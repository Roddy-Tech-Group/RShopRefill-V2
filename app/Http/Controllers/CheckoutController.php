<?php

namespace App\Http\Controllers;

use App\Domain\Cart\Services\CartManager;
use App\Domain\Cart\Services\CartPricingService;
use App\Domain\Fraud\Services\FraudDetectionService;
use App\Domain\Order\Exceptions\InvalidCouponException;
use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Providers\FlutterwavePaymentProvider;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Domain\PaymentInsights\Exceptions\PaymentBackoffException;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\MissingTransactionPinException;
use App\Domain\Wallet\Exceptions\WalletOnHoldException;
use App\Http\Resources\PaymentSessionResource;
use App\Models\Order;
use App\Models\PaymentSession;
use App\Models\Setting;
use App\Models\User;
use App\Support\FeatureFlag;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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
        ]);

        $user = $request->user();
        $isGuestCheckout = false;

        // Guest checkout (features.guest_checkout_enabled, default ON): the
        // order runs through the exact same pipeline under a shadow account
        // keyed by the delivery email. The guest claims the account later via
        // the emailed link (password + transaction PIN).
        if (! $user) {
            abort_unless(FeatureFlag::on('guest_checkout'), 403, 'Please sign in to complete checkout.');

            $this->ensureGuestCheckoutRateLimits($request, $data['delivery_email']);

            // Wallet and Rcoin belong to signed-in accounts: a guest keying in
            // a registered customer's email must never spend that account's
            // balance or points.
            if ($data['payment_method'] === 'wallet' || $request->boolean('apply_rcoin') || $request->input('apply_rcoin') === 'full') {
                $msg = 'Sign in to pay with your wallet or Rcoin.';
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json(['message' => $msg], 422);
                }

                return redirect($this->shopRoute($request, 'checkout'))->with('checkout_status', $msg);
            }

            $user = $this->resolveGuestUser($data['delivery_email']);
            $isGuestCheckout = true;
        }

        // Optional hard-gate: when the compliance toggle is ON, the buyer must
        // have a verified email address before they can pay. Soft by default
        // so the storefront stays browseable without verification friction.
        // Guest shadow accounts are exempt: their inbox is proven later by the
        // claim link, and the fulfilled email goes to that same inbox anyway.
        if (! $isGuestCheckout && Setting::get('require_email_verified_for_checkout', false) && ! $user->hasVerifiedEmail()) {
            $msg = 'Please verify your email address before completing checkout. Check your inbox for the verification link.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => $msg, 'redirect' => route('verification.notice')], 403);
            }

            return redirect()->route('verification.notice')->with('checkout_status', $msg);
        }

        // Guests keep their cart under the guest_token cookie; the shadow user
        // was only just created, so resolving by user id would mint an empty cart.
        $guestToken = $request->cookie('guest_token') ?? $request->header('X-Guest-Token');
        $cart = $isGuestCheckout && $guestToken
            ? $this->cartManager->resolveCart(null, $guestToken)
            : ($isGuestCheckout ? null : $this->cartManager->resolveCart($user->id));
        $cart?->load('items.product', 'items.variant');

        if (! $cart || $cart->items->isEmpty()) {
            return redirect($this->shopRoute($request, 'checkout'))
                ->with('checkout_status', 'Your cart is empty.');
        }

        $fraudService = app(FraudDetectionService::class);
        $cartTotals = app(CartPricingService::class)->calculateCartTotals($cart->items);
        $amount = $cartTotals['total'];

        // Display currency comes from the customer's locale (hidden field on the
        // checkout form). Settlement is always USD; this is presentation only.
        $displayCurrency = strtoupper((string) $request->input('currency', 'USD'));
        if (strlen($displayCurrency) !== 3) {
            $displayCurrency = 'USD';
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

        if ($fraudService->isSuspiciousCheckout($user, $amount, $request->ip())) {
            $msg = $user->isKycVerified()
                ? 'Your checkout attempt was flagged by our security systems. Please contact support.'
                : 'Your checkout attempt was flagged by our security systems. Please complete KYC verification to proceed.';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => $msg], 403);
            }

            return redirect($this->shopRoute($request, 'checkout'))->with('checkout_status', $msg);
        }

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
            );

            $fraudService->recordCheckout($user, $request->ip());

            // Order placed - the cart is converted, so drop the ephemeral
            // "Buy now" tag too (its item ids are now stale).
            $request->session()->forget('cart.buy_now_items');

            // The confirmation page is user-scoped; grant this browser session
            // access to its own guest orders (the guest has no login yet).
            if ($isGuestCheckout) {
                $request->session()->push('guest_order_numbers', $order->order_number);
            }
        } catch (PaymentBackoffException $e) {
            // Rate limited before any write happened, so there is no order to
            // clean up. Surface the wait plainly and keep the cart intact.
            // Without this the catch-all below swallowed the exception's own
            // 429 render() and the buyer saw a generic checkout failure with
            // no idea they simply had to wait.
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message' => $e->customerMessage(),
                    'is_rate_limited' => true,
                    'wait_seconds' => $e->waitSeconds,
                ], 429);
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
     * Scope an order lookup to its rightful viewer: the owning user when
     * signed in, or - for guest checkout - an order number this browser
     * session placed. Order numbers can never be enumerated by others.
     */
    private function orderScopedToViewer(Request $request, string $orderNumber)
    {
        $query = Order::query()->where('order_number', $orderNumber);

        if ($request->user()) {
            return $query->where('user_id', $request->user()->id);
        }

        abort_unless(
            in_array($orderNumber, $request->session()->get('guest_order_numbers', []), true),
            404,
        );

        return $query;
    }

    /**
     * Find or create the shadow account a guest order is placed under. An
     * email that already belongs to a registered customer attaches the order
     * to that account (the fulfilled email goes to the same inbox either
     * way); a fresh email gets an unclaimed guest account with a random
     * password, converted to a real account via the emailed claim link.
     */
    private function resolveGuestUser(string $email): User
    {
        $existing = User::where('email', $email)->first();
        if ($existing) {
            return $existing;
        }

        return User::create([
            'name' => Str::ucfirst(Str::before($email, '@')) ?: 'Guest',
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
            'is_guest' => true,
        ]);
    }

    /**
     * Guest checkout abuse guard: per-IP and per-email attempt caps (the
     * route's throttle:10,1 still applies on top). Signed-in checkout is
     * untouched - it already has fraud detection keyed to the account.
     */
    private function ensureGuestCheckoutRateLimits(Request $request, string $email): void
    {
        $limits = [
            'guest-checkout:ip:'.$request->ip() => 8,
            'guest-checkout:email:'.strtolower($email) => 4,
        ];

        foreach ($limits as $key => $maxAttempts) {
            abort_if(
                RateLimiter::tooManyAttempts($key, $maxAttempts),
                429,
                'Too many checkout attempts. Please try again in a few minutes.',
            );
            RateLimiter::hit($key, 600); // 10-minute window
        }
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
        // underlying PaymentAttempt. Guard there so a logged-in customer can't
        // hand-craft a URL pointing at someone else's session. Guests must
        // have the order number in their session.
        if ($request->user()) {
            abort_unless($attempt->user_id === $request->user()->id, 403);
        } else {
            $guestOrders = $request->session()->get('guest_order_numbers', []);
            abort_unless($attempt->order && in_array($attempt->order->order_number, $guestOrders, true), 403);
        }

        $reportedStatus = strtolower((string) $request->query('status'));

        if (in_array($reportedStatus, ['successful', 'completed'], true)) {
            /** @var FlutterwavePaymentProvider $flw */
            $flw = app(PaymentGatewayFactory::class)->getProvider('flutterwave');

            // Verify against Flutterwave instead of trusting the query string.
            // If verification succeeds the provider marks the attempt Paid and
            // the webhook handler will complete the order fulfilment chain.
            $verified = $flw->verifyPayment($attempt);

            if ($verified && $attempt->order) {
                return redirect($this->shopRoute($request, 'order', $attempt->order->order_number));
            }
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
