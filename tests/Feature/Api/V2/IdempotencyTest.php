<?php

namespace Tests\Feature\Api\V2;

use App\Domain\Security\Services\ApiTokenService;
use App\Http\Middleware\EnforceIdempotency;
use App\Models\ApiIdempotencyKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * The guard that stops a retried payment being taken twice.
 *
 * The scenario it exists for, in full: Chidi taps Pay on MTN data. We create the
 * order and charge him. His bus goes into a tunnel and our "success" reply never
 * lands. His app sees a timeout, so it retries. Without this guard we create a
 * second order and charge him again, and he is owed a refund and an apology.
 *
 * Almost every test below is a variant of "the handler must run exactly once",
 * which is why the test route counts its own invocations rather than trusting the
 * response alone. A response that merely LOOKS right while the work happened
 * twice is exactly the bug being prevented.
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /** How many times the guarded handler actually executed. */
    private static int $calls = 0;

    private string $token;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        self::$calls = 0;

        $this->user = User::factory()->create();
        $this->token = app(ApiTokenService::class)
            ->issuePair($this->user, 'install-phone-1')
            ->accessToken;

        $guarded = ['api-v2', 'auth:sanctum', EnforceIdempotency::class];

        // Stands in for place-order: it "creates" something and reports what.
        Route::middleware($guarded)->post('v2/_test/pay', function (Request $request) {
            self::$calls++;

            return response()->json([
                'data' => ['receipt' => 'RSR-'.self::$calls, 'amount' => $request->input('amount')],
            ], 201);
        });

        // A second money endpoint, to prove keys are scoped per endpoint.
        Route::middleware($guarded)->post('v2/_test/fund', function () {
            self::$calls++;

            return response()->json(['data' => ['funded' => true]], 201);
        });

        // Refuses the way a real handler refuses: deterministically.
        Route::middleware($guarded)->post('v2/_test/refused', function () {
            self::$calls++;

            return response()->json(['error' => ['type' => 'insufficient_balance']], 422);
        });

        // Something unplanned.
        Route::middleware($guarded)->post('v2/_test/broken', function () {
            self::$calls++;

            throw new RuntimeException('database went away');
        });

        // A response too large to be worth remembering.
        Route::middleware($guarded)->post('v2/_test/huge', function () {
            self::$calls++;

            return response()->json(['data' => ['blob' => str_repeat('x', 300_000)]], 201);
        });

        // Reading is always safe, so it needs no key.
        Route::middleware($guarded)->get('v2/_test/read', fn () => response()->json(['data' => ['ok' => true]]));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function tap(string $path, ?string $key, array $body = ['amount' => 1000], ?string $token = null): TestResponse
    {
        // Laravel reuses one application instance across test HTTP calls and
        // Sanctum's guard memoises the user it resolved, so a second call would
        // otherwise authenticate as whoever the first one did.
        $this->app['auth']->forgetGuards();

        $headers = ['Authorization' => 'Bearer '.($token ?? $this->token)];

        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }

        return $this->postJson($path, $body, $headers);
    }

    // ------------------------------------------------------------ the main case

    /**
     * The whole point. Same tap, sent twice, charged once.
     */
    public function test_a_retried_tap_gets_the_first_answer_and_the_work_happens_once(): void
    {
        $first = $this->tap('/v2/_test/pay', 'tap-0000-1111-2222');
        $retry = $this->tap('/v2/_test/pay', 'tap-0000-1111-2222');

        $first->assertCreated();
        $retry->assertCreated();

        // Byte-identical, so the app cannot tell it was a retry from the body.
        $this->assertSame($first->json(), $retry->json());
        $this->assertSame('RSR-1', $retry->json('data.receipt'));

        // The bit that matters: one order, not two.
        $this->assertSame(1, self::$calls);
    }

    public function test_a_replay_says_so_in_a_header_and_a_first_answer_does_not(): void
    {
        $first = $this->tap('/v2/_test/pay', 'tap-0000-1111-2222');
        $retry = $this->tap('/v2/_test/pay', 'tap-0000-1111-2222');

        $this->assertNull($first->headers->get('Idempotent-Replay'));
        $this->assertSame('true', $retry->headers->get('Idempotent-Replay'));
    }

    public function test_a_different_tap_is_a_different_payment(): void
    {
        $this->tap('/v2/_test/pay', 'tap-first-one-here')->assertCreated();
        $this->tap('/v2/_test/pay', 'tap-second-one-x')->assertCreated();

        // Two genuine intents, two payments. The guard must not over-collapse.
        $this->assertSame(2, self::$calls);
    }

    // ------------------------------------------------------------ the key itself

    public function test_a_money_request_without_a_key_is_refused(): void
    {
        $this->tap('/v2/_test/pay', null)
            ->assertStatus(400)
            ->assertJsonPath('error.type', 'idempotency_key_required');

        // Refused before the handler, so nothing was created.
        $this->assertSame(0, self::$calls);
    }

    public function test_a_useless_key_is_refused(): void
    {
        // Too short to be unique in practice: "1" is what every buggy client
        // sends, and they would all collide with each other.
        $this->tap('/v2/_test/pay', '1')
            ->assertStatus(400)
            ->assertJsonPath('error.type', 'idempotency_key_invalid');

        $this->assertSame(0, self::$calls);
    }

    public function test_reading_needs_no_key(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/v2/_test/read', ['Authorization' => 'Bearer '.$this->token])
            ->assertOk();
    }

    // --------------------------------------------------------------- misuse

    /**
     * The dangerous version of key re-use is a different AMOUNT. Replaying the
     * old success would tell the app a 5,000 naira payment succeeded when only
     * 1,000 was ever taken.
     */
    public function test_the_same_key_with_a_different_amount_is_refused(): void
    {
        $this->tap('/v2/_test/pay', 'tap-0000-1111-2222', ['amount' => 1000])->assertCreated();

        $this->tap('/v2/_test/pay', 'tap-0000-1111-2222', ['amount' => 5000])
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'idempotency_key_reused');

        $this->assertSame(1, self::$calls);
    }

    public function test_the_same_key_on_a_different_endpoint_is_refused(): void
    {
        $this->tap('/v2/_test/pay', 'tap-0000-1111-2222')->assertCreated();

        $this->tap('/v2/_test/fund', 'tap-0000-1111-2222')
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'idempotency_key_reused');

        $this->assertSame(1, self::$calls);
    }

    /**
     * Field order in the JSON is not part of the intent. Two clients, or two
     * builds, sending the same values differently ordered must collapse to one
     * payment rather than being read as a mismatch.
     */
    public function test_field_order_does_not_change_the_fingerprint(): void
    {
        $this->tap('/v2/_test/pay', 'tap-0000-1111-2222', ['amount' => 1000, 'currency' => 'NGN'])
            ->assertCreated();

        $this->tap('/v2/_test/pay', 'tap-0000-1111-2222', ['currency' => 'NGN', 'amount' => 1000])
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame(1, self::$calls);
    }

    /**
     * Keys are scoped per customer. Two people whose apps happen to generate the
     * same value must not see each other's receipts, which would be a data leak
     * as well as a bug.
     */
    public function test_two_customers_can_hold_the_same_key_independently(): void
    {
        $other = User::factory()->create();
        $otherToken = app(ApiTokenService::class)->issuePair($other, 'install-phone-2')->accessToken;

        $mine = $this->tap('/v2/_test/pay', 'tap-0000-1111-2222');
        $theirs = $this->tap('/v2/_test/pay', 'tap-0000-1111-2222', ['amount' => 1000], $otherToken);

        $mine->assertCreated();
        $theirs->assertCreated();

        $this->assertSame('RSR-1', $mine->json('data.receipt'));
        $this->assertSame('RSR-2', $theirs->json('data.receipt'));
        $this->assertSame(2, self::$calls);
    }

    // ------------------------------------------------------------- concurrency

    /**
     * Both taps arrive at once. The second must be told to wait rather than start
     * a second payment, because there is no answer to replay yet.
     */
    public function test_a_second_tap_while_the_first_is_still_running_is_told_to_wait(): void
    {
        $this->claimedKey('tap-0000-1111-2222', lockedUntil: now()->addMinute());

        $this->tap('/v2/_test/pay', 'tap-0000-1111-2222')
            ->assertStatus(409)
            ->assertJsonPath('error.type', 'request_in_progress');

        $this->assertSame(0, self::$calls);
    }

    /**
     * The first attempt died: PHP timed out, or the worker was killed. The intent
     * must not stay stuck for the whole 24 hours, so once the lock lapses a retry
     * takes it over.
     */
    public function test_a_retry_takes_over_after_the_first_attempt_dies(): void
    {
        $this->claimedKey('tap-0000-1111-2222', lockedUntil: now()->subMinute());

        $this->tap('/v2/_test/pay', 'tap-0000-1111-2222')->assertCreated();

        $this->assertSame(1, self::$calls);
    }

    public function test_a_key_can_be_used_again_once_its_window_has_passed(): void
    {
        $this->tap('/v2/_test/pay', 'tap-0000-1111-2222')->assertCreated();

        ApiIdempotencyKey::sole()->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->tap('/v2/_test/pay', 'tap-0000-1111-2222')->assertCreated();

        // Two genuine payments, a day apart. Not a replay.
        $this->assertSame(2, self::$calls);
    }

    // --------------------------------------------------------------- outcomes

    /**
     * A refusal is deterministic, so it is worth remembering: the same tap will be
     * refused for the same reason, and replaying it saves running the work again.
     */
    public function test_a_deterministic_refusal_is_remembered(): void
    {
        $this->tap('/v2/_test/refused', 'tap-0000-1111-2222')->assertStatus(422);

        $this->tap('/v2/_test/refused', 'tap-0000-1111-2222')
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'insufficient_balance')
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame(1, self::$calls);
    }

    /**
     * Something unplanned means the outcome is unknown, so the key is RELEASED and
     * a retry is allowed. Refusing forever would leave the customer permanently
     * unable to complete that payment with no way out but support.
     */
    public function test_an_unexpected_failure_releases_the_key_so_a_retry_can_work(): void
    {
        $this->tap('/v2/_test/broken', 'tap-0000-1111-2222')->assertStatus(500);

        // Nothing kept, so the intent is not wedged.
        $this->assertSame(0, ApiIdempotencyKey::count());

        $this->tap('/v2/_test/broken', 'tap-0000-1111-2222')->assertStatus(500);

        $this->assertSame(2, self::$calls);
    }

    /**
     * We cannot replay what we refuse to store, so rather than pretend, the key is
     * released. Logged loudly, because a money response this size is a bug.
     */
    public function test_a_response_too_large_to_store_releases_the_key(): void
    {
        $this->tap('/v2/_test/huge', 'tap-0000-1111-2222')->assertCreated();

        $this->assertSame(0, ApiIdempotencyKey::count());
    }

    // ----------------------------------------------------------------- pruning

    public function test_pruning_removes_expired_keys_and_leaves_live_ones(): void
    {
        $this->tap('/v2/_test/pay', 'tap-live-one-here')->assertCreated();
        $this->tap('/v2/_test/fund', 'tap-stale-one-xx')->assertCreated();

        ApiIdempotencyKey::where('path', 'v2/_test/fund')
            ->update(['expires_at' => now()->subDay()]);

        $this->artisan('api:prune')->assertSuccessful();

        $this->assertSame(1, ApiIdempotencyKey::count());
        $this->assertSame('v2/_test/pay', ApiIdempotencyKey::sole()->path);
    }

    /**
     * Stands in for a first attempt that is mid-flight (or died mid-flight), so
     * the concurrency branches can be exercised without two real processes.
     */
    private function claimedKey(string $key, Carbon $lockedUntil): ApiIdempotencyKey
    {
        $input = ['amount' => 1000];
        ksort($input);

        return ApiIdempotencyKey::create([
            'user_id' => $this->user->id,
            'key_hash' => hash('sha256', $key),
            'method' => 'POST',
            'path' => 'v2/_test/pay',
            'request_hash' => hash('sha256', (string) json_encode($input)),
            'status' => ApiIdempotencyKey::STATUS_IN_PROGRESS,
            'locked_until' => $lockedUntil,
            'expires_at' => now()->addDay(),
        ]);
    }
}
