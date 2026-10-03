<?php

namespace Tests\Feature\Api\V2;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Google sign-in from the app.
 *
 * The website completes an OAuth code exchange, which ties the result to our
 * client id and secret. A phone does not: it signs in natively and comes away
 * with an ID token, and the server has to decide for itself whether to believe
 * it.
 *
 * The audience test below is the important one. An ID token proves who the holder
 * is to whoever the token was issued FOR, so without checking that, a token
 * minted for ANY other Google application would sign that person into
 * RShopRefill. It is the standard way this integration is got wrong, and it fails
 * completely silently: every legitimate sign-in still works.
 */
class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    private const OURS = 'ours.apps.googleusercontent.com';

    private const HEADERS = ['X-Installation-ID' => 'install-phone-1'];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Notification::fake();
        Http::preventStrayRequests();

        config(['services.google.mobile_client_ids' => [self::OURS]]);
        config(['services.google.client_id' => null]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeGoogleClaims(array $overrides = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response(array_merge([
                'iss' => 'https://accounts.google.com',
                'aud' => self::OURS,
                'sub' => '1234567890',
                'email' => 'maxwell@gmail.com',
                'email_verified' => 'true',
                'name' => 'Maxwell Johnpaul',
                'given_name' => 'Maxwell',
                'picture' => 'https://lh3.googleusercontent.com/a/photo',
            ], $overrides), 200),
        ]);
    }

    private function signIn(): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/v2/auth/google', ['id_token' => 'a.b.c'], self::HEADERS);
    }

    public function test_a_valid_google_token_creates_the_account_and_signs_it_in(): void
    {
        $this->fakeGoogleClaims();

        $this->signIn()
            ->assertCreated()
            ->assertJsonPath('data.status', 'authenticated')
            ->assertJsonPath('data.user.email', 'maxwell@gmail.com');

        $user = User::sole();
        $this->assertSame('1234567890', $user->google_id);
        $this->assertSame('Maxwell Johnpaul', $user->name);
    }

    public function test_an_existing_google_customer_signs_straight_back_in(): void
    {
        $this->fakeGoogleClaims();
        $this->signIn()->assertCreated();

        $this->fakeGoogleClaims();
        $this->signIn()
            ->assertOk()
            ->assertJsonPath('data.status', 'authenticated');

        $this->assertSame(1, User::count());
    }

    /**
     * THE one. A token issued to somebody else's Google application must not sign
     * its holder into RShopRefill, however genuine the token is.
     */
    public function test_a_token_issued_to_another_application_is_refused(): void
    {
        $this->fakeGoogleClaims(['aud' => 'someone-elses-app.apps.googleusercontent.com']);

        $this->signIn()
            ->assertUnauthorized()
            ->assertJsonPath('error.type', 'google_token_rejected');

        $this->assertSame(0, User::count());
    }

    public function test_a_token_from_the_wrong_issuer_is_refused(): void
    {
        $this->fakeGoogleClaims(['iss' => 'https://accounts.evil.example']);

        $this->signIn()->assertUnauthorized();
        $this->assertSame(0, User::count());
    }

    /**
     * An unverified Google address must not be able to claim an account that
     * genuinely owns that address.
     */
    public function test_an_unverified_google_email_is_refused(): void
    {
        $this->fakeGoogleClaims(['email_verified' => 'false']);

        $this->signIn()->assertUnauthorized();
        $this->assertSame(0, User::count());
    }

    public function test_a_token_with_no_email_is_refused(): void
    {
        $this->fakeGoogleClaims(['email' => '']);

        $this->signIn()->assertUnauthorized();
    }

    /** Google answers 400 for an expired or malformed token. */
    public function test_an_expired_token_is_refused(): void
    {
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response(['error' => 'invalid_token'], 400),
        ]);

        $this->signIn()
            ->assertUnauthorized()
            ->assertJsonPath('error.type', 'google_token_rejected');
    }

    /**
     * Google being down is not a reason to let somebody in, and not a reason to
     * return a 500 either: the app should show "try again", not "something broke".
     */
    public function test_google_being_unreachable_refuses_rather_than_failing_open(): void
    {
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response('', 503),
        ]);

        $this->signIn()
            ->assertStatus(503)
            ->assertJsonPath('error.type', 'google_unavailable');
        $this->assertSame(0, User::count());
    }

    /**
     * With no client ids configured there is nothing to check the audience
     * against, so the only safe answer is to refuse. A deploy that forgets the
     * setting must not accept every Google token in the world.
     */
    public function test_google_sign_in_refuses_when_no_audience_is_configured(): void
    {
        config(['services.google.mobile_client_ids' => [], 'services.google.client_id' => null]);

        // Nothing should even be asked of Google.
        Http::fake();

        // Unavailable rather than rejected: nothing is wrong with the customer's
        // token, the deploy is missing its allowlist, and "sign in again" would
        // send them round in circles.
        $this->signIn()
            ->assertStatus(503)
            ->assertJsonPath('error.type', 'google_unavailable');

        Http::assertNothingSent();
    }

    public function test_google_sign_in_must_name_the_device(): void
    {
        $this->fakeGoogleClaims();

        $this->postJson('/v2/auth/google', ['id_token' => 'a.b.c'])
            ->assertStatus(400)
            ->assertJsonPath('error.type', 'installation_id_required');
    }

    public function test_the_id_token_is_required(): void
    {
        $this->postJson('/v2/auth/google', [], self::HEADERS)
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['id_token']]]);
    }

    /**
     * Google being down is "try again in a moment". A rejected token is "sign in
     * again". The app shows different screens, so they need different types.
     */
    public function test_a_google_outage_is_reported_as_unavailable_not_as_a_rejected_token(): void
    {
        Http::fake(['oauth2.googleapis.com/tokeninfo*' => Http::response('upstream error', 500)]);

        $this->signIn()
            ->assertStatus(503)
            ->assertJsonPath('error.type', 'google_unavailable');
    }

    /**
     * Google proves who the person is. It does not reopen an account we closed.
     */
    public function test_a_banned_customer_cannot_sign_in_with_google(): void
    {
        $this->fakeGoogleClaims();

        User::factory()->create([
            'email' => 'maxwell@gmail.com',
            'google_id' => '1234567890',
            'banned_at' => now(),
        ]);

        $this->signIn()
            ->assertForbidden()
            ->assertJsonPath('error.type', 'account_banned');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
