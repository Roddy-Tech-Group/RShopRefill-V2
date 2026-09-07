<?php

namespace Tests\Feature\Security;

use App\Domain\Risk\Services\RiskEngine;
use App\Domain\Security\Events\OtpRequested;
use App\Domain\Security\Services\SecurityPolicyService;
use App\Models\CommunicationLog;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Stevebauman\Location\Facades\Location;
use Tests\TestCase;

/**
 * The device-trust lifecycle, tuned for UX that does not cry wolf:
 *
 *  - the account's FIRST device is trusted at birth (no "new device
 *    detected" seconds after registration, no OTP wall at rollout);
 *  - a genuinely new second device still gets the OTP challenge;
 *  - devices that sign in without needing OTP still become known, so the
 *    24-hour maturity clock can ever finish for "never" users;
 *  - the 24-hour hold guards the RAW API for PIN mutations and device
 *    revocation, not just the Livewire buttons in front of them;
 *  - "Forgot Password?" on the security page actually does something for a
 *    signed-in user instead of bouncing off the guest middleware.
 */
class DeviceTrustLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Location::shouldReceive('get')->andReturn(false);
    }

    private function login(User $user): void
    {
        // Pin the risk engine LOW so these tests exercise the device-trust
        // rules alone; risk-forced OTP has its own coverage elsewhere.
        $this->mock(RiskEngine::class)
            ->shouldReceive('evaluateLoginRisk')->andReturn('LOW');

        event(new Login('web', $user, false));
    }

    public function test_the_first_device_of_an_account_is_trusted_at_birth(): void
    {
        Event::fake([OtpRequested::class]);

        $user = User::factory()->create(['email_otp_preference' => 'new_device']);

        $this->login($user);

        $device = TrustedDevice::where('user_id', $user->id)->sole();
        $this->assertTrue($device->isTrusted(), 'the registration device must not be treated as an intruder');
        $this->assertNull(session('pending_email_otp'));
        Event::assertNotDispatched(OtpRequested::class);
    }

    public function test_a_second_unknown_device_still_gets_the_otp_challenge(): void
    {
        Event::fake([OtpRequested::class]);

        $user = User::factory()->create(['email_otp_preference' => 'new_device']);

        // The first device the account has ever seen, from another browser.
        TrustedDevice::create([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', 'other-browser'),
            'user_agent' => 'OtherBrowser/1.0',
            'trusted_since' => now()->subWeek(),
            'trust_score' => 40,
        ]);

        $this->login($user);

        $fresh = TrustedDevice::where('user_id', $user->id)->latest('id')->first();
        $this->assertFalse($fresh->isTrusted(), 'an unknown second device must stay untrusted until verified');
        $this->assertTrue(session('pending_email_otp'));
        Event::assertDispatched(OtpRequested::class);
    }

    public function test_a_login_that_needs_no_otp_still_starts_the_trust_clock(): void
    {
        Event::fake([OtpRequested::class]);

        $user = User::factory()->create(['email_otp_preference' => 'never']);

        TrustedDevice::create([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', 'other-browser'),
            'user_agent' => 'OtherBrowser/1.0',
            'trusted_since' => now()->subWeek(),
            'trust_score' => 40,
        ]);

        $this->login($user);

        $fresh = TrustedDevice::where('user_id', $user->id)->latest('id')->first();
        // No challenge for a "never" user, but the device becomes KNOWN, so
        // the 24-hour maturity hold can eventually pass. It used to stay
        // untrusted forever, locking these users out of sensitive changes.
        Event::assertNotDispatched(OtpRequested::class);
        $this->assertTrue($fresh->isTrusted());
        $this->assertFalse(
            app(SecurityPolicyService::class)->isDeviceMature($fresh),
            'freshly trusted means the 24h hold is still in force',
        );
    }

    public function test_the_api_blocks_pin_mutations_from_an_immature_device(): void
    {
        $user = User::factory()->create(['transaction_pin' => bcrypt('9482')]);

        // The device this test request will resolve to (ip + user agent).
        $device = TrustedDevice::create([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', 'this-device'),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony',
            'trusted_since' => now(),
            'trust_score' => 40,
        ]);

        $payload = ['old_pin' => '9482', 'new_pin' => '7391', 'new_pin_confirmation' => '7391'];

        $this->actingAs($user)
            ->putJson('/api/wallets/pin/change', $payload)
            ->assertStatus(403)
            ->assertJsonPath('device_restricted', true);

        // Same device, past the hold: the guard steps aside.
        $device->update(['trusted_since' => now()->subHours(25)]);

        $this->actingAs($user)
            ->putJson('/api/wallets/pin/change', $payload)
            ->assertSuccessful();
    }

    /**
     * Device revocation is gated by the account PASSWORD rather than the
     * maturity hold: an intruder holding a stolen session but not the
     * password still cannot cut the owner's phone loose, while the owner on
     * a brand-new laptop can revoke the stolen phone immediately instead of
     * waiting a day. Accounts with no password (Google-only) keep the hold.
     */
    public function test_the_api_gates_device_revocation_on_the_password_not_the_hold(): void
    {
        $user = User::factory()->create();

        TrustedDevice::create([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', 'this-device'),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony',
            'trusted_since' => now(),
            'trust_score' => 40,
        ]);

        $victim = TrustedDevice::create([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', 'owners-phone'),
            'user_agent' => 'iPhone',
            'trusted_since' => now()->subMonth(),
            'trust_score' => 40,
        ]);

        // The intruder has the session but not the password.
        $this->actingAs($user)
            ->deleteJson("/api/security/devices/{$victim->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->deleteJson("/api/security/devices/{$victim->id}", ['password' => 'not-it'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->assertTrue($victim->fresh()->isTrusted(), 'the owner\'s device must survive the intruder\'s revoke attempts');

        // The owner, minutes into a new device, proves the password and is
        // not made to wait out the hold.
        $this->deleteJson("/api/security/devices/{$victim->id}", ['password' => 'password'])
            ->assertOk();

        $this->assertFalse($victim->fresh()->isTrusted());
    }

    public function test_a_passwordless_account_keeps_the_hold_on_device_revocation(): void
    {
        $user = User::factory()->create(['password' => null, 'google_id' => 'g-123']);

        TrustedDevice::create([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', 'this-device'),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony',
            'trusted_since' => now(),
            'trust_score' => 40,
        ]);

        $victim = TrustedDevice::create([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', 'owners-phone'),
            'user_agent' => 'iPhone',
            'trusted_since' => now()->subMonth(),
            'trust_score' => 40,
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/security/devices/{$victim->id}")
            ->assertStatus(403)
            ->assertJsonPath('device_restricted', true);

        $this->assertTrue($victim->fresh()->isTrusted());
    }

    public function test_forgot_password_sends_a_reset_link_to_the_signed_in_user(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)
            ->test('settings.security')
            ->call('sendPasswordResetLink');

        // Password resets route through the Communication Platform, whose
        // log row is the proof-of-send the platform's own validator uses.
        $this->assertTrue(
            CommunicationLog::query()
                ->where('user_id', $user->id)
                ->where('category', 'password_reset')
                ->exists(),
            'the signed-in Forgot Password button must produce a reset email',
        );
    }
}
