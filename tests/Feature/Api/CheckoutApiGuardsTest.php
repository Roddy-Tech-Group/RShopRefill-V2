<?php

namespace Tests\Feature\Api;

use App\Domain\Fraud\Services\FraudDetectionService;
use App\Models\Cart;
use App\Models\Setting;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The API checkout route reaches the same placeOrder as the web form, so it
 * must pass the same gates. It used to skip the admin checkout kill switch,
 * the email-verification requirement and the fraud guard entirely: an admin
 * could "turn checkout off" and the API would keep selling.
 */
class CheckoutApiGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function postCheckout(User $user, array $payload = []): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/checkout/place-order', $payload + [
            'cart_id' => '00000000-0000-0000-0000-000000000000',
            'payment_method' => 'flutterwave',
            'preferred_currency' => 'USD',
        ]);
    }

    public function test_checkout_kill_switch_refuses_the_api_route(): void
    {
        SiteSetting::put('features.checkout_enabled', 'off', 'features');
        Cache::flush();

        // 503 before validation: "checkout is off" must not depend on the
        // caller first supplying a valid cart.
        $this->postCheckout(User::factory()->create())->assertStatus(503);
    }

    public function test_unverified_email_is_refused_when_the_setting_requires_it(): void
    {
        Setting::create(['key' => 'require_email_verified_for_checkout', 'value' => '1']);

        $unverified = User::factory()->create(['email_verified_at' => null]);

        $this->postCheckout($unverified)
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Please verify your email address before checking out.']);
    }

    public function test_suspicious_checkout_is_refused_by_the_fraud_guard(): void
    {
        $fraud = $this->createMock(FraudDetectionService::class);
        $fraud->method('isSuspiciousCheckout')->willReturn(true);
        $this->app->instance(FraudDetectionService::class, $fraud);

        $user = User::factory()->create();
        $cart = Cart::create(['user_id' => $user->id, 'status' => 'active', 'last_activity_at' => now()]);

        $this->postCheckout($user, ['cart_id' => $cart->id])->assertStatus(403);
    }
}
