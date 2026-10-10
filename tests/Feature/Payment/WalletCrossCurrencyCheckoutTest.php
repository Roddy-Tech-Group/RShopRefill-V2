<?php

namespace Tests\Feature\Payment;

use App\Domain\Fulfillment\Services\FulfillmentProviderFactory;
use App\Domain\Order\Services\CheckoutService;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\WalletPaymentProvider;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Services\TransactionPinService;
use App\Jobs\FulfillOrderItemJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Checkout pays from whichever wallet covers the order, the way the eSIM
 * top-up already does, instead of only the wallet in the cart's currency.
 *
 * The order and its payment attempt keep the cart currency and amount. The
 * wallet provider records on the attempt which wallet it charged and the
 * rate it used, frozen at that moment, and every later movement on that
 * attempt (hold, debit, release, refund, reversal) uses that same wallet at
 * that same rate, so what goes back is exactly what came out.
 */
class WalletCrossCurrencyCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zendit.api_key' => 'ZENDIT_API_KEY_MOCK',
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'services.nowpayments.api_key' => 'NOWPAYMENTS_KEY_MOCK',
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
        ]);

        CurrencyRate::create(['code' => 'USD', 'name' => 'US Dollar', 'type' => 'fiat', 'rate_per_usd' => 1.0, 'is_active' => true]);
        CurrencyRate::create(['code' => 'XAF', 'name' => 'CFA Franc', 'type' => 'fiat', 'rate_per_usd' => 600.0, 'is_active' => true]);
        CurrencyRate::create(['code' => 'NGN', 'name' => 'Naira', 'type' => 'fiat', 'rate_per_usd' => 1500.0, 'is_active' => true]);

        $this->user = User::factory()->create();
        app(TransactionPinService::class)->setupPin($this->user, '5283');

        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Gaming', 'slug' => 'gaming']);
        $product = Product::create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Steam Gift Card',
            'slug' => 'steam-gift-card',
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'provider_offer_id' => 'steam-offer-10',
            'sku' => 'STEAM10',
            'currency' => 'USD',
            'face_value' => 10.00,
            'cost_price' => 9.50,
            'retail_price' => 10.50,
            'is_available' => true,
        ]);

        Cache::flush();
        PricingRule::create(['markup_type' => 'fixed', 'markup_value' => 1.00, 'is_active' => true]);

        // A USD 11.00 cart: $10 face + $1 markup.
        $this->cart = Cart::create(['user_id' => $this->user->id, 'status' => 'active', 'last_activity_at' => now()]);
        CartItem::create([
            'cart_id' => $this->cart->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 11.00,
            'provider_cost_usd' => 9.50,
            'markup_amount' => 1.00,
            'unit_price_snapshot' => 11.00,
            'subtotal_snapshot' => 11.00,
        ]);
    }

    private function wallet(string $currency, float $balance): Wallet
    {
        return Wallet::create([
            'user_id' => $this->user->id,
            'currency' => Currency::from($currency),
            'balance' => $balance,
            'locked_balance' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Place the USD cart with the wallet method through the service and
     * authorise it with the PIN, leaving the funds reserved.
     */
    private function reserve(?string $walletCurrency = null): PaymentAttempt
    {
        $order = app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'wallet',
            displayCurrency: 'USD',
            walletCurrency: $walletCurrency,
        );

        $attempt = $order->paymentAttempts->first();
        $token = app(TransactionPinService::class)->verifyPin($this->user, '5283');
        app(WalletPaymentProvider::class)->authorizeTransaction($attempt, $token);

        return $attempt->fresh();
    }

    private function fulfil(PaymentAttempt $attempt): void
    {
        $job = new FulfillOrderItemJob($attempt->order->items()->firstOrFail());
        $job->handle(app(FulfillmentProviderFactory::class), app(OrderService::class), app(WalletPaymentProvider::class));
    }

    // ------------------------------------------------ choosing the wallet

    public function test_checkout_pays_from_the_wallet_that_covers_the_order_when_the_cart_currency_wallet_is_empty(): void
    {
        Queue::fake([FulfillOrderItemJob::class]);

        $xaf = $this->wallet('XAF', 10_000);
        $usd = $this->wallet('USD', 0);

        $this->actingAs($this->user)
            ->postJson(route('api.checkout.place-order'), [
                'cart_id' => $this->cart->id,
                'payment_method' => 'wallet',
                'preferred_currency' => 'USD',
                'delivery_email' => 'customer@rshop.com',
            ])
            ->assertStatus(201);

        $order = Order::firstOrFail();
        $attempt = $order->paymentAttempts()->firstOrFail();

        // The attempt keeps the cart's currency and amount; the wallet choice
        // and the frozen rate sit beside them.
        $this->assertSame('USD', strtoupper($attempt->currency));
        $this->assertEqualsWithDelta(11.00, (float) $attempt->amount, 0.001);
        $this->assertSame('XAF', $attempt->metadata['wallet']['currency']);
        $this->assertEqualsWithDelta(600.0, (float) $attempt->metadata['wallet']['rate'], 0.0001);
        $this->assertEqualsWithDelta(6_600.00, (float) $attempt->metadata['wallet']['amount'], 0.001);

        $token = $this->actingAs($this->user)->postJson(route('api.wallets.pin.verify'), ['pin' => '5283'])->assertOk()->json('auth_token');
        $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', $attempt->paymentSession->id), [
            'method' => 'wallet',
            'details' => ['auth_token' => $token],
        ])->assertOk();

        // 6,600 XAF held in the XAF wallet; the empty USD wallet untouched.
        $this->assertEqualsWithDelta(6_600.00, (float) $xaf->fresh()->locked_balance, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $usd->fresh()->locked_balance, 0.001);

        $order->refresh();
        $this->assertSame('XAF', $order->metadata['paid_from_wallet_currency']);
        $this->assertEqualsWithDelta(6_600.00, (float) $order->metadata['paid_amount'], 0.001);
    }

    /**
     * Wallet is offered in every currency checkout sells in, including the
     * ones no wallet is held in (XOF, KES, UGX, RWF). The order is paid from a
     * wallet the customer does hold, at the frozen rate; the order currency
     * itself never needed a wallet of its own.
     *
     * @return array<string, array{0: string, 1: float}>
     */
    public static function currenciesWithoutTheirOwnWallet(): array
    {
        return [
            'XOF' => ['XOF', 600.0],
            'KES' => ['KES', 130.0],
            'UGX' => ['UGX', 3700.0],
            'RWF' => ['RWF', 1400.0],
        ];
    }

    #[DataProvider('currenciesWithoutTheirOwnWallet')]
    public function test_an_order_in_a_currency_with_no_wallet_of_its_own_is_paid_from_a_wallet_the_customer_holds(string $currency, float $ratePerUsd): void
    {
        Queue::fake([FulfillOrderItemJob::class]);
        CurrencyRate::create(['code' => $currency, 'name' => $currency, 'type' => 'fiat', 'rate_per_usd' => $ratePerUsd, 'is_active' => true]);
        $usd = $this->wallet('USD', 50);

        $this->actingAs($this->user)
            ->postJson(route('api.checkout.place-order'), [
                'cart_id' => $this->cart->id,
                'payment_method' => 'wallet',
                'preferred_currency' => $currency,
                'delivery_email' => 'customer@rshop.com',
            ])
            ->assertStatus(201);

        $attempt = Order::firstOrFail()->paymentAttempts()->firstOrFail();
        $this->assertSame($currency, strtoupper($attempt->currency));
        $this->assertSame('USD', $attempt->metadata['wallet']['currency']);

        $token = $this->actingAs($this->user)->postJson(route('api.wallets.pin.verify'), ['pin' => '5283'])->assertOk()->json('auth_token');
        $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', $attempt->paymentSession->id), [
            'method' => 'wallet',
            'details' => ['auth_token' => $token],
        ])->assertOk();

        $this->assertEqualsWithDelta((float) $attempt->metadata['wallet']['amount'], (float) $usd->fresh()->locked_balance, 0.001);

        // Delivered: the held dollars are taken, at the rate frozen at checkout.
        $this->fulfil($attempt->fresh());
        $this->assertEqualsWithDelta(50 - (float) $attempt->metadata['wallet']['amount'], (float) $usd->fresh()->balance, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $usd->fresh()->locked_balance, 0.001);
        $this->assertEqualsWithDelta((float) $attempt->amount / $ratePerUsd, (float) $attempt->metadata['wallet']['amount'], 0.01);
    }

    /**
     * An order placed for card can be paid with the wallet at the pay step
     * (the mobile app's choose_method flow). The goods must never go out
     * without the wallet being charged: either the switch is refused, or the
     * wallet is debited exactly as a wallet checkout would be.
     */
    public function test_a_card_order_paid_with_the_wallet_never_ships_without_charging_it(): void
    {
        Queue::fake([FulfillOrderItemJob::class]);
        $usd = $this->wallet('USD', 50);

        $order = app(CheckoutService::class)->placeOrder(user: $this->user, cart: $this->cart, paymentMethod: 'flutterwave', displayCurrency: 'USD');
        $attempt = $order->paymentAttempts->first();
        $this->assertSame('flutterwave', $attempt->gateway);

        $token = $this->actingAs($this->user)->postJson(route('api.wallets.pin.verify'), ['pin' => '5283'])->assertOk()->json('auth_token');
        $response = $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', $attempt->paymentSession->id), [
            'method' => 'wallet',
            'details' => ['auth_token' => $token],
        ]);

        if ($response->status() >= 400) {
            $this->assertSame(0.0, (float) $usd->fresh()->locked_balance, 'refused, yet the wallet was held');
            $this->assertSame(50.0, (float) $usd->fresh()->balance);

            return;
        }

        $this->fulfil($attempt->fresh());

        $this->assertEqualsWithDelta(39.00, (float) $usd->fresh()->balance, 0.001, 'the goods went out but the wallet was never debited');
        $this->assertEqualsWithDelta(0.00, (float) $usd->fresh()->locked_balance, 0.001, 'the hold was never released');
    }

    /** The switched order is a wallet payment in every respect, not a card one holding wallet money. */
    public function test_switching_a_card_order_to_the_wallet_makes_it_a_wallet_payment(): void
    {
        Queue::fake([FulfillOrderItemJob::class]);
        $this->wallet('USD', 50);

        $order = app(CheckoutService::class)->placeOrder(user: $this->user, cart: $this->cart, paymentMethod: 'flutterwave', displayCurrency: 'USD');
        $attempt = $order->paymentAttempts->first();

        $token = $this->actingAs($this->user)->postJson(route('api.wallets.pin.verify'), ['pin' => '5283'])->assertOk()->json('auth_token');
        $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', $attempt->paymentSession->id), [
            'method' => 'wallet',
            'details' => ['auth_token' => $token],
        ])->assertOk();

        $attempt->refresh();
        $this->assertSame('wallet', $attempt->gateway);
        $this->assertSame(PaymentStatus::Reserved, $attempt->payment_status);
        $this->assertSame('USD', $attempt->metadata['wallet']['currency']);
        $this->assertSame('wallet', $attempt->paymentSession->fresh()->session_type);
        $this->assertSame('wallet', $attempt->paymentSession->fresh()->provider);
    }

    /** Once a card charge may be under way, the order cannot also be paid from the wallet. */
    public function test_a_card_payment_already_started_cannot_switch_to_the_wallet(): void
    {
        $usd = $this->wallet('USD', 50);

        $order = app(CheckoutService::class)->placeOrder(user: $this->user, cart: $this->cart, paymentMethod: 'flutterwave', displayCurrency: 'USD');
        $attempt = $order->paymentAttempts->first();
        $attempt->paymentSession->forceFill(['status' => 'awaiting_payment'])->save();

        $token = $this->actingAs($this->user)->postJson(route('api.wallets.pin.verify'), ['pin' => '5283'])->assertOk()->json('auth_token');
        $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', $attempt->paymentSession->id), [
            'method' => 'wallet',
            'details' => ['auth_token' => $token],
        ])->assertStatus(422);

        $this->assertSame('flutterwave', $attempt->fresh()->gateway);
        $this->assertEqualsWithDelta(0.00, (float) $usd->fresh()->locked_balance, 0.001);
        $this->assertSame('awaiting_payment', $attempt->paymentSession->fresh()->status);
    }

    /** A switch the wallet cannot cover is refused and leaves the card order exactly as it was. */
    public function test_a_refused_switch_to_the_wallet_leaves_the_card_order_untouched(): void
    {
        $usd = $this->wallet('USD', 5);

        $order = app(CheckoutService::class)->placeOrder(user: $this->user, cart: $this->cart, paymentMethod: 'flutterwave', displayCurrency: 'USD');
        $attempt = $order->paymentAttempts->first();

        $token = $this->actingAs($this->user)->postJson(route('api.wallets.pin.verify'), ['pin' => '5283'])->assertOk()->json('auth_token');
        $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', $attempt->paymentSession->id), [
            'method' => 'wallet',
            'details' => ['auth_token' => $token],
        ])->assertStatus(400);

        $attempt->refresh();
        $this->assertSame('flutterwave', $attempt->gateway);
        $this->assertArrayNotHasKey('wallet', $attempt->metadata ?? []);
        $this->assertSame('awaiting_method', $attempt->paymentSession->fresh()->status);
        $this->assertSame('card', $attempt->paymentSession->fresh()->session_type);
        $this->assertEqualsWithDelta(0.00, (float) $usd->fresh()->locked_balance, 0.001);
    }

    public function test_the_cart_currency_wallet_is_preferred_when_it_covers_the_order(): void
    {
        $usd = $this->wallet('USD', 50);
        $xaf = $this->wallet('XAF', 100_000);

        $attempt = $this->reserve();

        $this->assertSame('USD', $attempt->metadata['wallet']['currency']);
        $this->assertEqualsWithDelta(11.00, (float) $usd->fresh()->locked_balance, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $xaf->fresh()->locked_balance, 0.001);
    }

    public function test_usd_is_preferred_over_other_wallets_when_the_cart_currency_wallet_is_short(): void
    {
        $this->wallet('NGN', 1_000_000);
        $usd = $this->wallet('USD', 50);

        $order = app(CheckoutService::class)->placeOrder(user: $this->user, cart: $this->cart, paymentMethod: 'wallet', displayCurrency: 'XAF');
        $attempt = $order->paymentAttempts->first();

        $this->assertSame('XAF', strtoupper($attempt->currency));
        $this->assertSame('USD', $attempt->metadata['wallet']['currency']);
        $this->assertEqualsWithDelta(1.0 / 600.0, (float) $attempt->metadata['wallet']['rate'], 0.000001);
        $this->assertEqualsWithDelta((float) $attempt->amount / 600.0, (float) $attempt->metadata['wallet']['amount'], 0.01);
    }

    public function test_the_customer_may_pick_another_wallet_that_covers_the_order(): void
    {
        $usd = $this->wallet('USD', 50);
        $xaf = $this->wallet('XAF', 100_000);

        $attempt = $this->reserve(walletCurrency: 'XAF');

        $this->assertSame('XAF', $attempt->metadata['wallet']['currency']);
        $this->assertEqualsWithDelta(6_600.00, (float) $xaf->fresh()->locked_balance, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $usd->fresh()->locked_balance, 0.001);
    }

    public function test_a_chosen_wallet_that_does_not_cover_the_order_is_refused_not_switched(): void
    {
        $this->wallet('USD', 50);
        $this->wallet('XAF', 1_000);

        $response = $this->actingAs($this->user)->postJson(route('api.checkout.place-order'), [
            'cart_id' => $this->cart->id,
            'payment_method' => 'wallet',
            'preferred_currency' => 'USD',
            'wallet_currency' => 'XAF',
        ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('XAF wallet', $response->json('message'));
        $this->assertStringNotContainsString('Wallet [', $response->json('message'));
    }

    public function test_no_covering_wallet_is_refused_with_a_plain_message(): void
    {
        $this->wallet('USD', 5);
        $this->wallet('XAF', 1_000);

        $response = $this->actingAs($this->user)->postJson(route('api.checkout.place-order'), [
            'cart_id' => $this->cart->id,
            'payment_method' => 'wallet',
            'preferred_currency' => 'USD',
        ]);

        $response->assertStatus(400);
        $this->assertSame(InsufficientBalanceException::forOrder()->getMessage(), $response->json('message'));
    }

    public function test_the_rcoin_balance_never_pays_for_an_order(): void
    {
        $this->wallet('RCOIN', 1_000_000);
        $this->wallet('USD', 5);

        $this->actingAs($this->user)->postJson(route('api.checkout.place-order'), [
            'cart_id' => $this->cart->id,
            'payment_method' => 'wallet',
            'preferred_currency' => 'USD',
        ])->assertStatus(400);
    }

    public function test_a_different_wallet_choice_is_a_different_checkout(): void
    {
        $this->wallet('USD', 50);
        $this->wallet('XAF', 100_000);

        $first = app(CheckoutService::class)->placeOrder(user: $this->user, cart: $this->cart, paymentMethod: 'wallet', displayCurrency: 'USD');
        $same = app(CheckoutService::class)->placeOrder(user: $this->user, cart: $this->cart, paymentMethod: 'wallet', displayCurrency: 'USD');
        $other = app(CheckoutService::class)->placeOrder(user: $this->user, cart: $this->cart, paymentMethod: 'wallet', displayCurrency: 'USD', walletCurrency: 'XAF');

        $this->assertSame($first->id, $same->id, 'an unchanged checkout is reused');
        $this->assertNotSame($first->id, $other->id, 'a different wallet is a fresh checkout');
    }

    // ------------------------------------------------ money in, money out

    public function test_the_debit_settles_in_the_paying_wallet_at_the_frozen_rate(): void
    {
        $xaf = $this->wallet('XAF', 10_000);
        $attempt = $this->reserve();

        // The rate moves between the hold and the debit; the debit ignores it.
        CurrencyRate::where('code', 'XAF')->update(['rate_per_usd' => 650.0]);

        $this->fulfil($attempt);

        $xaf->refresh();
        $this->assertEqualsWithDelta(3_400.00, (float) $xaf->balance, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $xaf->locked_balance, 0.001);
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
    }

    public function test_a_refund_returns_to_the_wallet_that_paid_at_the_same_rate(): void
    {
        $xaf = $this->wallet('XAF', 10_000);
        $usd = $this->wallet('USD', 0);
        $attempt = $this->reserve();
        $this->fulfil($attempt);
        $this->assertEqualsWithDelta(3_400.00, (float) $xaf->fresh()->balance, 0.001);

        CurrencyRate::where('code', 'XAF')->update(['rate_per_usd' => 650.0]);

        // An admin refund of the whole order, expressed in the order's currency.
        $this->assertTrue(app(WalletPaymentProvider::class)->refundPayment($attempt->fresh(), 11.00, 'admin-test'));

        $this->assertEqualsWithDelta(10_000.00, (float) $xaf->fresh()->balance, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $usd->fresh()->balance, 0.001);
    }

    public function test_partial_refunds_add_up_to_exactly_what_was_charged(): void
    {
        $xaf = $this->wallet('XAF', 10_000);
        $attempt = $this->reserve();
        $this->fulfil($attempt);

        // Three uneven partial refunds in USD: 3.67 + 3.67 + 3.66 = 11.00. Each
        // converts to a rounded XAF figure; together they must return exactly
        // the 6,600 XAF that was charged, never a unit more or less.
        $provider = app(WalletPaymentProvider::class);
        $this->assertTrue($provider->refundPayment($attempt->fresh(), 3.67, 'item-1'));
        $this->assertTrue($provider->refundPayment($attempt->fresh(), 3.67, 'item-2'));
        $this->assertTrue($provider->refundPayment($attempt->fresh(), 3.66, 'final'));

        $this->assertEqualsWithDelta(10_000.00, (float) $xaf->fresh()->balance, 0.0001);
    }

    public function test_a_replayed_refund_moves_no_more_money_across_currencies(): void
    {
        $xaf = $this->wallet('XAF', 10_000);
        $attempt = $this->reserve();
        $this->fulfil($attempt);

        $provider = app(WalletPaymentProvider::class);
        $this->assertTrue($provider->refundPayment($attempt->fresh(), 5.50, 'item-1'));
        $this->assertTrue($provider->refundPayment($attempt->fresh(), 5.50, 'item-1'));

        $this->assertEqualsWithDelta(3_400.00 + 3_300.00, (float) $xaf->fresh()->balance, 0.001);
    }

    public function test_a_failed_item_releases_its_share_from_the_paying_wallet_and_the_rest_settles_exactly(): void
    {
        $xaf = $this->wallet('XAF', 10_000);
        $attempt = $this->reserve();
        $provider = app(WalletPaymentProvider::class);

        // Half the order failed before delivery: its share is released from
        // the hold in XAF, and the other half settles from what stays held.
        $provider->releasePartialFunds($attempt, 5.50);
        $this->assertEqualsWithDelta(3_300.00, (float) $xaf->fresh()->locked_balance, 0.001);

        $provider->finalizeDebit($attempt->fresh());

        $xaf->refresh();
        $this->assertEqualsWithDelta(6_700.00, (float) $xaf->balance, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $xaf->locked_balance, 0.001);
    }

    public function test_uneven_partial_releases_never_strand_a_unit_in_the_hold(): void
    {
        // A rate that does not divide cleanly: 3 USD items at 1/3 each.
        CurrencyRate::where('code', 'XAF')->update(['rate_per_usd' => 655.957]);
        $xaf = $this->wallet('XAF', 100_000);
        $attempt = $this->reserve();
        $provider = app(WalletPaymentProvider::class);

        $held = (float) $xaf->fresh()->locked_balance;
        $this->assertEqualsWithDelta(round(11.00 * 655.957, 2), $held, 0.001);

        $provider->releasePartialFunds($attempt->fresh(), 3.67);
        $provider->releasePartialFunds($attempt->fresh(), 3.67);
        $provider->finalizeDebit($attempt->fresh());

        $xaf->refresh();
        $this->assertEqualsWithDelta(0.00, (float) $xaf->locked_balance, 0.0001);
        $this->assertEqualsWithDelta(100_000 - round(3.66 * 655.957, 2), (float) $xaf->balance, 0.02);
    }

    public function test_releasing_a_reserved_payment_returns_the_whole_hold(): void
    {
        $xaf = $this->wallet('XAF', 10_000);
        $attempt = $this->reserve();

        app(WalletPaymentProvider::class)->releaseFunds($attempt);

        $xaf->refresh();
        $this->assertEqualsWithDelta(10_000.00, (float) $xaf->balance, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $xaf->locked_balance, 0.001);
    }

    public function test_an_attempt_without_a_recorded_wallet_choice_still_uses_the_order_currency_wallet(): void
    {
        // Attempts placed before this shipped carry no wallet choice: they
        // behave exactly as before, one wallet, rate one.
        $usd = $this->wallet('USD', 50);
        $attempt = $this->reserve();
        $attempt->forceFill(['metadata' => null])->save();

        app(WalletPaymentProvider::class)->finalizeDebit($attempt->fresh());

        $this->assertEqualsWithDelta(39.00, (float) $usd->fresh()->balance, 0.001);
    }

    // ------------------------------------------------ the page

    public function test_the_checkout_page_offers_every_wallet_with_its_rate(): void
    {
        $this->wallet('XAF', 10_000);
        $this->wallet('USD', 2);
        $this->wallet('RCOIN', 5_000);

        $response = $this->actingAs($this->user)->get(route('shop.checkout'));

        $response->assertOk();
        $options = collect($response->viewData('walletOptions'))->keyBy('currency');

        $this->assertTrue($options->has('XAF'));
        $this->assertTrue($options->has('USD'));
        $this->assertFalse($options->has('RCOIN'), 'Rcoin is a discount, never a paying wallet');
        $this->assertEqualsWithDelta(600.0, $options['XAF']['per_usd'], 0.0001);
        $this->assertEqualsWithDelta(10_000.0, $options['XAF']['balance'], 0.001);
    }
}
