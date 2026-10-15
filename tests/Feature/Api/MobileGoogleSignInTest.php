<?php

namespace Tests\Feature\Api;

use App\Domain\Risk\Services\RiskEngine;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Stevebauman\Location\Facades\Location;
use Tests\TestCase;

/**
 * The mobile app's Google sign-in: the website's own Google sign-in, opened in
 * the phone's browser, handing the account back to the app with a one-time
 * code that only the app that began it can redeem (AppGoogleHandoff). Pinned
 * here: the browser is never signed in by it, the code needs the app's secret
 * and works once, codes only ever go to the app's own link, and an account
 * Google just created is held until it gives what sign-up asks for.
 */
class MobileGoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    private const APP_LINK = 'rshoprefill://auth/google';

    private string $phone;

    private string $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        Location::shouldReceive('get')->andReturn(false);
        Cache::flush();
        $this->mock(RiskEngine::class)->shouldReceive('evaluateLoginRisk')->andReturn('LOW');

        $this->phone = (string) Str::uuid();
        $this->verifier = Str::random(64);
    }

    private function challenge(?string $verifier = null): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier ?? $this->verifier, true)), '+/', '-_'), '=');
    }

    private function googleReturns(SocialiteUser|\Exception $outcome): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        $provider->shouldReceive('setHttpClient')->andReturnSelf();
        $provider->shouldReceive('stateless')->andReturnSelf();

        if ($outcome instanceof \Exception) {
            $provider->shouldReceive('user')->andThrow($outcome);
        } else {
            $provider->shouldReceive('user')->andReturn($outcome);
        }

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function googleUser(string $email): SocialiteUser
    {
        return (new SocialiteUser)->setRaw(['email_verified' => true])->map([
            'id' => 'google-'.md5($email),
            'name' => 'Jane Doe',
            'nickname' => null,
            'email' => $email,
            'avatar' => 'https://lh3.googleusercontent.com/photo.jpg',
        ]);
    }

    /**
     * Google's callback in the phone's browser, for a sign-in the app began,
     * and what the browser is sent back to the app with.
     *
     * @return array<string, string>
     */
    private function backFromGoogle(): array
    {
        $location = (string) $this->withSession([
            'google_oauth_app' => ['challenge' => $this->challenge(), 'return' => self::APP_LINK],
        ])->get(route('auth.google.callback'))->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith(self::APP_LINK.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    private function app(): static
    {
        return $this->withHeaders(['Accept' => 'application/json', 'X-Installation-ID' => $this->phone]);
    }

    private function redeem(string $code, ?string $verifier = null): TestResponse
    {
        return $this->app()->postJson(route('api.mobile.auth.google'), [
            'code' => $code,
            'verifier' => $verifier ?? $this->verifier,
        ]);
    }

    public function test_the_app_opens_the_websites_google_sign_in(): void
    {
        $this->googleReturns($this->googleUser('jane@example.com'));

        $this->get(route('auth.google.app', ['challenge' => $this->challenge(), 'return' => self::APP_LINK]))
            ->assertRedirect('https://accounts.google.com/o/oauth2/auth')
            ->assertSessionHas('google_oauth_app', ['challenge' => $this->challenge(), 'return' => self::APP_LINK]);
    }

    public function test_a_code_only_ever_goes_to_the_apps_own_link(): void
    {
        $this->googleReturns($this->googleUser('jane@example.com'));

        $this->get(route('auth.google.app', ['challenge' => $this->challenge(), 'return' => 'https://evil.example/catch']))
            ->assertStatus(400);
        $this->get(route('auth.google.app', ['challenge' => $this->challenge(), 'return' => 'rshoprefill://elsewhere']))
            ->assertStatus(400);
        $this->get(route('auth.google.app', ['challenge' => 'not-a-challenge', 'return' => self::APP_LINK]))
            ->assertStatus(400);

        // Expo Go's link, while developing (never in production; see AppGoogleHandoff).
        $this->get(route('auth.google.app', [
            'challenge' => $this->challenge(),
            'return' => 'exp://192.168.1.231:8081/--/auth/google',
        ]))->assertRedirect('https://accounts.google.com/o/oauth2/auth');
    }

    public function test_an_existing_account_comes_back_to_the_app_and_is_signed_in_there(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);
        $this->googleReturns($this->googleUser('jane@example.com'));

        $query = $this->backFromGoogle();

        // The browser only carried the sign-in; it is not signed in by it.
        $this->assertGuest('web');
        $this->assertArrayHasKey('code', $query);

        $this->redeem($query['code'])
            ->assertOk()
            ->assertJsonPath('status', 'signed_in')
            ->assertJsonPath('user.email', 'jane@example.com')
            ->assertJsonPath('user.profile_completed', true);

        $this->assertSame($user->id, PersonalAccessToken::sole()->tokenable_id);
    }

    public function test_the_code_needs_the_secret_of_the_app_that_began_it_and_works_once(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);
        $this->googleReturns($this->googleUser('jane@example.com'));
        $code = $this->backFromGoogle()['code'];

        // An app that caught the link has the code but not the secret.
        $this->redeem($code, Str::random(64))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        // And the wrong try spent it, so the right secret is now too late.
        $this->redeem($code)->assertStatus(422);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_a_browser_signed_in_to_the_website_is_left_as_it_was(): void
    {
        $someoneElse = User::factory()->create();
        User::factory()->create(['email' => 'jane@example.com']);
        $this->googleReturns($this->googleUser('jane@example.com'));

        $this->actingAs($someoneElse);
        $this->assertArrayHasKey('code', $this->backFromGoogle());

        $this->assertAuthenticatedAs($someoneElse, 'web');
    }

    public function test_a_failed_google_sign_in_comes_back_to_the_app_as_an_error(): void
    {
        $this->googleReturns(new \RuntimeException('state mismatch'));

        $query = $this->backFromGoogle();

        $this->assertArrayNotHasKey('code', $query);
        $this->assertSame('Google authentication failed. Please try again.', $query['error']);
    }

    public function test_a_website_google_sign_in_forgets_an_app_one_left_unfinished(): void
    {
        $this->googleReturns($this->googleUser('jane@example.com'));

        $this->withSession(['google_oauth_app' => ['challenge' => $this->challenge(), 'return' => self::APP_LINK]])
            ->get(route('auth.google.redirect'))
            ->assertRedirect('https://accounts.google.com/o/oauth2/auth')
            ->assertSessionMissing('google_oauth_app');
    }

    public function test_a_new_google_account_is_held_until_it_gives_what_sign_up_asks_for(): void
    {
        $this->googleReturns($this->googleUser('new@example.com'));

        $token = $this->redeem($this->backFromGoogle()['code'])
            ->assertOk()
            ->assertJsonPath('status', 'signed_in')
            ->assertJsonPath('user.profile_completed', false)
            ->json('token');

        $signedIn = fn () => $this->app()->withToken($token);

        // Held: only finishing, the profile and signing out are open.
        $signedIn()->getJson(route('api.dashboard'))
            ->assertStatus(403)
            ->assertJsonPath('profile_required', true);
        $signedIn()->getJson(route('api.mobile.me'))->assertOk();

        // Held to the sign-up's own rules.
        $signedIn()->postJson(route('api.mobile.profile.complete'), [
            'country' => 'NG',
            'phone' => '8031234567',
            'gender' => 'female',
        ])->assertStatus(422)->assertJsonValidationErrors(['terms', 'adult']);

        $signedIn()->postJson(route('api.mobile.profile.complete'), [
            'country' => 'NG',
            'phone' => '8031234567',
            'gender' => 'female',
            'terms' => true,
            'adult' => true,
        ])->assertOk()->assertJsonPath('user.profile_completed', true);

        $user = User::where('email', 'new@example.com')->sole();
        $this->assertSame('+2348031234567', $user->phone);
        $this->assertSame('NG', $user->country);

        // Finished: the hold is lifted, and the step cannot be taken twice.
        $signedIn()->getJson(route('api.dashboard'))->assertOk();
        $signedIn()->postJson(route('api.mobile.profile.complete'), [
            'country' => 'GH',
            'phone' => '241234567',
            'gender' => 'male',
            'terms' => true,
            'adult' => true,
        ])->assertStatus(409);
        $this->assertSame('+2348031234567', $user->fresh()->phone);
    }

    public function test_a_banned_account_is_not_signed_in(): void
    {
        User::factory()->create(['email' => 'jane@example.com', 'banned_at' => now()]);
        $this->googleReturns($this->googleUser('jane@example.com'));

        $this->redeem($this->backFromGoogle()['code'])->assertStatus(403);
        $this->assertSame(0, PersonalAccessToken::count());
    }
}
