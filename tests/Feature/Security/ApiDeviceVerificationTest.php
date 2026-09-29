<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A sign-in from a new device is not finished until the emailed code is
 * entered. The web group has always held such a session on the verify page,
 * but the API answers on the same session cookie and did not check: someone
 * with only the password could stop at the code prompt and read /api/orders,
 * delivered gift card codes included. The API now refuses that session too.
 */
class ApiDeviceVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_session_waiting_on_its_device_code_is_refused_by_the_api(): void
    {
        $user = User::factory()->create();
        Order::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->withSession(['pending_email_otp' => true])
            ->getJson('/api/orders')
            ->assertForbidden()
            ->assertJsonPath('device_verification_required', true)
            ->assertJsonMissingPath('data');
    }

    public function test_the_refusal_covers_money_moving_routes_as_well_as_reads(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['pending_email_otp' => true])
            ->postJson('/api/wallets/pin/setup', ['pin' => '4827', 'pin_confirmation' => '4827'])
            ->assertForbidden()
            ->assertJsonPath('device_verification_required', true);

        $this->assertNull($user->fresh()->transaction_pin);
    }

    public function test_a_verified_session_reaches_the_api_as_before(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('data.0.order_number', $order->order_number);
    }

    public function test_the_web_still_redirects_to_the_verify_page(): void
    {
        // The JSON refusal is for /api only; a page request keeps the
        // redirect to the code prompt it has always had.
        $this->actingAs(User::factory()->create())
            ->withSession(['pending_email_otp' => true])
            ->get(route('dashboard'))
            ->assertRedirect(route('security.verify-device'));
    }

    public function test_webhooks_carry_no_session_and_are_unaffected(): void
    {
        // Provider callbacks never hold a customer session, so the check has
        // nothing to act on. An unsigned call still fails on its signature,
        // not on device verification.
        $response = $this->postJson('/api/webhooks/flutterwave', []);

        $this->assertStringNotContainsString('device_verification_required', (string) $response->getContent());
    }
}
