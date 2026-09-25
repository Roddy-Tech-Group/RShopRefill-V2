<?php

namespace App\Http\Controllers\Api;

use App\Domain\Kyc\Exceptions\KycStepUpRequiredException;
use App\Domain\Kyc\Exceptions\SpendingLimitExceededException;
use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Exceptions\GatewayDisabledException;
use App\Domain\Payment\Exceptions\PaymentCategoryIneligibleException;
use App\Domain\PaymentInsights\Exceptions\PaymentBackoffException;
use App\Domain\Risk\Exceptions\RiskDeniedException;
use App\Domain\Risk\Exceptions\RiskReviewRequiredException;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\MissingTransactionPinException;
use App\Domain\Wallet\Exceptions\WalletOnHoldException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentSessionResource;
use App\Models\Cart;
use App\Models\Order;
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
        // Same gate set as CheckoutController::process. This controller used to
        // skip these, so the admin checkout kill switch and the fraud guard were
        // enforced on the web form and silently absent on the API route to the
        // same service. (Email verification is now enforced for both by the
        // EnsureEmailVerified route middleware, before this method runs.) Guards
        // must live wherever placeOrder is reachable, and the kill switch answers
        // before validation: "checkout is off" must not depend on a valid cart.
        abort_if(! FeatureFlag::on('checkout'), 503, 'Checkout is temporarily unavailable.');

        $user = $request->user();

        $validated = $request->validate([
            'cart_id' => ['required', 'uuid', 'exists:carts,id'],
            'payment_method' => ['required', 'string', Rule::in(['wallet', 'flutterwave', 'crypto'])],
            'preferred_currency' => ['required', 'string', 'size:3'],
            'delivery_email' => ['nullable', 'email'],
            'wallet_currency' => ['nullable', 'string', 'size:3'],
        ]);

        $cart = Cart::with(['items.product', 'items.variant'])->findOrFail($validated['cart_id']);

        try {
            $order = $this->checkoutService->placeOrder(
                user: $user,
                cart: $cart,
                paymentMethod: $validated['payment_method'],
                displayCurrency: strtoupper($validated['preferred_currency']),
                deliveryEmail: $validated['delivery_email'] ?? null,
                applyRcoin: $request->boolean('apply_rcoin'),
                walletCurrency: $validated['wallet_currency'] ?? null,
            );

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
        } catch (PaymentCategoryIneligibleException $e) {
            // A cart category is not approved on the resolved gateway. The gate
            // runs before any write, so there is nothing to clean up.
            return response()->json([
                'message' => $e->customerMessage(),
                'category_ineligible' => true,
            ], 422);
        } catch (PaymentBackoffException $e) {
            // 429 with the wait and whether it is a lock, not a generic 400.
            // Nothing was written, so the client is safe to retry once the
            // window passes. The exception renders the one shared payload.
            return $e->render($request);
        } catch (WalletOnHoldException|InsufficientBalanceException|MissingTransactionPinException $e) {
            // Friendly wallet errors carry a customer-ready message — return
            // it verbatim instead of prefixing "Checkout failed:".
            return response()->json(['message' => $e->getMessage()], 400);
        } catch (KycStepUpRequiredException $e) {
            // Crossed the identity-verification threshold; hand back the Smile
            // session so the client can launch verification, then retry.
            return response()->json($e->toResponse(), 422);
        } catch (SpendingLimitExceededException $e) {
            // Rolling spending cap reached; the message nudges unverified
            // customers toward KYC, which raises the cap.
            return response()->json($e->toResponse(), 422);
        } catch (RiskReviewRequiredException $e) {
            // Held for manual review; neutral message, nothing written.
            return response()->json($e->toResponse(), 422);
        } catch (RiskDeniedException $e) {
            // Refused by the risk engine; customer-safe message, no reason codes.
            return response()->json($e->toResponse(), 403);
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
