<?php

namespace Tests\Feature\Compliance;

use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Services\TransactionPinService;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ComplianceGatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zendit.api_key' => 'ZENDIT_API_KEY_MOCK',
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'services.nowpayments.api_key' => 'NOWPAYMENTS_KEY_MOCK',
        ]);

        Cache::flush();
    }

    public function test_unverified_user_is_always_blocked_at_checkout(): void
    {
        // Email verification is mandatory (no toggle): an unverified user is
        // stopped at checkout by the EnsureEmailVerified middleware.
        $user = User::factory()->unverified()->create();
        $this->seedCartFor($user);

        $response = $this->actingAs($user)->from(route('shop.checkout'))->post(route('checkout.process'), [
            'delivery_email' => 'buyer@example.test',
            'payment_method' => 'wallet',
            'currency' => 'USD',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_verified_user_passes_the_checkout_email_gate(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->seedCartFor($user);

        $response = $this->actingAs($user)->post(route('checkout.process'), [
            'delivery_email' => 'buyer@example.test',
            'payment_method' => 'wallet',
            'currency' => 'USD',
        ]);

        // Past the email gate; whatever the checkout does next, it is not an
        // email-verification block.
        $response->assertSessionDoesntHaveErrors('email');
    }

    public function test_kyc_gate_off_lets_unverified_user_request_withdrawal(): void
    {
        Setting::set('withdrawal_enabled', true, 'boolean');
        Setting::set('require_kyc_for_withdrawal', false, 'boolean');
        Setting::set('withdrawal_min_rcoin', 100, 'integer');
        Setting::set('withdrawal_minimum_usd', 0.10, 'float');
        Setting::set('withdrawal_conversion_rate', 0.005, 'float');

        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);
        Wallet::create([
            'user_id' => $user->id,
            'currency' => Currency::RCOIN,
            'balance' => 500,
            'locked_balance' => 0,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('dashboard.rewards.withdraw'), [
            'withdraw_amount' => 200,
            'withdraw_method' => 'wallet',
        ]);

        $response->assertSessionDoesntHaveErrors('withdraw_amount');
    }

    public function test_kyc_gate_on_blocks_unverified_user_withdrawal(): void
    {
        Setting::set('withdrawal_enabled', true, 'boolean');
        Setting::set('require_kyc_for_withdrawal', true, 'boolean');
        Setting::set('withdrawal_min_rcoin', 100, 'integer');
        Setting::set('withdrawal_minimum_usd', 0.10, 'float');
        Setting::set('withdrawal_conversion_rate', 0.005, 'float');

        $user = User::factory()->create(['kyc_status' => 'pending']);
        Wallet::create([
            'user_id' => $user->id,
            'currency' => Currency::RCOIN,
            'balance' => 500,
            'locked_balance' => 0,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('dashboard.rewards.withdraw'), [
            'withdraw_amount' => 200,
            'withdraw_method' => 'wallet',
        ]);

        $response->assertSessionHasErrors(['withdraw_amount']);
        $this->assertStringContainsString('identity verification', session('errors')->first('withdraw_amount'));
    }

    public function test_kyc_gate_on_lets_verified_user_withdraw(): void
    {
        Setting::set('withdrawal_enabled', true, 'boolean');
        Setting::set('require_kyc_for_withdrawal', true, 'boolean');
        Setting::set('withdrawal_min_rcoin', 100, 'integer');
        Setting::set('withdrawal_minimum_usd', 0.10, 'float');
        Setting::set('withdrawal_conversion_rate', 0.005, 'float');

        $user = User::factory()->create(['kyc_status' => 'verified', 'email_verified_at' => now()]);
        Wallet::create([
            'user_id' => $user->id,
            'currency' => Currency::RCOIN,
            'balance' => 500,
            'locked_balance' => 0,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('dashboard.rewards.withdraw'), [
            'withdraw_amount' => 200,
            'withdraw_method' => 'wallet',
        ]);

        $response->assertSessionDoesntHaveErrors('withdraw_amount');
    }

    private function seedCartFor(User $user): Cart
    {
        app(TransactionPinService::class)->setupPin($user, '5283');
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
            'sku' => 'STEAM10-'.$user->id,
            'currency' => 'USD',
            'face_value' => 10.00,
            'cost_price' => 9.50,
            'retail_price' => 10.50,
            'is_available' => true,
        ]);

        PricingRule::firstOrCreate(['markup_type' => 'fixed', 'markup_value' => 1.00, 'is_active' => true]);

        Wallet::create([
            'user_id' => $user->id,
            'currency' => Currency::USD,
            'balance' => 100.00,
            'locked_balance' => 0.00,
            'is_active' => true,
        ]);

        $cart = Cart::create([
            'user_id' => $user->id,
            'status' => 'active',
            'last_activity_at' => now(),
        ]);

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
}
