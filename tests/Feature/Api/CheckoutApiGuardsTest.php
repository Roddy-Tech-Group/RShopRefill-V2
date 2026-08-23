<?php

namespace Tests\Feature\Api;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RiskLabel;
use App\Models\SiteSetting;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The API checkout route reaches the same placeOrder as the web form, so it
 * must pass the same gates. It used to skip the admin checkout kill switch,
 * the email-verification requirement and the risk guard entirely: an admin
 * could "turn checkout off" and the API would keep selling.
 */
class CheckoutApiGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
            'pricing.floor_mode' => 'legacy',
        ]);
    }

    private function postCheckout(User $user, array $payload = []): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/checkout/place-order', $payload + [
            'cart_id' => '00000000-0000-0000-0000-000000000000',
            'payment_method' => 'flutterwave',
            'preferred_currency' => 'USD',
        ]);
    }

    /**
     * A valid cart with one purchasable item, so placeOrder reaches the risk gate
     * instead of failing cart validation first.
     */
    private function cartWithItem(User $user): Cart
    {
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
        PricingRule::create(['markup_type' => 'fixed', 'markup_value' => 1.00, 'is_active' => true]);

        $cart = Cart::create(['user_id' => $user->id, 'status' => 'active', 'last_activity_at' => now()]);
        CartItem::create([
            'cart_id' => $cart->id,
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

        return $cart;
    }

    public function test_checkout_kill_switch_refuses_the_api_route(): void
    {
        SiteSetting::put('features.checkout_enabled', 'off', 'features');
        Cache::flush();

        // 503 before validation: "checkout is off" must not depend on the
        // caller first supplying a valid cart.
        $this->postCheckout(User::factory()->create())->assertStatus(503);
    }

    public function test_unverified_email_is_always_refused_at_checkout(): void
    {
        // Email verification is mandatory: the EnsureEmailVerified middleware
        // refuses an unverified user before placeOrder runs, no setting needed.
        $unverified = User::factory()->create(['email_verified_at' => null]);

        $this->postCheckout($unverified)
            ->assertStatus(403)
            ->assertJsonFragment(['email_unverified' => true]);
    }

    public function test_a_risk_denied_checkout_is_refused_by_the_engine(): void
    {
        // Risk engine enforcing; a confirmed-fraud label forces a deny override.
        SiteSetting::put('features.risk_engine_enabled', 'on', 'features');
        SiteSetting::put('features.risk_engine_enforce_enabled', 'on', 'features');
        Cache::flush();

        $user = User::factory()->create();
        $cart = $this->cartWithItem($user);
        RiskLabel::create([
            'user_id' => $user->id,
            'label' => RiskLabel::LABEL_CONFIRMED_FRAUD,
            'source' => 'admin',
        ]);

        $this->postCheckout($user, ['cart_id' => $cart->id])
            ->assertStatus(403)
            ->assertJsonFragment(['risk_declined' => true]);
    }

    public function test_a_checkout_past_the_daily_spending_cap_is_refused(): void
    {
        SiteSetting::put('features.spending_limits_enforce_enabled', 'on', 'features');
        Cache::flush();
        config(['limits.standard.day' => 2000]);

        $user = User::factory()->create();
        $cart = $this->cartWithItem($user); // 11.00 USD
        Order::factory()->create([
            'user_id' => $user->id,
            'total_amount' => 1995,
            'created_at' => now(),
        ]);

        $this->postCheckout($user, ['cart_id' => $cart->id])
            ->assertStatus(422)
            ->assertJsonFragment(['limit_exceeded' => true]);
    }

    public function test_a_reviewed_checkout_is_held_with_a_neutral_message(): void
    {
        SiteSetting::put('features.risk_engine_enabled', 'on', 'features');
        SiteSetting::put('features.risk_engine_enforce_enabled', 'on', 'features');
        Cache::flush();

        // Map the critical band to review (not deny) and drop the override so the
        // confirmed-fraud signal scores into that band instead of forcing deny.
        config([
            'risk.overrides' => [],
            'risk.band_outcomes' => ['LOW' => 'allow', 'MEDIUM' => 'step_up', 'HIGH' => 'review', 'CRITICAL' => 'review'],
        ]);

        $user = User::factory()->create();
        $cart = $this->cartWithItem($user);
        RiskLabel::create([
            'user_id' => $user->id,
            'label' => RiskLabel::LABEL_CONFIRMED_FRAUD,
            'source' => 'admin',
        ]);

        $this->postCheckout($user, ['cart_id' => $cart->id])
            ->assertStatus(422)
            ->assertJsonFragment(['risk_review' => true]);

        $this->assertDatabaseHas('risk_reviews', ['user_id' => $user->id, 'status' => 'pending']);
    }
}
