<?php

namespace Tests\Feature\Wallet;

use App\Domain\Wallet\Events\TransactionPinResetRequested;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Requesting a transaction-PIN reset used to need nothing but a session and
 * had no limit: a stolen cookie could rotate the wallet PIN, and every click
 * sent another email. It now needs the account password (for accounts that
 * have one) and allows three requests an hour, wrong passwords included.
 */
class TransactionPinResetGuardTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPin(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'transaction_pin' => Hash::make('4826'),
            'transaction_pin_set_at' => now(),
            'pin_status' => 'active',
        ], $overrides));

        // A mature device, so the 24-hour new-device hold is not what refuses.
        TrustedDevice::create([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', 'this-device'),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony',
            'trusted_since' => now()->subDays(2),
            'trust_score' => 40,
        ]);

        return $user;
    }

    public function test_the_api_requires_the_password_and_caps_requests_per_hour(): void
    {
        Event::fake([TransactionPinResetRequested::class]);
        $user = $this->userWithPin();

        $this->actingAs($user)
            ->postJson('/api/wallets/pin/reset/request')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->postJson('/api/wallets/pin/reset/request', ['password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password' => 'That password is incorrect.']);

        Event::assertNotDispatched(TransactionPinResetRequested::class);

        $this->postJson('/api/wallets/pin/reset/request', ['password' => 'password'])
            ->assertOk();

        Event::assertDispatchedTimes(TransactionPinResetRequested::class, 1);

        // The two failed attempts spent the budget too: this is the fourth.
        $this->postJson('/api/wallets/pin/reset/request', ['password' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        Event::assertDispatchedTimes(TransactionPinResetRequested::class, 1);
    }

    public function test_a_passwordless_account_can_still_request_a_reset(): void
    {
        Event::fake([TransactionPinResetRequested::class]);
        $user = $this->userWithPin(['password' => null, 'google_id' => 'g-1']);

        $this->actingAs($user)
            ->postJson('/api/wallets/pin/reset/request')
            ->assertOk();

        Event::assertDispatched(TransactionPinResetRequested::class);
    }

    public function test_the_security_centre_button_requires_the_password(): void
    {
        Event::fake([TransactionPinResetRequested::class]);
        $user = $this->userWithPin();
        $this->actingAs($user);

        Volt::test('settings.transaction-pin')
            ->call('requestReset')
            ->assertHasErrors(['reset_password']);

        Volt::test('settings.transaction-pin')
            ->set('reset_password', 'wrong')
            ->call('requestReset')
            ->assertHasErrors(['reset_password'])
            ->assertSee('That password is incorrect.');

        Event::assertNotDispatched(TransactionPinResetRequested::class);

        Volt::test('settings.transaction-pin')
            ->set('reset_password', 'password')
            ->call('requestReset')
            ->assertHasNoErrors();

        Event::assertDispatched(TransactionPinResetRequested::class);
    }

    public function test_a_hard_locked_customer_is_pointed_at_forgot_pin(): void
    {
        $user = $this->userWithPin(['pin_status' => 'locked', 'transaction_pin_attempts' => 10]);
        $this->actingAs($user);

        Volt::test('settings.transaction-pin')
            ->assertSet('locked', true)
            ->assertSet('hardLocked', true)
            ->assertSee('Use Forgot PIN below to set a new one');
    }
}
