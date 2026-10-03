<?php

namespace Tests\Feature\Api\V2;

use App\Domain\Security\Services\ApiTokenService;
use App\Http\Middleware\ThrottleApiV2Group;
use App\Models\User;
use App\Support\ApiV2Error;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The shape of /v2 itself, before any endpoint is built on it.
 *
 * Three things are pinned here, and all three are the kind of thing that looks
 * like tidy-up later and is actually a regression:
 *
 *   1. /v2 is STATELESS. No session, no cookie. The moment a session appears, a
 *      browser cookie can start authenticating requests that were supposed to
 *      need a token, and token expiry and per-device revocation are bypassed.
 *   2. Every failure comes back as { "error": { "type": ... } }. The app
 *      branches on `type`, so it has to be there on every error path, including
 *      the ones the framework raises rather than our code.
 *   3. The storefront's existing /api responses are NOT touched by any of it.
 */
class V2FoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stand-ins for endpoints built in later slices, so the group's own
        // behaviour can be proved on its own.
        Route::middleware(['api-v2', 'auth:sanctum'])
            ->get('v2/_test/private', fn () => response()->json(['data' => ['ok' => true]]));

        // Refuses the way the middleware /v2 shares with the website refuses: its own
        // shape, with a flag, and no `type`.
        Route::middleware('api-v2')->get('v2/_test/web-shaped', fn () => response()->json([
            'message' => 'For your security, this action is restricted on new devices.',
            'device_restricted' => true,
        ], 403));

        // A guard written for a web form, answering a redirect.
        Route::middleware('api-v2')->get('v2/_test/redirecting', fn () => redirect('/login'));

        // Already ours. Must pass through untouched.
        Route::middleware('api-v2')->get('v2/_test/deliberate', fn () => ApiV2Error::make(
            'wallet_on_hold',
            'Your wallet is on hold.',
            409,
        ));

        Route::middleware('api-v2')
            ->post('v2/_test/validated', function (Request $request) {
                $request->validate(['email' => ['required', 'email']]);

                return response()->json(['data' => ['ok' => true]]);
            });
    }

    public function test_meta_reports_the_contract_without_authentication(): void
    {
        $this->getJson('/v2/meta')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['api_version', 'minimum_app_build', 'server_time'],
            ]);
    }

    /**
     * See note 1. StartSession is not in the api-v2 group, so nothing should
     * ever hand the caller a session cookie.
     */
    public function test_v2_never_sets_a_session_cookie(): void
    {
        $response = $this->getJson('/v2/meta');

        $names = array_map(
            fn ($cookie) => $cookie->getName(),
            $response->headers->getCookies(),
        );

        $this->assertNotContains(config('session.cookie'), $names);
        $this->assertSame([], $names, 'v2 responses should set no cookies at all');
    }

    public function test_an_unknown_v2_path_answers_in_the_error_envelope(): void
    {
        $this->getJson('/v2/does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('error.type', 'not_found');
    }

    public function test_a_token_route_without_a_token_answers_in_the_error_envelope(): void
    {
        $this->getJson('/v2/_test/private')
            ->assertUnauthorized()
            ->assertJsonPath('error.type', 'unauthenticated');
    }

    public function test_a_token_route_with_a_token_passes(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;

        $this->getJson('/v2/_test/private', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    public function test_validation_failures_name_the_offending_fields(): void
    {
        $this->postJson('/v2/_test/validated', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'validation_failed')
            ->assertJsonStructure(['error' => ['type', 'message', 'fields' => ['email']]]);
    }

    /**
     * A client that sends no Accept header still gets JSON rather than a
     * redirect to the login page, which is what an HTML-negotiated 401 does.
     */
    public function test_a_client_that_forgets_the_accept_header_still_gets_json(): void
    {
        $response = $this->get('/v2/_test/private');

        $response->assertUnauthorized();
        $this->assertStringContainsString(
            'application/json',
            (string) $response->headers->get('Content-Type'),
        );
        $response->assertJsonPath('error.type', 'unauthenticated');
    }

    public function test_an_app_build_below_the_minimum_is_turned_away(): void
    {
        config(['api.v2.minimum_app_build' => 42]);

        $this->getJson('/v2/meta', ['X-App-Build' => '41'])
            ->assertStatus(426)
            ->assertJsonPath('error.type', 'upgrade_required')
            ->assertJsonPath('error.minimum_build', 42);

        $this->getJson('/v2/meta', ['X-App-Build' => '42'])->assertOk();
    }

    /**
     * A build too old to send the header at all counts as 0, so raising the
     * floor retires it too. While the floor is 0 the check is inert.
     */
    public function test_a_missing_build_number_passes_only_while_the_gate_is_off(): void
    {
        config(['api.v2.minimum_app_build' => 0]);
        $this->getJson('/v2/meta')->assertOk();

        config(['api.v2.minimum_app_build' => 1]);
        $this->getJson('/v2/meta')->assertStatus(426);
    }

    /**
     * RejectMalformedInput is prepended to the GLOBAL middleware stack, so it
     * guards /v2 as well. It used to refuse with a bare {"message": ...}, which
     * is the one shape the app cannot read: it branches on error.type, and an
     * installed build cannot be corrected afterwards.
     *
     * Sent as a form rather than postJson(): json_encode cannot represent these
     * bytes, so the payload would never reach the filter.
     */
    public function test_malformed_input_on_v2_still_answers_in_the_error_envelope(): void
    {
        $this->post('/v2/auth/login', ['email' => "maxwell\xB1\x31@example.com"])
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'malformed_request');
    }

    /**
     * See note 3. The v2 envelope is scoped by path, so the storefront's own
     * JavaScript keeps reading the shapes it already reads.
     */
    /**
     * /v2 reuses middleware written for the website, and those refuse in their own
     * shapes. Patching each one would work until the next is written and somebody
     * forgets, so a normaliser guarantees the envelope on the way out.
     */
    public function test_a_website_shaped_refusal_is_rewritten_into_the_envelope(): void
    {
        $response = $this->getJson('/v2/_test/web-shaped')->assertForbidden();

        // The flag becomes a stable type, because "you are on a new device" and an
        // ordinary 403 need different screens in the app.
        $response->assertJsonPath('error.type', 'device_restricted');

        // Their message is kept: it is written for customers and is better than
        // anything generic we would substitute.
        $this->assertStringContainsString('restricted on new devices', $response->json('error.message'));

        // And the original flag is carried through rather than dropped.
        $this->assertTrue($response->json('error.device_restricted'));
    }

    /**
     * A redirect is never a valid answer on /v2. An app receiving one shows the
     * customer a parse failure instead of a reason.
     */
    public function test_a_redirect_is_never_returned_to_the_app(): void
    {
        $response = $this->getJson('/v2/_test/redirecting');

        // A 500 rather than a plausible-looking 403: we do not know what the guard
        // meant, and inventing a refusal would hide the wiring bug.
        $response->assertStatus(500);
        $response->assertJsonPath('error.type', 'server_error');
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertIsString($response->json('error.type'));
    }

    public function test_a_deliberate_envelope_is_left_exactly_as_it_was(): void
    {
        $this->getJson('/v2/_test/deliberate')
            ->assertStatus(409)
            ->assertJsonPath('error.type', 'wallet_on_hold')
            ->assertJsonPath('error.message', 'Your wallet is on hold.');
    }

    public function test_the_existing_api_error_shape_is_unchanged(): void
    {
        $response = $this->getJson('/api/does-not-exist');

        $response->assertNotFound();
        $response->assertJsonMissingPath('error.type');
    }

    // ---------------------------------------------------- web-shaped refusals

    /**
     * The website's email gate answers with a flag and a countdown. The app has to
     * route that to its verify-your-email screen, which it can only do by type.
     */
    public function test_a_verify_your_email_refusal_keeps_a_type_the_app_can_route_on(): void
    {
        Route::middleware('api-v2')->get('v2/_test/unverified', fn () => response()->json([
            'message' => 'Please verify your email to continue.',
            'email_unverified' => true,
            'resend_retry_after' => 30,
        ], 403));

        $this->getJson('/v2/_test/unverified')
            ->assertForbidden()
            ->assertJsonPath('error.type', 'email_verification_required')
            ->assertJsonPath('error.resend_retry_after', 30);
    }

    /**
     * A suspension arrives as an OBJECT of operator-facing detail. The type is
     * kept; the object, which carries the reason written for our staff, is not.
     */
    public function test_a_suspension_refusal_keeps_its_type_and_drops_the_operator_detail(): void
    {
        Route::middleware('api-v2')->get('v2/_test/suspended', fn () => response()->json([
            'message' => 'Your account is currently suspended.',
            'suspension' => ['reason' => 'chargeback pattern', 'suspended_at' => now()->toIso8601String()],
        ], 403));

        $this->getJson('/v2/_test/suspended')
            ->assertForbidden()
            ->assertJsonPath('error.type', 'account_suspended')
            ->assertJsonMissingPath('error.suspension');
    }

    /**
     * A replayed refusal is still a replay. The marker must survive normalisation
     * or the app, and a support engineer reading a log, cannot tell it from a
     * fresh refusal.
     */
    public function test_the_replay_marker_survives_normalisation(): void
    {
        Route::middleware('api-v2')->get('v2/_test/replayed-refusal', fn () => response()
            ->json(['message' => 'Please verify your email.', 'email_unverified' => true], 403)
            ->header('Idempotent-Replay', 'true'));

        $response = $this->getJson('/v2/_test/replayed-refusal')->assertForbidden();

        $this->assertSame('true', $response->headers->get('Idempotent-Replay'));
        $this->assertSame('email_verification_required', $response->json('error.type'));
    }

    // -------------------------------------------------------- middleware order

    /**
     * Laravel re-sorts middleware so Authenticate runs before ThrottleRequests.
     * That would have put every made-up bearer token through a database lookup
     * before the limiter saw it. The group ceiling has to sit in FRONT of auth.
     */
    public function test_the_group_rate_ceiling_runs_before_authentication(): void
    {
        $route = app('router')->getRoutes()->getByName('v2.cart.show');

        $this->assertNotNull($route);

        $middleware = array_map(
            fn (string $name) => explode(':', $name)[0],
            app('router')->gatherRouteMiddleware($route),
        );

        $throttleAt = array_search(ThrottleApiV2Group::class, $middleware, true);
        $authAt = array_search(Authenticate::class, $middleware, true);

        $this->assertNotFalse($throttleAt, 'The group ceiling is not on the route at all.');
        $this->assertNotFalse($authAt);
        $this->assertLessThan($authAt, $throttleAt, 'Authentication runs before the group rate ceiling.');
    }

    // ------------------------------------------------------------ bad inputs

    /**
     * A limiter keyed on the email must not take the request down with a type
     * error when the field arrives as an array. That is a validation failure.
     */
    public function test_an_array_where_an_email_should_be_is_refused_as_validation_not_a_crash(): void
    {
        $this->postJson('/v2/auth/login', [
            'email' => ['not', 'an', 'email'],
            'password' => 'whatever',
        ], ['X-Installation-ID' => 'install-phone-1'])
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'validation_failed');
    }

    public function test_a_tampered_cursor_is_refused_as_validation_not_a_crash(): void
    {
        $user = User::factory()->create();
        $token = app(ApiTokenService::class)
            ->issuePair($user, 'install-phone-1')
            ->accessToken;

        // base64 of {} : a cursor with no columns and no direction.
        $this->getJson('/v2/orders?cursor=e30', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['cursor']]]);
    }
}
