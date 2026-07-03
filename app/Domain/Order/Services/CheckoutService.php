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
use Illuminate\Support\Str;

class CheckoutService
{
    public function __construct(
        private readonly OrderValidationService $orderValidationService,
        private readonly PaymentGatewayFactory $paymentGatewayFactory,
        private readonly WalletPaymentProvider $walletPaymentProvider,
        private readonly PaymentSessionService $paymentSessionService,
        private readonly CurrencyRateService $currencyRateService,
    ) {}

    /**
     * Coordinate the checkout and order placement process.
     * Enforces row locking and atomic persistence.
     *
     * @throws \Exception
     */
    public function placeOrder(User $user, Cart $cart, string $paymentMethod, string $displayCurrency, ?string $deliveryEmail = null, bool|string $applyRcoin = false, ?string $couponCode = null): Order
    {
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
        //     same cart does not pile up Pending rows for admins.
        Order::where('cart_id', $cart->id)
            ->where('order_status', OrderStatus::Pending)
            ->update(['order_status' => OrderStatus::Cancelled]);

        // 3. Atomically build Order + snapshot items. The cart is read-locked here
        //    but intentionally NOT deleted yet - see step 7. If the gateway init
        //    in step 5 throws (encryption key, bad currency, network), we want
        //    the cart to stay intact so the customer can retry without losing
        //    their items. Cart deletion now lives at the end, after init succeeds.
        $order = DB::transaction(function () use ($user, $cart, $paymentMethod, $displayCurrency, $validatedTotals, $orderNumber, $deliveryEmail, $exchangeRate, $applyRcoin, $couponCode) {

            // Re-lock the cart to avoid race conditions
            $lockedCart = Cart::where('id', $cart->id)->lockForUpdate()->firstOrFail();
            $lockedCart->load('items.product', 'items.variant');

            // Coupon redemption. Resolved BEFORE Rcoin so the customer's wallet
            // redemption is calculated against the discounted total. Coupons are
            // per-variant so the matching cart item is what carries the discount.
            $couponId = null;
            $couponCodeApplied = null;
            $couponDiscountUsd = 0.0;
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

                // Discount is applied per-unit then scaled by quantity. subtotal_snapshot
                // is the line total (unit x qty) so divide back to a unit price first.
                $unitPriceUsd = (float) $matchingItem->subtotal_snapshot / max(1, $matchingItem->quantity);
                $discountedUnit = $couponModel->applyTo($unitPriceUsd);
                $couponDiscountUsd = round(($unitPriceUsd - $discountedUnit) * $matchingItem->quantity, 2);

                if ($couponDiscountUsd > 0) {
                    $validatedTotals['total'] = max(0, $validatedTotals['total'] - $couponDiscountUsd);
                    $couponId = $couponModel->id;
                    $couponCodeApplied = $couponModel->code;
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

            // Increment the coupon's redemption counter inside the same lock so
            // a max_uses cap can't be exceeded under concurrent checkouts.
            if ($couponModel !== null && $couponId !== null) {
                $couponModel->increment('used_count');
            }

            return $order;
        });

        // 4. Create the PaymentAttempt
        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => $paymentMethod,
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
}
