<?php

namespace Tests\Feature\Api\V2;

use App\Domain\Security\Services\ApiTokenService;
use App\Domain\Security\Services\SessionRevocationService;
use App\Models\ExpoPushToken;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Registering a phone for push notifications.
 *
 * Called on launch and whenever the operating system rotates the token, which it
 * does without warning, so the endpoint has to survive being called constantly.
 *
 * The test that earns its keep is the sold-phone one. A push token identifies a
 * PHONE, not an account. Phones get sold, and shared between a customer and their
 * spouse. If registering a token did not take it from whoever held it before, the
 * previous owner's order and wallet notifications would go on arriving on somebody
 * else's lock screen.
 */
class PushTokenTest extends TestCase
{
    use RefreshDatabase;

    private const INSTALLATION = 'install-phone-1';

    private const TOKEN = 'ExponentPushToken[AAAAAAAAAAAAAAAAAAAAAA]';

    private User $user;

    private string $accessToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->accessToken = app(ApiTokenService::class)
            ->issuePair($this->user, self::INSTALLATION)
            ->accessToken;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function api(string $method, string $path, array $body = [], ?string $installation = self::INSTALLATION, ?string $bearer = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $headers = ['Authorization' => 'Bearer '.($bearer ?? $this->accessToken)];

        if ($installation !== null) {
            $headers['X-Installation-ID'] = $installation;
        }

        return $this->json($method, $path, $body, $headers);
    }

    public function test_a_phone_can_register_for_notifications(): void
    {
        $this->api('POST', '/v2/push/token', [
            'token' => self::TOKEN,
            'platform' => 'android',
            'app_version' => '1.0.0',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'registered');

        $row = ExpoPushToken::sole();

        $this->assertSame($this->user->id, $row->user_id);
        $this->assertSame(self::TOKEN, $row->token);
        $this->assertSame(self::INSTALLATION, $row->installation_id);
        $this->assertSame('android', $row->platform);
    }

    /**
     * The OS rotates tokens whenever it likes, so the app re-registers on every
     * launch. That must update the device's row rather than pile up duplicates.
     */
    public function test_re_registering_updates_the_device_instead_of_duplicating_it(): void
    {
        $this->api('POST', '/v2/push/token', ['token' => self::TOKEN])->assertOk();

        $rotated = 'ExponentPushToken[BBBBBBBBBBBBBBBBBBBBBB]';

        $this->api('POST', '/v2/push/token', ['token' => $rotated])->assertOk();

        $this->assertSame(1, ExpoPushToken::count());
        $this->assertSame($rotated, ExpoPushToken::sole()->token);
    }

    /**
     * THE one. The phone was sold, or is shared. Whoever registers the token now owns
     * it, and the previous holder stops receiving anything on it.
     */
    public function test_registering_a_token_takes_it_from_whoever_held_it_before(): void
    {
        $this->api('POST', '/v2/push/token', ['token' => self::TOKEN])->assertOk();

        $newOwner = User::factory()->create();
        $theirAccess = app(ApiTokenService::class)->issuePair($newOwner, 'install-phone-2')->accessToken;

        $this->api('POST', '/v2/push/token', ['token' => self::TOKEN], 'install-phone-2', $theirAccess)
            ->assertOk();

        // Exactly one row holds the token, and it is the new owner's.
        $row = ExpoPushToken::sole();

        $this->assertSame($newOwner->id, $row->user_id);
        $this->assertSame(0, ExpoPushToken::where('user_id', $this->user->id)->count());
    }

    public function test_a_device_can_stop_receiving_notifications(): void
    {
        $this->api('POST', '/v2/push/token', ['token' => self::TOKEN])->assertOk();

        $this->api('DELETE', '/v2/push/token')
            ->assertOk()
            ->assertJsonPath('data.status', 'unregistered');

        $this->assertSame(0, ExpoPushToken::count());
    }

    /**
     * A signed-out phone must stop receiving pushes, or somebody's wallet activity
     * keeps appearing on a lock screen with no account open on it.
     */
    public function test_signing_out_stops_the_pushes_for_that_phone(): void
    {
        $this->api('POST', '/v2/push/token', ['token' => self::TOKEN])->assertOk();

        $this->api('POST', '/v2/auth/logout')->assertOk();

        $this->assertSame(0, ExpoPushToken::count());
    }

    public function test_signing_out_everywhere_stops_the_pushes_on_every_phone(): void
    {
        $this->api('POST', '/v2/push/token', ['token' => self::TOKEN])->assertOk();

        $tablet = app(ApiTokenService::class)->issuePair($this->user, 'install-tablet')->accessToken;

        $this->api('POST', '/v2/push/token', [
            'token' => 'ExponentPushToken[CCCCCCCCCCCCCCCCCCCCCC]',
        ], 'install-tablet', $tablet)->assertOk();

        $this->assertSame(2, ExpoPushToken::count());

        $this->api('POST', '/v2/auth/logout-all')->assertOk();

        $this->assertSame(0, ExpoPushToken::count());
    }

    /**
     * A garbage value would sit in the table forever and every push to it would fail,
     * which is noise we would then have to diagnose.
     */
    public function test_something_that_is_not_a_push_token_is_refused(): void
    {
        $this->api('POST', '/v2/push/token', ['token' => 'not-a-real-token'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['token']]]);

        $this->assertSame(0, ExpoPushToken::count());
    }

    public function test_registering_requires_naming_the_device(): void
    {
        $this->api('POST', '/v2/push/token', ['token' => self::TOKEN], null)
            ->assertStatus(400)
            ->assertJsonPath('error.type', 'installation_id_required');
    }

    public function test_registering_requires_a_signed_in_customer(): void
    {
        $this->postJson('/v2/push/token', ['token' => self::TOKEN], [
            'X-Installation-ID' => self::INSTALLATION,
        ])->assertUnauthorized();
    }

    // --------------------------------------------- signed out means silent

    /**
     * Revoking a device from the security centre kills its tokens. It has to kill
     * its push registration too, or the signed-out phone keeps showing this
     * account's wallet activity on its lock screen.
     */
    public function test_revoking_the_device_removes_its_push_registration(): void
    {
        Notification::fake();
        Mail::fake();

        $this->api('POST', '/v2/push/token', ['token' => self::TOKEN, 'platform' => 'android'])->assertOk();

        $device = TrustedDevice::create([
            'user_id' => $this->user->id,
            'device_uuid' => self::INSTALLATION,
            'device_name' => 'Pixel',
            'os' => 'Android',
            'browser' => 'App',
            'device_type' => 'mobile',
            'ip_address' => '102.89.44.7',
            'trust_score' => 80,
            'trusted_since' => now()->subMonth(),
            'last_seen_at' => now(),
            'fingerprint' => hash('sha256', self::INSTALLATION),
        ]);

        app(SessionRevocationService::class)->revokeDevice($device);

        $this->assertSame(0, ExpoPushToken::count());
    }

    public function test_changing_the_password_removes_the_phones_push_registration(): void
    {
        $this->api('POST', '/v2/push/token', ['token' => self::TOKEN, 'platform' => 'ios'])->assertOk();

        $this->user->forceFill(['password' => Hash::make('New-Passw0rd!1')])->save();

        $this->assertSame(0, ExpoPushToken::count());
    }
}
