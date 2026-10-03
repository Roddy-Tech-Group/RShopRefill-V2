<?php

namespace App\Http\Middleware;

use App\Models\ApiIdempotencyKey;
use App\Models\User;
use App\Support\ApiV2Error;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes a money-moving request safe to retry.
 *
 * The scenario, which is routine rather than theoretical on the networks our
 * customers use: the customer taps Pay, we create the order and take the money,
 * and the reply never arrives because the connection dropped. The app sees a
 * timeout, so it retries with the only information it has. Without this guard we
 * would create a second order and charge a second time.
 *
 * The app sends an `Idempotency-Key` header holding one value per INTENT, not per
 * HTTP attempt: generated when the customer taps, and re-sent unchanged on every
 * retry of that tap. The first request through claims the key; a retry finds the
 * claim and gets the original answer back.
 *
 * Three deliberate departures from the usual write-up of this pattern:
 *
 *  1. The key is REQUIRED, not optional. Stripe makes it optional because it
 *     cannot force thousands of third-party clients to send one. We write the
 *     only client, so requiring it converts "the app might double-charge if
 *     somebody forgets" into "it cannot".
 *  2. The request body is fingerprinted, and a key re-used with different details
 *     is refused rather than answered with the old result. Replaying a stale
 *     success would hide a client bug, and the dangerous version of that bug is a
 *     different amount.
 *  3. A 5xx RELEASES the key. See recordOutcome for why that is right here
 *     specifically rather than as a general rule.
 */
class EnforceIdempotency
{
    /** Only these can create or change anything, so only these need guarding. */
    private const GUARDED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Printable ASCII, no spaces, 8 to 255 characters. A UUID fits comfortably
     * and is what the app should send. The floor exists so a client cannot send
     * "1" and collide with every other client that had the same idea.
     */
    private const KEY_PATTERN = '/^[\x21-\x7E]{8,255}$/';

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), self::GUARDED_METHODS, true)) {
            return $next($request);
        }

        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            // Every route carrying this middleware sits behind auth:sanctum, so
            // this is a wiring mistake rather than something a customer can
            // reach. It must refuse rather than silently skip the guard: a
            // money endpoint running unguarded is the exact failure this class
            // exists to prevent.
            return ApiV2Error::make('unauthenticated', 'Please sign in to continue.', 401);
        }

        $key = trim((string) $request->header('Idempotency-Key', ''));

        if ($key === '') {
            return ApiV2Error::make(
                'idempotency_key_required',
                'This app build cannot complete payments safely. Please update the app.',
                400,
            );
        }

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            return ApiV2Error::make(
                'idempotency_key_invalid',
                'This app build cannot complete payments safely. Please update the app.',
                400,
            );
        }

        $keyHash = hash('sha256', $key);
        $method = $request->method();
        $path = $request->path();
        $requestHash = $this->fingerprint($request);

        // Two attempts, because the claim can lose a race against pruning: the
        // insert collides, and by the time the existing row is read it has been
        // deleted for being expired.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $claim = $this->claim($user, $keyHash, $method, $path, $requestHash);

            if ($claim instanceof Response) {
                return $claim;
            }

            if ($claim instanceof ApiIdempotencyKey) {
                try {
                    return $this->recordOutcome($claim, $next($request));
                } catch (Throwable $e) {
                    // Normally a handler's exception is rendered into a 500 by the
                    // route pipeline before it reaches here, and recordOutcome
                    // releases it as a 5xx. This catch is for the rest: an
                    // exception from recording the outcome itself, or one a
                    // middleware below us let escape. No response exists for us to
                    // remember and the outcome is unknown, so the key is released
                    // for the same reason a 5xx is: otherwise this intent stays
                    // locked and the customer is told "still processing" for the
                    // next two minutes, then refused for a day.
                    //
                    // Re-thrown, so Laravel still reports it and Sentry still
                    // sees it. Swallowing it here would hide a real failure.
                    $this->release($claim, 'unhandled_exception', ['exception' => $e::class]);

                    throw $e;
                }
            }
        }

        // Both attempts raced. Telling the client to retry is honest and safe;
        // guessing and running the request unguarded would not be.
        return ApiV2Error::make(
            'request_in_progress',
            'That request is still being processed. Please wait a moment.',
            409,
        );
    }

    /**
     * Claim the key, or decide what to answer instead.
     *
     * @return ApiIdempotencyKey|Response|null the row to run under, a response to
     *                                         return instead, or null to retry
     *                                         the claim
     */
    private function claim(
        User $user,
        string $keyHash,
        string $method,
        string $path,
        string $requestHash,
    ): ApiIdempotencyKey|Response|null {
        try {
            // The unique index on (user_id, key_hash) IS the concurrency control.
            // Two simultaneous taps race to insert and exactly one wins.
            return ApiIdempotencyKey::create([
                'user_id' => $user->id,
                'key_hash' => $keyHash,
                'method' => $method,
                'path' => $path,
                'request_hash' => $requestHash,
                'status' => ApiIdempotencyKey::STATUS_IN_PROGRESS,
                'locked_until' => now()->addSeconds($this->lockSeconds()),
                'expires_at' => now()->addHours($this->ttlHours()),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Somebody already holds this key. Which of several situations it is
            // decides the answer.
        }

        $existing = ApiIdempotencyKey::where('user_id', $user->id)
            ->where('key_hash', $keyHash)
            ->first();

        if ($existing === null) {
            // Raced with pruning. Retry the claim.
            return null;
        }

        if ($existing->isExpired()) {
            // Past its window, so the key is free again. Delete and retry rather
            // than reuse the row, so nothing from the old intent leaks into the
            // new one.
            $existing->delete();

            return null;
        }

        if (! $existing->matches($method, $path, $requestHash)) {
            Log::warning('api.v2.idempotency_key_reused_with_different_request', [
                'user_id' => $user->id,
                'path' => $path,
                'original_path' => $existing->path,
            ]);

            return ApiV2Error::make(
                'idempotency_key_reused',
                'That request could not be completed. Please start again.',
                422,
            );
        }

        if ($existing->isCompleted()) {
            return $this->replay($existing);
        }

        if ($existing->isLocked()) {
            // The first attempt is still running. Answering 409 is the only
            // honest option: we cannot replay an answer that does not exist yet,
            // and running the request again is the thing being prevented.
            return ApiV2Error::make(
                'request_in_progress',
                'That request is still being processed. Please wait a moment.',
                409,
            );
        }

        // In progress but the lock lapsed, so the first attempt died. Take the
        // row over rather than leaving this intent stuck for the full 24 hours.
        //
        // The takeover is a conditional UPDATE, not a read-then-save: two retries
        // arriving together after the lock lapsed would otherwise BOTH see it as
        // free, both re-lock it, and both run the handler, which is the exact
        // double execution this class exists to prevent. The database lets
        // exactly one of these updates match.
        $taken = ApiIdempotencyKey::whereKey($existing->getKey())
            ->where('status', ApiIdempotencyKey::STATUS_IN_PROGRESS)
            ->where(function ($query) {
                $query->whereNull('locked_until')->orWhere('locked_until', '<', now());
            })
            ->update([
                'locked_until' => now()->addSeconds($this->lockSeconds()),
                'updated_at' => now(),
            ]);

        if ($taken !== 1) {
            // Somebody else took it over a moment ago and is running it now.
            return ApiV2Error::make(
                'request_in_progress',
                'That request is still being processed. Please wait a moment.',
                409,
            );
        }

        return $existing->fresh();
    }

    /**
     * Store what happened, so the next retry of this intent gets the same answer.
     */
    private function recordOutcome(ApiIdempotencyKey $row, Response $response): Response
    {
        $status = $response->getStatusCode();

        if ($status >= 500) {
            // RELEASE the key, so a retry is possible.
            //
            // This is the one judgement call in the class, and it is right for
            // this system rather than as a blanket rule. Our handlers answer 4xx
            // for every failure they anticipate, so a 5xx means something
            // unplanned, where the outcome is genuinely unknown. Refusing every
            // future retry would leave the customer permanently unable to
            // complete that intent, with no way out but support. And
            // CheckoutService is explicitly built to be retried against the same
            // cart: it cancels the superseded pending order rather than stacking
            // a second one.
            //
            // If a handler is ever added that moves money and cannot survive
            // being retried, it must not rely on this middleware alone.
            $this->release($row, 'server_error', ['status' => $status]);

            return $response;
        }

        $body = (string) $response->getContent();

        if (strlen($body) > $this->maxBodyBytes()) {
            // Cannot replay what is not stored, so release rather than pretend.
            // Logged loudly: a money response this large is worth investigating.
            $this->release($row, 'response_too_large', ['bytes' => strlen($body)]);

            return $response;
        }

        $row->forceFill([
            'status' => ApiIdempotencyKey::STATUS_COMPLETED,
            'response_status' => $status,
            'response_body' => $body,
            'completed_at' => now(),
            'locked_until' => null,
        ])->save();

        return $response;
    }

    /**
     * Give the key back, so this intent can be attempted again.
     *
     * Used wherever we cannot honestly promise to replay an answer: an
     * unanticipated 5xx, an escaped exception, or a response too large to store.
     * The alternative is refusing every retry of that intent for a day, which
     * leaves the customer stuck with no route out but support.
     *
     * @param  array<string, mixed>  $context
     */
    private function release(ApiIdempotencyKey $row, string $reason, array $context = []): void
    {
        $row->delete();

        Log::warning('api.v2.idempotency_key_released', array_merge([
            'reason' => $reason,
            'user_id' => $row->user_id,
            'method' => $row->method,
            'path' => $row->path,
        ], $context));
    }

    private function replay(ApiIdempotencyKey $row): Response
    {
        // A real JsonResponse carrying the stored bytes verbatim, not a plain
        // Response that merely claims a JSON content type. Anything downstream that
        // inspects the body has to be able to see it as JSON, or a deliberate error
        // envelope gets rewritten on the way out.
        return (new JsonResponse(null, (int) $row->response_status))
            ->setJson((string) $row->response_body)
            // So the app, and anyone reading a support log, can tell a replay
            // from a fresh answer. The body is deliberately identical.
            ->header('Idempotent-Replay', 'true');
    }

    /**
     * A stable fingerprint of what was asked for.
     *
     * Keys are sorted recursively so the same intent hashes the same however the
     * client happened to order its JSON. Uploaded files are excluded: they cannot
     * be hashed cheaply, and no money endpoint takes one.
     */
    private function fingerprint(Request $request): string
    {
        $input = $request->except(array_keys($request->allFiles()));

        $this->sortRecursively($input);

        return hash('sha256', (string) json_encode($input));
    }

    /**
     * @param  array<array-key, mixed>  $input
     */
    private function sortRecursively(array &$input): void
    {
        foreach ($input as &$value) {
            if (is_array($value)) {
                $this->sortRecursively($value);
            }
        }

        unset($value);

        ksort($input);
    }

    private function ttlHours(): int
    {
        return max(1, (int) config('api.v2.idempotency.ttl_hours', 24));
    }

    private function lockSeconds(): int
    {
        return max(5, (int) config('api.v2.idempotency.lock_seconds', 120));
    }

    private function maxBodyBytes(): int
    {
        return max(1024, (int) config('api.v2.idempotency.max_body_bytes', 262144));
    }
}
