<?php

namespace App\Http\Controllers;

use App\Domain\Cart\Services\CartManager;
use App\Domain\Cart\Services\CartPricingService;
use App\Domain\Fraud\Services\FraudDetectionService;
use App\Domain\Order\Exceptions\InvalidCouponException;
use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Providers\FlutterwavePaymentProvider;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\MissingTransactionPinException;
use App\Domain\Wallet\Exceptions\WalletOnHoldException;
use App\Http\Resources\PaymentSessionResource;
use App\Models\Order;
use App\Models\PaymentSession;
use App\Models\Setting;
use App\Support\FeatureFlag;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
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
        abort_unless($user, 403);

        // Optional hard-gate: when the compliance toggle is ON, the buyer must
        // have a verified email address before they can pay. Soft by default
        // so the storefront stays browseable without verification friction.
        if (Setting::get('require_email_verified_for_checkout', false) && ! $user->hasVerifiedEmail()) {
            $msg = 'Please verify your email address before completing checkout. Check your inbox for the verification link.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => $msg, 'redirect' => route('verification.notice')], 403);
            }

            return redirect()->route('verification.notice')->with('checkout_status', $msg);
        }

        $cart = $this->cartManager->resolveCart($user->id);
        $cart->load('items.product', 'items.variant');

        if ($cart->items->isEmpty()) {
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
        $supported = [
            'USD' => ['card', 'apple_pay', 'crypto', 'wallet'],
            'EUR' => ['card', 'apple_pay', 'crypto', 'wallet', 'pay_with_bank'],
            'GBP' => ['card', 'apple_pay', 'crypto', 'wallet', 'pay_with_bank'],
            'NGN' => ['card', 'apple_pay', 'bank_transfer', 'crypto', 'wallet', 'ussd', 'pay_with_bank', 'bank_qr', 'mobile_wallet'],
            'GHS' => ['card', 'apple_pay', 'mobile_money', 'crypto', 'wallet'],
            'XAF' => ['card', 'apple_pay', 'mobile_money', 'crypto', 'wallet'],
            'XOF' => ['card', 'apple_pay', 'mobile_money', 'crypto', 'wallet'],
            'KES' => ['card', 'apple_pay', 'mobile_money', 'crypto', 'wallet'],
            'UGX' => ['card', 'apple_pay', 'mobile_money', 'crypto', 'wallet'],
            'RWF' => ['card', 'apple_pay', 'mobile_money', 'crypto', 'wallet'],
        ];

        $allowedMethods = $supported[$displayCurrency] ?? ['card', 'apple_pay'];
        if (! in_array($data['payment_method'], $allowedMethods)) {
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
            $msg = 'Your checkout attempt was flagged by our security systems. Please contact support.';
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
            );

            $fraudService->recordCheckout($user, $request->ip());

            // Order placed — the cart is converted, so drop the ephemeral
            // "Buy now" tag too (its item ids are now stale).
            $request->session()->forget('cart.buy_now_items');
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
     * Order confirmation page. Scoped to the owning user so order numbers
     * can't be enumerated by other customers.
     */
    public function order(Request $request, string $orderNumber)
    {
        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->where('user_id', $request->user()?->id)
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
        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->where('user_id', $request->user()?->id)
            ->firstOrFail();

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
        // hand-craft a URL pointing at someone else's session.
        abort_unless($attempt->user_id === $request->user()?->id, 403);

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
        return Order::query()
            ->where('order_number', $orderNumber)
            ->where('user_id', $request->user()?->id)
            ->with('items')
            ->firstOrFail();
    }
}
