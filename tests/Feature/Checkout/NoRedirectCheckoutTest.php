<?php

namespace Tests\Feature\Checkout;

use App\Domain\Order\Services\CheckoutService;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Nobody is redirected away from our checkout. Every gateway's card and
 * bank method opens that gateway's secure popup on our page, and mobile
 * money puts the prompt on the phone from our page.
 */
class NoRedirectCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.zendit.api_key' => 'ZENDIT_API_KEY_MOCK', 'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK', 'pricing.safety_markup_percent' => 10.0, 'pricing.min_margin_percent' => 1.0]);
        $this->user = User::factory()->create();
        CurrencyRate::create(['code' => 'NGN', 'name' => 'Naira', 'type' => 'fiat', 'rate_per_usd' => 1500.0, 'is_active' => true]);

        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Gaming', 'slug' => 'gaming']);
        $product = Product::create(['category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'provider_name' => 'zendit', 'country_code' => 'US', 'currency_code' => 'USD', 'name' => 'Steam', 'slug' => 'steam']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'provider_offer_id' => 'steam-10', 'sku' => 'STEAM10', 'currency' => 'USD', 'face_value' => 10.0, 'cost_price' => 9.5, 'retail_price' => 10.5, 'is_available' => true]);
        Cache::flush();
        PricingRule::create(['markup_type' => 'fixed', 'markup_value' => 1.0, 'is_active' => true]);
        $this->cart = Cart::create(['user_id' => $this->user->id, 'status' => 'active', 'last_activity_at' => now()]);
        CartItem::create(['cart_id' => $this->cart->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1, 'display_currency' => 'USD', 'display_amount' => 11.0, 'provider_cost_usd' => 9.5, 'markup_amount' => 1.0, 'unit_price_snapshot' => 11.0, 'subtotal_snapshot' => 11.0]);
        $this->cart->load('items.product', 'items.variant');
    }

    private function gateway(string $gateway, bool $on): void
    {
        SiteSetting::put("features.gateway_{$gateway}_enabled", $on ? 'on' : 'off', 'features');
    }

    /** @return array{0: PaymentAttempt, 1: PaymentSession} */
    private function ngnOrderOn(string $gateway): array
    {
        $this->gateway('flutterwave', false);
        $this->gateway('paystack', $gateway === 'paystack');
        $this->gateway('korapay', $gateway === 'korapay');

        $order = app(CheckoutService::class)->placeOrder(user: $this->user, cart: $this->cart->fresh()->load('items.product', 'items.variant'), paymentMethod: 'flutterwave', displayCurrency: 'NGN');
        $attempt = $order->paymentAttempts->first();

        return [$attempt, $attempt->paymentSession];
    }

    public function test_the_checkout_page_carries_every_gateways_popup_script(): void
    {
        $source = File::get(resource_path('views/shop/checkout.blade.php'));

        $this->assertStringContainsString('https://checkout.flutterwave.com/v3.js', $source);
        $this->assertStringContainsString('korapay-collections.min.js', $source);
        $this->assertStringContainsString('https://js.paystack.co/v2/inline.js', $source);
        $this->assertStringContainsString("inlineData.provider === 'korapay'", $source);
        $this->assertStringContainsString("inlineData.provider === 'paystack'", $source);
    }

    public function test_every_flutterwave_bank_method_opens_in_the_popup_on_our_page(): void
    {
        $expected = ['ussd' => 'ussd', 'pay_with_bank' => 'account', 'bank_qr' => 'qr', 'mobile_wallet' => 'opay,enaira,mobilemoneynigeria'];

        foreach ($expected as $method => $options) {
            $this->gateway('flutterwave', true);
            $order = app(CheckoutService::class)->placeOrder(user: $this->user, cart: $this->cart->fresh()->load('items.product', 'items.variant'), paymentMethod: 'flutterwave', displayCurrency: 'NGN');
            $attempt = $order->paymentAttempts->first();
            $session = $attempt->paymentSession;

            $this->actingAs($this->user)->postJson("/api/payment-sessions/{$session->id}/pay", ['method' => $method])->assertOk();

            $session->refresh();
            $this->assertSame('awaiting_payment', $session->status, "{$method} must not redirect");
            $this->assertSame('flutterwave', $session->payment_payload['inline']['provider']);
            $this->assertSame($options, $session->payment_payload['inline']['payment_options']);
            $this->assertArrayNotHasKey('redirect_url', $session->payment_payload);

            $this->cart = Cart::create(['user_id' => $this->user->id, 'status' => 'active', 'last_activity_at' => now()]);
            CartItem::create(['cart_id' => $this->cart->id, 'product_id' => $attempt->order->items->first()->product_id, 'product_variant_id' => $attempt->order->items->first()->product_variant_id, 'quantity' => 1, 'display_currency' => 'USD', 'display_amount' => 11.0, 'provider_cost_usd' => 9.5, 'markup_amount' => 1.0, 'unit_price_snapshot' => 11.0, 'subtotal_snapshot' => 11.0]);
        }
    }

    public function test_every_kora_bank_and_card_method_opens_its_popup_on_our_page(): void
    {
        foreach (['card', 'bank_transfer', 'pay_with_bank'] as $method) {
            [$attempt, $session] = $this->ngnOrderOn('korapay');

            $this->actingAs($this->user)->postJson("/api/payment-sessions/{$session->id}/pay", ['method' => $method])->assertOk();

            $session->refresh();
            $this->assertSame('awaiting_payment', $session->status, "{$method} must not redirect");
            $this->assertSame('korapay', $session->payment_payload['inline']['provider']);
            $this->assertSame([$method], $session->payment_payload['inline']['channels']);
            $this->assertSame($attempt->idempotency_key, $session->payment_payload['inline']['reference']);
            $this->assertArrayNotHasKey('redirect_url', $session->payment_payload);

            $this->cart = Cart::create(['user_id' => $this->user->id, 'status' => 'active', 'last_activity_at' => now()]);
            CartItem::create(['cart_id' => $this->cart->id, 'product_id' => $attempt->order->items->first()->product_id, 'product_variant_id' => $attempt->order->items->first()->product_variant_id, 'quantity' => 1, 'display_currency' => 'USD', 'display_amount' => 11.0, 'provider_cost_usd' => 9.5, 'markup_amount' => 1.0, 'unit_price_snapshot' => 11.0, 'subtotal_snapshot' => 11.0]);
        }
    }

    public function test_every_paystack_method_opens_its_popup_on_our_page(): void
    {
        foreach (['card' => 'card', 'bank_transfer' => 'bank_transfer', 'ussd' => 'ussd'] as $method => $channel) {
            [$attempt, $session] = $this->ngnOrderOn('paystack');

            $this->actingAs($this->user)->postJson("/api/payment-sessions/{$session->id}/pay", ['method' => $method])->assertOk();

            $session->refresh();
            $this->assertSame('awaiting_payment', $session->status, "{$method} must not redirect");
            $this->assertSame('paystack', $session->payment_payload['inline']['provider']);
            $this->assertSame([$channel], $session->payment_payload['inline']['channels']);
            $this->assertArrayNotHasKey('redirect_url', $session->payment_payload);

            $this->cart = Cart::create(['user_id' => $this->user->id, 'status' => 'active', 'last_activity_at' => now()]);
            CartItem::create(['cart_id' => $this->cart->id, 'product_id' => $attempt->order->items->first()->product_id, 'product_variant_id' => $attempt->order->items->first()->product_variant_id, 'quantity' => 1, 'display_currency' => 'USD', 'display_amount' => 11.0, 'provider_cost_usd' => 9.5, 'markup_amount' => 1.0, 'unit_price_snapshot' => 11.0, 'subtotal_snapshot' => 11.0]);
        }
    }
}
