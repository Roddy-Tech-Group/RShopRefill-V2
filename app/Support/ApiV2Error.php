<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The single error shape every /v2 response uses.
 *
 * The mobile app cannot be recalled. Someone will be running today's build in
 * six months, so the app has to branch on something stable, and a human-readable
 * sentence is not stable: it gets reworded, translated, or improved. `type` is
 * the contract. `message` is for the customer's eyes and may change freely.
 *
 *   { "error": { "type": "validation_failed",
 *                "message": "...",
 *                "fields": { "email": ["..."] } } }
 *
 * Scoped to /v2 on purpose. The storefront's /api routes keep returning the
 * shapes their JavaScript already reads; nothing here touches them.
 */
final class ApiV2Error
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public static function make(string $type, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json([
            'error' => array_merge(['type' => $type, 'message' => $message], $extra),
        ], $status);
    }

    /**
     * Maps an exception onto the envelope, or returns null to leave handling
     * to the framework (anything outside /v2).
     */
    public static function fromException(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('v2', 'v2/*')) {
            return null;
        }

        if ($e instanceof ValidationException) {
            return self::make(
                'validation_failed',
                'Some of the details you sent are not valid.',
                422,
                ['fields' => $e->errors()],
            );
        }

        if ($e instanceof AuthenticationException) {
            return self::make(
                'unauthenticated',
                'Please sign in to continue.',
                401,
            );
        }

        if ($e instanceof AuthorizationException) {
            return self::make('forbidden', 'You do not have access to this.', 403);
        }

        // Carries a ready-made response (abort(response()->json(...))). Left to the
        // framework, which returns that response; it then passes back through
        // NormaliseV2ErrorEnvelope on its way out and gets the envelope there.
        // Handling it here would turn a deliberate 4xx into a generic 500.
        if ($e instanceof HttpResponseException) {
            return null;
        }

        if ($e instanceof ThrottleRequestsException) {
            // Seconds, so the app can wait exactly that long and say so,
            // rather than guessing or hammering.
            $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return self::make(
                'too_many_requests',
                'Too many attempts. Please wait a moment and try again.',
                429,
                ['retry_after' => $retryAfter],
            )->withHeaders($e->getHeaders());
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            // A 404 or 405 message is the framework's, not ours, and it names
            // internals: a missing model becomes "No query results for model
            // [App\Models\X] 123" before this callback runs, and a wrong verb names
            // the route and its allowed methods. Neither helps a customer.
            $message = in_array($status, [404, 405], true) ? '' : $e->getMessage();

            return self::fromHttpStatus($status, $message)
                ->withHeaders($e->getHeaders());
        }

        // Anything unplanned. The message is NEVER echoed outside local
        // debugging: an exception string leaks table names, file paths and
        // sometimes credentials. It is logged instead, where the reporter
        // (Sentry) has already seen it.
        Log::error('api.v2.unhandled_exception', [
            'path' => $request->path(),
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        return self::make(
            'server_error',
            'Something went wrong on our side. Please try again.',
            500,
            config('app.debug') ? ['debug' => $e->getMessage()] : [],
        );
    }

    /**
     * The envelope for a bare HTTP status, used by the exception renderer and by
     * NormaliseV2ErrorEnvelope when it has to wrap a response that came from
     * middleware shared with the website.
     */
    public static function fromHttpStatus(int $status, string $message = ''): JsonResponse
    {
        [$type, $default] = self::typeForStatus($status);

        // An abort() with its own sentence is deliberate and customer-facing; a bare
        // framework abort() has an empty message, so fall back. 5xx never echoes a
        // message, deliberate or not: it may carry internals.
        $safe = ($message !== '' && $status < 500) ? $message : $default;

        return self::make($type, $safe, $status);
    }

    /**
     * @return array{0: string, 1: string} [stable type, customer-facing fallback]
     */
    public static function typeForStatus(int $status): array
    {
        return match ($status) {
            401 => ['unauthenticated', 'Please sign in to continue.'],
            403 => ['forbidden', 'You do not have access to this.'],
            404 => ['not_found', 'That does not exist.'],
            405 => ['method_not_allowed', 'That is not how this endpoint is called.'],
            409 => ['conflict', 'That conflicts with something that already happened.'],
            413 => ['payload_too_large', 'That is too large to send.'],
            422 => ['validation_failed', 'Some of the details you sent are not valid.'],
            426 => ['upgrade_required', 'Please update the app to continue.'],
            429 => ['too_many_requests', 'Too many attempts. Please wait a moment and try again.'],
            503 => ['service_unavailable', 'We are briefly unavailable. Please try again shortly.'],
            default => $status >= 500
                ? ['server_error', 'Something went wrong on our side. Please try again.']
                : ['request_failed', 'That request could not be completed.'],
        };
    }
}
