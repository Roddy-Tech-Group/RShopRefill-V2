<?php

namespace App\Http\Controllers\Api;

use App\Domain\Order\Services\CheckoutService;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\MissingTransactionPinException;
use App\Domain\Wallet\Exceptions\WalletOnHoldException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentSessionResource;
use App\Models\Cart;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CheckoutApiController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService
    ) {}

    public function placeOrder(Request $request)
    {
        $validated = $request->validate([
            'cart_id' => ['required', 'uuid', 'exists:carts,id'],
            'payment_method' => ['required', 'string', Rule::in(['wallet', 'flutterwave', 'crypto'])],
            'preferred_currency' => ['required', 'string', 'size:3'],
            'delivery_email' => ['nullable', 'email'],
        ]);

        $cart = Cart::with(['items.product', 'items.variant'])->findOrFail($validated['cart_id']);

        try {
            $order = $this->checkoutService->placeOrder(
                user: $request->user(),
                cart: $cart,
                paymentMethod: $validated['payment_method'],
                displayCurrency: strtoupper($validated['preferred_currency']),
                deliveryEmail: $validated['delivery_email'] ?? null,
                applyRcoin: $request->boolean('apply_rcoin')
            );

            $order->load(['items', 'paymentAttempts', 'paymentAttempts.paymentSession']);
            $latestAttempt = $order->paymentAttempts->sortByDesc('created_at')->first();
            $paymentSession = $latestAttempt?->paymentSession;

            return response()->json([
                'message' => 'Order placed successfully.',
                'order' => new OrderResource($order),
                'payment_session' => $paymentSession ? new PaymentSessionResource($paymentSession) : null,
            ], 201);
        } catch (WalletOnHoldException|InsufficientBalanceException|MissingTransactionPinException $e) {
            // Friendly wallet errors carry a customer-ready message — return
            // it verbatim instead of prefixing "Checkout failed:".
            return response()->json(['message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('SILENT CHECKOUT ERROR: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
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
