<?php

namespace App\Http\Controllers\Api;

use App\Domain\Fraud\Services\FraudDetectionService;
use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Exceptions\GatewayDisabledException;
use App\Domain\PaymentInsights\Exceptions\PaymentBackoffException;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\MissingTransactionPinException;
use App\Domain\Wallet\Exceptions\WalletOnHoldException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentSessionResource;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Setting;
use App\Support\FeatureFlag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CheckoutApiController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService
    ) {}

    public function placeOrder(Request $request)
    {
        // Same gate set as CheckoutController::process. This controller used
        // to skip all three, so the admin checkout kill switch, the
        // email-verification requirement and the fraud guard were enforced on
        // the web form and silently absent on the API route to the same
        // service. Guards must live wherever placeOrder is reachable, and the
        // kill switch answers before validation: "checkout is off" must not
        // depend on the caller supplying a valid cart first.
        abort_if(! FeatureFlag::on('checkout'), 503, 'Checkout is temporarily unavailable.');

        $user = $request->user();

        if (Setting::get('require_email_verified_for_checkout', false) && ! $user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Please verify your email address before checking out.'], 403);
        }

        $validated = $request->validate([
            'cart_id' => ['required', 'uuid', 'exists:carts,id'],
            'payment_method' => ['required', 'string', Rule::in(['wallet', 'flutterwave', 'crypto'])],
            'preferred_currency' => ['required', 'string', 'size:3'],
            'delivery_email' => ['nullable', 'email'],
        ]);

        $cart = Cart::with(['items.product', 'items.variant'])->findOrFail($validated['cart_id']);

        $fraudService = app(FraudDetectionService::class);
        $cartAmount = (float) $cart->items->sum('subtotal_snapshot');

        if ($fraudService->isSuspiciousCheckout($user, $cartAmount, $request->ip())) {
            return response()->json([
                'message' => $user->isKycVerified()
                    ? 'Your checkout attempt was flagged by our security systems. Please contact support.'
                    : 'Your checkout attempt was flagged by our security systems. Please complete KYC verification to proceed.',
            ], 403);
        }

        try {
            $order = $this->checkoutService->placeOrder(
                user: $user,
                cart: $cart,
                paymentMethod: $validated['payment_method'],
                displayCurrency: strtoupper($validated['preferred_currency']),
                deliveryEmail: $validated['delivery_email'] ?? null,
                applyRcoin: $request->boolean('apply_rcoin')
            );

            if ($order->wasRecentlyCreated) {
                $fraudService->recordCheckout($user, $request->ip());
            }

            $order->load(['items', 'paymentAttempts', 'paymentAttempts.paymentSession']);
            $latestAttempt = $order->paymentAttempts->sortByDesc('created_at')->first();
            $paymentSession = $latestAttempt?->paymentSession;

            return response()->json([
                'message' => 'Order placed successfully.',
                'order' => new OrderResource($order),
                'payment_session' => $paymentSession ? new PaymentSessionResource($paymentSession) : null,
            ], 201);
        } catch (GatewayDisabledException $e) {
            return response()->json([
                'message' => $e->customerMessage(),
                'gateway_disabled' => true,
            ], 503);
        } catch (PaymentBackoffException $e) {
            // 429 with the wait, not a generic 400. Nothing was written, so the
            // client is safe to retry once the window passes.
            return response()->json([
                'message' => $e->customerMessage(),
                'is_rate_limited' => true,
                'wait_seconds' => $e->waitSeconds,
            ], 429);
        } catch (WalletOnHoldException|InsufficientBalanceException|MissingTransactionPinException $e) {
            // Friendly wallet errors carry a customer-ready message — return
            // it verbatim instead of prefixing "Checkout failed:".
            return response()->json(['message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('SILENT CHECKOUT ERROR: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'message' => 'Checkout failed: '.$e->getMessage(),
            ], 400);
        }
    }

    public function index(Request $request)
    {
        $orders = Order::with(['items', 'paymentAttempts'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(15);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, string $orderNumber)
    {
        $order = Order::with(['items', 'paymentAttempts'])
            ->where('user_id', $request->user()->id)
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        return new OrderResource($order);
    }
}
