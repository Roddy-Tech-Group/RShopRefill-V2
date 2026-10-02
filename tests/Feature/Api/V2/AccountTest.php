<?php

namespace Tests\Feature\Api\V2;

use App\Domain\Security\Services\ApiTokenService;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The customer's own account: details, devices, identity status.
 *
 * Two of these are about what is NOT here, and both are deliberate.
 *
 * The email address cannot be changed through /v2. On the web that flow re-proves
 * the password, notifies the old address and re-verifies the new one, because the
 * email is the account. A thinner version here would be the weakest door into every
 * account we have.
 *
 * And the device list does not publish the fingerprint, the trust score or the full
 * IP. The score would tell an attacker how close their device is to being trusted;
 * the address helps no customer answer "was this me?" that the city does not.
 */
class AccountTest extends TestCase
{
    use RefreshDatabase;

    private const INSTALLATION = 'install-phone-1';

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->user = User::factory()->create([
            'email' => 'maxwell@example.com',
            'password' => Hash::make('Str0ng-Passw0rd!'),
            'country' => 'NG',
            'phone' => '+2348031111111',
        ]);

        $this->token = app(ApiTokenService::class)
            ->issuePair($this->user, self::INSTALLATION)
            ->accessToken;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function api(string $method, string $path, array $body = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, $path, $body, [
            'Authorization' => 'Bearer '.$this->token,
            'X-Installation-ID' => self::INSTALLATION,
        ]);
    }

    private function device(array $overrides = [], ?User $owner = null): TrustedDevice
    {
        $uuid = $overrides['device_uuid'] ?? 'install-laptop';

        return TrustedDevice::create(array_merge([
            'user_id' => ($owner ?? $this->user)->id,
            'device_uuid' => $uuid,
            'device_name' => 'Maxwell MacBook',
            'os' => 'macOS',
            'browser' => 'Chrome',
            'device_type' => 'desktop',
            'ip_address' => '102.89.44.7',
            'city' => 'Port Harcourt',
            'country' => 'Nigeria',
            'country_code' => 'NG',
            'trust_score' => 80,
            'trusted_since' => now()->subMonth(),
            'last_seen_at' => now(),
            'fingerprint' => hash('sha256', $uuid),
        ], $overrides));
    }

    // ------------------------------------------------------------------ profile

    public function test_a_profile_can_be_updated_one_field_at_a_time(): void
    {
        $this->api('PUT', '/v2/account/profile', ['name' => 'Maxwell Johnpaul'])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Maxwell Johnpaul');

        // The omitted fields survive.
        $this->assertSame('NG', $this->user->fresh()->country);
        $this->assertSame('+2348031111111', $this->user->fresh()->phone);
    }

    public function test_a_phone_number_is_stored_canonically(): void
    {
        $this->api('PUT', '/v2/account/profile', ['phone' => '08032222222', 'country' => 'NG'])
            ->assertOk();

        // E.164, the one format every gateway and SMS provider reads the same way.
        $this->assertSame('+2348032222222', $this->user->fresh()->phone);
    }

    /**
     * A comparison on the national number could not see that 0803... and +234803...
     * are the same line, which is how two accounts end up claiming one number.
     */
    public function test_a_number_already_on_another_account_is_refused(): void
    {
        User::factory()->create(['phone' => '+2348039999999']);

        $this->api('PUT', '/v2/account/profile', ['phone' => '08039999999', 'country' => 'NG'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['phone']]]);
    }

    /**
     * The email is the account. Sending one must not quietly move it.
     */
    public function test_the_email_address_cannot_be_changed_here(): void
    {
        $this->api('PUT', '/v2/account/profile', [
            'name' => 'Maxwell Johnpaul',
            'email' => 'attacker@example.com',
        ])->assertOk();

        $this->assertSame('maxwell@example.com', $this->user->fresh()->email);
    }

    public function test_an_invalid_country_is_refused(): void
    {
        $this->api('PUT', '/v2/account/profile', ['country' => 'ZZ'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['country']]]);
    }

    // ------------------------------------------------------------------ devices

    public function test_the_device_list_shows_the_callers_own_devices_and_marks_this_one(): void
    {
        $this->device(['device_uuid' => self::INSTALLATION, 'device_name' => 'Maxwell iPhone']);
        $this->device(['device_uuid' => 'install-laptop']);
        $this->device(['device_uuid' => 'install-theirs'], User::factory()->create());

        $devices = $this->api('GET', '/v2/account/devices')->assertOk()->json('data');

        $this->assertCount(2, $devices);

        $current = collect($devices)->firstWhere('is_current', true);

        $this->assertNotNull($current, 'the phone making the request should be marked');
        $this->assertSame('Maxwell iPhone', $current['name']);
        $this->assertSame('Port Harcourt', $current['location']['city']);
    }

    public function test_the_device_list_never_publishes_our_own_risk_signals(): void
    {
        $this->device();

        $body = $this->api('GET', '/v2/account/devices')->assertOk()->getContent();

        // The score would tell an attacker how close their device is to trusted.
        $this->assertStringNotContainsString('trust_score', $body);
        $this->assertStringNotContainsString('fingerprint', $body);
        // The city answers "was this me?"; the exact address helps nobody.
        $this->assertStringNotContainsString('102.89.44.7', $body);
    }

    public function test_signing_a_device_out_needs_the_account_password(): void
    {
        $device = $this->device();

        $this->api('DELETE', '/v2/account/devices/'.$device->id)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['password']]]);

        $this->api('DELETE', '/v2/account/devices/'.$device->id, ['password' => 'wrong-password'])
            ->assertStatus(422);

        $this->api('DELETE', '/v2/account/devices/'.$device->id, ['password' => 'Str0ng-Passw0rd!'])
            ->assertOk()
            ->assertJsonPath('data.status', 'device_signed_out');
    }

    public function test_another_customers_device_cannot_be_signed_out(): void
    {
        $theirs = $this->device(['device_uuid' => 'install-theirs'], User::factory()->create());

        $this->api('DELETE', '/v2/account/devices/'.$theirs->id, ['password' => 'Str0ng-Passw0rd!'])
            ->assertNotFound()
            ->assertJsonPath('error.type', 'device_not_found');

        $this->assertNotNull($theirs->fresh());
    }

    // ----------------------------------------------------------------- security

    public function test_the_otp_preference_can_be_changed_from_a_settled_device(): void
    {
        $this->device(['device_uuid' => self::INSTALLATION, 'trusted_since' => now()->subMonth()]);

        $this->api('PUT', '/v2/account/security/otp-preference', ['email_otp_preference' => 'always'])
            ->assertOk()
            ->assertJsonPath('data.email_otp_preference', 'always');

        $this->assertSame('always', $this->user->fresh()->email_otp_preference);
    }

    /**
     * Relaxing this is among the first things an account thief reaches for, so it sits
     * behind the 24-hour new-device hold. The refusal arrives in the v2 envelope even
     * though the middleware is shared with the website, because the normaliser
     * guarantees that.
     */
    public function test_a_brand_new_device_cannot_relax_the_otp_preference(): void
    {
        $this->device(['device_uuid' => self::INSTALLATION, 'trusted_since' => now()]);

        $this->api('PUT', '/v2/account/security/otp-preference', ['email_otp_preference' => 'never'])
            ->assertForbidden()
            ->assertJsonPath('error.type', 'device_restricted');

        $this->assertNotSame('never', $this->user->fresh()->email_otp_preference);
    }

    // ---------------------------------------------------------------------- KYC

    public function test_identity_status_can_be_read(): void
    {
        $this->api('GET', '/v2/account/kyc')
            ->assertOk()
            ->assertJsonStructure(['data' => ['status', 'verified', 'tier']]);
    }

    public function test_the_account_endpoints_need_a_signed_in_customer(): void
    {
        $this->getJson('/v2/account/devices')->assertUnauthorized();
        $this->getJson('/v2/account/kyc')->assertUnauthorized();
    }

    // -------------------------------------------- the hold cannot be dodged

    /**
     * The website's hold resolved the device from the session, then the header,
     * then an IP match, and let the request through when all three failed. On a
     * token API that meant omitting the header switched the hold OFF. On /v2 the
     * device is named by the installation id or the request is refused.
     */
    public function test_the_new_device_hold_cannot_be_dodged_by_omitting_the_installation_id(): void
    {
        $this->device(['device_uuid' => self::INSTALLATION, 'trusted_since' => now()]);

        $this->app['auth']->forgetGuards();

        $this->json('PUT', '/v2/account/security/otp-preference', ['email_otp_preference' => 'never'], [
            'Authorization' => 'Bearer '.$this->token,
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.type', 'installation_id_required');

        $this->assertNotSame('never', $this->user->fresh()->email_otp_preference);
    }

    public function test_the_new_device_hold_cannot_be_dodged_with_an_installation_id_we_have_never_seen(): void
    {
        $this->device(['device_uuid' => self::INSTALLATION, 'trusted_since' => now()]);

        $this->app['auth']->forgetGuards();

        $this->json('PUT', '/v2/account/security/otp-preference', ['email_otp_preference' => 'never'], [
            'Authorization' => 'Bearer '.$this->token,
            'X-Installation-ID' => 'install-nobody-knows',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.type', 'device_restricted');

        $this->assertNotSame('never', $this->user->fresh()->email_otp_preference);
    }

    /**
     * A customer whose account was just taken over is signing in from a NEW phone
     * themselves. The password is their proof; the hold must not make them wait a
     * day to cut the thief loose.
     */
    public function test_a_password_holder_on_a_brand_new_device_can_still_cut_a_thief_loose(): void
    {
        $this->device(['device_uuid' => self::INSTALLATION, 'trusted_since' => now()]);
        $thief = $this->device(['device_uuid' => 'install-thief', 'device_name' => 'Unknown Android']);

        $this->api('DELETE', '/v2/account/devices/'.$thief->id, ['password' => 'Str0ng-Passw0rd!'])
            ->assertOk()
            ->assertJsonPath('data.status', 'device_signed_out');

        $this->assertNull($thief->fresh()->trusted_since);
    }

    /**
     * No password means nothing to prove with, so for a Google-only account the hold
     * is the only protection, and it applies.
     */
    public function test_a_google_only_account_on_a_brand_new_device_cannot_revoke_a_device(): void
    {
        $this->user->forceFill(['password' => null, 'google_id' => 'g-123'])->save();

        // Clearing the password IS a password change, which signs every phone out.
        // This phone signs back in.
        $this->token = app(ApiTokenService::class)->issuePair($this->user, self::INSTALLATION)->accessToken;

        $this->device(['device_uuid' => self::INSTALLATION, 'trusted_since' => now()]);
        $other = $this->device(['device_uuid' => 'install-other']);

        $this->api('DELETE', '/v2/account/devices/'.$other->id)
            ->assertForbidden()
            ->assertJsonPath('error.type', 'device_restricted');

        $this->assertNotNull($other->fresh()->trusted_since);
    }

    // --------------------------------------------------- profile hygiene

    /**
     * A verified phone is a verified NUMBER. Changing it cannot carry the
     * verification, or the identity tier it earned, across to a number nobody has
     * proved they hold.
     */
    public function test_changing_the_phone_number_clears_its_verification(): void
    {
        $this->user->forceFill(['phone_verified_at' => now()])->save();

        $this->api('PUT', '/v2/account/profile', ['phone' => '+2348032222222'])
            ->assertOk()
            ->assertJsonPath('data.user.phone_verified', false);

        $this->assertNull($this->user->fresh()->phone_verified_at);
    }

    public function test_a_display_currency_we_cannot_price_in_is_refused(): void
    {
        $this->api('PUT', '/v2/account/profile', ['display_currency' => 'ABC'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['display_currency']]]);
    }

    /**
     * An uploaded avatar is stored as a site-relative path. A browser on our domain
     * resolves that; an app cannot.
     */
    public function test_an_uploaded_avatar_is_published_as_an_absolute_url(): void
    {
        $this->user->forceFill(['avatar_url' => '/storage/avatars/1/me.jpg'])->save();

        $url = $this->api('GET', '/v2/auth/me')->assertOk()->json('data.user.avatar_url');

        $this->assertStringStartsWith('http', $url);
        $this->assertStringEndsWith('/storage/avatars/1/me.jpg', $url);
    }
}
