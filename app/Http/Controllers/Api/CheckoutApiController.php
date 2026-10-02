<?php

namespace App\Http\Controllers\Api;

use App\Domain\Kyc\Exceptions\KycStepUpRequiredException;
use App\Domain\Kyc\Exceptions\SpendingLimitExceededException;
use App\Domain\Order\Services\CheckoutService;
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
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentSessionResource;
use App\Models\Cart;
use App\Models\Order;
use App\Rules\ChargeableCurrency;
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

        // Upper-cased before validation so the rate-table lookup below matches
        // the row however the client spelt the code.
        foreach (['preferred_currency', 'wallet_currency'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => strtoupper(trim($request->input($field)))]);
            }
        }

        $validated = $request->validate([
            'cart_id' => ['required', 'uuid'],
            'payment_method' => ['required', 'string', Rule::in(['wallet', 'flutterwave', 'crypto'])],
            // A currency with a live, active rate, exactly as the web form
            // requires. The rate lookup behind checkout used to answer an
            // unknown code with the USD row, so "5.00 ZZZ" became a real order.
            'preferred_currency' => ['required', 'string', 'size:3', new ChargeableCurrency],
            'delivery_email' => ['nullable', 'email'],
            'wallet_currency' => ['nullable', 'string', 'size:3'],
        ]);

        // The method must be one this currency can take, the same check the web
        // form makes. 'flutterwave' is the fiat rail and the specific method is
        // chosen at the pay step, where card is the one every currency accepts.
        $methodKey = $validated['payment_method'] === 'flutterwave' ? 'card' : $validated['payment_method'];

        if (! SupportedPaymentMethods::allows($validated['preferred_currency'], $methodKey)) {
            return response()->json([
                'message' => "The selected payment method is not available for {$validated['preferred_currency']}.",
            ], 422);
        }

        // Only the caller's own active cart, the one the web checkout would
        // resolve for them. A bare findOrFail on the id let any signed-in
        // customer place an order against someone else's cart, and the old
        // `exists` rule confirmed to anyone which cart ids were real.
        $cart = Cart::with(['items.product', 'items.variant'])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->find($validated['cart_id']);

        abort_if($cart === null, 404, 'Cart not found.');

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
        } catch (RateUnavailableException $e) {
            // No usable rate for the charge currency (too old, or none at all).
            // Nothing was written; one customer sentence rather than the pair
            // and age the stale exception carries for the log.
            return response()->json([
                'message' => 'We cannot price your order in '.$validated['preferred_currency'].' right now. Please try again shortly or choose another currency.',
            ], 422);
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
