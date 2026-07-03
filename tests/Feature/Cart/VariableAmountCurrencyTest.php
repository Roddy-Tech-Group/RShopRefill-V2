<?php

namespace Tests\Feature\Cart;

use App\Domain\Order\Services\OrderValidationService;
use App\Models\Cart;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Variable-amount variants are entered in the variant's native currency, but the
 * cart settles in USD - so the storefront posts requested_value already converted
 * to USD. The range check must convert it back to native before comparing against
 * min_amount/max_amount, otherwise a valid foreign-currency amount (e.g. 50,000
 * XOF) reads as far below a native min (1,639) and is wrongly rejected.
 */
class VariableAmountCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ProductVariant $variant;

    /** XOF units per 1 USD - matches a real rate row. */
    private float $rate = 563.83758200;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pricing.safety_markup_percent' => 10.0, 'pricing.min_margin_percent' => 1.0]);

        $this->user = User::factory()->create();

        $category = Category::create(['name' => 'Mobile Airtime', 'slug' => 'mobile-airtime', 'type' => 'digital']);
        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'brand_key' => 'Orange',
            'country_code' => 'SN',
            'currency_code' => 'XOF',
            'name' => 'Orange Senegal Top-up',
            'slug' => 'orange-senegal-topup',
            'is_active' => true,
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'provider_offer_id' => 'orange-sn-variable',
            'sku' => 'ORANGE-SN-VAR',
            'currency' => 'XOF',
            'cost_price' => 5.00,
            'retail_price' => 0.00,
            'min_amount' => 1639.00,
            'max_amount' => 131164.00,
            'is_variable' => true,
            'is_available' => true,
        ]);

        CurrencyRate::create(['code' => 'XOF', 'name' => 'West African CFA franc', 'type' => 'fiat', 'rate_per_usd' => $this->rate, 'is_active' => true]);

        Cache::flush();
        PricingRule::create(['markup_type' => 'fixed', 'markup_value' => 1.00, 'is_active' => true]);
    }

    /** A USD-converted request for a valid native amount must be accepted. */
    public function test_a_valid_native_custom_amount_is_accepted(): void
    {
        $requestedUsd = 50000 / $this->rate; // 50,000 XOF, well within [1639, 131164]

        $this->actingAs($this->user)
            ->postJson(route('cart.items.add'), [
                'product_variant_id' => $this->variant->id,
                'quantity' => 1,
                'requested_value' => $requestedUsd,
            ])
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    /** A native amount below min_amount must 422, not 500. */
    public function test_a_below_minimum_native_amount_is_rejected(): void
    {
        $requestedUsd = 1000 / $this->rate; // 1,000 XOF < 1,639 min

        $this->actingAs($this->user)
            ->postJson(route('cart.items.add'), [
                'product_variant_id' => $this->variant->id,
                'quantity' => 1,
                'requested_value' => $requestedUsd,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('requested_value');
    }

    /** A native amount above max_amount must 422, not 500. */
    public function test_an_above_maximum_native_amount_is_rejected(): void
    {
        $requestedUsd = 200000 / $this->rate; // 200,000 XOF > 131,164 max

        $this->actingAs($this->user)
            ->postJson(route('cart.items.add'), [
                'product_variant_id' => $this->variant->id,
                'quantity' => 1,
                'requested_value' => $requestedUsd,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('requested_value');
    }

    /** A valid native amount in the cart must not be flagged at checkout validation. */
    public function test_a_valid_native_amount_passes_checkout_validation(): void
    {
        $requestedUsd = 50000 / $this->rate;

        $add = $this->actingAs($this->user)->postJson(route('cart.items.add'), [
            'product_variant_id' => $this->variant->id,
            'quantity' => 1,
            'requested_value' => $requestedUsd,
        ])->assertOk();

        $cart = Cart::query()->latest('id')->firstOrFail();
        $cart->load('items.product', 'items.variant');

        // Must not throw "below_minimum_amount" / "above_maximum_amount".
        $totals = app(OrderValidationService::class)->validateForCheckout($cart);

        $this->assertSame('USD', $totals['currency']);
    }
}
