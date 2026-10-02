<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Cart\Services\CartManager;
use App\Domain\Catalog\Services\ApiCatalogPricer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\AddCartItemRequest;
use App\Http\Requests\Api\V2\UpdateCartItemRequest;
use App\Http\Resources\V2\CartResource;
use App\Models\Cart;
use App\Models\ProductVariant;
use App\Support\ApiV2Error;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The basket.
 *
 * SIGNED IN ONLY, unlike the storefront's cart, which lets a visitor fill one
 * before creating an account. That difference is deliberate: guest checkout was
 * removed, so a guest cart on the app could only ever be a dead end, and an app
 * has a sign-in screen at the front anyway. Fewer states, no merge endpoint, no
 * guest token to leak.
 *
 * Everything goes through CartManager, which is what the website uses. Cart
 * pricing, deduplication of the same variant, quantity ceilings and the
 * requested-amount bookkeeping all live there, and reimplementing any of it here
 * would be a second version of the arithmetic that eventually disagrees.
 */
class CartController extends Controller
{
    public function __construct(
        private CartManager $cartManager,
        private ApiCatalogPricer $pricer,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return $this->respond($this->cart($request));
    }

    public function addItem(AddCartItemRequest $request): JsonResponse
    {
        $variant = ProductVariant::with('product')->findOrFail($request->integer('variant_id'));

        $requestedUsd = $this->requestedUsd($request, $variant);

        if ($requestedUsd instanceof JsonResponse) {
            return $requestedUsd;
        }

        // ONLY the validated metadata keys. CartManager merges whatever it is given
        // into the line's snapshot and checkout copies that onto the order, so an
        // unvalidated key would let the app write our own bookkeeping fields (the
        // requested native amount among them) and have them honoured at fulfilment.
        $metadata = array_filter(
            (array) ($request->validated()['metadata'] ?? []),
            fn ($value) => $value !== null && $value !== '',
        );

        // An offer the supplier needs an account number or phone number to deliver
        // cannot go in the cart without it. Refused here rather than after payment,
        // which is the difference between a validation message and a refund.
        $missing = $variant->missingPurchaseFields($metadata, $request->user());

        if ($missing) {
            throw ValidationException::withMessages($missing);
        }

        // Throws ValidationException for an unavailable variant, a disabled
        // category, or an amount outside the variant's range. The v2 renderer
        // turns those into a 422 naming the field.
        $this->cartManager->addItem(
            $this->cart($request),
            $variant,
            $request->integer('quantity'),
            $requestedUsd,
            $metadata !== [] ? $metadata : null,
        );

        return $this->respond($this->cart($request), 201);
    }

    public function updateItem(UpdateCartItemRequest $request, string $item): JsonResponse
    {
        $cart = $this->cart($request);

        // Scoped to this customer's own cart. Looking the item up by id alone would
        // let anyone edit a line in somebody else's basket.
        if (! $cart->items()->whereKey($item)->exists()) {
            return $this->itemGone();
        }

        $this->cartManager->updateQuantity($cart, $item, $request->integer('quantity'));

        return $this->respond($this->cart($request));
    }

    public function removeItem(Request $request, string $item): JsonResponse
    {
        $cart = $this->cart($request);

        if (! $cart->items()->whereKey($item)->exists()) {
            return $this->itemGone();
        }

        $this->cartManager->removeItem($cart, $item);

        return $this->respond($this->cart($request));
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $this->cart($request);

        $this->cartManager->clearCart($cart);

        return $this->respond($this->cart($request));
    }

    /**
     * The typed amount converted to the settlement currency, or a refusal.
     *
     * The app sends what the customer typed, in the variant's own currency.
     * CartManager expects USD. One conversion, here, using the same rate the quote
     * endpoint used, so a quote and an add-to-cart for the same amount agree.
     */
    private function requestedUsd(AddCartItemRequest $request, ProductVariant $variant): float|JsonResponse|null
    {
        $amount = $request->input('amount');

        if (! $variant->is_variable) {
            if ($amount !== null) {
                // Silently ignoring it would let the app believe it had set a price
                // it had not. A fixed denomination's amount is the denomination.
                return ApiV2Error::make(
                    'amount_not_accepted',
                    'That option has a fixed amount.',
                    422,
                );
            }

            return null;
        }

        if ($amount === null) {
            throw ValidationException::withMessages([
                'amount' => 'Please enter an amount for this item.',
            ]);
        }

        return (float) $amount / $this->pricer->nativeUnitsPerUsd($variant);
    }

    private function cart(Request $request): Cart
    {
        return $this->cartManager
            ->resolveCart($request->user()->id)
            ->load(['items.product', 'items.variant']);
    }

    private function respond(Cart $cart, int $status = 200): JsonResponse
    {
        return response()->json(['data' => (new CartResource($cart))->resolve()], $status);
    }

    /**
     * Same answer for a line that never existed and one belonging to somebody else,
     * so the endpoint cannot be used to discover other people's cart ids.
     */
    private function itemGone(): JsonResponse
    {
        return ApiV2Error::make('cart_item_not_found', 'That item is no longer in your cart.', 404);
    }
}
