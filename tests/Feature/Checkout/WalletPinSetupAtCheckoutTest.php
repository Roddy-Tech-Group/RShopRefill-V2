<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A wallet payment with no transaction PIN yet stays inside the checkout.
 *
 * The "set it up" link in the failure message is a button that opens a PIN
 * setup step in the same modal. The PIN is saved through the same endpoint the
 * security page uses, and the kept submission is then sent again, so the
 * customer carries on to the PIN step and their order without ever leaving
 * the page.
 */
class WalletPinSetupAtCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function checkout(User $user, ?string $locale = null): string
    {
        $request = $this->actingAs($user);

        if ($locale !== null) {
            $request = $request->withSession(['locale' => $locale]);
        }

        return $request->get('/checkout')->assertOk()->getContent();
    }

    public function test_the_missing_pin_message_offers_a_button_that_opens_setup_in_the_modal(): void
    {
        $html = $this->checkout(User::factory()->create());

        // The link is a button now, and no longer sends the customer to settings.
        $this->assertStringContainsString('data-pin-setup', $html);
        $this->assertStringNotContainsString('/dashboard/password', $html);
        $this->assertStringContainsString("closest('[data-pin-setup]')) { startPinSetup() }", $html);

        // The setup step ships with the page: two PIN fields, a save button, a way out.
        $this->assertStringContainsString("paymentState === 'wallet_pin_setup'", $html);
        $this->assertStringContainsString('x-ref="newPinInput"', $html);
        $this->assertStringContainsString('x-model="newPinConfirmation"', $html);
        $this->assertStringContainsString(__('checkout.wallet_pin_setup_title'), $html);
        $this->assertStringContainsString(__('checkout.wallet_pin_setup_later'), $html);

        // It saves through the PIN endpoint and then resubmits the kept form.
        $this->assertStringContainsString("fetch('/api/wallets/pin/setup'", $html);
        $this->assertStringContainsString('async savePinAndContinue()', $html);
        $this->assertStringContainsString('this.hasTransactionPin = true;', $html);
    }

    public function test_the_setup_endpoint_saves_a_pin_for_a_signed_in_customer(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/wallets/pin/setup', ['pin' => '5283', 'pin_confirmation' => '5283'])
            ->assertCreated();

        $this->assertTrue($user->fresh()->hasTransactionPin());
    }

    public function test_the_setup_endpoint_refuses_a_mismatched_pin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/wallets/pin/setup', ['pin' => '5283', 'pin_confirmation' => '5284'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin');

        $this->assertFalse($user->fresh()->hasTransactionPin());
    }

    public function test_the_setup_step_ships_in_french(): void
    {
        $html = $this->checkout(User::factory()->create(), 'fr');

        $this->assertStringContainsString(__('checkout.wallet_pin_setup_title', [], 'fr'), $html);
        $this->assertStringContainsString(__('checkout.wallet_pin_setup_save', [], 'fr'), $html);
        $this->assertStringNotContainsString('Set up your wallet PIN', $html);
    }
}
