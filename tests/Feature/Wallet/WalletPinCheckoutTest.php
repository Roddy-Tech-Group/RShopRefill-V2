<?php

namespace Tests\Feature\Wallet;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\MissingTransactionPinException;
use App\Domain\Wallet\Services\TransactionPinService;
use App\Jobs\FulfillOrderItemJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * End-to-end coverage for the wallet checkout path when a transaction PIN is set.
 *
 * The PIN-enabled flow is deliberately deferred: placeOrder must NOT touch funds;
 * the customer verifies their PIN (auth token) and the pay endpoint authorizes the
 * debit. This guards the CheckoutService deferral wired on top of the CTO backend.
 */
class WalletPinCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Cart $cart;

    private const PIN = '5283'; // not in the weak-PIN blocklist

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zendit.api_key' => 'ZENDIT_API_KEY_MOCK',
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
        ]);

        $this->user = User::factory()->create();

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

        $this->cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'last_activity_at' => now(),
        ]);
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

    private function fundedWallet(float $balance = 100.0): Wallet
    {
        return Wallet::create([
            'user_id' => $this->user->id,
            'currency' => Currency::USD,
            'balance' => $balance,
            'locked_balance' => 0.00,
            'is_active' => true,
        ]);
    }

    private function placeWalletOrder(): Order
    {
        return app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'wallet',
            displayCurrency: 'USD',
        );
    }

    public function test_wallet_checkout_with_a_pin_defers_and_does_not_touch_funds(): void
    {
        Queue::fake([FulfillOrderItemJob::class]);
        app(TransactionPinService::class)->setupPin($this->user, self::PIN);
        $wallet = $this->fundedWallet();

        $order = $this->placeWalletOrder();

        // Order is created but parked — payment not settled.
        $this->assertEquals(OrderStatus::Pending, $order->order_status);
        $this->assertNotEquals(PaymentStatus::Paid, $order->payment_status);

        // The session waits for the customer to authorize with their PIN.
        $session = $order->paymentAttempts->first()->paymentSession;
        $this->assertNotSame('confirmed', $session->status);

        // Funds are untouched: not debited, not locked.
        $wallet->refresh();
        $this->assertEquals(100.00, (float) $wallet->balance);
        $this->assertEquals(0.00, (float) $wallet->locked_balance);

        // No fulfillment until the PIN is authorized.
        Queue::assertNotPushed(FulfillOrderItemJob::class);
    }

    public function test_wallet_checkout_without_a_pin_throws_missing_pin_exception(): void
    {
        Queue::fake([FulfillOrderItemJob::class]);
        $wallet = $this->fundedWallet();

        $this->expectException(MissingTransactionPinException::class);
        $this->placeWalletOrder();
    }

    public function test_full_pin_authorization_completes_the_wallet_payment(): void
    {
        Queue::fake([FulfillOrderItemJob::class]);
        app(TransactionPinService::class)->setupPin($this->user, self::PIN);
        $wallet = $this->fundedWallet();

        $order = $this->placeWalletOrder();
        $session = $order->paymentAttempts->first()->paymentSession;

        // 1. Verify the PIN to obtain a single-use auth token.
        $verify = $this->actingAs($this->user)
            ->postJson(route('api.wallets.pin.verify'), ['pin' => self::PIN])
            ->assertOk();
        $token = $verify->json('auth_token');
        $this->assertNotEmpty($token);

        // 2. Authorize the wallet debit with the token.
        $this->actingAs($this->user)
            ->postJson(route('api.payment-sessions.pay', $session->id), [
                'method' => 'wallet',
                'details' => ['auth_token' => $token],
            ])->assertOk();

        // Session confirmed, order paid, funds reserved, fulfillment dispatched.
        $this->assertSame('confirmed', $session->fresh()->status);
        $order->refresh();
        $this->assertEquals(PaymentStatus::Paid, $order->payment_status);

        $wallet->refresh();
        $this->assertEquals(11.00, (float) $wallet->locked_balance);
        Queue::assertPushed(FulfillOrderItemJob::class, 1);
    }

    public function test_a_wrong_pin_does_not_authorize_payment(): void
    {
        app(TransactionPinService::class)->setupPin($this->user, self::PIN);
        $this->fundedWallet();
        $this->placeWalletOrder();

        $this->actingAs($this->user)
            ->postJson(route('api.wallets.pin.verify'), ['pin' => '9182'])
            ->assertStatus(422);
    }

    public function test_wallet_checkout_with_insufficient_funds_throws_insufficient_balance_exception(): void
    {
        Queue::fake([FulfillOrderItemJob::class]);
        app(TransactionPinService::class)->setupPin($this->user, self::PIN);
        $this->fundedWallet(5.00); // Order total is 11.00

        $this->expectException(InsufficientBalanceException::class);
        $this->placeWalletOrder();
    }
}
