<?php

namespace App\Http\Controllers;

use App\Domain\Cart\Services\CartManager;
use App\Domain\Cart\Services\CartPricingService;
use App\Domain\Wallet\Exceptions\StaleRateException;
use App\Domain\Wallet\Services\CurrencyRateService;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\FeatureFlag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Storefront-facing cart endpoints.
 *
 * The backend's API CartController is stateless (guest-token only) — a logged-in
 * web customer's session cookie won't authenticate it without Sanctum SPA config.
 * These WEB routes carry the session cookie, so the cart ties to the user_id for
 * authed customers and to a `guest_token` cookie for guests. Both paths delegate
 * to the same backend CartManager service; only the auth/transport differs.
 *
 * Responses are a compact JSON shape the storefront's Alpine cart store consumes.
 */
class CartWebController extends Controller
{
    public function __construct(
        private CartManager $cartManager,
        private CartPricingService $pricing,
        private CurrencyRateService $currencyRateService,
    ) {}

    /**
     * The cart page (HTML). Store-driven — the Alpine cart store hydrates it
     * from the /cart/data JSON endpoint, so no data is passed server-side.
     */
    public function page()
    {
        return view('shop.cart');
    }

    public function show(Request $request)
    {
        [$cart, $token] = $this->resolve($request);

        // Ephemeral "Buy now" items only live during a checkout visit. Any time
        // the cart is read from somewhere that ISN'T checkout (the JS store sends
        // ?ctx=checkout only on checkout pages), purge them so an abandoned Buy
        // now never lingers in the persistent cart.
        if ($request->query('ctx') !== 'checkout') {
            $buyNowIds = (array) $request->session()->pull('cart.buy_now_items', []);
            if ($buyNowIds) {
                $cart->items()->whereIn('id', $buyNowIds)->delete();
            }
        }

        return $this->respond($cart, $token, $request->query('currency'));
    }

    public function add(Request $request)
    {
        // features.guest_cart_enabled kill-switch. Off = guests get a 401
        // and must sign in before adding to cart. Authed users always pass.
        if (! $request->user() && ! FeatureFlag::on('guest_cart')) {
            return response()->json([
                'message' => 'Please sign in to add items to your cart.',
                'auth_required' => true,
            ], 401);
        }

        $data = $request->validate([
            'product_variant_id' => ['required', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'requested_value' => ['nullable', 'numeric', 'min:0.01'],
            'metadata' => ['nullable', 'array'],
            // Top-up recipient phone. Stored on cart_items.metadata_snapshot
            // and copied onto order_items.metadata at checkout so the Zendit
            // top-up fulfilment provider can read it.
            'metadata.recipient_phone' => ['nullable', 'string', 'regex:/^\+?[0-9 \-]{6,20}$/'],
            'metadata.delivery_email' => ['nullable', 'email'],
            // Bill payment account / meter ID. Alphanumeric + common separators,
            // 4-30 chars (covers electricity meters, DSTV smartcards, etc.).
            'metadata.account_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9 \-]{4,30}$/'],
            // "Buy now" adds: tagged ephemeral so they don't linger if the
            // shopper abandons checkout (see show()).
            'buy_now' => ['nullable', 'boolean'],
        ]);

        [$cart, $token] = $this->resolve($request);
        $variant = ProductVariant::findOrFail($data['product_variant_id']);

        $item = $this->cartManager->addItem(
            $cart,
            $variant,
            (int) $data['quantity'],
            isset($data['requested_value']) ? (float) $data['requested_value'] : null,
            $data['metadata'] ?? null,
        );

        if ($request->boolean('buy_now')) {
            $ids = (array) $request->session()->get('cart.buy_now_items', []);
            $ids[] = $item->id;
            $request->session()->put('cart.buy_now_items', array_values(array_unique($ids)));
        }

        return $this->respond($cart, $token, $request->query('currency'));
    }

    public function update(Request $request, string $item)
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
        ]);

        [$cart, $token] = $this->resolve($request);
        $this->cartManager->updateQuantity($cart, $item, (int) $data['quantity']);

        return $this->respond($cart, $token, $request->query('currency'));
    }

    public function remove(Request $request, string $item)
    {
        [$cart, $token] = $this->resolve($request);
        $this->cartManager->removeItem($cart, $item);

        return $this->respond($cart, $token, $request->query('currency'));
    }

    /**
     * Resolve the active cart for this request. Authed users key by user_id;
     * guests key by a `guest_token` cookie (minted here if absent).
     *
     * @return array{0: Cart, 1: string|null} [cart, guestTokenToSet]
     */
    private function resolve(Request $request): array
    {
        $userId = auth()->id();
        $token = $request->cookie('guest_token');

        if (! $userId && ! $token) {
            $token = (string) Str::uuid();
        }

        $cart = $this->cartManager->resolveCart($userId, $userId ? null : $token);

        return [$cart, $userId ? null : $token];
    }

    /**
     * Compact JSON the cart store + popup render from. Queues the guest_token
     * cookie when the request belongs to a guest.
     *
     * Pricing is two-layered: the settlement figures (*_usd) are the marked-up USD
     * prices snapshotted on the CartItem; the display figures are those converted
     * into the customer's chosen currency via the live CurrencyRate. USD is the
     * base — every currency, USD included, is multiplied by its rate_per_usd.
     */
    private function respond(Cart $cart, ?string $guestToken, ?string $currencyCode)
    {
        $cart->load('items.product.category', 'items.variant');
        $totals = $this->pricing->calculateCartTotals($cart->items);

        // Route the conversion through CurrencyRateService so the same freshness
        // gate that protects checkout protects the cart display too. If the rate
        // is critically stale (> 48h, StaleRateException) we degrade to raw USD
        // and flag the response so the frontend can show a "prices in USD -
        // rates refreshing" banner, instead of silently showing the customer a
        // stale price they could then check out at.
        $resolvedCurrency = strtoupper(trim((string) $currencyCode)) ?: 'USD';
        $rateStale = false;
        try {
            $exchangeRate = $this->currencyRateService->resolveRate('USD', $resolvedCurrency);
        } catch (StaleRateException $e) {
            Log::warning('Cart fell back to USD display due to critically stale rate.', [
                'requested_currency' => $resolvedCurrency,
                'error' => $e->getMessage(),
            ]);
            $resolvedCurrency = 'USD';
            $exchangeRate = 1.0;
            $rateStale = true;
        }

        // ISO -> country name, for human labels next to flags ("US" reads as
        // "United States", "WW" as "Global").
        $countryNames = array_flip(config('countries.codes', []));

        $items = $cart->items->map(function ($item) use ($exchangeRate, $countryNames) {
            $product = $item->product;
            $variant = $item->variant;
            // eSIM products carry no brand_key, so brandDisplayName comes back
            // empty - fall through to the product name ("US Data eSIM").
            $name = $product
                ? (Product::brandDisplayName($product->brand_key) ?: $product->name)
                : ($item->metadata_snapshot['product_name'] ?? 'Item');

            $unitUsd = (float) $item->unit_price_snapshot;
            $lineUsd = (float) $item->subtotal_snapshot;

            $countryCode = strtoupper((string) ($product?->country_code ?? ''));
            $isGlobal = $countryCode === 'WW';

            return [
                'id' => $item->id,
                'name' => $name,
                'country' => $product?->country_code,
                'country_name' => $isGlobal ? 'Global' : ($countryNames[$countryCode] ?? $product?->country_code),
                // Tile fallback when the product has no logo (eSIMs): the
                // country flag, or a globe for worldwide coverage.
                'flag' => $isGlobal ? null : Product::flagUrl($countryCode),
                'is_global' => $isGlobal,
                'logo' => $product ? Product::brandLogoUrl($product->brand_key, $product->logo_url) : null,
                'face_label' => $this->faceLabel($variant),
                'quantity' => (int) $item->quantity,
                'unit_price_usd' => round($unitUsd, 2),
                'unit_price' => round($unitUsd * $exchangeRate, 2),
                'line_total_usd' => round($lineUsd, 2),
                'line_total' => round($lineUsd * $exchangeRate, 2),
                // Category drives per-item copy on the cart + checkout pages
                // (gift-card region notice, top-up phone hint, eSIM QR hint).
                'category_slug' => $product?->category?->slug,
                'recipient_phone' => $item->metadata_snapshot['recipient_phone'] ?? null,
            ];
        })->values();

        $subtotalUsd = (float) ($totals['subtotal'] ?? 0);

        $response = response()->json([
            'count' => (int) $cart->items->sum('quantity'),
            'currency' => $resolvedCurrency,
            'currency_symbol' => Product::currencySymbol($resolvedCurrency),
            'rate' => $exchangeRate,
            'rate_stale' => $rateStale,
            'subtotal_usd' => round($subtotalUsd, 2),
            'subtotal' => round($subtotalUsd * $exchangeRate, 2),
            'estimated_rcoin_reward' => (int) ($totals['estimated_rcoin_reward'] ?? 0),
            'items' => $items,
        ]);

        if ($guestToken) {
            $response->cookie('guest_token', $guestToken, 60 * 24 * 30); // 30 days
        }

        return $response;
    }

    /**
     * The card's native face value as a short label, e.g. "£10". Null for
     * variable-amount cards (no fixed face value) so the UI can omit it.
     */
    private function faceLabel(?ProductVariant $variant): ?string
    {
        if (! $variant || $variant->face_value === null) {
            return null;
        }

        $value = (float) $variant->face_value;
        $valueStr = $value === floor($value)
            ? (string) (int) $value
            : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return Product::currencySymbol($variant->currency ?: 'USD').$valueStr;
    }
}
