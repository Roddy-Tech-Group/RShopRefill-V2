<?php

namespace App\Http\View\Composers;

use App\Domain\Cart\Services\CartManager;
use App\Domain\Cart\Services\CartPricingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class CartComposer
{
    public function __construct(
        protected CartManager $cartManager,
        protected CartPricingService $pricingService
    ) {}

    /**
     * Bind data to the view.
     */
    public function compose(View $view): void
    {
        /*
         * Once per request, memoized on the request instance - NOT once per
         * view: this composer is bound to '*', so it runs for every view
         * rendered, and a listing page renders ~1,800 of them. Resolving and
         * pricing the cart that many times was thousands of queries per page
         * for anyone signed in (or carrying a guest-cart cookie). The cart
         * cannot change in the middle of a render, so the first resolution
         * serves them all.
         */
        $attributes = request()->attributes;

        if (! $attributes->has('memo:cart_composer')) {
            // Resolve the cart based on session or guest token cookie
            $userId = Auth::id();
            $guestToken = request()->cookie('guest_token') ?? request()->header('X-Guest-Token');

            $cartCount = 0;
            $cartSubtotal = 0;
            $cartItems = collect();

            if ($userId || $guestToken) {
                try {
                    $cart = $this->cartManager->resolveCart($userId, $guestToken);
                    if ($cart && $cart->items->isNotEmpty()) {
                        $cart->load('items.product', 'items.variant');
                        $cartCount = $cart->items->sum('quantity');
                        $totals = $this->pricingService->calculateCartTotals($cart->items);
                        $cartSubtotal = $totals['subtotal'];
                        $cartItems = $cart->items;
                    }
                } catch (\Exception $e) {
                    // Ignore resolution errors for view rendering (e.g. invalid token)
                }
            }

            $attributes->set('memo:cart_composer', [$cartCount, $cartSubtotal, $cartItems]);
        }

        [$cartCount, $cartSubtotal, $cartItems] = $attributes->get('memo:cart_composer');

        $view->with([
            'cartCount' => $cartCount,
            'cartSubtotal' => $cartSubtotal,
            'cartItems' => $cartItems,
        ]);
    }
}
