<?php

namespace Tests\Feature\Api\V2;

use App\Domain\Security\Exceptions\RefreshTokenRejected;
use App\Domain\Security\Services\ApiTokenService;
use App\Models\ApiRefreshToken;
use App\Models\ExpoPushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * The token lifecycle the whole mobile app hangs off.
 *
 * The shape being proved: a short access token sent on every request, a long
 * refresh token kept in the phone's encrypted storage, and the refresh token
 * being SINGLE USE so a stolen copy stops working the moment the real phone
 * refreshes.
 *
 * Two of these tests are the ones that matter most and they pull in opposite
 * directions, which is why both are here:
 *
 *   - a consumed refresh token presented again is treated as THEFT and kills
 *     every token for that device, and
 *   - the same thing inside the retry grace is treated as a DROPPED RESPONSE
 *     and answered idempotently, because our customers are on networks where
 *     that happens constantly and being signed out for it is unacceptable.
 */
class ApiTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private ApiTokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->tokens = app(ApiTokenService::class);

        Route::middleware(['api-v2', 'auth:sanctum'])
            ->get('v2/_test/private', fn () => response()->json(['data' => ['id' => auth()->id()]]));
    }

    private function callWith(string $token): TestResponse
    {
        // Laravel reuses ONE application instance across test HTTP calls and
        // Sanctum's guard memoises the user it resolved. Without forgetting the
        // guards, a second call in the same test authenticates as whoever the
        // first call resolved, whatever token it carries, and the test passes on
        // a stale cache instead of on the token it is supposed to be proving.
        $this->app['auth']->forgetGuards();

        return $this->getJson('/v2/_test/private', ['Authorization' => 'Bearer '.$token]);
    }

    public function test_an_issued_access_token_works_and_the_refresh_token_is_stored_only_as_a_hash(): void
    {
        $user = User::factory()->create();

        $pair = $this->tokens->issuePair($user, 'installation-a');

        $this->callWith($pair->accessToken)
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $row = ApiRefreshToken::sole();

        $this->assertSame(hash('sha256', $pair->refreshToken), $row->token_hash);
        $this->assertNotSame($pair->refreshToken, $row->token_hash);

        // The plaintext must exist nowhere in the table. A database dump has to
        // be worthless for signing in as somebody.
        $this->assertDatabaseMissing('api_refresh_tokens', ['token_hash' => $pair->refreshToken]);
    }

    public function test_the_access_token_expires_on_its_own_clock(): void
    {
        $user = User::factory()->create();

        $pair = $this->tokens->issuePair($user, 'installation-a');

        $this->travel((int) config('api.v2.access_token_minutes') + 1)->minutes();

        $this->callWith($pair->accessToken)->assertUnauthorized();
    }

    /**
     * The separation guarantee. A refresh token is not a Sanctum token at all,
     * so it cannot authenticate an API call even on a route that forgot to
     * declare an ability. This is the reason refresh tokens live in their own
     * table rather than as abilities on a Sanctum token.
     */
    public function test_a_refresh_token_can_never_authenticate_an_api_call(): void
    {
        $user = User::factory()->create();

        $pair = $this->tokens->issuePair($user, 'installation-a');

        $this->callWith($pair->refreshToken)->assertUnauthorized();
    }

    public function test_exchanging_rotates_both_halves_and_retires_the_old_access_token(): void
    {
        $user = User::factory()->create();

        $first = $this->tokens->issuePair($user, 'installation-a');
        $second = $this->tokens->exchange($first->refreshToken, 'installation-a');

        $this->assertNotSame($first->accessToken, $second->accessToken);
        $this->assertNotSame($first->refreshToken, $second->refreshToken);

        // New one works.
        $this->callWith($second->accessToken)->assertOk();

        // Old one is gone, not merely expiring later. Two live access tokens per
        // device after every refresh would be a slow leak of valid credentials.
        $this->callWith($first->accessToken)->assertUnauthorized();

        $this->assertNotNull(
            ApiRefreshToken::where('token_hash', hash('sha256', $first->refreshToken))->sole()->consumed_at,
        );
    }

    public function test_rotation_stays_in_the_same_device_family(): void
    {
        $user = User::factory()->create();

        $first = $this->tokens->issuePair($user, 'installation-a');
        $second = $this->tokens->exchange($first->refreshToken);
        $third = $this->tokens->exchange($second->refreshToken);

        $this->assertSame($first->familyId, $second->familyId);
        $this->assertSame($second->familyId, $third->familyId);
        $this->assertCount(3, ApiRefreshToken::all());
    }

    /**
     * Theft. Outside the grace, presenting a token that has already been
     * exchanged means two parties hold it, so the device is cut loose entirely.
     */
    public function test_reusing_a_consumed_refresh_token_revokes_the_whole_device(): void
    {
        config(['api.v2.refresh_replay_seconds' => 0]);

        $user = User::factory()->create();

        $first = $this->tokens->issuePair($user, 'installation-a');
        $second = $this->tokens->exchange($first->refreshToken);

        try {
            $this->tokens->exchange($first->refreshToken);
            $this->fail('reusing a consumed refresh token should have been refused');
        } catch (RefreshTokenRejected $e) {
            $this->assertSame('refresh_token_reused', $e->type);
        }

        // Not just the replayed token: the successor the real phone is holding
        // dies too, which is the point. Whoever has a copy, both are locked out
        // and the owner signs in again.
        $this->callWith($second->accessToken)->assertUnauthorized();

        $this->assertSame(
            0,
            ApiRefreshToken::where('family_id', $first->familyId)->whereNull('revoked_at')->count(),
        );
    }

    /**
     * The counterweight. The app sent its refresh token, we answered, the answer
     * was lost, and the app retried with the only token it still has. That is a
     * dropped response, not a theft, and the customer must not be signed out for
     * being on bad mobile data.
     */
    public function test_an_innocent_retry_inside_the_grace_gets_the_same_pair_back(): void
    {
        config(['api.v2.refresh_replay_seconds' => 60]);

        $user = User::factory()->create();

        $first = $this->tokens->issuePair($user, 'installation-a');
        $second = $this->tokens->exchange($first->refreshToken);
        $retry = $this->tokens->exchange($first->refreshToken);

        $this->assertSame($second->accessToken, $retry->accessToken);
        $this->assertSame($second->refreshToken, $retry->refreshToken);

        // And nothing was revoked, so the phone carries on working.
        $this->callWith($second->accessToken)->assertOk();

        // The retry must not have minted a third token either.
        $this->assertCount(2, ApiRefreshToken::all());
    }

    public function test_the_grace_does_not_outlive_its_window(): void
    {
        config(['api.v2.refresh_replay_seconds' => 60]);

        $user = User::factory()->create();

        $first = $this->tokens->issuePair($user, 'installation-a');
        $this->tokens->exchange($first->refreshToken);

        $this->travel(61)->seconds();

        $this->expectException(RefreshTokenRejected::class);
        $this->tokens->exchange($first->refreshToken);
    }

    public function test_an_unknown_refresh_token_is_refused_without_touching_anything(): void
    {
        $user = User::factory()->create();
        $pair = $this->tokens->issuePair($user, 'installation-a');

        try {
            $this->tokens->exchange('not-a-real-token');
            $this->fail('an unknown refresh token should have been refused');
        } catch (RefreshTokenRejected $e) {
            $this->assertSame('refresh_token_invalid', $e->type);
        }

        // A guess must not cost the real phone its session.
        $this->callWith($pair->accessToken)->assertOk();
    }

    public function test_an_expired_refresh_token_is_refused(): void
    {
        $user = User::factory()->create();
        $pair = $this->tokens->issuePair($user, 'installation-a');

        ApiRefreshToken::sole()->forceFill(['expires_at' => now()->subMinute()])->save();

        try {
            $this->tokens->exchange($pair->refreshToken);
            $this->fail('an expired refresh token should have been refused');
        } catch (RefreshTokenRejected $e) {
            $this->assertSame('refresh_token_expired', $e->type);
        }
    }

    public function test_a_revoked_refresh_token_is_refused(): void
    {
        $user = User::factory()->create();
        $pair = $this->tokens->issuePair($user, 'installation-a');

        $this->tokens->revokeFamily($pair->familyId);

        try {
            $this->tokens->exchange($pair->refreshToken);
            $this->fail('a revoked refresh token should have been refused');
        } catch (RefreshTokenRejected $e) {
            $this->assertSame('refresh_token_revoked', $e->type);
        }
    }

    public function test_a_banned_account_cannot_refresh_and_loses_its_tokens(): void
    {
        $user = User::factory()->create();
        $pair = $this->tokens->issuePair($user, 'installation-a');

        $user->forceFill(['banned_at' => now()])->save();

        try {
            $this->tokens->exchange($pair->refreshToken);
            $this->fail('a banned account should not be able to refresh');
        } catch (RefreshTokenRejected $e) {
            $this->assertSame('refresh_token_revoked', $e->type);
        }

        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $user->id)->count());
    }

    public function test_signing_out_one_device_leaves_the_others_signed_in(): void
    {
        $user = User::factory()->create();

        $phone = $this->tokens->issuePair($user, 'installation-phone');
        $tablet = $this->tokens->issuePair($user, 'installation-tablet');

        $phoneToken = PersonalAccessToken::findToken($phone->accessToken);
        $this->tokens->revokeCurrentDevice($phoneToken);

        $this->callWith($phone->accessToken)->assertUnauthorized();
        $this->callWith($tablet->accessToken)->assertOk();

        // And the signed-out phone cannot refresh its way back in.
        $this->expectException(RefreshTokenRejected::class);
        $this->tokens->exchange($phone->refreshToken);
    }

    public function test_signing_out_everywhere_kills_every_device(): void
    {
        $user = User::factory()->create();

        $phone = $this->tokens->issuePair($user, 'installation-phone');
        $tablet = $this->tokens->issuePair($user, 'installation-tablet');

        $this->tokens->revokeEverything($user);

        $this->callWith($phone->accessToken)->assertUnauthorized();
        $this->callWith($tablet->accessToken)->assertUnauthorized();

        $this->assertSame(0, ApiRefreshToken::whereNull('revoked_at')->count());
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $user->id)->count());
    }

    public function test_one_customers_sign_out_does_not_touch_another(): void
    {
        $maxwell = User::factory()->create();
        $roddy = User::factory()->create();

        $mine = $this->tokens->issuePair($maxwell, 'installation-a');
        $theirs = $this->tokens->issuePair($roddy, 'installation-b');

        $this->tokens->revokeEverything($maxwell);

        $this->callWith($mine->accessToken)->assertUnauthorized();
        $this->callWith($theirs->accessToken)->assertOk();
    }

    public function test_pruning_removes_only_long_dead_rows(): void
    {
        $user = User::factory()->create();

        $live = $this->tokens->issuePair($user, 'installation-live');
        $this->tokens->issuePair($user, 'installation-stale');

        ApiRefreshToken::where('installation_id', 'installation-stale')
            ->update(['expires_at' => now()->subDays(60)]);

        $removed = $this->tokens->prune(30);

        $this->assertSame(1, $removed['refresh']);
        $this->assertSame(
            hash('sha256', $live->refreshToken),
            ApiRefreshToken::sole()->token_hash,
        );
    }

    // -------------------------------------------------- bound to the device

    /**
     * A refresh token is issued to ONE installation. Presented from another, it
     * is a copy in somebody else's hands, and the whole device chain goes with it.
     */
    public function test_a_refresh_token_presented_from_another_installation_is_refused_and_the_family_revoked(): void
    {
        $user = User::factory()->create();
        $pair = $this->tokens->issuePair($user, 'installation-a');

        try {
            $this->tokens->exchange($pair->refreshToken, 'installation-b');
            $this->fail('A refresh token from another installation was accepted.');
        } catch (RefreshTokenRejected) {
            // expected
        }

        $this->callWith($pair->accessToken)->assertUnauthorized();
    }

    /**
     * The retry grace is for the SAME phone retrying after a dropped answer. A
     * consumed token replayed from a different installation inside that window is
     * theft, not a retry, and gets the theft treatment.
     */
    public function test_the_retry_grace_does_not_extend_to_another_installation(): void
    {
        $user = User::factory()->create();
        $first = $this->tokens->issuePair($user, 'installation-a');
        $second = $this->tokens->exchange($first->refreshToken, 'installation-a');

        try {
            $this->tokens->exchange($first->refreshToken, 'installation-b');
            $this->fail('A consumed token replayed from another installation was honoured.');
        } catch (RefreshTokenRejected) {
            // expected
        }

        $this->callWith($second->accessToken)->assertUnauthorized();
    }

    // ------------------------------------------------- push registrations

    public function test_signing_out_everywhere_removes_the_phones_push_registrations(): void
    {
        $user = User::factory()->create();
        $this->tokens->issuePair($user, 'installation-a');
        $this->pushToken($user, 'installation-a');

        $this->tokens->revokeEverything($user);

        $this->assertSame(0, ExpoPushToken::where('user_id', $user->id)->count());
    }

    public function test_revoking_one_device_removes_only_that_phones_push_registration(): void
    {
        $user = User::factory()->create();
        $this->tokens->issuePair($user, 'installation-a');
        $this->tokens->issuePair($user, 'installation-b');
        $this->pushToken($user, 'installation-a', 'ExponentPushToken[AAAAAAAAAAAAAAAAAAAAAA]');
        $this->pushToken($user, 'installation-b', 'ExponentPushToken[BBBBBBBBBBBBBBBBBBBBBB]');

        $this->tokens->revokeByInstallation($user, 'installation-a');

        $this->assertSame(
            ['installation-b'],
            ExpoPushToken::where('user_id', $user->id)->pluck('installation_id')->all(),
        );
    }

    private function pushToken(User $user, string $installation, string $token = 'ExponentPushToken[CCCCCCCCCCCCCCCCCCCCCC]'): ExpoPushToken
    {
        return ExpoPushToken::create([
            'user_id' => $user->id,
            'installation_id' => $installation,
            'token' => $token,
            'platform' => 'android',
            'app_version' => '1.0.0',
            'last_registered_at' => now(),
        ]);
    }
}
