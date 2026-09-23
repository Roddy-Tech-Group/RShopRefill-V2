<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Domain\Cart\Services\CartManager;
use App\Domain\Cart\Services\CartMergeService;
use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function __construct(
        private CartManager $cartManager,
        private CartMergeService $mergeService
    ) {}

    /**
     * The storefront API authenticates the web session (there is no token
     * guard); asking a non-existent `sanctum` guard here always answered
     * null, so a signed-in shopper was silently treated as a guest.
     */
    private function resolveCart(Request $request)
    {
        $userId = $request->user()?->id;
        $guestToken = $request->header('X-Guest-Token');

        return $this->cartManager->resolveCart($userId, $guestToken);
    }

    public function show(Request $request)
    {
        $cart = $this->resolveCart($request);
        $cart->load('items.product', 'items.variant');

        return new CartResource($cart);
    }

    public function addItem(Request $request)
    {
        $request->validate([
            'product_variant_id' => ['required', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'requested_value' => ['nullable', 'numeric', 'min:0.01'],
            'metadata' => ['nullable', 'array'],
            // Top-up recipient: digits-only after normalisation, no longer
            // than E.164's 15 digit upper bound. Stored on cart + order item.
            'metadata.recipient_phone' => ['nullable', 'string', 'regex:/^\+?[0-9 \-]{6,20}$/'],
            'metadata.sender_phone' => ['nullable', 'string', 'regex:/^\+?[0-9 \-]{6,20}$/'],
            'metadata.delivery_email' => ['nullable', 'email'],
            'metadata.account_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9 \-]{4,30}$/'],
        ]);

        $cart = $this->resolveCart($request);
        $variant = ProductVariant::findOrFail($request->product_variant_id);

        // An offer the supplier needs an account number or phone number to
        // deliver cannot go in the cart without it; refuse it here rather
        // than after payment.
        if ($missing = $variant->missingPurchaseFields((array) $request->input('metadata', []), $request->user())) {
            throw ValidationException::withMessages($missing);
        }

        $this->cartManager->addItem(
            $cart,
            $variant,
            (int) $request->quantity,
            $request->requested_value ? (float) $request->requested_value : null,
            $request->input('metadata') ?: null,
        );

        $cart->load('items.product', 'items.variant');

        return new CartResource($cart);
    }

    public function updateItem(Request $request, string $itemId)
    {
        $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $cart = $this->resolveCart($request);

        $this->cartManager->updateQuantity($cart, $itemId, (int) $request->quantity);

        $cart->load('items.product', 'items.variant');

        return new CartResource($cart);
    }

    public function removeItem(Request $request, string $itemId)
    {
        $cart = $this->resolveCart($request);
        $this->cartManager->removeItem($cart, $itemId);

        return response()->noContent();
    }

    public function clear(Request $request)
    {
        $cart = $this->resolveCart($request);
        $this->cartManager->clearCart($cart);

        return response()->noContent();
    }

    public function merge(Request $request)
    {
        $request->validate([
            'guest_token' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $cart = $this->mergeService->mergeGuestCart($request->guest_token, $user);

        if (! $cart) {
            // If they didn't have a guest cart and didn't have a user cart, resolve an empty one
            $cart = $this->cartManager->resolveCart($user->id);
        }

        $cart->load('items.product', 'items.variant');

        return new CartResource($cart);
    }
}
