<?php

namespace App\Http\Middleware;

use App\Support\ApiV2Error;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantees that every /v2 failure comes back in the one shape the app reads.
 *
 * The exception renderer already handles anything THROWN. This handles anything
 * RETURNED, which is the case that quietly breaks the contract: /v2 reuses
 * middleware written for the website, and those refuse with their own shapes.
 * EnsureDeviceMature answers `{"message": ..., "device_restricted": true}`,
 * EnsureEmailVerified and EnsureAccountNotSuspended have their own variants, and a
 * web-shaped guard can even answer with a redirect.
 *
 * Patching each of those with a v2 branch would work until the next one is written
 * and somebody forgets. One normaliser cannot be forgotten.
 *
 * What it will not do: touch a success, or rewrite an envelope that already has a
 * `type`. Anything deliberate passes straight through.
 *
 * Their MESSAGES are kept. They are written for customers and are usually better
 * than a generic sentence; only the shape around them changes. Any extra scalar
 * flags they set are carried into the error object rather than dropped, so a client
 * that wants `device_restricted` still finds it.
 */
class NormaliseV2ErrorEnvelope
{
    /**
     * Flags the website's middleware set, mapped to stable `type` values. Without
     * this a device-maturity refusal and an ordinary 403 would be indistinguishable
     * to the app, and they need different screens.
     */
    private const FLAG_TYPES = [
        'device_restricted' => 'device_restricted',
        'gateway_disabled' => 'gateway_disabled',
        'category_ineligible' => 'category_ineligible',
        // EnsureEmailVerified: the app opens the verify-your-email screen.
        'email_unverified' => 'email_verification_required',
        // EnsureAccountNotSuspended, protected mode: the app opens recovery.
        'protected_mode' => 'account_protected',
        // EnforceMaintenanceMode: show the notice and stop retrying.
        'maintenance' => 'maintenance',
    ];

    /**
     * Flags that arrive as an OBJECT rather than a boolean. EnsureAccountNotSuspended
     * sends `suspension: {reason, suspended_at, ...}`; its presence is the signal.
     * The object itself is not carried (see carriedFlags), only the type.
     */
    private const OBJECT_FLAG_TYPES = [
        'suspension' => 'account_suspended',
    ];

    /** Keys not worth copying into the error object. */
    private const DROPPED_KEYS = ['message', 'status', 'errors', 'error'];

    /**
     * Headers that survive normalisation. Retry-After because a client needs it to
     * wait the right time; Idempotent-Replay because a replayed refusal is still a
     * replay and the app (and anyone reading a support log) must be able to tell.
     */
    private const CARRIED_HEADERS = ['Retry-After', 'Idempotent-Replay'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $status = $response->getStatusCode();

        // A REDIRECT. Never a valid answer on a token API: it means a guard written
        // for a web form called back() or redirect() without checking whether the
        // caller wanted HTML. The app cannot follow it anywhere useful.
        //
        // Answered as a 500 rather than translated into a plausible 403, because we
        // do not know what the guard meant and a plausible-looking refusal would hide
        // the wiring bug instead of surfacing it. 304 is left alone: that is caching,
        // not a redirect.
        if ($status >= 300 && $status < 400 && $status !== 304) {
            Log::error('api.v2.route_answered_with_a_redirect', [
                'path' => $request->path(),
                'status' => $status,
                'location' => $response->headers->get('Location'),
            ]);

            return ApiV2Error::fromHttpStatus(500);
        }

        if ($status < 400) {
            return $response;
        }

        if ($response instanceof JsonResponse) {
            return $this->normalise($response->getData(true), $response);
        }

        // A plain Response that carries JSON anyway (a handler that built one by
        // hand, or a package response). Judged by content type rather than class:
        // treating it as non-JSON would rewrite a deliberate envelope into a
        // generic one.
        if (str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return $this->normalise(json_decode((string) $response->getContent(), true), $response);
        }

        // Not JSON at all: a redirect from a guard written for a web form, or an HTML
        // error page. Neither is ever a valid answer on /v2, and an app receiving one
        // would show the customer a parse failure instead of a reason.
        Log::warning('api.v2.non_json_error_response_normalised', [
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'class' => $response::class,
        ]);

        return ApiV2Error::fromHttpStatus($response->getStatusCode());
    }

    /**
     * @param  mixed  $payload  the decoded body, whatever shape it arrived in
     */
    private function normalise(mixed $payload, Response $response): Response
    {
        if (! is_array($payload)) {
            return ApiV2Error::fromHttpStatus($response->getStatusCode());
        }

        // Already ours. Deliberate envelopes are never rewritten.
        if (isset($payload['error']['type'])) {
            return $response;
        }

        $status = $response->getStatusCode();
        [$fallbackType, $fallbackMessage] = ApiV2Error::typeForStatus($status);

        $type = $this->typeFromFlags($payload) ?? $fallbackType;

        $message = is_string($payload['message'] ?? null) && $payload['message'] !== ''
            ? $payload['message']
            : $fallbackMessage;

        // Laravel's own validation shape, in case one reaches here without having
        // been thrown as a ValidationException.
        $extra = isset($payload['errors']) && is_array($payload['errors'])
            ? ['fields' => $payload['errors']]
            : [];

        $normalised = ApiV2Error::make($type, $message, $status, array_merge($extra, $this->carriedFlags($payload)));

        // Only a named few headers are carried over. Copying every header risks
        // dragging a stale Content-Type or cache directive onto the new body.
        foreach (self::CARRIED_HEADERS as $header) {
            if ($response->headers->has($header)) {
                $normalised->headers->set($header, (string) $response->headers->get($header));
            }
        }

        return $normalised;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function typeFromFlags(array $payload): ?string
    {
        foreach (self::FLAG_TYPES as $flag => $type) {
            if (($payload[$flag] ?? false) === true) {
                return $type;
            }
        }

        foreach (self::OBJECT_FLAG_TYPES as $flag => $type) {
            if (is_array($payload[$flag] ?? null)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Extra scalars the original response carried, so nothing useful is lost in
     * translation. Arrays and objects are left behind: they are the shapes most
     * likely to hold something internal.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, scalar>
     */
    private function carriedFlags(array $payload): array
    {
        $carried = [];

        foreach ($payload as $key => $value) {
            if (in_array($key, self::DROPPED_KEYS, true)) {
                continue;
            }

            if (is_scalar($value)) {
                $carried[$key] = $value;
            }
        }

        return $carried;
    }
}
