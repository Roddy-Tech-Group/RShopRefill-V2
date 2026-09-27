<?php

namespace Tests\Feature\Security;

use App\Domain\Security\Events\NewDeviceDetected;
use App\Domain\Security\Events\OtpRequested;
use App\Domain\Security\Services\SecurityPolicyService;
use App\Http\Controllers\Security\DeviceVerificationController;
use App\Models\CommunicationLog;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\UserLoginCountry;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;
use Tests\TestCase;

/**
 * The device email-OTP verify step. The rule pinned here: a six-digit code
 * cannot be brute-forced inside its lifetime. Wrong guesses are capped and the
 * code is burned after the fifth miss, forcing the customer to request a new
 * one. Comparison is constant-time (hash_equals).
 *
 * And what a passed code does to the device: a new device becomes trusted and
 * the customer is told about it, while a device that was already trusted keeps
 * its trust, its 24-hour hold stays finished, and no "new device" email goes
 * out about it.
 */
class DeviceOtpVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function pending(User $user): void
    {
        Cache::put('device_otp_'.$user->id, '123456', now()->addMinutes(10));

        $this->actingAs($user)->withSession([
            'pending_email_otp' => true,
            'pending_device_id' => 999999, // no such device; the verify path tolerates it
        ]);
    }

    public function test_correct_code_verifies_the_device(): void
    {
        $user = User::factory()->create();
        $this->pending($user);

        $response = $this->post(route('security.verify-device.verify'), ['otp' => '123456']);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionMissing('pending_email_otp');
        $this->assertNull(Cache::get('device_otp_'.$user->id));
    }

    public function test_code_is_burned_after_five_wrong_guesses(): void
    {
        $user = User::factory()->create();
        $this->pending($user);

        // Four misses: rejected, but the code survives.
        for ($i = 0; $i < 4; $i++) {
            $this->from(route('security.verify-device'))
                ->post(route('security.verify-device.verify'), ['otp' => '000000'])
                ->assertSessionHasErrors('otp');
        }
        $this->assertNotNull(Cache::get('device_otp_'.$user->id), 'Code should survive the first four misses.');

        // Fifth miss burns the code.
        $this->from(route('security.verify-device'))
            ->post(route('security.verify-device.verify'), ['otp' => '000000'])
            ->assertSessionHasErrors('otp');
        $this->assertNull(Cache::get('device_otp_'.$user->id), 'Code should be burned after the fifth miss.');

        // Even the correct code no longer works: it must be re-requested.
        $this->from(route('security.verify-device'))
            ->post(route('security.verify-device.verify'), ['otp' => '123456'])
            ->assertSessionHasErrors('otp');
    }

    /**
     * The page is six single-digit boxes assembled into the one `otp` field the
     * server has always read. Only the first box offers the code from the mail.
     */
    public function test_the_page_is_six_digit_boxes_feeding_one_hidden_code_field(): void
    {
        $user = User::factory()->create();
        $this->pending($user);

        $html = $this->get(route('security.verify-device'))->assertOk()->getContent();

        $this->assertSame(6, preg_match_all('/<input[^>]*inputmode="numeric"[^>]*maxlength="1"/', $html));
        $this->assertSame(1, substr_count($html, 'autocomplete="one-time-code"'));
        $this->assertStringContainsString('<input type="hidden" name="otp" :value="code">', $html);

        // The old single wide field is gone.
        $this->assertStringNotContainsString('placeholder="------"', $html);
        $this->assertStringNotContainsString('tracking-[0.5em]', $html);
    }

    /**
     * The "expires in" clock starts from the lifetime the server stamped when it
     * issued the code, and the address it went to is masked past two characters.
     */
    public function test_the_page_shows_the_real_remaining_lifetime_and_a_masked_address(): void
    {
        $user = User::factory()->create(['email' => 'roddy@example.com']);
        $this->pending($user);
        Cache::put('device_otp_expires_'.$user->id, time() + 300, now()->addMinutes(5));

        $html = $this->get(route('security.verify-device'))->assertOk()->getContent();

        // A few seconds may pass between the stamp and the render.
        $this->assertMatchesRegularExpression('/expiresIn: (29[0-9]|300),/', $html);
        $this->assertStringContainsString('ro***@example.com', $html);
        $this->assertStringNotContainsString('roddy@example.com', $html);
    }

    /** A code with no stamp (issued before the stamp existed) shows no clock rather than a made-up one. */
    public function test_without_a_stamp_the_clock_is_absent(): void
    {
        $user = User::factory()->create();
        $this->pending($user);

        $html = $this->get(route('security.verify-device'))->assertOk()->getContent();

        $this->assertStringContainsString('expiresIn: null,', $html);
    }

    /** Resending stamps the new code's lifetime beside it, at the one TTL the controller declares. */
    public function test_a_resent_code_is_stamped_with_its_lifetime(): void
    {
        Event::fake([OtpRequested::class]);

        $user = User::factory()->create();
        $this->pending($user);

        $this->from(route('security.verify-device'))
            ->post(route('security.verify-device.send'))
            ->assertRedirect(route('security.verify-device'));

        $stamp = Cache::get('device_otp_expires_'.$user->id);
        $ttl = DeviceVerificationController::OTP_TTL_MINUTES * 60;

        $this->assertIsInt($stamp);
        $this->assertEqualsWithDelta(time() + $ttl, $stamp, 5);
    }

    /** Burning or using a code takes its stamp with it, so a stale clock never shows for a dead code. */
    public function test_a_used_code_takes_its_stamp_with_it(): void
    {
        $user = User::factory()->create();
        $this->pending($user);
        Cache::put('device_otp_expires_'.$user->id, time() + 300, now()->addMinutes(5));

        $this->post(route('security.verify-device.verify'), ['otp' => '123456'])->assertRedirect(route('dashboard'));

        $this->assertNull(Cache::get('device_otp_expires_'.$user->id));
    }

    /**
     * A device this account has used for two months, well past the 24-hour
     * hold on sensitive actions.
     */
    private function longTrustedDevice(User $user): TrustedDevice
    {
        return TrustedDevice::query()->create([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', 'owners-phone-'.$user->id),
            'user_agent' => 'Symfony',
            'trusted_since' => now()->subMonths(2)->startOfSecond(),
            'trust_score' => 70,
            'last_seen_at' => now()->subHours(2),
        ]);
    }

    /** A code is waiting for this exact device, the way a challenged login leaves it. */
    private function pendingForDevice(User $user, TrustedDevice $device): void
    {
        Cache::put('device_otp_'.$user->id, '123456', now()->addMinutes(10));

        $this->actingAs($user)->withSession([
            'pending_email_otp' => true,
            'pending_device_id' => $device->id,
        ]);
    }

    private function newDeviceEmailWasQueued(User $user): bool
    {
        return CommunicationLog::query()
            ->where('user_id', $user->id)
            ->where('category', 'new_device')
            ->exists();
    }

    /**
     * The login country check asks a code of a device the customer has used for
     * months, because the login came from another country. Passing it proves
     * the country. It must not also make the device new again: that restarted
     * the 24-hour hold on withdrawals and PIN changes, and emailed the customer
     * about a "new device" that was their own phone.
     */
    public function test_a_trusted_device_passing_a_country_challenge_keeps_its_trust(): void
    {
        Event::fake([OtpRequested::class]);
        config(['location.maxmind.local.path' => base_path('composer.json')]);
        Location::shouldReceive('get')->andReturnUsing(fn (string $ip) => match ($ip) {
            '8.8.8.8' => Position::make(['countryCode' => 'US', 'countryName' => 'United States']),
            default => false,
        });

        $user = User::factory()->create(['email_otp_preference' => 'never']);
        $device = $this->longTrustedDevice($user);
        $trustedSince = $device->trusted_since->toIso8601String();

        // Seen in Cameroon half an hour ago, and never proved the United States.
        UserLoginCountry::query()->create([
            'user_id' => $user->id,
            'country_code' => 'CM',
            'country_name' => 'Cameroon',
            'last_seen_at' => now()->subMinutes(30),
            'proven_at' => now()->subMonth(),
        ]);

        // The same phone signs in from the United States.
        $this->app['request']->server->set('REMOTE_ADDR', '8.8.8.8');
        $this->app['request']->cookies->set('device_uuid', $device->device_uuid);
        event(new Login('web', $user, false));

        $this->assertTrue(session('pending_email_otp'), 'the country change must ask for the code');
        $this->assertSame($device->id, session('pending_device_id'), 'the code is asked of the trusted device itself');

        $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->post(route('security.verify-device.verify'), ['otp' => Cache::get('device_otp_'.$user->id)])
            ->assertRedirect(route('dashboard'));

        $device->refresh();
        $this->assertSame($trustedSince, $device->trusted_since->toIso8601String(), 'the trust clock must not restart');
        $this->assertSame(70, (int) $device->trust_score, 'the trust score must not drop back to a new device\'s');
        $this->assertTrue(app(SecurityPolicyService::class)->isDeviceMature($device), 'the 24-hour hold must stay finished');
        $this->assertFalse($this->newDeviceEmailWasQueued($user), 'no "new device" email about the customer\'s own phone');

        // The code still proves the country.
        $this->assertNotNull(UserLoginCountry::where('user_id', $user->id)->where('country_code', 'US')->sole()->proven_at);
    }

    /**
     * A customer who asks for a code on every login passes one every time. Each
     * pass used to make their long-trusted device new again, so the hold on
     * sensitive actions never finished for them.
     */
    public function test_a_trusted_device_passing_an_every_login_code_keeps_its_trust(): void
    {
        $user = User::factory()->create(['email_otp_preference' => 'always']);
        $device = $this->longTrustedDevice($user);
        $trustedSince = $device->trusted_since->toIso8601String();
        $this->pendingForDevice($user, $device);

        $this->post(route('security.verify-device.verify'), ['otp' => '123456'])
            ->assertRedirect(route('dashboard'));

        $device->refresh();
        $this->assertSame($trustedSince, $device->trusted_since->toIso8601String());
        $this->assertSame(70, (int) $device->trust_score);
        $this->assertTrue(app(SecurityPolicyService::class)->isDeviceMature($device));
        $this->assertFalse($this->newDeviceEmailWasQueued($user));
    }

    /** A genuinely new device is unchanged: it becomes trusted, starts its hold, and the customer is told. */
    public function test_a_new_device_passing_the_code_becomes_trusted_and_is_announced(): void
    {
        Event::fake([NewDeviceDetected::class]);

        $user = User::factory()->create();
        $this->longTrustedDevice($user);

        $newDevice = TrustedDevice::query()->create([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', 'new-laptop'),
            'device_name' => 'Windows PC',
            'trust_score' => 20,
        ]);
        $this->pendingForDevice($user, $newDevice);

        $this->post(route('security.verify-device.verify'), ['otp' => '123456'])
            ->assertRedirect(route('dashboard'));

        $newDevice->refresh();
        $this->assertTrue($newDevice->isTrusted());
        $this->assertSame(40, (int) $newDevice->trust_score);
        $this->assertFalse(app(SecurityPolicyService::class)->isDeviceMature($newDevice), 'a just-trusted device is still inside its hold');
        Event::assertDispatched(NewDeviceDetected::class, fn (NewDeviceDetected $event) => $event->device->is($newDevice));
    }

    /** The "new device" email really goes out for a new device, so its absence above means something. */
    public function test_a_new_device_passing_the_code_queues_the_new_device_email(): void
    {
        $user = User::factory()->create();
        $this->longTrustedDevice($user);

        $newDevice = TrustedDevice::query()->create([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', 'new-laptop'),
            'device_name' => 'Windows PC',
            'trust_score' => 20,
        ]);
        $this->pendingForDevice($user, $newDevice);

        $this->post(route('security.verify-device.verify'), ['otp' => '123456'])
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($this->newDeviceEmailWasQueued($user));
    }
}
