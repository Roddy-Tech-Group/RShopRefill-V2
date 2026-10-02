<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The token foundation the mobile app will stand on.
 *
 * Sanctum exists here for one reason: a phone has no cookie jar, so it cannot
 * carry a session the way every existing /api route relies on. It authenticates
 * with a bearer token instead.
 *
 * Three of the configuration decisions behind that are subtle enough that a
 * future tidy-up would undo them without noticing, and each one fails quietly
 * rather than loudly. They are pinned here on purpose:
 *
 *   1. sanctum.guard is EMPTY. Sanctum's default is ['web'], which would let a
 *      browser session authenticate a token route and hand back a user wearing
 *      a TransientToken. A TransientToken answers true to every ability check,
 *      so per-device revocation and token expiry would both be skipped.
 *   2. sanctum.expiration is NULL. It reads like a default lifetime but
 *      Guard::isValidAccessToken ANDs it with each token's own expires_at, so
 *      it is really a ceiling over every token. Setting it to the access-token
 *      lifetime would expire the long-lived refresh tokens on the same clock.
 *   3. sanctum.routes is FALSE. The one route Sanctum registers for itself is
 *      GET /sanctum/csrf-cookie, which belongs to the browser-SPA mode we do
 *      not use.
 */
class SanctumFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A bare route carrying nothing but the token guard, so these tests prove
        // the guard itself and nothing the /v2 group adds on top of it.
        Route::middleware('auth:sanctum')->get('/_test/token-guard', fn () => response()->json([
            'id' => auth()->id(),
        ]));
    }

    private function hitGuard(?string $token = null): TestResponse
    {
        // Laravel reuses ONE application instance across test HTTP calls and
        // Sanctum's guard memoises the user it resolved. Without forgetting the
        // guards, a second call in the same test authenticates as whoever the
        // first call resolved, whatever token it carries, and the test passes on
        // a stale cache instead of on the token it is supposed to be proving.
        $this->app['auth']->forgetGuards();

        return $this->getJson(
            '/_test/token-guard',
            $token === null ? [] : ['Authorization' => 'Bearer '.$token],
        );
    }

    public function test_a_token_authenticates_a_request(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('maxwell-iphone')->plainTextToken;

        $this->hitGuard($token)
            ->assertOk()
            ->assertExactJson(['id' => $user->id]);
    }

    public function test_a_request_carrying_no_token_is_refused(): void
    {
        User::factory()->create();

        $this->hitGuard()->assertUnauthorized();
    }

    public function test_a_made_up_token_is_refused(): void
    {
        User::factory()->create();

        $this->hitGuard('1|rsr_not-a-real-token')->assertUnauthorized();
    }

    /**
     * A token past its expires_at is dead. This is the whole point of issuing a
     * short access token: if it leaks, it is worthless within the hour.
     */
    public function test_an_expired_token_is_refused(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('maxwell-iphone', ['*'], now()->subMinute())->plainTextToken;

        $this->hitGuard($token)->assertUnauthorized();
    }

    /**
     * The refresh token has to outlive the access token by weeks, which only
     * works while no global ceiling is cutting every token down to the same
     * lifetime. See note 2 on the class.
     */
    public function test_a_long_lived_token_is_not_cut_short_by_a_global_ceiling(): void
    {
        $this->assertNull(
            config('sanctum.expiration'),
            'sanctum.expiration is a ceiling over EVERY token, not a default. Setting it expires refresh tokens too.',
        );

        $user = User::factory()->create();

        $token = $user->createToken('maxwell-iphone', ['*'], now()->addDays(30))->plainTextToken;

        $this->hitGuard($token)->assertOk();
    }

    /**
     * See note 1 on the class. A session cookie must not open a token route.
     */
    public function test_a_browser_session_cannot_authenticate_the_token_guard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/_test/token-guard')
            ->assertUnauthorized();
    }

    public function test_sanctum_registers_no_routes_of_its_own(): void
    {
        $this->assertFalse(config('sanctum.routes'));
        $this->assertFalse(Route::has('sanctum.csrf-cookie'));

        $this->get('/sanctum/csrf-cookie')->assertNotFound();
    }

    /**
     * A prefix makes a leaked token recognisable, both to a human reading a log
     * and to the secret-scanning services that watch public repositories for
     * known token shapes.
     */
    public function test_an_issued_token_carries_the_scanning_prefix(): void
    {
        $user = User::factory()->create();

        // plainTextToken is "{id}|{token}", and the prefix belongs to the token
        // half, so it sits immediately after the pipe.
        [$id, $secret] = explode('|', $user->createToken('maxwell-iphone')->plainTextToken, 2);

        $this->assertSame((string) $user->tokens()->sole()->getKey(), $id);
        $this->assertStringStartsWith('rsr_', $secret);
    }

    /**
     * The point of the whole exercise: none of this changes how the website
     * signs anyone in. The existing /api routes authenticate the same session
     * cookie they always have.
     */
    public function test_the_existing_session_authenticated_api_still_works(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('api.dashboard'))
            ->assertOk();
    }
}
