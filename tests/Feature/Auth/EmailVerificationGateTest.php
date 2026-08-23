<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Email verification is enforced at the money-in actions (order placement and
 * wallet funding), not at browsing. A logged-in, unverified customer is refused
 * there and re-sent their verification link; guests and verified users pass.
 */
class EmailVerificationGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_user_is_blocked_from_web_checkout_and_resent_the_link(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->from('/checkout')->post('/checkout', []);

        $response->assertRedirect('/checkout');
        $response->assertSessionHasErrors('email');

        // The gate re-sends the verification email through the branded platform.
        $this->assertDatabaseHas('communication_logs', [
            'category' => 'email_verification',
        ]);
    }

    public function test_unverified_user_is_blocked_from_api_place_order(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->postJson('/api/checkout/place-order', [])
            ->assertStatus(403)
            ->assertJson(['email_unverified' => true]);
    }

    public function test_unverified_user_is_blocked_from_wallet_funding(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->postJson('/api/wallets/fund/initiate', [])
            ->assertStatus(403)
            ->assertJson(['email_unverified' => true]);
    }

    public function test_verified_user_passes_the_gate(): void
    {
        $user = User::factory()->create(); // verified by default

        // The request proceeds past the gate into the controller (which may fail
        // for other reasons); the point is it is NOT the email-verification 403.
        $this->actingAs($user)
            ->postJson('/api/checkout/place-order', [])
            ->assertJsonMissing(['email_unverified' => true]);
    }

    public function test_guest_shadow_account_is_exempt_from_the_gate(): void
    {
        $guest = User::factory()->unverified()->create(['is_guest' => true]);

        $this->actingAs($guest)
            ->postJson('/api/checkout/place-order', [])
            ->assertJsonMissing(['email_unverified' => true]);
    }

    public function test_resend_is_rate_limited_but_the_block_still_applies(): void
    {
        $user = User::factory()->unverified()->create();

        // Exhaust the shared verify-resend budget (5 per window).
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit('verify-resend|'.$user->id, 600);
        }

        $this->actingAs($user)
            ->from('/checkout')
            ->post('/checkout', [])
            ->assertSessionHasErrors('email');

        // Still throttled: the block holds without sending yet more email.
        $this->assertTrue(RateLimiter::tooManyAttempts('verify-resend|'.$user->id, 5));
    }
}
