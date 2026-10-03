<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Order\Services\CheckoutService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\PlaceOrderRequest;
use App\Http\Resources\V2\OrderResource;
use App\Http\Resources\V2\PaymentSessionResource;
use App\Models\Cart;
use App\Support\ApiV2Error;
use App\Support\ApiV2PaymentFailures;
use App\Support\FeatureFlag;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turning a cart into an order.
 *
 * This is the endpoint that creates money, so three things about it are deliberate.
 *
 * It calls CheckoutService::placeOrder, the SAME service the website charges through.
 * Not a copy of it, and not a simplified version: the gateway kill switch, the
 * category eligibility gate, the risk engine, the spending caps, the coupon
 * redemption and the Rcoin arithmetic all live in there and all still apply. If the
 * app could get a cheaper or looser path to an order than a browser can, the app
 * would be the hole.
 *
 * It sits behind the idempotency guard, which is the point of Phase 1. The customer
 * taps Pay on mobile data, we create the order and take the money, and the reply is
 * lost in a tunnel. The retry that follows gets this order back rather than a second
 * one.
 *
 * And it resolves the cart by the id the app sends, scoped to the caller. Inferring
 * "their active cart" would let a second device's change alter what gets charged
 * between the review screen and the tap.
 */
class CheckoutController extends Controller
{
    public function __construct(private CheckoutService $checkout) {}

    public function placeOrder(PlaceOrderRequest $request): JsonResponse
    {
        // The admin kill switch, answered before validation of anything else:
        // "checkout is off" must not depend on having a valid cart.
        if (! FeatureFlag::on('checkout')) {
            return ApiV2Error::make(
                'checkout_unavailable',
                'Checkout is temporarily unavailable. Please try again shortly.',
                503,
            );
        }

        $user = $request->user();

        // The caller's OWN active cart. A bare lookup by id would let any signed-in
        // customer place an order against somebody else's basket, and an `exists`
        // rule would confirm to anyone which cart ids are real.
        $cart = Cart::with(['items.product', 'items.variant'])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->find($request->input('cart_id'));

        if ($cart === null) {
            return ApiV2Error::make('cart_not_found', 'That basket is no longer available.', 404);
        }

        if ($cart->items->isEmpty()) {
            return ApiV2Error::make('cart_empty', 'There is nothing in your basket.', 422);
        }

        try {
            $order = $this->checkout->placeOrder(
                user: $user,
                cart: $cart,
                paymentMethod: (string) $request->input('payment_method'),
                displayCurrency: strtoupper((string) $request->input('currency')),
                deliveryEmail: $request->input('delivery_email'),
                applyRcoin: $request->boolean('apply_rcoin'),
                couponCode: $request->input('coupon_code'),
                walletCurrency: $request->input('wallet_currency'),
            );
        } catch (Throwable $e) {
            $envelope = ApiV2PaymentFailures::toEnvelope($e);

            if ($envelope !== null) {
                return $envelope;
            }

            // Something nobody anticipated. Logged with the cart so it can be traced,
            // and answered without leaking the exception: a 5xx also RELEASES the
            // idempotency key, so the customer can try again rather than being stuck
            // on that intent for a day.
            Log::error('api.v2.checkout_failed', [
                'user_id' => $user->id,
                'cart_id' => $cart->id,
                'payment_method' => $request->input('payment_method'),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        $order->load(['items', 'paymentAttempts.paymentSession']);

        $session = $order->paymentAttempts
            ->sortByDesc('created_at')
            ->first()
            ?->paymentSession;

        return response()->json([
            'data' => [
                'order' => (new OrderResource($order))->resolve(),
                // How to pay. Null only for a method that needs no session at all,
                // which the app should treat as "already settled, refresh the order".
                'payment' => $session !== null
                    ? (new PaymentSessionResource($session->loadMissing('paymentAttempt.order')))->resolve()
                    : null,
            ],
        ], 201);
    }
}
