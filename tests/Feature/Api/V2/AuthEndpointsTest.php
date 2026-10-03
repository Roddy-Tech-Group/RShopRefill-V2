<?php

namespace Tests\Feature\Api\V2;

use App\Domain\Security\Services\ApiTokenService;
use App\Models\ApiRefreshToken;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * The app's front door: register, sign in, prove a new device, refresh, sign out.
 *
 * The thing most of these tests exist to hold in place is that a phone gets in
 * under the SAME rules as a browser. The website challenges a sign-in from an
 * unrecognised device with an emailed code, and the app has to as well: if /v2
 * simply handed out tokens after a password check, the app would be the way
 * around the whole device-trust regime, and nothing in the website's own tests
 * would notice.
 */
class AuthEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_HEADERS = ['X-Installation-ID' => 'install-phone-1'];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Notification::fake();
    }

    /**
     * Laravel reuses ONE application instance across test HTTP calls and
     * Sanctum's guard memoises the user it resolved. Without forgetting the
     * guards, a second call in the same test authenticates as whoever the first
     * call resolved, whatever token it carries.
     */
    private function forgetGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function tokenHeaders(string $token, array $extra = []): array
    {
        return array_merge(self::PHONE_HEADERS, ['Authorization' => 'Bearer '.$token], $extra);
    }

    private function callMe(string $token): TestResponse
    {
        $this->forgetGuards();

        return $this->getJson('/v2/auth/me', $this->tokenHeaders($token));
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Maxwell',
            'last_name' => 'Johnpaul',
            'email' => 'maxwell@example.com',
            'country' => 'NG',
            'phone' => '8031234567',
            'gender' => 'male',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
            'terms' => true,
        ], $overrides);
    }

    private function customer(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'customer@example.com',
            'password' => Hash::make('Str0ng-Passw0rd!'),
        ], $overrides));
    }

    /** A device that is already known, so the next NEW one is a real new device. */
    private function existingTrustedDevice(User $user): TrustedDevice
    {
        return TrustedDevice::create([
            'user_id' => $user->id,
            'device_uuid' => 'install-laptop',
            'trust_score' => 50,
            'trusted_since' => now()->subMonth(),
            'ip_address' => '102.89.1.1',
            'user_agent' => 'Mozilla/5.0',
            // NOT NULL on the table; DeviceTrustManager computes it from the
            // installation id, agent and address. Any stable value will do here.
            'fingerprint' => hash('sha256', 'install-laptop'),
        ]);
    }

    private function codeFor(User $user): string
    {
        return (string) Cache::get('device_otp_'.$user->id);
    }

    // ---------------------------------------------------------------- register

    public function test_registering_creates_a_complete_account_and_signs_it_in(): void
    {
        $response = $this->postJson('/v2/auth/register', $this->registrationPayload(), self::PHONE_HEADERS);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'authenticated')
            ->assertJsonPath('data.user.email', 'maxwell@example.com')
            ->assertJsonPath('data.tokens.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['tokens' => [
                'access_token', 'expires_in', 'expires_at', 'refresh_token', 'refresh_expires_at',
            ]]]);

        $user = User::sole();

        // The two name fields fold back into the one column every screen reads.
        $this->assertSame('Maxwell Johnpaul', $user->name);
        // Stored in E.164, the only format every gateway reads the same way.
        $this->assertSame('+2348031234567', $user->phone);
        // Stamped, so the account never lands in the profile-completion hole.
        $this->assertNotNull($user->profile_completed_at);

        // The account's first device is trusted at birth, so no code is demanded
        // for the very phone somebody just registered on.
        $this->assertTrue(TrustedDevice::sole()->isTrusted());
    }

    /**
     * expires_in must be an INTEGER. Carbon's diffInSeconds returns a float, so
     * without a cast this goes out as 3600.0, and a typed client generated from
     * the contract rejects it. The app cannot be corrected once installed, so the
     * wire types are pinned.
     */
    public function test_the_token_lifetime_is_sent_as_an_integer(): void
    {
        $tokens = $this->postJson('/v2/auth/register', $this->registrationPayload(), self::PHONE_HEADERS)
            ->json('data.tokens');

        $this->assertIsInt($tokens['expires_in']);
        $this->assertGreaterThan(0, $tokens['expires_in']);
    }

    public function test_registering_fires_the_registered_event_so_the_wallet_exists(): void
    {
        $this->postJson('/v2/auth/register', $this->registrationPayload(), self::PHONE_HEADERS)
            ->assertCreated();

        $this->assertTrue(User::sole()->wallets()->exists());
    }

    public function test_the_issued_access_token_works_immediately(): void
    {
        $token = $this->postJson('/v2/auth/register', $this->registrationPayload(), self::PHONE_HEADERS)
            ->json('data.tokens.access_token');

        $this->callMe($token)
            ->assertOk()
            ->assertJsonPath('data.user.email', 'maxwell@example.com');
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        $this->customer(['email' => 'maxwell@example.com']);

        $this->postJson('/v2/auth/register', $this->registrationPayload(), self::PHONE_HEADERS)
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['email']]]);
    }

    /**
     * The same line written two ways. A unique rule on the national number could
     * not see that these are one phone, so the check is on E.164.
     */
    public function test_the_same_phone_in_another_format_is_refused(): void
    {
        $this->customer(['email' => 'other@example.com', 'phone' => '+2348031234567']);

        $this->postJson('/v2/auth/register', $this->registrationPayload(['phone' => '08031234567']), self::PHONE_HEADERS)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['phone']]]);
    }

    public function test_registering_requires_accepting_the_terms(): void
    {
        $this->postJson('/v2/auth/register', $this->registrationPayload(['terms' => false]), self::PHONE_HEADERS)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['terms']]]);
    }

    /**
     * Without a stable installation id every request looks like a new device: a
     * new device row each time, a code demanded on every login, and the
     * new-device hold never passing. Refused clearly rather than degrading.
     */
    public function test_signing_up_without_naming_the_device_is_refused(): void
    {
        $this->postJson('/v2/auth/register', $this->registrationPayload())
            ->assertStatus(400)
            ->assertJsonPath('error.type', 'installation_id_required');

        $this->assertSame(0, User::count());
    }

    // ------------------------------------------------------------------- login

    public function test_a_known_device_signs_in_and_gets_tokens(): void
    {
        $user = $this->customer();
        // Its first device, so it is trusted at birth and needs no code.
        $response = $this->postJson('/v2/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'Str0ng-Passw0rd!',
        ], self::PHONE_HEADERS);

        $response->assertOk()->assertJsonPath('data.status', 'authenticated');

        $this->callMe($response->json('data.tokens.access_token'))
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_a_wrong_password_and_an_unknown_address_answer_identically(): void
    {
        $this->customer();

        $wrongPassword = $this->postJson('/v2/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'not-the-password',
        ], self::PHONE_HEADERS);

        $unknownAddress = $this->postJson('/v2/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'not-the-password',
        ], self::PHONE_HEADERS);

        $wrongPassword->assertUnauthorized()->assertJsonPath('error.type', 'invalid_credentials');
        $unknownAddress->assertUnauthorized()->assertJsonPath('error.type', 'invalid_credentials');

        // Byte-identical, so the endpoint cannot be used to find out which of our
        // customers' addresses are registered.
        $this->assertSame($wrongPassword->json(), $unknownAddress->json());
    }

    public function test_a_google_only_account_cannot_be_signed_into_with_a_password(): void
    {
        $this->customer(['password' => null, 'google_id' => 'google-123']);

        $this->postJson('/v2/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'anything',
        ], self::PHONE_HEADERS)
            ->assertUnauthorized()
            ->assertJsonPath('error.type', 'invalid_credentials');
    }

    public function test_a_banned_account_cannot_sign_in(): void
    {
        $this->customer(['banned_at' => now()]);

        $this->postJson('/v2/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'Str0ng-Passw0rd!',
        ], self::PHONE_HEADERS)
            ->assertForbidden()
            ->assertJsonPath('error.type', 'account_banned');
    }

    public function test_a_closed_account_cannot_sign_in(): void
    {
        $this->customer(['anonymised_at' => now()]);

        $this->postJson('/v2/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'Str0ng-Passw0rd!',
        ], self::PHONE_HEADERS)
            ->assertForbidden()
            ->assertJsonPath('error.type', 'account_closed');
    }

    // --------------------------------------------------------- device challenge

    /**
     * THE one that matters. A sign-in from a device this account has never used
     * must be challenged on the app exactly as it is in a browser, and must NOT
     * receive tokens until the code is right.
     */
    public function test_a_new_device_is_challenged_and_gets_no_tokens(): void
    {
        $user = $this->customer();
        $this->existingTrustedDevice($user);

        $response = $this->postJson('/v2/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'Str0ng-Passw0rd!',
        ], self::PHONE_HEADERS);

        $response->assertOk()
            ->assertJsonPath('data.status', 'verification_required')
            ->assertJsonStructure(['data' => ['challenge_token', 'challenge_expires_at', 'sent_to']]);

        // No tokens anywhere in the answer, and none minted behind it.
        $this->assertNull($response->json('data.tokens'));
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertSame(0, ApiRefreshToken::count());

        // A code really was issued, and the address is masked.
        $this->assertNotSame('', $this->codeFor($user));
        $this->assertSame('cu******@example.com', $response->json('data.sent_to'));
    }

    /**
     * The challenge token is not a credential for anything else. It cannot read
     * a profile, an order or a wallet.
     */
    public function test_a_challenge_token_cannot_be_used_as_an_access_token(): void
    {
        $user = $this->customer();
        $this->existingTrustedDevice($user);

        $challenge = $this->postJson('/v2/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'Str0ng-Passw0rd!',
        ], self::PHONE_HEADERS)->json('data.challenge_token');

        $this->callMe($challenge)->assertUnauthorized();
    }

    public function test_the_right_code_finishes_the_sign_in_and_trusts_the_device(): void
    {
        $user = $this->customer();
        $this->existingTrustedDevice($user);

        $challenge = $this->postJson('/v2/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'Str0ng-Passw0rd!',
        ], self::PHONE_HEADERS)->json('data.challenge_token');

        $response = $this->postJson('/v2/auth/challenge/verify', [
            'challenge_token' => $challenge,
            'code' => $this->codeFor($user),
        ], self::PHONE_HEADERS);

        $response->assertOk()
            ->assertJsonPath('data.status', 'authenticated')
            ->assertJsonPath('data.user.id', $user->id);

        $this->callMe($response->json('data.tokens.access_token'))->assertOk();

        // The phone is now a known device, so the next sign-in is not challenged.
        $this->assertTrue(
            TrustedDevice::where('device_uuid', 'install-phone-1')->sole()->isTrusted(),
        );
    }

    public function test_a_wrong_code_leaves_the_sign_in_alive_to_retry(): void
    {
        $user = $this->customer();
        $this->existingTrustedDevice($user);

        $challenge = $this->postJson('/v2/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'Str0ng-Passw0rd!',
        ], self::PHONE_HEADERS)->json('data.challenge_token');

        // 422, not 401: the app should let them retype rather than throw them
        // back to the login screen.
        $this->postJson('/v2/auth/challenge/verify', [
            'challenge_token' => $challenge,
            'code' => '000000',
        ], self::PHONE_HEADERS)
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'code_invalid');

        // And the right code still works afterwards.
        $this->postJson('/v2/auth/challenge/verify', [
            'challenge_token' => $challenge,
            'code' => $this->codeFor($user),
        ], self::PHONE_HEADERS)->assertOk();
    }

    public function test_five_wrong_codes_burn_the_code_and_end_the_attempt(): void
    {
        $user = $this->customer();
        $this->existingTrustedDevice($user);

        $challenge = $this->postJson('/v2/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'Str0ng-Passw0rd!',
        ], self::PHONE_HEADERS)->json('data.challenge_token');

        $realCode = $this->codeFor($user);

        foreach (range(1, 4) as $ignored) {
            $this->postJson('/v2/auth/challenge/verify', [
                'challenge_token' => $challenge,
                'code' => '000000',
            ], self::PHONE_HEADERS)->assertStatus(422);
        }

        $this->postJson('/v2/auth/challenge/verify', [
            'challenge_token' => $challenge,
            'code' => '000000',
        ], self::PHONE_HEADERS)
            ->assertUnauthorized()
            ->assertJsonPath('error.type', 'code_burned');

        // Even the correct code is dead now: the budget bought five guesses out
        // of a million, and the sixth ends the attempt.
        $this->postJson('/v2/auth/challenge/verify', [
            'challenge_token' => $challenge,
            'code' => $realCode,
        ], self::PHONE_HEADERS)
            ->assertUnauthorized()
            ->assertJsonPath('error.type', 'challenge_expired');
    }

    /**
     * A challenge token lifted off the wire is useless on another phone: it only
     * finishes the sign-in that started on the device that began it.
     */
    public function test_a_challenge_cannot_be_completed_from_a_different_device(): void
    {
        $user = $this->customer();
        $this->existingTrustedDevice($user);

        $challenge = $this->postJson('/v2/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'Str0ng-Passw0rd!',
        ], self::PHONE_HEADERS)->json('data.challenge_token');

        $this->postJson('/v2/auth/challenge/verify', [
            'challenge_token' => $challenge,
            'code' => $this->codeFor($user),
        ], ['X-Installation-ID' => 'install-attacker'])
            ->assertUnauthorized()
            ->assertJsonPath('error.type', 'challenge_device_mismatch');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_an_invented_challenge_token_is_refused(): void
    {
        $this->postJson('/v2/auth/challenge/verify', [
            'challenge_token' => 'not-a-real-challenge',
            'code' => '123456',
        ], self::PHONE_HEADERS)
            ->assertUnauthorized()
            ->assertJsonPath('error.type', 'challenge_expired');
    }

    public function test_a_challenge_cannot_be_spent_twice(): void
    {
        $user = $this->customer();
        $this->existingTrustedDevice($user);

        $challenge = $this->postJson('/v2/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'Str0ng-Passw0rd!',
        ], self::PHONE_HEADERS)->json('data.challenge_token');

        $code = $this->codeFor($user);

        $this->postJson('/v2/auth/challenge/verify', [
            'challenge_token' => $challenge,
            'code' => $code,
        ], self::PHONE_HEADERS)->assertOk();

        $this->postJson('/v2/auth/challenge/verify', [
            'challenge_token' => $challenge,
            'code' => $code,
        ], self::PHONE_HEADERS)
            ->assertUnauthorized()
            ->assertJsonPath('error.type', 'challenge_expired');
    }

    public function test_asking_for_another_code_too_soon_is_refused_with_a_countdown(): void
    {
        $user = $this->customer();
        $this->existingTrustedDevice($user);

        $challenge = $this->postJson('/v2/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'Str0ng-Passw0rd!',
        ], self::PHONE_HEADERS)->json('data.challenge_token');

        // Login already sent one, with a one-minute cooldown behind it.
        $response = $this->postJson('/v2/auth/challenge/resend', [
            'challenge_token' => $challenge,
        ], self::PHONE_HEADERS);

        $response->assertStatus(429)->assertJsonPath('error.type', 'resend_cooldown');
        $this->assertGreaterThan(0, $response->json('error.retry_after'));
    }

    public function test_another_code_can_be_sent_once_the_cooldown_has_passed(): void
    {
        $user = $this->customer();
        $this->existingTrustedDevice($user);

        $challenge = $this->postJson('/v2/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'Str0ng-Passw0rd!',
        ], self::PHONE_HEADERS)->json('data.challenge_token');

        $first = $this->codeFor($user);

        $this->travel(61)->seconds();

        $this->postJson('/v2/auth/challenge/resend', [
            'challenge_token' => $challenge,
        ], self::PHONE_HEADERS)
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $this->assertNotSame($first, $this->codeFor($user));
    }

    // ----------------------------------------------------------------- refresh

    public function test_refreshing_returns_a_new_pair_and_kills_the_old_access_token(): void
    {
        $registered = $this->postJson('/v2/auth/register', $this->registrationPayload(), self::PHONE_HEADERS);

        $oldAccess = $registered->json('data.tokens.access_token');
        $refresh = $registered->json('data.tokens.refresh_token');

        $response = $this->postJson('/v2/auth/refresh', ['refresh_token' => $refresh], self::PHONE_HEADERS);

        $response->assertOk()->assertJsonPath('data.status', 'authenticated');

        $newAccess = $response->json('data.tokens.access_token');
        $this->assertNotSame($oldAccess, $newAccess);

        $this->callMe($newAccess)->assertOk();
        $this->callMe($oldAccess)->assertUnauthorized();
    }

    public function test_an_invented_refresh_token_is_refused(): void
    {
        $this->postJson('/v2/auth/refresh', ['refresh_token' => 'nope'], self::PHONE_HEADERS)
            ->assertUnauthorized()
            ->assertJsonPath('error.type', 'refresh_token_invalid');
    }

    // ----------------------------------------------------------------- session

    public function test_me_needs_a_token(): void
    {
        $this->getJson('/v2/auth/me', self::PHONE_HEADERS)
            ->assertUnauthorized()
            ->assertJsonPath('error.type', 'unauthenticated');
    }

    public function test_signing_out_ends_this_device_only(): void
    {
        $user = $this->customer();

        $phone = app(ApiTokenService::class)->issuePair($user, 'install-phone-1');
        $tablet = app(ApiTokenService::class)->issuePair($user, 'install-tablet');

        $this->forgetGuards();
        $this->postJson('/v2/auth/logout', [], $this->tokenHeaders($phone->accessToken))
            ->assertOk()
            ->assertJsonPath('data.status', 'signed_out');

        $this->callMe($phone->accessToken)->assertUnauthorized();
        $this->callMe($tablet->accessToken)->assertOk();
    }

    public function test_signing_out_everywhere_ends_every_device(): void
    {
        $user = $this->customer();

        $phone = app(ApiTokenService::class)->issuePair($user, 'install-phone-1');
        $tablet = app(ApiTokenService::class)->issuePair($user, 'install-tablet');

        $this->forgetGuards();
        $this->postJson('/v2/auth/logout-all', [], $this->tokenHeaders($phone->accessToken))
            ->assertOk()
            ->assertJsonPath('data.status', 'signed_out_everywhere');

        $this->callMe($phone->accessToken)->assertUnauthorized();
        $this->callMe($tablet->accessToken)->assertUnauthorized();
    }

    /**
     * EnsureAccountActive stops a banned customer in the browser. Without its
     * token-side counterpart, banning somebody would leave their phone working
     * until the token happened to expire.
     */
    public function test_banning_an_account_stops_its_phone_and_revokes_the_token(): void
    {
        $user = $this->customer();

        $pair = app(ApiTokenService::class)->issuePair($user, 'install-phone-1');

        $this->callMe($pair->accessToken)->assertOk();

        $user->forceFill(['banned_at' => now()])->save();

        $this->callMe($pair->accessToken)
            ->assertForbidden()
            ->assertJsonPath('error.type', 'account_banned');

        // Revoked, not merely refused, so the app is pushed back to the login
        // screen instead of retrying with something that will never work.
        $this->callMe($pair->accessToken)->assertUnauthorized();
    }

    // ---------------------------------------------------------------- password

    public function test_forgot_password_answers_the_same_for_a_known_and_an_unknown_address(): void
    {
        $this->customer();

        $known = $this->postJson('/v2/auth/password/forgot', ['email' => 'customer@example.com']);
        $unknown = $this->postJson('/v2/auth/password/forgot', ['email' => 'nobody@example.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_a_bad_reset_token_is_refused(): void
    {
        $this->customer();

        $this->postJson('/v2/auth/password/reset', [
            'token' => 'not-a-real-token',
            'email' => 'customer@example.com',
            'password' => 'An0ther-Passw0rd!',
            'password_confirmation' => 'An0ther-Passw0rd!',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'reset_token_invalid');
    }

    /**
     * The reason people reset a password is that somebody else may have it, so
     * leaving a thief's token alive would defeat the act.
     */
    public function test_resetting_the_password_signs_every_phone_out(): void
    {
        $user = $this->customer();

        $pair = app(ApiTokenService::class)->issuePair($user, 'install-phone-1');
        $this->callMe($pair->accessToken)->assertOk();

        $token = Password::createToken($user);

        $this->postJson('/v2/auth/password/reset', [
            'token' => $token,
            'email' => 'customer@example.com',
            'password' => 'An0ther-Passw0rd!',
            'password_confirmation' => 'An0ther-Passw0rd!',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'password_reset');

        $this->callMe($pair->accessToken)->assertUnauthorized();
        $this->assertSame(0, ApiRefreshToken::whereNull('revoked_at')->count());

        // And the new password actually works.
        $this->postJson('/v2/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'An0ther-Passw0rd!',
        ], self::PHONE_HEADERS)->assertOk();
    }

    // --------------------------------------------------------- audit parity

    /**
     * The website's guard fires Failed on a wrong password, and the audit log and
     * the alerting hang off it. A failed app sign-in has to leave the same trace,
     * and never the password.
     */
    public function test_a_failed_sign_in_is_recorded_like_the_websites(): void
    {
        Event::fake([Failed::class]);
        $this->customer();

        $this->postJson('/v2/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'wrong-password',
        ], self::PHONE_HEADERS)->assertUnauthorized();

        Event::assertDispatched(Failed::class, function (Failed $event) {
            return $event->guard === 'sanctum'
                && $event->user !== null
                && ! array_key_exists('password', $event->credentials);
        });
    }

    /**
     * The website signs a new registrant in, which fires Login and writes the
     * first-sign-in audit row. Registering on the app is that first sign-in too.
     */
    public function test_registering_fires_the_sign_in_event_the_listeners_expect(): void
    {
        Event::fake([Login::class]);

        $this->postJson('/v2/auth/register', $this->registrationPayload(), self::PHONE_HEADERS)
            ->assertCreated();

        Event::assertDispatched(Login::class, fn (Login $event) => $event->guard === 'sanctum');
    }
}
