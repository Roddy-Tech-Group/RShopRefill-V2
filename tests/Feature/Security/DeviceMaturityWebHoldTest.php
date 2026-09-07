<?php

namespace Tests\Feature\Security;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Loose ends from the security cleanup, each one a behaviour that used to be
 * declared but never wired:
 *
 *  - the maturity hold now covers money OUT (the Rcoin withdrawal form) and
 *    answers a web form with an error on the page, not a JSON body;
 *  - a PIN cleared by support ("reset_required") is finally read somewhere:
 *    the PIN screen tells the customer to set a new one;
 *  - the storefront cart API asked a non-existent `sanctum` guard for the
 *    user and so treated every signed-in shopper as a guest.
 */
class DeviceMaturityWebHoldTest extends TestCase
{
    use RefreshDatabase;

    private function userOnDevice(\DateTimeInterface $trustedSince): User
    {
        $user = User::factory()->create();

        TrustedDevice::create([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', 'this-device'),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony',
            'trusted_since' => $trustedSince,
            'trust_score' => 40,
        ]);

        return $user;
    }

    public function test_the_withdrawal_form_is_held_on_a_new_device_with_an_error_on_the_page(): void
    {
        $user = $this->userOnDevice(now());

        $this->actingAs($user)
            ->from('/dashboard/rewards')
            ->post(route('dashboard.rewards.withdraw'), ['withdraw_amount' => 5000])
            ->assertRedirect('/dashboard/rewards')
            ->assertSessionHasErrors(['device']);
    }

    public function test_a_mature_device_reaches_the_withdrawal_controller(): void
    {
        $user = $this->userOnDevice(now()->subDays(2));

        $this->actingAs($user)
            ->from('/dashboard/rewards')
            ->post(route('dashboard.rewards.withdraw'), ['withdraw_amount' => 5000])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors(['device']);
    }

    public function test_a_customer_whose_pin_was_reset_by_support_is_told_to_set_a_new_one(): void
    {
        $user = User::factory()->create(['transaction_pin' => null, 'pin_status' => 'reset_required']);
        $this->actingAs($user);

        $component = Volt::test('settings.transaction-pin')
            ->assertSet('resetRequired', true)
            ->assertSee('Our support team reset your transaction PIN');

        $component->set('pin', '4826')->set('pin_confirmation', '4826')->call('setupPin')
            ->assertHasNoErrors()
            ->assertSet('resetRequired', false);

        $this->assertSame('active', $user->fresh()->pin_status);
    }

    public function test_the_storefront_cart_api_recognises_the_signed_in_shopper(): void
    {
        $user = User::factory()->create();

        // 201 on the first call: the shopper's cart is created on demand.
        $this->actingAs($user)->withSession(['auth.session_version' => 0])
            ->getJson('/api/storefront/cart')
            ->assertSuccessful();

        $this->assertDatabaseHas('carts', ['user_id' => $user->id]);
    }
}
