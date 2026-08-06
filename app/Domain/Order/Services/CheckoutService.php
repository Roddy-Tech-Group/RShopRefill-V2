<?php

namespace App\Domain\Order\Services;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\OrderPlaced;
use App\Domain\Order\Exceptions\InvalidCouponException;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\WalletPaymentProvider;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Payment\Support\PaymentMethodNormaliser;
use App\Domain\PaymentInsights\Enums\ProductLine;
use App\Domain\Rewards\Services\RewardEngine;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Services\CurrencyRateService;
use App\Domain\Wallet\Services\WalletService;
use App\Jobs\FulfillOrderItemJob;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckoutService
{
    public function __construct(
        private readonly OrderValidationService $orderValidationService,
        private readonly PaymentGatewayFactory $paymentGatewayFactory,
        private readonly WalletPaymentProvider $walletPaymentProvider,
        private readonly PaymentSessionService $paymentSessionService,
        private readonly CurrencyRateService $currencyRateService,
        private readonly CouponApplicationService $couponApplicationService,
        private readonly CouponRedemptionService $couponRedemptionService,
    ) {}

    /**
     * Coordinate the checkout and order placement process.
     * Enforces row locking and atomic persistence.
     *
     * @param  string  $paymentMethod  The gateway that will process this order
     *                                 (wallet / crypto / flutterwave).
     * @param  string|null  $selectedMethod  The method the customer actually
     *                                       picked (card, ussd, bank_transfer…).
     *                                       Flutterwave fronts eight of these, so
     *                                       the gateway alone cannot identify it.
     *                                       Falls back to the gateway, which is
     *                                       exact for wallet and crypto.
     *
     * @throws \Exception
     */
    /**
     * Session states a customer can still pay from. A checkout parked in one of
     * these, and not yet past its expiry, is worth handing back rather than
     * rebuilding from scratch.
     */
    private const REUSABLE_SESSION_STATUSES = [
        'pending',
        'awaiting_method',
        'awaiting_payment',
        'awaiting_transfer',
        'awaiting_confirmation',
        'awaiting_customer_action',
    ];

    /**
     * How much runway a live session must have left before it is worth handing
     * back instead of minting a fresh checkout.
     */
    private const MINIMUM_REUSABLE_MINUTES = 2;

    public function placeOrder(User $user, Cart $cart, string $paymentMethod, string $displayCurrency, ?string $deliveryEmail = null, bool|string $applyRcoin = false, ?string $couponCode = null, ?string $selectedMethod = null): Order
    {
        // 0. Idempotent re-entry. A customer who taps Pay again on an unchanged
        //    cart while their previous checkout is still live gets that same
        //    checkout back instead of a second order. Repeat taps are the single
        //    biggest source of duplicate rows: each one used to write an order,
        //    N order items, an attempt, a session and a coupon hold, then
        //    immediately cancel the previous one.
        //
        //    Deliberately ahead of the rate limit. Handing back an existing
        //    checkout writes nothing, so throttling it protects nothing and
        //    merely keeps a customer away from a payment screen they already
        //    have (and could reach directly through the pay endpoint anyway).
        //
        //    The fingerprint is what makes this safe: if the cart contents,
        //    currency, coupon, Rcoin choice or payment method changed at all,
        //    it does not match and we fall through to a fresh checkout. Two
        //    carts that merely happen to total the same amount never collide.
        $fingerprint = $this->checkoutFingerprint($cart, $paymentMethod, $selectedMethod, $displayCurrency, $applyRcoin, $couponCode);

        if ($reusable = $this->findReusableCheckout($user, $cart, $fingerprint)) {
            Log::info('checkout.reused_live_session', [
                'order_number' => $reusable->order_number,
                'cart_id' => $cart->id,
            ]);

            return $reusable->load(['paymentAttempts', 'paymentAttempts.paymentSession']);
        }

        // 0b. Rate limit BEFORE anything is written. A throttled checkout must
        //     cost zero rows: this guard used to sit in createForOrder (step 6),
        //     by which point the order, its items, the attempt, the coupon
        //     reservation and OrderPlaced had all committed, so a throttled
        //     customer was left with an order stranded at Pending forever that
        //     no sweeper could see.
        $this->paymentSessionService->assertNotRateLimited($user, ProductLine::Checkout->value);

        // 1. Recalculate and validate cart items/prices (in raw USD)
        $validatedTotals = $this->orderValidationService->validateForCheckout($cart);

        // 1b. Resolve the display currency rate via CurrencyRateService so the
        //     same freshness gate that protects wallet funding protects the order
        //     boundary too. Routing here used to call CurrencyRate::resolve()
        //     directly (legacy fallback table, NO freshness check) which meant a
        //     customer could check out at a 3-week-old rate while accounting
        //     reconciled against the live registry rate. Throws StaleRateException
        //     (caught by the controller) when the live pair is past 48h.
        $exchangeRate = $this->currencyRateService->resolveRate('USD', $displayCurrency);

        // 2. Generate a readable, unique order number
        $orderNumber = 'RSR-'.date('Ymd').'-'.strtoupper(Str::random(6));

        // 2b. Cancel any earlier still-pending order for this cart so retrying the
        //     same cart does not pile up Pending rows for admins. Release their
        //     coupon reservations too: this is a bulk update, so it fires no
        //     model events, and a superseded order that keeps holding a slot is
        //     exactly how repeat checkouts used to eat a promotion alive.
        $supersededOrders = Order::where('cart_id', $cart->id)
            ->where('order_status', OrderStatus::Pending)
            ->get();

        if ($supersededOrders->isNotEmpty()) {
            Order::whereIn('id', $supersededOrders->pluck('id'))
                ->update(['order_status' => OrderStatus::Cancelled]);

            foreach ($supersededOrders as $supersededOrder) {
                $this->couponRedemptionService->releaseForOrder($supersededOrder, 'superseded_checkout');
            }
        }

        // 3. Atomically build Order + snapshot items. The cart is read-locked here
        //    but intentionally NOT deleted yet - see step 7. If the gateway init
        //    in step 5 throws (encryption key, bad currency, network), we want
        //    the cart to stay intact so the customer can retry without losing
        //    their items. Cart deletion now lives at the end, after init succeeds.
        $order = DB::transaction(function () use ($user, $cart, $paymentMethod, $displayCurrency, $validatedTotals, $orderNumber, $deliveryEmail, $exchangeRate, $applyRcoin, $couponCode, $fingerprint) {

            // Re-lock the cart to avoid race conditions
            $lockedCart = Cart::where('id', $cart->id)->lockForUpdate()->firstOrFail();
            $lockedCart->load('items.product', 'items.variant');

            // Coupon redemption. Resolved BEFORE Rcoin so the customer's wallet
            // redemption is calculated against the discounted total. Coupons are
            // per-variant so the matching cart item is what carries the discount.
            $couponId = null;
            $couponCodeApplied = null;
            $couponDiscountUsd = 0.0;
            $couponDiscountClamped = false;
            $couponModel = null;

            if ($couponCode !== null && $couponCode !== '') {
                $normalizedCode = Str::upper(trim($couponCode));
                $couponModel = Coupon::where('code', $normalizedCode)->lockForUpdate()->first();

                if ($couponModel === null) {
                    throw InvalidCouponException::unknown($normalizedCode);
                }
                if (! $couponModel->isRedeemable()) {
                    throw InvalidCouponException::notRedeemable($normalizedCode);
                }

                $matchingItem = $lockedCart->items->firstWhere('product_variant_id', $couponModel->product_variant_id);
                if ($matchingItem === null) {
                    throw InvalidCouponException::notInCart($normalizedCode);
                }

                // Shared discount math (identical to the /coupon/preview
                // endpoint) including the cost-floor clamp in enforce mode.
                $quote = $this->couponApplicationService->discountFor($couponModel, $matchingItem);
                $couponDiscountUsd = $quote['discount_usd'];
                $couponDiscountClamped = $quote['clamped'];

                if ($couponDiscountUsd > 0) {
                    $validatedTotals['total'] = max(0, $validatedTotals['total'] - $couponDiscountUsd);
                    $couponId = $couponModel->id;
                    $couponCodeApplied = $couponModel->code;

                    // Apply discount to the in-memory item so OrderItem captures the discounted price
                    $matchingItem->subtotal_snapshot = max(0, $matchingItem->subtotal_snapshot - $couponDiscountUsd);
                    $matchingItem->display_amount = max(0, $matchingItem->display_amount - $couponDiscountUsd);
                }
            }

            $rcoinApplied = 0;
            $rcoinDiscountUsd = 0.0;

            if ($applyRcoin && Setting::get('redemption_enabled', true)) {
                $rewardEngine = app(RewardEngine::class);
                $walletService = app(WalletService::class);

                $wallet = $walletService->getOrCreateWallet($user, Currency::RCOIN);
                $availableBalance = (int) $wallet->balance;
                $minRedemption = (int) Setting::get('redemption_min_rcoin', 2000);

                if ($availableBalance > 0 && $availableBalance >= $minRedemption) {
                    // "full" mode (passed by the rewards page convert flow) lifts
                    // the redemption_max_percentage cap and lets the customer
                    // settle the entire order with Rcoin if they have enough.
                    // The standard truthy toggle stays capped at the configured max.
                    $isFullMode = $applyRcoin === 'full';

                    if ($isFullMode) {
                        // Cap at the order total - never debit more Rcoin than
                        // the bill, even if the user has a huge balance.
                        $maxRcoinAllowed = $rewardEngine->usdToRcoin($validatedTotals['total']);
                    } else {
                        $maxPct = (float) Setting::get('redemption_max_percentage', 30.0);
                        $maxUsdAllowed = $validatedTotals['total'] * ($maxPct / 100);
                        $maxRcoinAllowed = $rewardEngine->usdToRcoin($maxUsdAllowed);
                    }

                    $rcoinApplied = min($availableBalance, $maxRcoinAllowed);
                    $rcoinDiscountUsd = $rewardEngine->rcoinToUsd($rcoinApplied);

                    // If applied, deduct it from validated totals
                    $validatedTotals['total'] = max(0, $validatedTotals['total'] - $rcoinDiscountUsd);
                }
            }

            // Convert settlement USD totals into the display currency.
            $displaySubtotal = round($validatedTotals['subtotal'] * $exchangeRate, 4);
            $displayMarkup = round($validatedTotals['total_markup'] * $exchangeRate, 4);
            $displayTotal = round($validatedTotals['total'] * $exchangeRate, 4);

            $order = Order::create([
                'user_id' => $user->id,
                'order_number' => $orderNumber,
                'cart_id' => $lockedCart->id,
                'settlement_currency' => $displayCurrency,
                'display_currency' => $displayCurrency,
                'subtotal_amount' => $displaySubtotal,
                'markup_amount' => $displayMarkup,
                'total_amount' => $displayTotal,
                'payment_method' => $paymentMethod,
                'payment_status' => PaymentStatus::Unpaid,
                'fulfillment_status' => FulfillmentStatus::NotStarted,
                'order_status' => OrderStatus::Pending,
                'placed_at' => now(),
                'metadata' => [
                    'delivery_email' => $deliveryEmail ?? $user->email,
                    'device_ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'exchange_rate' => $exchangeRate,
                    'settlement_subtotal_usd' => $validatedTotals['subtotal'],
                    'settlement_total_usd' => $validatedTotals['total'],
                    'rcoin_applied' => $rcoinApplied,
                    'rcoin_discount_usd' => $rcoinDiscountUsd,
                    'coupon_id' => $couponId,
                    'coupon_code' => $couponCodeApplied,
                    'coupon_discount_usd' => $couponDiscountUsd,
                    'coupon_discount_clamped' => $couponDiscountClamped,
                    // Identifies this exact checkout so a repeat Pay tap on an
                    // unchanged cart reuses it rather than duplicating it.
                    'checkout_fingerprint' => $fingerprint,
                ],
            ]);

            // Save order items with absolute snapshots.
            // Explode each cart item into N separate OrderItem rows (one per unit)
            // so every unit gets its own independent fulfillment job and provider
            // API call. Providers like Zendit have no quantity parameter - each
            // API call purchases exactly 1 unit.
            foreach ($lockedCart->items as $item) {
                // display_amount and subtotal_snapshot are already totals (unit × qty),
                // so divide back to get the per-unit figure before applying exchange rate.
                $unitDisplayAmount = round(($item->display_amount / $item->quantity) * $exchangeRate, 4);
                $unitSubtotal = round(($item->subtotal_snapshot / $item->quantity) * $exchangeRate, 4);

                // Variable/custom-amount items: the cart's display_amount is the chosen
                // face value in the offer's own currency (cart_items.display_currency =
                // variant->currency, e.g. USD for a Visa card) and is NOT exchange-converted.
                // Persist that per-unit offer-currency value so fulfilment sends the right
                // figure to the provider - sending the converted display_amount made Zendit
                // see a USD card's XAF amount as USD and reject it ("value is out of range").
                $unitProviderFaceValue = $item->variant->is_variable
                    ? round($item->display_amount / $item->quantity, 4)
                    : null;

                for ($i = 0; $i < $item->quantity; $i++) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'category_id' => $item->product->category_id,
                        'subcategory_id' => $item->product->subcategory_id,
                        'provider_name' => $item->variant->metadata['provider'] ?? $item->product->provider_name,
                        'provider_offer_id' => $item->variant->provider_offer_id,
                        'product_snapshot' => array_merge($item->product->toArray(), [
                            // toArray() doesn't include eager relations - but fulfilment
                            // providers and order views need the category slug to branch
                            // (esim vs. mobile-airtime vs. gift card). Add it explicitly.
                            'category' => $item->product->category
                                ? ['id' => $item->product->category->id, 'slug' => $item->product->category->slug, 'name' => $item->product->category->name]
                                : null,
                        ]),
                        'variant_snapshot' => $item->variant->toArray(),
                        'quantity' => 1,
                        'display_currency' => $displayCurrency,
                        'display_amount' => $unitDisplayAmount,
                        'provider_face_value' => $unitProviderFaceValue,
                        'provider_cost_usd' => $item->provider_cost_usd,
                        'markup_amount' => $item->markup_amount,
                        'subtotal_amount' => $unitSubtotal,
                        'fulfillment_status' => FulfillmentStatus::NotStarted,
                        // Carry the buyer-supplied context (top-up recipient phone,
                        // gift-card delivery email, …) forward so the fulfilment
                        // provider can act on it.
                        'metadata' => $item->metadata_snapshot ?: null,
                    ]);
                }
            }

            // NOTE: Cart deletion intentionally deferred to step 7 (post-init).

            if ($rcoinApplied > 0) {
                $walletService = app(WalletService::class);
                $wallet = $walletService->getOrCreateWallet($user, Currency::RCOIN);
                $walletService->lockFunds($wallet, $rcoinApplied);
            }

            // Reserve the coupon slot inside the same lock so a max_uses cap
            // can't be exceeded under concurrent checkouts. This used to be a
            // straight used_count increment, which nothing ever undid: an
            // abandoned checkout consumed a redemption forever, so a customer
            // clicking Pay repeatedly could exhaust a capped promotion without
            // paying once. The reservation lapses on its own if they never pay.
            if ($couponModel !== null && $couponId !== null) {
                $this->couponRedemptionService->reserve($couponModel, $order, $user, $couponDiscountUsd);
            }

            return $order;
        });

        // 4. Create the PaymentAttempt
        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => $paymentMethod,
            // Null when Flutterwave was chosen without a specific method — the
            // session's pay() call records it the moment the customer commits.
            'payment_method' => PaymentMethodNormaliser::normalise($selectedMethod ?? $paymentMethod),
            'idempotency_key' => 'PAY-'.Str::uuid()->toString(),
            'currency' => $displayCurrency,
            'amount' => $order->total_amount,
            'exchange_rate_snapshot' => $exchangeRate,
            'payment_status' => PaymentStatus::Pending,
        ]);

        OrderPlaced::dispatch($order);

        // 5. Initialize payment attempt via the selected gateway. If this throws
        //    (encryption key invalid, currency unsupported, network down, etc.)
        //    the order goes to Failed and the exception re-raises - but the
        //    customer's cart is preserved (see step 3 comment) so they can fix
        //    the .env / pick another method and try again without re-adding items.
        try {
            $provider = $this->paymentGatewayFactory->getProvider($paymentMethod);
            $initResult = $provider->initializePayment($attempt);
        } catch (\Throwable $e) {
            DB::transaction(function () use ($order, $attempt, $user) {
                $order->update([
                    'order_status' => OrderStatus::Failed,
                    'payment_status' => PaymentStatus::Failed,
                ]);
                $attempt->update([
                    'payment_status' => PaymentStatus::Failed,
                    'failed_at' => now(),
                ]);

                $rcoinApplied = $order->metadata['rcoin_applied'] ?? 0;
                if ($rcoinApplied > 0) {
                    $walletService = app(WalletService::class);
                    $wallet = $walletService->getOrCreateWallet($user, Currency::RCOIN);
                    $walletService->unlockFunds($wallet, $rcoinApplied);
                }

                // Gateway init died, so no session will ever exist for this
                // order and nothing downstream can release its coupon slot.
                $this->couponRedemptionService->releaseForOrder($order, 'gateway_init_failed');
            });

            throw $e;
        }

        // 6. Create the PaymentSession
        $paymentSession = $this->paymentSessionService->createForOrder($order, $attempt, $initResult);

        // 7. The cart is intentionally NOT cleared here. It is cleared only when
        //    the payment is CONFIRMED (PaymentSessionService::confirmSession), so
        //    a failed or abandoned card/crypto payment keeps the cart for retry.

        // 7. Handle the internal wallet flow. When the wallet provider deferred for
        //    transaction-PIN authorization (status "awaiting_customer_action"), we do
        //    NOT touch funds here - the customer verifies their PIN on the frontend,
        //    which calls the pay endpoint to authorize, debit, and dispatch fulfillment.
        //    Only the no-PIN path settles synchronously below.
        if ($paymentMethod === 'wallet' && ($initResult['status'] ?? null) !== 'awaiting_customer_action') {
            DB::transaction(function () use ($order, $attempt, $paymentSession) {
                // Reserve → confirm → debit in one atomic transaction.
                $order->payment_status = PaymentStatus::Reserved;
                $order->order_status = OrderStatus::Processing;
                $order->save();

                // Wallet confirms instantly
                $this->paymentSessionService->confirmSession($paymentSession, ['transaction_id' => $attempt->gateway_reference]);

                // Actually debit the wallet (unlock reserved funds + deduct + record transaction).
                // Without this, funds stay locked but never deducted from the balance.
                $this->walletPaymentProvider->finalizeDebit($attempt);

                // Reflect the confirmed payment status on the order.
                $order->payment_status = PaymentStatus::Paid;
                $order->save();
            });

            // Dispatch fulfillment - funds are now fully debited.
            foreach ($order->items as $item) {
                FulfillOrderItemJob::dispatch($item);
            }
        }

        return $order->load(['paymentAttempts', 'paymentAttempts.paymentSession']);
    }

    /**
     * A stable identity for "this exact checkout".
     *
     * Everything that would change what the customer pays, or what they
     * receive, belongs in here. Deliberately includes each line's
     * metadata_snapshot: swapping the recipient phone number on an airtime
     * top-up leaves the price identical but is emphatically a different
     * purchase, and reusing the previous checkout would send the money to the
     * wrong number.
     */
    private function checkoutFingerprint(Cart $cart, string $paymentMethod, ?string $selectedMethod, string $displayCurrency, bool|string $applyRcoin, ?string $couponCode): string
    {
        $lines = $cart->items()
            ->orderBy('product_variant_id')
            ->orderBy('id')
            ->get()
            ->map(fn ($item) => implode(':', [
                $item->product_variant_id,
                $item->quantity,
                (string) $item->subtotal_snapshot,
                (string) $item->display_amount,
                md5((string) json_encode($item->metadata_snapshot)),
            ]))
            ->implode('|');

        return hash('sha256', implode('~', [
            $cart->id,
            $lines,
            $paymentMethod,
            $selectedMethod ?? '',
            strtoupper($displayCurrency),
            is_string($applyRcoin) ? $applyRcoin : ($applyRcoin ? '1' : '0'),
            strtoupper($couponCode ?? ''),
        ]));
    }

    /**
     * The customer's own still-payable checkout for this exact cart, if one
     * exists. Returns null the moment anything differs, so a changed cart or a
     * lapsed session always produces a fresh order.
     */
    private function findReusableCheckout(User $user, Cart $cart, string $fingerprint): ?Order
    {
        $candidates = Order::where('cart_id', $cart->id)
            // Scoped to the buyer, not just the cart. A guest cart is resolved
            // from a cookie and each guest email mints its own shadow account,
            // so one cart really can carry orders belonging to different users.
            // Matching on cart alone would hand somebody else's order back.
            ->where('user_id', $user->id)
            ->where('order_status', OrderStatus::Pending)
            ->where('payment_status', PaymentStatus::Unpaid)
            ->with('paymentAttempts.paymentSession')
            ->orderByDesc('id')
            ->get();

        foreach ($candidates as $candidate) {
            if (($candidate->metadata['checkout_fingerprint'] ?? null) !== $fingerprint) {
                continue;
            }

            $session = $candidate->paymentAttempts
                ->sortByDesc('created_at')
                ->first()?->paymentSession;

            if (! $session || ! in_array($session->status, self::REUSABLE_SESSION_STATUSES, true)) {
                continue;
            }

            // An expired session cannot be paid from, and one about to expire is
            // barely better: handing back forty seconds of runway means the
            // customer starts entering card details into a screen that dies
            // under them. Rapid repeat taps, which is what this exists to
            // absorb, all land nowhere near this boundary. A customer who
            // returns at the very end of the window genuinely wants a fresh
            // checkout, so let them have one.
            if (! $session->expires_at || $session->expires_at->lt(now()->addMinutes(self::MINIMUM_REUSABLE_MINUTES))) {
                continue;
            }

            return $candidate;
        }

        return null;
    }
}
