<?php

namespace Tests\Feature\Api;

use App\Domain\Risk\Services\RiskEngine;
use App\Domain\Security\Events\NewDeviceDetected;
use App\Domain\Security\Events\OtpRequested;
use App\Domain\Security\Services\SessionRevocationService;
use App\Models\PersonalAccessToken;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Stevebauman\Location\Facades\Location;
use Tests\TestCase;

/**
 * The mobile app's sign-in. What is pinned here is that it is the website's
 * sign-in by another road, not a softer one: the same password limit, the same
 * emailed device code for a phone the account has not seen, and a token that
 * ends on every action that ends a browser session (a removed device, "sign out
 * everywhere", a password change, a ban). And that browsers still reach the
 * same API with their session cookie.
 */
class MobileAuthTest extends TestCase
{
    use RefreshDatabase;

    private string $phone;

    protected function setUp(): void
    {
        parent::setUp();

        Location::shouldReceive('get')->andReturn(false);
        Cache::flush();

        // Pin the risk engine LOW so these tests exercise the device rules
        // alone; risk-forced codes have their own coverage.
        $this->mock(RiskEngine::class)->shouldReceive('evaluateLoginRisk')->andReturn('LOW');

        $this->phone = (string) Str::uuid();
    }

    private function app(?string $installationId = null): static
    {
        return $this->withHeaders([
            'Accept' => 'application/json',
            'X-Installation-ID' => $installationId ?? $this->phone,
        ]);
    }

    private function signIn(User $user): string
    {
        return $this->app()
            ->postJson(route('api.mobile.auth.login'), ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('status', 'signed_in')
            ->json('token');
    }

    private function aKnownOtherDevice(User $user): void
    {
        TrustedDevice::create([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', 'other-phone'),
            'user_agent' => 'OtherBrowser/1.0',
            'trusted_since' => now()->subWeek(),
            'trust_score' => 40,
        ]);
    }

    public function test_the_first_device_signs_in_straight_to_a_token_bound_to_it(): void
    {
        Event::fake([OtpRequested::class]);
        $user = User::factory()->create(['email_otp_preference' => 'new_device']);

        $token = $this->signIn($user);

        $row = PersonalAccessToken::sole();
        $this->assertSame($this->phone, $row->device->device_uuid);
        $this->assertTrue($row->device->isTrusted());
        $this->assertNotNull($row->userSession);
        $this->assertNotNull($row->expires_at);
        Event::assertNotDispatched(OtpRequested::class);

        $this->app()->withToken($token)->getJson(route('api.dashboard'))
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
        $this->app()->withToken($token)->getJson(route('api.mobile.me'))
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_a_phone_the_account_has_not_seen_must_enter_the_emailed_code(): void
    {
        Event::fake([OtpRequested::class, NewDeviceDetected::class]);
        $user = User::factory()->create(['email' => 'amina@example.com', 'email_otp_preference' => 'new_device']);
        $this->aKnownOtherDevice($user);

        $response = $this->app()
            ->postJson(route('api.mobile.auth.login'), ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'verification_required')
            ->assertJsonPath('masked_email', 'am***@example.com')
            ->assertJsonMissingPath('token');

        $this->assertSame(600, $response->json('expires_in'));
        $this->assertSame(60, $response->json('resend_in'));
        $this->assertSame(0, PersonalAccessToken::count());
        Event::assertDispatched(OtpRequested::class);

        $this->app()
            ->postJson(route('api.mobile.auth.verify-device'), [
                'challenge' => $response->json('challenge'),
                'code' => Cache::get('device_otp_'.$user->id),
            ])
            ->assertOk()
            ->assertJsonPath('status', 'signed_in');

        $device = TrustedDevice::where('device_uuid', $this->phone)->sole();
        $this->assertTrue($device->isTrusted());
        $this->assertSame(1, PersonalAccessToken::count());
        Event::assertDispatched(NewDeviceDetected::class);
    }

    public function test_the_code_is_burned_after_five_wrong_guesses(): void
    {
        Event::fake([OtpRequested::class]);
        $user = User::factory()->create(['email_otp_preference' => 'new_device']);
        $this->aKnownOtherDevice($user);
        $challenge = $this->app()
            ->postJson(route('api.mobile.auth.login'), ['email' => $user->email, 'password' => 'password'])
            ->json('challenge');
        $real = Cache::get('device_otp_'.$user->id);
        $wrong = $real === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 4; $i++) {
            $this->app()
                ->postJson(route('api.mobile.auth.verify-device'), ['challenge' => $challenge, 'code' => $wrong])
                ->assertStatus(422)
                ->assertJsonPath('errors.code.0', 'Invalid or expired code.');
        }

        $this->app()
            ->postJson(route('api.mobile.auth.verify-device'), ['challenge' => $challenge, 'code' => $wrong])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Too many incorrect attempts. Please request a new code.');

        // Burned: even the real code no longer works.
        $this->app()
            ->postJson(route('api.mobile.auth.verify-device'), ['challenge' => $challenge, 'code' => $real])
            ->assertStatus(422);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_a_challenge_only_answers_the_phone_it_began_on(): void
    {
        Event::fake([OtpRequested::class]);
        $user = User::factory()->create(['email_otp_preference' => 'new_device']);
        $this->aKnownOtherDevice($user);
        $challenge = $this->app()
            ->postJson(route('api.mobile.auth.login'), ['email' => $user->email, 'password' => 'password'])
            ->json('challenge');

        $this->app((string) Str::uuid())
            ->postJson(route('api.mobile.auth.verify-device'), [
                'challenge' => $challenge,
                'code' => Cache::get('device_otp_'.$user->id),
            ])
            ->assertStatus(401);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_resending_waits_as_the_web_does(): void
    {
        Event::fake([OtpRequested::class]);
        $user = User::factory()->create(['email_otp_preference' => 'new_device']);
        $this->aKnownOtherDevice($user);
        $challenge = $this->app()
            ->postJson(route('api.mobile.auth.login'), ['email' => $user->email, 'password' => 'password'])
            ->json('challenge');

        // The first code set a one-minute wait.
        $this->app()
            ->postJson(route('api.mobile.auth.resend-code'), ['challenge' => $challenge])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Please wait before requesting another code.');

        // The minute passes: the wait is read against PHP's own clock, which
        // the test clock does not move, so the wait is lifted instead.
        Cache::forget('otp_throttle_'.$user->id);

        $this->app()
            ->postJson(route('api.mobile.auth.resend-code'), ['challenge' => $challenge])
            ->assertOk()
            ->assertJsonPath('resend_in', 180);
        Event::assertDispatchedTimes(OtpRequested::class, 2);
    }

    public function test_a_wrong_password_is_refused_and_shares_the_web_limit(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->app()
                ->postJson(route('api.mobile.auth.login'), ['email' => $user->email, 'password' => 'wrong'])
                ->assertStatus(422)
                ->assertJsonPath('errors.email.0', __('auth.failed'));
        }

        // Locked, even with the right password, until the minute is out.
        $this->app()
            ->postJson(route('api.mobile.auth.login'), ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(429);
    }

    public function test_sign_in_needs_the_phones_installation_id(): void
    {
        $user = User::factory()->create();

        $this->withHeaders(['Accept' => 'application/json'])
            ->postJson(route('api.mobile.auth.login'), ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('device');
    }

    public function test_a_token_does_not_work_from_another_phone(): void
    {
        $user = User::factory()->create();
        $token = $this->signIn($user);

        $this->app((string) Str::uuid())->withToken($token)->getJson(route('api.dashboard'))
            ->assertStatus(401);
        $this->assertSame(0, PersonalAccessToken::count(), 'a token tried from the wrong phone is deleted');
    }

    public function test_sign_out_everywhere_ends_the_app_token(): void
    {
        $user = User::factory()->create();
        $token = $this->signIn($user);

        app(SessionRevocationService::class)->revokeAll($user->fresh());

        $this->app()->withToken($token)->getJson(route('api.dashboard'))->assertStatus(401);
    }

    public function test_removing_the_device_in_the_security_centre_ends_the_app_token(): void
    {
        $user = User::factory()->create();
        $token = $this->signIn($user);

        app(SessionRevocationService::class)->revokeDevice(
            TrustedDevice::where('device_uuid', $this->phone)->sole(),
            notifyOwner: false,
        );

        $this->app()->withToken($token)->getJson(route('api.dashboard'))->assertStatus(401);
    }

    public function test_a_password_change_ends_the_app_token(): void
    {
        $user = User::factory()->create();
        $token = $this->signIn($user);

        $user->forceFill(['password' => Hash::make('a-new-password')])->save();

        $this->app()->withToken($token)->getJson(route('api.dashboard'))->assertStatus(401);
    }

    public function test_a_banned_account_loses_its_token(): void
    {
        $user = User::factory()->create();
        $token = $this->signIn($user);

        $user->forceFill(['banned_at' => now()])->save();

        $this->app()->withToken($token)->getJson(route('api.dashboard'))
            ->assertStatus(403)
            ->assertJsonPath('message', 'Your account has been banned. Please contact support.');
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_signing_out_ends_the_token(): void
    {
        $user = User::factory()->create();
        $token = $this->signIn($user);

        $this->app()->withToken($token)->postJson(route('api.mobile.auth.logout'))->assertNoContent();

        $this->assertSame(0, PersonalAccessToken::count());
        $this->app()->withToken($token)->getJson(route('api.dashboard'))->assertStatus(401);
    }

    public function test_a_browser_session_still_reaches_the_api(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('api.dashboard'))
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }
}
