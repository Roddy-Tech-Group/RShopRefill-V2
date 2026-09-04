<?php

namespace Tests\Feature\Checkout;

use App\Domain\Cart\Services\CartManager;
use App\Domain\Order\Services\CheckoutService;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A customer who asks for ₦1,000 of airtime must receive exactly ₦1,000.
 *
 * The storefront converts the typed amount to USD (the cart's settlement
 * currency); checkout must convert that requested USD figure BACK into the
 * offer's own currency for `provider_face_value`, which is what fulfilment
 * sends the provider. It was previously derived from the cart's
 * display_amount - by then the MARKED-UP USD subtotal - so a USD offer
 * over-delivered by the markup and a naira offer asked Zendit for "₦0.76",
 * which is rejected as out of range and kills the item.
 */
class VariableTopupExactAmountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zendit.api_key' => 'ZENDIT_API_KEY_MOCK',
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
            'pricing.floor_mode' => 'legacy',
        ]);

        Cache::flush();
        PricingRule::create(['markup_type' => 'percentage', 'markup_value' => 2, 'is_active' => true]);

        $this->user = User::factory()->create();
    }

    private function variableVariant(string $currency, float $min, float $max): ProductVariant
    {
        $category = Category::firstOrCreate(['slug' => 'mobile-airtime'], ['name' => 'Mobile Airtime', 'type' => 'digital']);
        $subcategory = Subcategory::firstOrCreate(
            ['category_id' => $category->id, 'slug' => 'mobile-top-up'],
            ['name' => 'Mobile Top Up'],
        );
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'currency_code' => $currency,
        ]);

        return ProductVariant::factory()->for($product)->create([
            'is_variable' => true,
            'is_available' => true,
            'currency' => $currency,
            'face_value' => 0,
            'retail_price' => 0,
            'cost_price' => 0,
            'min_amount' => $min,
            'max_amount' => $max,
        ]);
    }

    private function placeOrderFor(ProductVariant $variant, float $requestedUsd): Order
    {
        $manager = app(CartManager::class);
        $cart = $manager->resolveCart($this->user->id, null);
        $manager->addItem($cart, $variant, 1, $requestedUsd, ['recipient_phone' => '+2348000000000']);

        return app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $cart->fresh()->load('items.product', 'items.variant'),
            paymentMethod: 'flutterwave',
            displayCurrency: 'USD',
        );
    }

    public function test_a_naira_custom_amount_reaches_fulfilment_as_exactly_the_typed_naira(): void
    {
        CurrencyRate::create(['code' => 'NGN', 'name' => 'Nigerian Naira', 'type' => 'fiat', 'rate_per_usd' => 1345.622848, 'is_active' => true]);
        $variant = $this->variableVariant('NGN', 5, 150000);

        // The storefront sends the typed ₦1,000 converted to USD.
        $order = $this->placeOrderFor($variant, 1000 / 1345.622848);

        $item = $order->items()->sole();
        $this->assertEqualsWithDelta(1000.0, (float) $item->provider_face_value, 0.0001,
            'the recipient must be credited exactly the naira amount the customer typed');
    }

    public function test_a_usd_custom_amount_is_not_inflated_by_the_markup(): void
    {
        $variant = $this->variableVariant('USD', 1, 500);

        $order = $this->placeOrderFor($variant, 5.00);

        $item = $order->items()->sole();
        $this->assertEqualsWithDelta(5.0, (float) $item->provider_face_value, 0.0001,
            'the provider must deliver the requested $5, not the marked-up charge');
    }

    /**
     * Naira totals are charm-rounded UP to the nearest ₦10 at charge time:
     * clean numbers for the customer, never a kobo of loss for the platform.
     */
    public function test_a_naira_checkout_total_is_charm_rounded_up_to_ten(): void
    {
        $rate = 1345.622848;
        CurrencyRate::create(['code' => 'NGN', 'name' => 'Nigerian Naira', 'type' => 'fiat', 'rate_per_usd' => $rate, 'is_active' => true]);
        $variant = $this->variableVariant('NGN', 5, 150000);

        $manager = app(CartManager::class);
        $cart = $manager->resolveCart($this->user->id, null);
        $manager->addItem($cart, $variant, 1, 1234 / $rate, ['recipient_phone' => '+2348000000000']);

        $order = app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $cart->fresh()->load('items.product', 'items.variant'),
            paymentMethod: 'flutterwave',
            displayCurrency: 'NGN',
        );

        $chargedNaira = (float) $order->total_amount;
        $exactNaira = (float) $order->metadata['settlement_total_usd'] * $rate;

        $this->assertSame(0.0, fmod($chargedNaira, 10), 'the charged naira total must land on a ₦10 step');
        $this->assertGreaterThanOrEqual($exactNaira - 0.01, $chargedNaira, 'charm rounding must never round DOWN');
        $this->assertLessThan($exactNaira + 10, $chargedNaira, 'charm rounding must add less than one full step');
    }
}
